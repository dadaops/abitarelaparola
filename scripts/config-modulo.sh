#!/usr/bin/env bash
# Scrive api/config.php del modulo di iscrizione (usato dai workflow GitHub Actions).
# Uso: scripts/config-modulo.sh <file-config> <indirizzo-del-modulo> [nome-archivio]
# Collegamento al foglio Google (GOOGLE_SCRIPT_URL, GOOGLE_SCRIPT_TOKEN) e segreto reCAPTCHA (RECAPTCHA_SECRET): restano nei secret e finiscono
# solo nel file caricato sul server, mai nel repo o nello zip delle Releases.
set -euo pipefail

file="${1:?Indica il file di configurazione}"
sito="${2:?Indica l’indirizzo del modulo}"
archivio="${3:-dati-iscrizioni}"
sito="${sito%/}"

[[ "$sito" =~ ^https?://[A-Za-z0-9.:-]+(/[a-z0-9-]+)?$ ]] || { echo "Indirizzo non valido: $sito" >&2; exit 1; }
[[ "$archivio" =~ ^[a-z0-9-]+$ ]] || { echo "Nome dell'archivio non valido" >&2; exit 1; }
if [ -n "${GOOGLE_SCRIPT_URL:-}" ]; then
  [[ "$GOOGLE_SCRIPT_URL" =~ ^https://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec$ ]] || { echo "GOOGLE_SCRIPT_URL non valido" >&2; exit 1; }
  [[ "${GOOGLE_SCRIPT_TOKEN:-}" =~ ^[A-Za-z0-9]{32,128}$ ]] || { echo "GOOGLE_SCRIPT_TOKEN mancante o non valido" >&2; exit 1; }
  echo "Modulo collegato al foglio Google"
else
  echo "Foglio Google non collegato: le iscrizioni vengono salvate solo sull'hosting"
fi
if [ -n "${RECAPTCHA_SECRET:-}" ]; then
  [[ "$RECAPTCHA_SECRET" =~ ^[A-Za-z0-9_-]{30,60}$ ]] || { echo "RECAPTCHA_SECRET non valido" >&2; exit 1; }
  echo "reCAPTCHA attivo"
else
  echo "reCAPTCHA non configurato: restano campo trappola e tempo minimo"
fi
printf "<?php\nreturn [\n    'sito' => '%s',\n    'nome_archivio' => '%s',\n    'google_url' => '%s',\n    'google_token' => '%s',\n    'recaptcha_secret' => '%s',\n];\n" \
  "$sito" "$archivio" "${GOOGLE_SCRIPT_URL:-}" "${GOOGLE_SCRIPT_TOKEN:-}" "${RECAPTCHA_SECRET:-}" > "$file"
