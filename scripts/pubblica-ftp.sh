#!/usr/bin/env bash
# Carica una cartella locale sul server via SFTP, FTPS o FTP con lftp (usato dai workflow GitHub Actions).
# Uso: scripts/pubblica-ftp.sh <cartella-locale> <cartella-remota>
# Configurazione da variabili d'ambiente: SFTP_PROTOCOL (sftp|ftps|ftp), SFTP_HOST, SFTP_PORT, SFTP_USER,
# LFTP_PASSWORD, SFTP_KEY, SFTP_KNOWN_HOSTS, SFTP_DELETE, FTP_VERIFY_CERT. Vedi README.md.
set -euo pipefail

locale="${1:?Indica la cartella locale}"
remota="${2:?Indica la cartella remota}"
SFTP_PROTOCOL="${SFTP_PROTOCOL:-sftp}"
tmp="${RUNNER_TEMP:-$(mktemp -d)}"

case "$SFTP_PROTOCOL" in
  sftp|ftps|ftp) ;;
  *) echo "SFTP_PROTOCOL non valido: $SFTP_PROTOCOL (usa sftp, ftps o ftp)" >&2; exit 1 ;;
esac
mancanti=""
for v in SFTP_HOST SFTP_USER; do
  [ -n "${!v:-}" ] || mancanti="$mancanti $v"
done
if [ "$SFTP_PROTOCOL" = sftp ]; then
  [ -n "${SFTP_KNOWN_HOSTS:-}" ] || mancanti="$mancanti SFTP_KNOWN_HOSTS"
  [ -n "${SFTP_KEY:-}${LFTP_PASSWORD:-}" ] || mancanti="$mancanti SFTP_KEY/SFTP_PASSWORD"
else
  [ -n "${LFTP_PASSWORD:-}" ] || mancanti="$mancanti SFTP_PASSWORD"
fi
if [ -n "$mancanti" ]; then
  echo "Configurazione mancante:$mancanti" >&2
  exit 1
fi

# Raggiungibilità: Tophost rifiuta alcuni indirizzi di GitHub, quindi qualche tentativo prima di arrendersi
case "$SFTP_PROTOCOL" in sftp) porta="${SFTP_PORT:-22}" ;; *) porta="${SFTP_PORT:-21}" ;; esac
echo "IP pubblico del runner: $(curl -s -m 10 https://api.ipify.org || echo sconosciuto)"
raggiunto=""
for tentativo in 1 2 3; do
  if timeout 20 bash -c "</dev/tcp/$SFTP_HOST/$porta" 2>/dev/null; then raggiunto=1; break; fi
  echo "Tentativo $tentativo: il server non risponde sulla porta $porta"
  sleep 10
done
if [ -z "$raggiunto" ]; then
  echo "Il server non risponde sulla porta $porta da GitHub Actions." >&2
  echo "Se dal tuo computer funziona, il provider blocca le connessioni dall'estero o da questi IP:" >&2
  echo "su Tophost abilita l'accesso FTP dall'estero nel pannello di controllo, poi rilancia il workflow." >&2
  exit 1
fi
echo "Porta $porta raggiungibile"

case "$SFTP_PROTOCOL" in
  sftp)
    install -m 700 -d ~/.ssh
    printf '%s\n' "$SFTP_KNOWN_HOSTS" > ~/.ssh/known_hosts
    ssh_cmd="ssh -a -x -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$HOME/.ssh/known_hosts"
    if [ -n "${SFTP_KEY:-}" ]; then
      printf '%s\n' "$SFTP_KEY" > ~/.ssh/deploy_key
      chmod 600 ~/.ssh/deploy_key
      ssh_cmd="$ssh_cmd -o IdentitiesOnly=yes -i $HOME/.ssh/deploy_key"
    fi
    settings="set sftp:auto-confirm no; set sftp:connect-program \"$ssh_cmd\""
    url="sftp://$SFTP_HOST:$porta" ;;
  ftps)
    settings="set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate ${FTP_VERIFY_CERT:-yes}"
    # Alcuni server (es. Tophost) non inviano il certificato intermedio: lo si scarica
    # dall'indirizzo indicato nel certificato e lo si usa solo se firmato da una CA fidata.
    ca="$tmp/ca.pem"
    cp /etc/ssl/certs/ca-certificates.crt "$ca"
    issuer_url="$(echo | openssl s_client -starttls ftp -connect "$SFTP_HOST:$porta" -servername "$SFTP_HOST" 2>/dev/null \
      | openssl x509 -noout -ext authorityInfoAccess 2>/dev/null | sed -n 's/.*CA Issuers - URI:\(.*\)/\1/p' | head -1)"
    if [ -n "$issuer_url" ] && curl -fsS -m 20 "$issuer_url" -o "$tmp/issuer.crt"; then
      openssl x509 -inform DER -in "$tmp/issuer.crt" -out "$tmp/issuer.pem" 2>/dev/null \
        || openssl x509 -in "$tmp/issuer.crt" -out "$tmp/issuer.pem"
      if openssl verify -CAfile /etc/ssl/certs/ca-certificates.crt "$tmp/issuer.pem"; then
        cat "$tmp/issuer.pem" >> "$ca"
        echo "Aggiunto il certificato intermedio da $issuer_url"
      fi
    fi
    settings="$settings; set ssl:ca-file $ca"
    url="ftp://$SFTP_HOST:$porta" ;;
  ftp)
    settings="set ftp:ssl-allow false"
    url="ftp://$SFTP_HOST:$porta" ;;
esac

auth="-u $SFTP_USER,"
[ -n "${LFTP_PASSWORD:-}" ] && auth="--env-password -u $SFTP_USER"
delete=""
[ "${SFTP_DELETE:-}" = "true" ] && delete="--delete"

# Le pagine e gli altri file di testo vengono sempre ricaricati; gli altri solo se cambiano dimensione,
# perché il checkout azzera le date di modifica. Con SFTP_DELETE=true vengono cancellati dal server
# i file che non esistono più in locale.
testo="$(cd "$locale" && find . -type f \( -name '*.html' -o -name '*.php' -o -name '*.txt' -o -name '*.xml' \) | sed 's|^\./||' |
  while read -r f; do d="$(dirname "$f")"; [ "$d" = . ] && d=""; printf 'put -O "%s/%s" "%s/%s"\n' "${remota%/}" "$d" "${locale%/}" "$f"; done)"
lftp <<LFTP
set cmd:fail-exit yes
set net:max-retries 2
set net:timeout 30
set net:reconnect-interval-base 5
set ftp:passive-mode true
$settings
open $auth $url
mkdir -p -f "$remota"
mirror --reverse --ignore-time --parallel=4 --verbose $delete "${locale%/}/" "$remota"
$testo
bye
LFTP
