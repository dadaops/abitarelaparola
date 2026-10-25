# Abitare la parola

Landing page del corso di formazione «Abitare la parola» (Palermo, 7–8 novembre 2026).

Sito pubblico: https://www.abitarelaparola.it, ospitato su Tophost (piano TopWeb).
`abitarelaparola.it` senza `www` reindirizza a quell'indirizzo, che è quello canonico.

## Struttura

| Percorso | Contenuto |
|---|---|
| `site/` | Il sito pubblicato: `index.html`, `assets/`, `robots.txt`, `sitemap.xml` |
| `site/iscrizione/` | Modulo di iscrizione (alternativa al modulo Google) |
| `site/api/iscrizione.php` | Riceve le iscrizioni: le salva sull'hosting e le inoltra al foglio Google |
| `google-apps-script/Codice.gs` | Script da incollare nel foglio Google che riceve le iscrizioni (non pubblicato sul sito) |
| `scripts/build.sh` | Copia `site/` in `_site/` e sostituisce i segnaposto con l'indirizzo reale |
| `scripts/build-redirect.sh` | Prepara le pagine che reindirizzano al sito definitivo |
| `.github/workflows/sftp.yml` | Pubblica il sito su Tophost via FTPS a ogni push su `main` |
| `.github/workflows/release-zip.yml` | Crea nelle Releases uno zip del sito da caricare a mano via FTP |
| `.github/workflows/pages-redirect.yml` | Pubblica su GitHub Pages solo un reindirizzamento al sito definitivo |
| `.github/workflows/pages.yml` | Pubblica il sito su GitHub Pages (oggi fermo, vedi «GitHub Pages») |

Nei file di `site/` l'indirizzo del sito compare come `__SITE_URL__`: non va scritto a mano, lo inserisce
`scripts/build.sh` al momento della pubblicazione. Per un'anteprima locale con gli indirizzi sostituiti:

```bash
scripts/build.sh https://www.abitarelaparola.it _site
```

## Modulo di iscrizione (sperimentale, non pubblicato)

`site/iscrizione/` è l'alternativa al modulo Google. È in prova: la landing continua a usare il modulo Google e la pagina
non è indicizzata (`noindex`). Il flusso è spezzato in due, per non frenare l'acquisto con domande non necessarie:

| Passo | Cosa chiede | Stato dell'iscrizione |
|---|---|---|
| 1. Prenota il posto | nome, cognome, email, cellulare, consenso | `contatto` |
| 2. Pagamento | metodo (Carta del Docente in evidenza) e ricevuta, oppure «non ho ancora pagato» | `ricevuta_inviata` o `in_attesa_pagamento` |
| 3. Domande integrative | fascia Palazzo Butera, professione, anni, ambito, istituto, allergie, accompagnatore, note | (colonna «Domande integrative» = si) |

Dopo il passo 1 ogni iscrizione ha un **link personale** (`/iscrizione/?c=CODICE&k=CHIAVE`), mostrato a schermo e inviato
per email: serve a caricare la ricevuta più tardi e poi rispondere alle domande, da qualsiasi dispositivo. La chiave è
firmata con un segreto generato dal server (`api/.segreto.php`), quindi il link non si può indovinare.
Lo stato `confermato` lo scrivete voi nel foglio dopo aver verificato il pagamento: il modulo non lo sovrascrive mai.

Email automatiche (dal foglio Google, partono dall'account che possiede lo script):

- **Prenotazione** (pagamento rimandato): istruzioni di pagamento e link per caricare la ricevuta.
- **Ricevuta arrivata**: conferma e link alle domande integrative, se non ancora compilate.
- **Promemoria** (ogni giorno alle 10, fino alla scadenza): a chi ha prenotato da almeno 20 ore senza ricevuta, una sola volta.

Ogni passo viene salvato in due posti:

1. **Sull'hosting Tophost**: stato attuale in `iscrizioni/<codice>.json`, storico di tutti i passi in `iscrizioni.csv`
   (separatore `;`, si apre con Excel) e ricevute in `ricevute/`, dentro la cartella `dati-iscrizioni` creata dal PHP fuori
   dalla parte pubblica del sito. Se l'hosting non lo consente, la cartella è dentro il sito con un nome casuale
   (`dati-iscrizioni-…`, indicato in `api/.cartella-dati.php`) e protetta da `.htaccess`. Si scarica con FileZilla.
2. **Nel foglio Google**, se collegato: una riga per iscrizione, aggiornata a ogni passo senza cancellare i dati già
   presenti, e le ricevute in una cartella di Drive con il link nel foglio.

Altri accorgimenti: bozza dei contatti salvata nel browser, email corretta al volo (`gmial.com` → `gmail.com`), IBAN e
causale da copiare, anni di professione facoltativi per studenti e genitori, campo nascosto e tempo minimo contro i bot,
provenienza (UTM e referrer) salvata con l'iscrizione, invito a un collega su WhatsApp e promemoria nel calendario.

Eventi Analytics: `form_iniziato`, `generate_lead` (passo 1), `begin_checkout`, `add_payment_info`, `iscrizione_prenotata`
(evento chiave consigliato), `ricevuta_caricata` (evento chiave consigliato), `questionario_completato`,
`link_personale_aperto`, `form_errore`, `form_invio_fallito`, `invito_collega`, `aggiungi_calendario`.

La scadenza è `CHIUSURA` nello script della pagina e `SCADENZA` in `Codice.gs`: dopo quella data non si possono fare nuove
prenotazioni, ma il link personale continua a funzionare. Con `?anteprima` nell'indirizzo il modulo si apre anche dopo la scadenza.

### Collegare il foglio Google

1. Con l'account **abitarelaparola@gmail.com** (le email ai partecipanti partono da lì) crea un foglio Google, per esempio
   «Abitare la parola – Iscrizioni», e apri **Estensioni → Apps Script**.
2. Sostituisci il codice con il contenuto di `google-apps-script/Codice.gs` e salva.
3. Scegli la funzione `configura` ed esegui. Autorizza l'accesso richiesto (foglio, Drive, invio email, attivatori).
   Nel **Registro di esecuzione** compare il token: copialo.
4. **Esegui il deployment → Nuovo deployment → App web**: «Esegui come» **Me**, «Chi ha accesso» **Chiunque**.
   Copia l'URL che termina con `/exec`.
5. Salva i due valori nei secret del repo:

   ```bash
   gh secret set GOOGLE_SCRIPT_URL   --repo dadaops/abitarelaparola   # incolla l'URL /exec
   gh secret set GOOGLE_SCRIPT_TOKEN --repo dadaops/abitarelaparola   # incolla il token
   ```

Il workflow di caricamento scrive `api/config.php` sul server con l'indirizzo del sito e questi valori: non finiscono mai
nel repo né nello zip delle Releases. Senza secret il modulo salva solo sull'hosting (e non invia email). Se modifichi
`Codice.gs`, crea una nuova versione del deployment (Gestisci deployment → Modifica → Nuova versione), così l'URL resta lo stesso.

Verifica: `…/api/iscrizione.php` deve rispondere `{"ok":true,…,"google":true}`.

## Google Analytics

La pagina usa Google Analytics 4 (`G-LQWW7TVVDQ`). Oltre alle visualizzazioni invia questi eventi:

| Evento | Quando | Parametri |
|---|---|---|
| `iscrizione_click` | clic su un pulsante di iscrizione | `posizione`, `link_url` |
| `brochure_download` | clic su un link alla brochure | `posizione`, `file_name` |
| `contatto_email_click` | clic sull'indirizzo email | `posizione` |
| `navigazione_click` | clic su un link interno (menu, «Guarda il programma») | `posizione`, `sezione` |
| `faq_apertura` | apertura di una domanda frequente | `domanda` |
| `sezione_vista` | una sezione arriva a metà schermo (una volta per visita) | `sezione` |

`posizione` viene dall'attributo `data-posizione` dei link: `header`, `menu`, `hero`, `in_breve`, `relatori`,
`quota`, `faq`, `chiusura`, `footer`, `barra_mobile`. Per un nuovo link basta aggiungere l'attributo.

Configurazione della proprietà (Amministrazione):

- **Definizioni personalizzate** (fatto): dimensioni con ambito evento «Posizione link» (`posizione`),
  «Sezione» (`sezione`), «Domanda FAQ» (`domanda`) e «Nome file» (`file_name`).
- **Conservazione dei dati** (fatto): 14 mesi.
- **Eventi chiave** (da fare quando gli eventi compaiono, dopo il primo invio): in Eventi, stella accanto a
  `iscrizione_click` (obiettivo principale) e, se utile, `brochure_download` e `contatto_email_click`.

## Pubblicazione su Tophost

Il workflow `sftp.yml` costruisce il sito con l'indirizzo `SITE_URL` e lo carica con `lftp` nella cartella remota
a ogni push su `main`. Supporta `sftp`, `ftps` e `ftp`; con Tophost si usa `ftps`, perché il piano TopWeb non offre
SFTP né SSH.

Variabili e secret si impostano in GitHub: repo → Settings → Secrets and variables → Actions,
schede **Variables** e **Secrets**. I dati sensibili vanno sempre tra i **Secrets**. `SFTP_ENABLED` deve stare
tra le **Variables**; gli altri valori non sensibili funzionano in entrambe le schede (le variabili hanno la precedenza).

| Nome | Tipo | Obbligatorio | Valore |
|---|---|---|---|
| `SFTP_ENABLED` | variabile | sì | `true` per accendere il workflow |
| `SFTP_PROTOCOL` | variabile | no | `sftp` (predefinito), `ftps` (FTP cifrato con TLS) oppure `ftp`. Per Tophost: `ftps` |
| `SITE_URL` | variabile | sì | indirizzo pubblico del sito: `https://www.abitarelaparola.it` |
| `SFTP_HOST` | variabile | sì | server di upload. Per Tophost: `w-10.th.seeweb.it` (vedi sotto) |
| `SFTP_PORT` | variabile | no | predefinita `22` per sftp, `21` per ftp e ftps |
| `SFTP_USER` | variabile | sì | nome utente del server |
| `SFTP_REMOTE_DIR` | variabile | sì | cartella pubblica sul server, es. `/` o `public_html` |
| `SFTP_DELETE` | variabile | no | `true` per cancellare dal server i file non più presenti nel sito. Usalo solo se la cartella contiene esclusivamente questo sito |
| `FTP_VERIFY_CERT` | variabile | no | solo per ftps: `no` se il certificato TLS del server non corrisponde al nome host |
| `SFTP_PASSWORD` | secret | per ftp/ftps sì; per sftp in alternativa alla chiave | password del server |
| `SFTP_KEY` | secret | solo sftp, in alternativa alla password | chiave privata SSH dedicata al deploy |
| `SFTP_KNOWN_HOSTS` | secret | solo sftp, sì | impronta del server, da `ssh-keyscan -p 22 <host>` (verificala con il provider) |

Per lanciare un caricamento a mano e seguirlo:

```bash
gh workflow run sftp.yml --repo dadaops/abitarelaparola
gh run watch --repo dadaops/abitarelaparola
```

### Note su Tophost

- **Accesso FTP dall'estero**: deve essere abilitato nel pannello Tophost, perché i server di GitHub Actions sono
  negli Stati Uniti. Anche così Tophost rifiuta alcuni indirizzi di GitHub: se il workflow fallisce nel passo
  «Verifica che il server sia raggiungibile», rilancialo o usa lo zip da caricare a mano.
- **Nome del server**: il certificato FTPS di Tophost è intestato a `*.th.seeweb.it`, non a
  `ftp.abitarelaparola.it`. Per questo `SFTP_HOST` è il server a cui punta `ftp.abitarelaparola.it`
  (oggi `w-10.th.seeweb.it`; se Tophost lo cambia, si ricava con `dig +short ftp.abitarelaparola.it`).
- **Certificato intermedio**: il server FTPS non lo invia; il workflow lo scarica dall'indirizzo indicato nel
  certificato e lo usa solo se è firmato da un'autorità fidata.
- **HTTPS del sito**: si gestisce dal pannello Tophost. `SITE_URL` deve restare l'indirizzo finale che compare
  nella barra del browser (oggi con `www`).

## Zip da caricare a mano

A ogni push su `main` che modifica `site/`, il workflow `release-zip.yml` crea una release con lo zip del sito
già pronto (indirizzi inseriti, nessun segnaposto). Si può lanciare anche a mano, indicando l'indirizzo del sito:

```bash
gh workflow run release-zip.yml --repo dadaops/abitarelaparola -f site_url=https://www.abitarelaparola.it
gh release download --repo dadaops/abitarelaparola --pattern '*.zip'
```

Lo zip si trova anche nella pagina Releases del repo. Va estratto e il suo contenuto (`index.html`, `assets/`,
`robots.txt`, `sitemap.xml`) caricato con FileZilla nella cartella pubblica del server, sovrascrivendo i file.
L'indirizzo usato è, in ordine: quello indicato all'avvio, `SITE_URL`, `https://www.abitarelaparola.it`.
Vengono conservate le ultime 10 release del sito.

## GitHub Pages

GitHub Pages non ospita più il sito: con la variabile `PAGES_REDIRECT_TO=https://www.abitarelaparola.it`
il workflow `pages-redirect.yml` pubblica solo pagine che reindirizzano al sito definitivo, mantenendo il percorso
(`…/abitarelaparola/assets/anteprima.jpg` porta a `https://www.abitarelaparola.it/assets/anteprima.jpg`), e
`pages.yml` resta fermo. Serve a conservare SEO e link condivisi quando il sito era pubblicato su Pages.

Quando Search Console mostra che Google ha recepito il dominio definitivo, Pages si può spegnere del tutto:

```bash
gh variable set PAGES_ENABLED --repo dadaops/abitarelaparola --body false
gh api -X DELETE repos/dadaops/abitarelaparola/pages
```
