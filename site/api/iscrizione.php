<?php
// Riceve le iscrizioni dal modulo /iscrizione/.
// 1. Salva ogni invio sull'hosting: riga in iscrizioni.csv e ricevuta nella cartella ricevute/,
//    dentro una cartella non accessibile dal web.
// 2. Se configurato (api/config.php), inoltra l'iscrizione al foglio Google tramite Apps Script.
// GET restituisce lo stato del servizio, senza dati.
declare(strict_types=1);

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$config = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$googleUrl = (string)($config['google_url'] ?? '');
$googleToken = (string)($config['google_token'] ?? '');
$cartellaDati = (string)($config['cartella_dati'] ?? '') ?: cartellaPredefinita();

const MAX_FILE = 10 * 1024 * 1024;
const ESTENSIONI = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
];
const COLONNE = [
    'id', 'stato', 'ricevuto_il', 'nome', 'cognome', 'email', 'telefono', 'consenso',
    'professione', 'professione_altro', 'anni', 'ambito', 'ambito_altro', 'istituto',
    'fascia', 'intolleranze', 'accompagnatore', 'note',
    'pagamento', 'importo', 'ricevuta_quando', 'ricevuta_file',
    'utm_source', 'utm_medium', 'utm_campaign', 'referrer', 'pagina', 'tempo_compilazione',
    'inviato_a_google',
];

// Cartella dell'archivio: fuori dalla parte pubblica del sito se l'hosting lo consente, altrimenti
// una cartella del sito con nome casuale (creato una volta e ricordato in api/.cartella-dati.php),
// protetta anche da .htaccess. Il nome casuale serve se il server non applica .htaccess ai file statici.
function cartellaPredefinita(): string
{
    $sito = dirname(__DIR__);
    $fuori = dirname($sito) . '/dati-iscrizioni';
    if (is_dir($fuori) || (is_writable(dirname($sito)) && @mkdir($fuori, 0750))) {
        return $fuori;
    }
    $memo = __DIR__ . '/.cartella-dati.php';
    if (is_file($memo)) {
        return (string)(require $memo);
    }
    $cartella = $sito . '/dati-iscrizioni-' . bin2hex(random_bytes(12));
    file_put_contents($memo, '<?php return ' . var_export($cartella, true) . ";\n");
    return $cartella;
}

function rispondi(int $codice, array $dati): void
{
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function campo(string $nome, int $max = 500): string
{
    $v = $_POST[$nome] ?? '';
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
    if (!is_dir($cartella . '/ricevute') && !mkdir($cartella . '/ricevute', 0750, true) && !is_dir($cartella . '/ricevute')) {
        rispondi(500, ['ok' => false, 'errore' => 'archivio non disponibile']);
    }
    $htaccess = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
    foreach ([$cartella, $cartella . '/ricevute'] as $c) {
        if (!is_file($c . '/.htaccess')) {
            file_put_contents($c . '/.htaccess', $htaccess);
        }
        if (!is_file($c . '/index.html')) {
            file_put_contents($c . '/index.html', '');
        }
    }
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    rispondi(200, ['ok' => true, 'servizio' => 'iscrizioni', 'google' => $googleUrl !== '']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rispondi(405, ['ok' => false, 'errore' => 'metodo non consentito']);
}

// Bot: campo trappola compilato o modulo inviato in pochi secondi. Rispondiamo ok senza salvare.
if (campo('sito_web') !== '' || (campo('stato') === 'completa' && (int)campo('tempo_compilazione') < 5)) {
    rispondi(200, ['ok' => true]);
}

$d = [];
foreach (COLONNE as $c) {
    $d[$c] = '';
}
$d['id'] = campo('id', 40);
$d['stato'] = campo('stato', 20) === 'completa' ? 'completa' : 'parziale';
$d['ricevuto_il'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');
foreach (['nome', 'cognome', 'telefono', 'professione_altro', 'anni', 'ambito_altro', 'istituto', 'fascia', 'intolleranze',
          'accompagnatore', 'pagamento', 'ricevuta_quando', 'utm_source', 'utm_medium', 'utm_campaign', 'tempo_compilazione'] as $c) {
    $d[$c] = campo($c, 200);
}
foreach (['professione', 'ambito', 'referrer', 'pagina'] as $c) {
    $d[$c] = campo($c, 500);
}
$d['note'] = campo('note', 2000);
$d['email'] = mb_strtolower(campo('email', 200));
$d['consenso'] = campo('consenso') === 'si' ? 'si, ' . $d['ricevuto_il'] : '';
$d['importo'] = ['Bonifico' => '100', 'Carta del docente' => '122', 'Carta cultura' => '122'][$d['pagamento']] ?? '';

if (!preg_match('/^ALP-[0-9A-F]{16}$/', $d['id'])) {
    rispondi(400, ['ok' => false, 'errore' => 'identificativo non valido']);
}
$errori = [];
if (mb_strlen($d['nome']) < 2) $errori[] = 'nome';
if (mb_strlen($d['cognome']) < 2) $errori[] = 'cognome';
if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errori[] = 'email';
if (strlen(preg_replace('/\D/', '', $d['telefono'])) < 8) $errori[] = 'telefono';
if ($d['consenso'] === '') $errori[] = 'consenso';
if ($d['stato'] === 'completa') {
    if ($d['professione'] === '') $errori[] = 'professione';
    if ($d['ambito'] === '') $errori[] = 'ambito';
    if ($d['fascia'] === '') $errori[] = 'fascia';
    if ($d['importo'] === '') $errori[] = 'pagamento';
}
if ($errori) {
    rispondi(422, ['ok' => false, 'errore' => 'dati mancanti: ' . implode(', ', $errori)]);
}

preparaCartella($cartellaDati);

// Ricevuta del pagamento
$file = null;
if ($d['stato'] === 'completa' && isset($_FILES['ricevuta']) && $_FILES['ricevuta']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['ricevuta'];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > MAX_FILE) {
        rispondi(413, ['ok' => false, 'errore' => 'file troppo grande o non ricevuto']);
    }
    $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $estensione = ESTENSIONI[$tipo] ?? null;
    if ($estensione === null && preg_match('/\.(heic|heif)$/i', (string)$f['name'])) {
        $estensione = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $tipo = 'image/' . $estensione;
    }
    if ($estensione === null) {
        rispondi(415, ['ok' => false, 'errore' => 'formato del file non supportato']);
    }
    $nomeFile = $d['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $estensione;
    if (!move_uploaded_file($f['tmp_name'], $cartellaDati . '/ricevute/' . $nomeFile)) {
        rispondi(500, ['ok' => false, 'errore' => 'impossibile salvare il file']);
    }
    $file = ['nome' => $nomeFile, 'tipo' => $tipo, 'percorso' => $cartellaDati . '/ricevute/' . $nomeFile];
    $d['ricevuta_file'] = $nomeFile;
}

// Foglio Google
if ($googleUrl !== '') {
    $perGoogle = $d;
    unset($perGoogle['inviato_a_google']);
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

// Archivio sull'hosting: una riga per ogni invio (parziale e completo)
$csv = $cartellaDati . '/iscrizioni.csv';
$nuovo = !is_file($csv);
$fh = fopen($csv, 'ab');
if ($fh === false || !flock($fh, LOCK_EX)) {
    rispondi(500, ['ok' => false, 'errore' => 'archivio non disponibile']);
}
if ($nuovo) {
    fwrite($fh, "\xEF\xBB\xBF"); // BOM: Excel apre correttamente gli accenti
    fputcsv($fh, COLONNE, ';', '"', '');
}
fputcsv($fh, array_map('cella', array_values($d)), ';', '"', '');
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

rispondi(200, ['ok' => true, 'id' => $d['id'], 'google' => $d['inviato_a_google']]);
