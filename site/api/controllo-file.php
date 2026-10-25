<?php
// Controlli sulle ricevute caricate: sono ammessi solo PDF e JPG, verificati sul contenuto reale del file.
// - PDF: rifiutato se contiene contenuti attivi (JavaScript, avvio di programmi, file incorporati, moduli XFA,
//   invio di dati), cercati anche nelle parti compresse e nei nomi offuscati; rifiutato se cifrato in modo da
//   non poter essere ispezionato.
// - JPG: ricodificato da zero con GD, così vengono scartati dati estranei accodati o nascosti e i metadati EXIF.
// - Se sul server è disponibile ClamAV, ogni file viene anche scansionato dall'antivirus.
// Questo file definisce solo funzioni: aperto dal browser non produce nulla.
declare(strict_types=1);

const PDF_CHIAVI_VIETATE = [
    '/JavaScript' => 'JavaScript',
    '/JS' => 'JavaScript',
    '/Launch' => 'avvio di programmi',
    '/EmbeddedFile' => 'file incorporati',
    '/EmbeddedFiles' => 'file incorporati',
    '/RichMedia' => 'contenuti multimediali attivi',
    '/XFA' => 'moduli XFA',
    '/SubmitForm' => 'invio di dati',
    '/ImportData' => 'importazione di dati',
    '/GoToE' => 'collegamenti a file incorporati',
];
const PDF_MAX_DECOMPRESSO = 40 * 1024 * 1024; // protezione dalle «bombe» di compressione
const JPG_MAX_PIXEL = 60_000_000;

/**
 * @return array{ok: bool, errore?: string, tipo?: string, estensione?: string}
 */
function controllaRicevuta(string $percorso): array
{
    $inizio = (string)file_get_contents($percorso, false, null, 0, 1024);
    $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($percorso) ?: '';

    if ($tipo === 'application/pdf' && str_contains($inizio, '%PDF-')) {
        $esito = controllaPdf($percorso);
        $esito += ['tipo' => 'application/pdf', 'estensione' => 'pdf'];
    } elseif ($tipo === 'image/jpeg' && str_starts_with($inizio, "\xFF\xD8\xFF")) {
        $esito = ripulisciJpg($percorso);
        $esito += ['tipo' => 'image/jpeg', 'estensione' => 'jpg'];
    } else {
        return ['ok' => false, 'errore' => 'sono accettati solo file PDF o foto JPG'];
    }
    if (!$esito['ok']) {
        return $esito;
    }
    $antivirus = scansioneAntivirus($percorso);
    return $antivirus === null ? $esito : ['ok' => false, 'errore' => $antivirus];
}

/**
 * @return array{ok: bool, errore?: string}
 */
function controllaPdf(string $percorso): array
{
    $dati = (string)file_get_contents($percorso);
    if (!str_contains(substr($dati, -2048), '%%EOF')) {
        return ['ok' => false, 'errore' => 'il PDF sembra danneggiato o incompleto'];
    }

    // Testo da analizzare: la struttura del PDF senza i dati binari degli stream (immagini, testo delle pagine:
    // cercarvi le parole chiave darebbe falsi allarmi), più il contenuto decompresso degli «object stream»,
    // dove possono essere nascosti altri oggetti e quindi anche le azioni pericolose.
    $testo = '';
    $totale = 0;
    $posizione = 0;
    if (preg_match_all('/stream\r?\n/', $dati, $inizi, PREG_OFFSET_CAPTURE)) {
        foreach ($inizi[0] as [$marcatore, $da]) {
            $fine = strpos($dati, 'endstream', $da);
            if ($da < $posizione || $fine === false) {
                continue;
            }
            $dizionario = substr($dati, $posizione, $da - $posizione);
            $testo .= $dizionario . "\n";
            $posizione = $fine + 9;
            if (!preg_match('~/Type\s*/ObjStm~', normalizzaNomiPdf(substr($dizionario, -600)))) {
                continue;
            }
            $contenuto = substr($dati, $da + strlen($marcatore), $fine - $da - strlen($marcatore));
            $decompresso = @gzuncompress(rtrim($contenuto, "\r\n"), PDF_MAX_DECOMPRESSO);
            if ($decompresso === false) {
                $decompresso = @gzinflate(substr($contenuto, 2), PDF_MAX_DECOMPRESSO);
            }
            if (!is_string($decompresso)) {
                return ['ok' => false, 'errore' => 'il PDF contiene parti non leggibili: carica una foto JPG della ricevuta'];
            }
            $totale += strlen($decompresso);
            if ($totale > PDF_MAX_DECOMPRESSO) {
                return ['ok' => false, 'errore' => 'il PDF è troppo complesso per essere verificato: carica una foto JPG della ricevuta'];
            }
            $testo .= $decompresso . "\n";
        }
    }
    $testo .= substr($dati, $posizione);

    $testo = normalizzaNomiPdf($testo);

    foreach (PDF_CHIAVI_VIETATE as $chiave => $descrizione) {
        if (preg_match('~' . preg_quote($chiave, '~') . '(?![A-Za-z0-9])~', $testo)) {
            return ['ok' => false, 'errore' => "il PDF contiene $descrizione e non può essere accettato: carica la ricevuta originale o una foto JPG"];
        }
    }

    // Con la cifratura gli stream compressi non sono leggibili: se ci sono object stream non si può verificare.
    if (preg_match('~/Encrypt(?![A-Za-z])~', $testo) && preg_match('~/ObjStm(?![A-Za-z])~', $testo)) {
        return ['ok' => false, 'errore' => 'il PDF è protetto e non può essere verificato: carica una foto JPG o uno screenshot della ricevuta'];
    }
    return ['ok' => true];
}

// I nomi PDF possono essere scritti in esadecimale per eludere i controlli (/J#61vaScript = /JavaScript).
function normalizzaNomiPdf(string $testo): string
{
    return preg_replace_callback('~/[^\s/<>\[\]()%{}]+~', static fn($m) => preg_replace_callback(
        '/#([0-9A-Fa-f]{2})/', static fn($h) => chr(hexdec($h[1])), $m[0]
    ), $testo) ?? '';
}

/**
 * @return array{ok: bool, errore?: string}
 */
function ripulisciJpg(string $percorso): array
{
    $info = @getimagesize($percorso);
    if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
        return ['ok' => false, 'errore' => 'la foto non è un JPG valido'];
    }
    if ($info[0] * $info[1] > JPG_MAX_PIXEL) {
        return ['ok' => false, 'errore' => 'la foto ha una risoluzione troppo alta'];
    }
    if (!function_exists('imagecreatefromjpeg')) {
        // Senza GD non si può ricodificare: almeno si escludono codice e script nascosti nel file.
        $dati = (string)file_get_contents($percorso);
        return preg_match('/<\?php|<script|<\?=/i', $dati) ? ['ok' => false, 'errore' => 'la foto contiene dati non validi'] : ['ok' => true];
    }
    $immagine = @imagecreatefromjpeg($percorso);
    if ($immagine === false) {
        return ['ok' => false, 'errore' => 'la foto non è leggibile'];
    }
    // Le foto del telefono indicano l'orientamento nei metadati, che la ricodifica elimina: lo si applica prima.
    $orientamento = function_exists('exif_read_data') ? (int)(@exif_read_data($percorso)['Orientation'] ?? 1) : 1;
    $gradi = [3 => 180, 6 => -90, 8 => 90][$orientamento] ?? 0;
    if ($gradi !== 0) {
        $ruotata = imagerotate($immagine, $gradi, 0);
        if ($ruotata !== false) {
            $immagine = $ruotata;
        }
    }
    $ok = imagejpeg($immagine, $percorso, 88);
    return $ok ? ['ok' => true] : ['ok' => false, 'errore' => 'impossibile elaborare la foto'];
}

// Restituisce un messaggio di errore se l'antivirus trova qualcosa, null se il file è pulito o se
// ClamAV non è disponibile sul server.
function scansioneAntivirus(string $percorso): ?string
{
    if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        return null;
    }
    foreach (['clamdscan --fdpass', 'clamscan'] as $comando) {
        $programma = strtok($comando, ' ');
        $trovato = [];
        @exec('command -v ' . escapeshellarg($programma) . ' 2>/dev/null', $trovato);
        if (!$trovato) {
            continue;
        }
        $uscita = [];
        $codice = 0;
        @exec($comando . ' --no-summary ' . escapeshellarg($percorso) . ' 2>&1', $uscita, $codice);
        if ($codice === 1) {
            return 'il file è stato segnalato dall’antivirus e non può essere accettato';
        }
        return null; // 0 = pulito; 2 = errore dell'antivirus, non blocca l'iscrizione
    }
    return null;
}
