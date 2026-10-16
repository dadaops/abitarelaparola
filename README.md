# Abitare la parola

Landing page del corso di formazione «Abitare la parola» (Palermo, 7–8 novembre 2026).

## Struttura

| Percorso | Contenuto |
|---|---|
| `site/` | Il sito pubblicato: `index.html`, `assets/`, `robots.txt`, `sitemap.xml` |
| `scripts/build.sh` | Copia `site/` in `_site/` e sostituisce i segnaposto con l'indirizzo reale |
| `scripts/build-redirect.sh` | Prepara le pagine che reindirizzano al sito definitivo |
| `.github/workflows/pages.yml` | Pubblica il sito su GitHub Pages a ogni push su `main` |
| `.github/workflows/pages-redirect.yml` | Al posto del sito, pubblica su GitHub Pages un reindirizzamento al sito definitivo (spento finché non è configurato) |
| `.github/workflows/sftp.yml` | Pubblica via SFTP, FTPS o FTP su un server a ogni push su `main` (spento finché non è configurato) |

Nei file di `site/` l'indirizzo del sito compare come `__SITE_URL__`: non va scritto a mano, lo inserisce
`scripts/build.sh` al momento della pubblicazione. Per un'anteprima locale con gli indirizzi sostituiti:

```bash
scripts/build.sh https://abitarelaparola.it _site
```

## GitHub Pages

Questa è la strada da seguire se il sito resta su GitHub. Se il sito va su Tophost, salta alla sezione
«Pubblicazione su un server remoto».

Indirizzo attuale: https://dadaops.dev/abitarelaparola/

### Dismettere dadaops.dev/abitarelaparola quando il sito definitivo è online

Per non danneggiare la SEO, il vecchio indirizzo non va semplicemente spento (risponderebbe 404):
va reindirizzato al sito definitivo. Così Google trasferisce il posizionamento e i link già condivisi,
per esempio su WhatsApp, continuano a funzionare.

1. Verifica che https://abitarelaparola.it risponda con il sito aggiornato.
2. Attiva il reindirizzamento e pubblicalo:

   ```bash
   gh variable set PAGES_REDIRECT_TO --repo dadaops/abitarelaparola --body https://abitarelaparola.it
   gh workflow run pages-redirect.yml --repo dadaops/abitarelaparola
   ```

   Da quel momento `pages.yml` non pubblica più il sito su Pages e ogni push aggiorna solo il reindirizzamento.
   Ogni indirizzo mantiene il proprio percorso:
   `https://dadaops.dev/abitarelaparola/assets/anteprima.jpg` porta a `https://abitarelaparola.it/assets/anteprima.jpg`.
3. Dopo qualche mese, quando Google ha recepito il nuovo indirizzo (verificalo in Search Console),
   puoi spegnere del tutto Pages:

   ```bash
   gh variable set PAGES_ENABLED --repo dadaops/abitarelaparola --body false
   gh api -X DELETE repos/dadaops/abitarelaparola/pages
   ```

Per tornare a pubblicare il sito su Pages: `gh variable delete PAGES_REDIRECT_TO --repo dadaops/abitarelaparola`
e poi `gh workflow run pages.yml --repo dadaops/abitarelaparola`.

### Passaggio al dominio abitarelaparola.it

1. Dal pannello del registrar, imposta i DNS:

   | Nome  | Tipo  | Valore |
   |-------|-------|--------|
   | `@`   | A     | `185.199.108.153`, `185.199.109.153`, `185.199.110.153`, `185.199.111.153` |
   | `@`   | AAAA  | `2606:50c0:8000::153`, `2606:50c0:8001::153`, `2606:50c0:8002::153`, `2606:50c0:8003::153` |
   | `www` | CNAME | `dadaops.github.io` |

2. Quando `dig +short abitarelaparola.it` restituisce gli indirizzi qui sopra, collega il dominio e rilancia il workflow:

   ```bash
   gh api -X PUT repos/dadaops/abitarelaparola/pages -f cname=abitarelaparola.it
   gh workflow run pages.yml --repo dadaops/abitarelaparola
   ```

3. Quando GitHub ha emesso il certificato (di solito entro un'ora), attiva HTTPS obbligatorio:

   ```bash
   gh api -X PUT repos/dadaops/abitarelaparola/pages -F https_enforced=true
   ```

Il vecchio indirizzo https://dadaops.dev/abitarelaparola/ dovrebbe reindirizzare al nuovo dominio: verificalo dopo il passaggio.
Infine aggiungi https://abitarelaparola.it/ a Google Search Console e invia `https://abitarelaparola.it/sitemap.xml`.

## Pubblicazione su un server remoto (SFTP, FTPS o FTP)

Il workflow `sftp.yml` costruisce il sito con l'indirizzo `SITE_URL` e lo carica con `lftp` nella cartella remota
a ogni push su `main`. È spento finché la variabile `SFTP_ENABLED` non vale `true`.

Variabili e secret si impostano in GitHub: repo → Settings → Secrets and variables → Actions,
schede **Variables** e **Secrets**. I dati sensibili vanno sempre tra i **Secrets**.

| Nome | Tipo | Obbligatorio | Valore |
|---|---|---|---|
| `SFTP_ENABLED` | variabile | sì | `true` per accendere il workflow |
| `SFTP_PROTOCOL` | variabile | no | `sftp` (predefinito), `ftps` (FTP cifrato con TLS) oppure `ftp` |
| `SITE_URL` | variabile | sì | indirizzo pubblico del sito, es. `https://abitarelaparola.it` |
| `SFTP_HOST` | variabile | sì | server di upload, es. `ftp.abitarelaparola.it` |
| `SFTP_PORT` | variabile | no | predefinita `22` per sftp, `21` per ftp e ftps |
| `SFTP_USER` | variabile | sì | nome utente del server |
| `SFTP_REMOTE_DIR` | variabile | sì | cartella pubblica sul server, es. `/` o `public_html` |
| `SFTP_DELETE` | variabile | no | `true` per cancellare dal server i file non più presenti nel sito. Usalo solo se la cartella contiene esclusivamente questo sito |
| `FTP_VERIFY_CERT` | variabile | no | solo per ftps: `no` se il certificato TLS del server non corrisponde al nome host |
| `SFTP_PASSWORD` | secret | per ftp/ftps sì; per sftp in alternativa alla chiave | password del server |
| `SFTP_KEY` | secret | solo sftp, in alternativa alla password | chiave privata SSH dedicata al deploy |
| `SFTP_KNOWN_HOSTS` | secret | solo sftp, sì | impronta del server, da `ssh-keyscan -p 22 <host>` (verificala con il provider) |

### Tophost, piano TopWeb

Il piano TopWeb offre accesso FTP ma non SFTP né SSH, quindi si usa `ftps` e, se il server non lo accetta, `ftp`.
I dati di accesso sono nell'email di attivazione dell'hosting e nel pannello di controllo Tophost.

1. Recupera dal pannello o dall'email: server FTP (di solito `ftp.abitarelaparola.it`), utente e password FTP.
2. Collegati una volta con FileZilla per capire in quale cartella va il sito: è quella che contiene la pagina
   predefinita di Tophost (`index.html` o `index.php`). Se i file sono direttamente nella radice, la cartella è `/`.
3. Imposta i valori, sostituendo quelli tra `< >`:

   ```bash
   R=dadaops/abitarelaparola
   gh variable set SFTP_PROTOCOL   --repo $R --body ftps
   gh variable set SITE_URL        --repo $R --body https://abitarelaparola.it
   gh variable set SFTP_HOST       --repo $R --body ftp.abitarelaparola.it
   gh variable set SFTP_USER       --repo $R --body '<utente FTP>'
   gh variable set SFTP_REMOTE_DIR --repo $R --body '<cartella, es. />'
   gh secret set SFTP_PASSWORD     --repo $R   # chiede la password senza mostrarla
   gh variable set SFTP_ENABLED    --repo $R --body true
   ```

4. Lancia il primo caricamento e controllane l'esito:

   ```bash
   gh workflow run sftp.yml --repo dadaops/abitarelaparola
   gh run watch --repo dadaops/abitarelaparola
   ```

   - Se fallisce con un errore TLS o di certificato, prova `FTP_VERIFY_CERT=no` o, in ultima istanza,
     `SFTP_PROTOCOL=ftp`. Con FTP semplice la password viaggia in chiaro.
   - Se fallisce la connessione, verifica con Tophost che l'FTP accetti accessi da qualsiasi IP:
     i server di GitHub Actions cambiano indirizzo a ogni esecuzione.
5. Verifica che il sito risponda su `https://abitarelaparola.it` e che il certificato HTTPS sia attivo
   (da attivare nel pannello Tophost, se non è automatico).
6. Con il sito su Tophost, i DNS del dominio restano quelli di Tophost: **non** seguire i passi
   «Passaggio al dominio abitarelaparola.it» della sezione GitHub Pages. Segui invece
   «Dismettere dadaops.dev/abitarelaparola», per non avere due copie della pagina indicizzate.
