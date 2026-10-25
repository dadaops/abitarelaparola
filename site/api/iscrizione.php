<?php
// Riceve le iscrizioni dal modulo /iscrizione/, in tre momenti dello stesso flusso:
//   azione=contatto      prenotazione del posto (nome, cognome, email, cellulare, consenso)
//   azione=pagamento     metodo di pagamento e ricevuta, subito o più tardi dal link personale
//   azione=questionario  domande integrative, dopo la ricevuta
// GET ?azione=stato&id=…&chiave=… restituisce lo stato per il link personale; GET senza parametri lo stato del servizio.
//
// Ogni iscrizione ha un codice (ALP-…) e una chiave firmata con un segreto del server: solo chi ha il link
// personale può completarla. I dati vengono:
// 1. salvati sull'hosting: stato attuale in iscrizioni/<codice>.json, storico in iscrizioni.csv, ricevute in
//    ricevute/, dentro una cartella non accessibile dal web;
// 2. inoltrati al foglio Google tramite Apps Script, se configurato in api/config.php.
declare(strict_types=1);

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require __DIR__ . '/controllo-file.php';

$config = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$googleUrl = (string)($config['google_url'] ?? '');
$googleToken = (string)($config['google_token'] ?? '');
$sito = rtrim((string)($config['sito'] ?? 'https://www.abitarelaparola.it'), '/');
$recaptchaSegreto = (string)($config['recaptcha_secret'] ?? '');
$recaptchaSoglia = (float)($config['recaptcha_soglia'] ?? 0.5);
$cartellaDati = (string)($config['cartella_dati'] ?? '') ?: cartellaPredefinita((string)($config['nome_archivio'] ?? 'dati-iscrizioni'));

const MAX_FILE = 10 * 1024 * 1024;
const IMPORTI = ['Bonifico' => '100', 'Carta del docente' => '122', 'Carta cultura' => '122'];
// Ordine degli stati: un invio non riporta mai indietro un'iscrizione.
const STATI = ['contatto' => 1, 'in_attesa_pagamento' => 2, 'ricevuta_inviata' => 3];
const COLONNE = [
    'id', 'stato', 'aggiornato', 'evento', 'nome', 'cognome', 'email', 'telefono', 'consenso',
    'pagamento', 'importo', 'ricevuta_file', 'questionario',
    'fascia', 'professione', 'professione_altro', 'anni', 'ambito', 'ambito_altro', 'istituto',
    'intolleranze', 'accompagnatore', 'note',
    'link_personale', 'utm_source', 'utm_medium', 'utm_campaign', 'referrer', 'pagina',
    'primo_contatto', 'inviato_a_google',
];

// Cartella dell'archivio: accanto alla radice pubblica del sito (quindi non raggiungibile dal web) se
// l'hosting lo consente, altrimenti dentro il modulo con nome casuale (creato una volta e ricordato in
// api/.cartella-dati.php), protetta anche da .htaccess. Il nome casuale serve se il server non applica
// .htaccess ai file statici.
function cartellaPredefinita(string $nome): string
{
    $radice = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: dirname(__DIR__);
    $fuori = dirname($radice) . '/' . $nome;
    if (is_dir($fuori) || (is_writable(dirname($radice)) && @mkdir($fuori, 0750))) {
        return $fuori;
    }
    $memo = __DIR__ . '/.cartella-dati.php';
    if (is_file($memo)) {
        return (string)(require $memo);
    }
    $cartella = dirname(__DIR__) . '/' . $nome . '-' . bin2hex(random_bytes(12));
    file_put_contents($memo, '<?php return ' . var_export($cartella, true) . ";\n");
    return $cartella;
}

// Segreto del server per firmare i link personali: generato al primo uso, non è nel repo.
function segreto(): string
{
    $file = __DIR__ . '/.segreto.php';
    if (!is_file($file)) {
        file_put_contents($file, '<?php return ' . var_export(bin2hex(random_bytes(32)), true) . ";\n", LOCK_EX);
    }
    return (string)(require $file);
}

function chiave(string $id): string
{
    return substr(hash_hmac('sha256', $id, segreto()), 0, 32);
}

function rispondi(int $codice, array $dati): void
{
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function campo(string $nome, int $max = 500, ?array $origine = null): string
{
    $v = ($origine ?? $_POST)[$nome] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
    return mb_substr($v, 0, $max);
}

// Evita che un valore venga interpretato come formula da Excel o Fogli Google.
function cella(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

function preparaCartella(string $cartella): void
{
    foreach (['ricevute', 'iscrizioni'] as $sotto) {
        if (!is_dir("$cartella/$sotto") && !mkdir("$cartella/$sotto", 0750, true) && !is_dir("$cartella/$sotto")) {
            rispondi(500, ['ok' => false, 'errore' => 'archivio non disponibile']);
        }
    }
    $htaccess = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
    foreach ([$cartella, "$cartella/ricevute", "$cartella/iscrizioni"] as $c) {
        if (!is_file("$c/.htaccess")) {
            file_put_contents("$c/.htaccess", $htaccess);
        }
        if (!is_file("$c/index.html")) {
            file_put_contents("$c/index.html", '');
        }
    }
}

function leggiIscrizione(string $cartella, string $id): ?array
{
    $file = "$cartella/iscrizioni/$id.json";
    if (!is_file($file)) {
        return null;
    }
    $d = json_decode((string)file_get_contents($file), true);
    return is_array($d) ? $d : null;
}

function salvaIscrizione(string $cartella, array $d): void
{
    file_put_contents("$cartella/iscrizioni/{$d['id']}.json", json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

    $csv = "$cartella/iscrizioni.csv";
    $nuovo = !is_file($csv);
    $fh = fopen($csv, 'ab');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        rispondi(500, ['ok' => false, 'errore' => 'archivio non disponibile']);
    }
    if ($nuovo) {
        fwrite($fh, "\xEF\xBB\xBF"); // BOM: Excel apre correttamente gli accenti
        fputcsv($fh, COLONNE, ';', '"', '');
    }
    fputcsv($fh, array_map(fn($c) => cella((string)($d[$c] ?? '')), COLONNE), ';', '"', '');
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function inviaAGoogle(string $url, array $dati): bool
{
    $json = json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true, // Apps Script risponde con un reindirizzamento
            CURLOPT_TIMEOUT => 25,
        ]);
        $risposta = curl_exec($ch);
    } else {
        $risposta = @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $json,
            'timeout' => 25,
        ]]));
    }
    $esito = is_string($risposta) ? json_decode($risposta, true) : null;
    return is_array($esito) && !empty($esito['ok']);
}

// reCAPTCHA v3: restituisce un messaggio di errore, oppure null se la verifica è superata o non configurata.
function verificaRecaptcha(string $segreto, float $soglia, string $azione): ?string
{
    if ($segreto === '') {
        return null;
    }
    $token = campo('recaptcha', 4000);
    if ($token === '') {
        return 'verifica anti-spam mancante: ricarica la pagina e riprova';
    }
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['secret' => $segreto, 'response' => $token, 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $risposta = json_decode((string)curl_exec($ch), true);
    if (!is_array($risposta)) {
        return null; // Google non raggiungibile: non blocchiamo l'iscrizione, restano campo trappola e controlli sui dati
    }
    if (empty($risposta['success'])
        || (isset($risposta['action']) && $risposta['action'] !== $azione)
        || (isset($risposta['score']) && (float)$risposta['score'] < $soglia)) {
        return 'verifica anti-spam non superata: riprova, oppure scrivi a abitarelaparola@gmail.com';
    }
    return null;
}

function iscrizioneAutorizzata(string $cartella, string $id, string $chiaveRicevuta): array
{
    if (!preg_match('/^ALP-[0-9A-F]{16}$/', $id) || !hash_equals(chiave($id), $chiaveRicevuta)) {
        rispondi(403, ['ok' => false, 'errore' => 'link non valido']);
    }
    $d = leggiIscrizione($cartella, $id);
    if ($d === null) {
        rispondi(404, ['ok' => false, 'errore' => 'iscrizione non trovata']);
    }
    return $d;
}

// --- Richieste GET -------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (campo('azione', 20, $_GET) !== 'stato') {
        $radice = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: dirname(__DIR__);
        rispondi(200, [
            'ok' => true,
            'servizio' => 'iscrizioni',
            'google' => $googleUrl !== '',
            'recaptcha' => $recaptchaSegreto !== '',
            'archivio' => str_starts_with($cartellaDati, $radice . '/') ? 'nel sito, cartella con nome casuale' : 'fuori dalla parte pubblica',
            'ricodifica_jpg' => function_exists('imagecreatefromjpeg'),
            'antivirus' => scansioneAntivirus(__FILE__) === null && (bool)array_filter(['clamdscan', 'clamscan'], static function ($p) {
                $t = [];
                @exec('command -v ' . escapeshellarg($p) . ' 2>/dev/null', $t);
                return (bool)$t;
            }),
        ]);
    }
    $d = iscrizioneAutorizzata($cartellaDati, campo('id', 40, $_GET), campo('chiave', 64, $_GET));
    rispondi(200, [
        'ok' => true,
        'nome' => $d['nome'],
        'email' => $d['email'],
        'pagamento' => $d['pagamento'] ?? '',
        'ricevuta' => ($d['ricevuta_file'] ?? '') !== '',
        'questionario' => ($d['questionario'] ?? '') === 'si',
    ]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rispondi(405, ['ok' => false, 'errore' => 'metodo non consentito']);
}

// --- Richieste POST ------------------------------------------------------------------------------
$azione = campo('azione', 20);
$adesso = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');

// Bot: campo trappola compilato o prenotazione inviata in pochi secondi. Rispondiamo ok senza salvare.
if (campo('sito_web') !== '' || ($azione === 'contatto' && (int)campo('tempo_compilazione') < 3)) {
    rispondi(200, ['ok' => true, 'id' => 'ALP-0000000000000000', 'chiave' => '']);
}

if (in_array($azione, ['contatto', 'pagamento', 'questionario'], true)
    && ($errore = verificaRecaptcha($recaptchaSegreto, $recaptchaSoglia, $azione)) !== null) {
    rispondi(403, ['ok' => false, 'errore' => $errore]);
}

preparaCartella($cartellaDati);
$file = null;

if ($azione === 'contatto') {
    // Una persona che torna indietro e corregge i contatti mantiene lo stesso codice.
    $id = campo('id', 40);
    $d = ($id !== '' && hash_equals(chiave($id), campo('chiave', 64))) ? leggiIscrizione($cartellaDati, $id) : null;
    if ($d === null) {
        $id = 'ALP-' . strtoupper(bin2hex(random_bytes(8)));
        $d = ['id' => $id, 'stato' => 'contatto', 'primo_contatto' => $adesso];
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $c) {
            $d[$c] = campo($c, 100);
        }
        $d['referrer'] = campo('referrer', 300);
        $d['pagina'] = campo('pagina', 300);
    }
    $d['nome'] = campo('nome', 80);
    $d['cognome'] = campo('cognome', 80);
    $d['email'] = mb_strtolower(campo('email', 200));
    $d['telefono'] = campo('telefono', 40);
    $d['consenso'] = campo('consenso') === 'si' ? 'si, ' . $adesso : '';
    $errori = [];
    if (mb_strlen($d['nome']) < 2) $errori[] = 'nome';
    if (mb_strlen($d['cognome']) < 2) $errori[] = 'cognome';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errori[] = 'email';
    if (strlen(preg_replace('/\D/', '', $d['telefono'])) < 8) $errori[] = 'cellulare';
    if ($d['consenso'] === '') $errori[] = 'consenso';
    if ($errori) {
        rispondi(422, ['ok' => false, 'errore' => 'dati mancanti: ' . implode(', ', $errori)]);
    }
    $d['link_personale'] = $sito . '/iscrizione/?c=' . $d['id'] . '&k=' . chiave($d['id']);
} elseif ($azione === 'pagamento') {
    $d = iscrizioneAutorizzata($cartellaDati, campo('id', 40), campo('chiave', 64));
    $pagamento = campo('pagamento', 40);
    if (!isset(IMPORTI[$pagamento])) {
        rispondi(422, ['ok' => false, 'errore' => 'scegli come pagare']);
    }
    $d['pagamento'] = $pagamento;
    $d['importo'] = IMPORTI[$pagamento];
    if (isset($_FILES['ricevuta']) && $_FILES['ricevuta']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['ricevuta'];
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > MAX_FILE) {
            rispondi(413, ['ok' => false, 'errore' => 'file troppo grande o non ricevuto']);
        }
        $controllo = controllaRicevuta($f['tmp_name']);
        if (!$controllo['ok']) {
            @unlink($f['tmp_name']);
            rispondi(415, ['ok' => false, 'errore' => $controllo['errore']]);
        }
        $tipo = $controllo['tipo'];
        $estensione = $controllo['estensione'];
        $nomeFile = $d['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $estensione;
        if (!move_uploaded_file($f['tmp_name'], "$cartellaDati/ricevute/$nomeFile")) {
            rispondi(500, ['ok' => false, 'errore' => 'impossibile salvare il file']);
        }
        $file = ['nome' => $nomeFile, 'tipo' => $tipo, 'percorso' => "$cartellaDati/ricevute/$nomeFile"];
        $d['ricevuta_file'] = $nomeFile;
        $nuovoStato = 'ricevuta_inviata';
    } else {
        $nuovoStato = 'in_attesa_pagamento';
    }
    if ((STATI[$nuovoStato] ?? 0) > (STATI[$d['stato']] ?? 0)) {
        $d['stato'] = $nuovoStato;
    }
} elseif ($azione === 'questionario') {
    $d = iscrizioneAutorizzata($cartellaDati, campo('id', 40), campo('chiave', 64));
    foreach (['fascia', 'anni', 'professione_altro', 'ambito_altro', 'istituto', 'intolleranze', 'accompagnatore'] as $c) {
        $d[$c] = campo($c, 200);
    }
    $d['professione'] = campo('professione', 500);
    $d['ambito'] = campo('ambito', 300);
    $d['note'] = campo('note', 2000);
    $soloStudenteGenitore = $d['professione'] !== '' && !array_diff(array_map('trim', explode(',', $d['professione'])), ['Studente', 'Genitore']);
    $errori = [];
    if ($d['fascia'] === '') $errori[] = 'fascia';
    if ($d['professione'] === '') $errori[] = 'professione';
    if ($d['anni'] === '' && !$soloStudenteGenitore) $errori[] = 'anni';
    if ($d['ambito'] === '') $errori[] = 'ambito';
    if ($errori) {
        rispondi(422, ['ok' => false, 'errore' => 'dati mancanti: ' . implode(', ', $errori)]);
    }
    $d['questionario'] = 'si';
} else {
    rispondi(400, ['ok' => false, 'errore' => 'azione non valida']);
}

$d['evento'] = $azione;
$d['aggiornato'] = $adesso;

if ($googleUrl !== '') {
    $perGoogle = $d;
    unset($perGoogle['inviato_a_google'], $perGoogle['ricevuta_file']);
    $perGoogle['token'] = $googleToken;
    if ($file) {
        $perGoogle['file'] = [
            'nome' => $file['nome'],
            'tipo' => $file['tipo'],
            'base64' => base64_encode((string)file_get_contents($file['percorso'])),
        ];
    }
    $d['inviato_a_google'] = inviaAGoogle($googleUrl, $perGoogle) ? 'si' : 'NO';
} else {
    $d['inviato_a_google'] = 'non configurato';
}

salvaIscrizione($cartellaDati, $d);

rispondi(200, [
    'ok' => true,
    'id' => $d['id'],
    'chiave' => chiave($d['id']),
    'stato' => $d['stato'],
    'ricevuta' => ($d['ricevuta_file'] ?? '') !== '',
]);
