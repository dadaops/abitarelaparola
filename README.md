# Abitare la parola

Landing page del corso di formazione «Abitare la parola» (Palermo, 7–8 novembre 2026).

## Struttura

| Percorso | Contenuto |
|---|---|
| `site/` | Il sito pubblicato: `index.html`, `assets/`, `robots.txt`, `sitemap.xml` |
| `scripts/build.sh` | Copia `site/` in `_site/` e sostituisce i segnaposto con l'indirizzo reale |
| `.github/workflows/pages.yml` | Pubblica su GitHub Pages a ogni push su `main` |
| `.github/workflows/sftp.yml` | Pubblica via SFTP su un server a ogni push su `main` (spento finché non è configurato) |

Nei file di `site/` l'indirizzo del sito compare come `__SITE_URL__`: non va scritto a mano, lo inserisce
`scripts/build.sh` al momento della pubblicazione. Per un'anteprima locale con gli indirizzi sostituiti:

```bash
scripts/build.sh https://abitarelaparola.it _site
```

## GitHub Pages

Indirizzo attuale: https://dadaops.dev/abitarelaparola/

Per spegnere la pubblicazione su Pages senza toccare i file: variabile del repo `PAGES_ENABLED=false`.

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

## Pubblicazione via SFTP

Il workflow `sftp.yml` costruisce il sito con l'indirizzo `SITE_URL` e lo carica con `lftp` nella cartella remota.
Si attiva impostando nel repo (Settings → Secrets and variables → Actions) questi valori:

| Nome | Tipo | Obbligatorio | Esempio / note |
|---|---|---|---|
| `SFTP_ENABLED` | variabile | sì | `true` per accendere il workflow |
| `SITE_URL` | variabile | sì | `https://abitarelaparola.it` |
| `SFTP_HOST` | variabile | sì | `ftp.esempio.it` |
| `SFTP_PORT` | variabile | no | predefinita `22` |
| `SFTP_USER` | variabile | sì | utente SFTP |
| `SFTP_REMOTE_DIR` | variabile | sì | cartella pubblica sul server, es. `/var/www/abitarelaparola` o `public_html` |
| `SFTP_DELETE` | variabile | no | `true` per cancellare dal server i file non più presenti nel sito. Usalo solo se la cartella contiene esclusivamente questo sito |
| `SFTP_KNOWN_HOSTS` | secret | sì | impronta del server, da `ssh-keyscan -p 22 ftp.esempio.it` (verifica l'impronta con il provider) |
| `SFTP_KEY` | secret | uno dei due | chiave privata SSH dedicata al deploy (consigliata) |
| `SFTP_PASSWORD` | secret | uno dei due | password SFTP, se il provider non accetta chiavi |

Esempio da terminale:

```bash
gh variable set SFTP_ENABLED --repo dadaops/abitarelaparola --body true
gh secret set SFTP_KEY --repo dadaops/abitarelaparola < ~/.ssh/abitarelaparola_deploy
```

Se il sito viene servito dal server SFTP, spegni GitHub Pages (`PAGES_ENABLED=false`) o fai in modo che
un solo indirizzo sia quello canonico, per non avere due copie della pagina indicizzate.
