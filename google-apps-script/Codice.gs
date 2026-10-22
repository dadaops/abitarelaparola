/**
 * Abitare la parola – ricezione delle iscrizioni nel foglio Google.
 *
 * Installazione (una volta sola, istruzioni complete nel README):
 * 1. Crea un foglio Google, apri Estensioni → Apps Script e incolla questo file al posto del codice esistente.
 * 2. Esegui la funzione `configura` e autorizza: crea il foglio «Iscrizioni», la cartella Drive per le
 *    ricevute e un token segreto, che trovi nel registro di esecuzione.
 * 3. Esegui il deployment come «App web», esegui come «Me», accesso «Chiunque».
 * 4. Salva URL dell'app web e token nei secret del repo GitHub (GOOGLE_SCRIPT_URL, GOOGLE_SCRIPT_TOKEN).
 *
 * Ogni iscrizione ha un codice (ALP-…): il contatto parziale e l'iscrizione completa aggiornano la stessa riga.
 */

const NOME_FOGLIO = 'Iscrizioni';
const NOME_CARTELLA = 'Abitare la parola – ricevute di pagamento';

const COLONNE = [
  ['id', 'Codice'],
  ['stato', 'Stato'],
  ['ricevuto_il', 'Ultimo aggiornamento'],
  ['nome', 'Nome'],
  ['cognome', 'Cognome'],
  ['email', 'Email'],
  ['telefono', 'Telefono'],
  ['consenso', 'Consenso privacy'],
  ['professione', 'Professione'],
  ['professione_altro', 'Professione (altro)'],
  ['anni', 'Anni di professione'],
  ['ambito', 'Ambito di studi'],
  ['ambito_altro', 'Ambito (altro)'],
  ['istituto', 'Istituto'],
  ['fascia', 'Fascia Palazzo Butera'],
  ['intolleranze', 'Intolleranze/allergie'],
  ['accompagnatore', 'Si iscrive con'],
  ['note', 'Note'],
  ['pagamento', 'Pagamento'],
  ['importo', 'Importo (€)'],
  ['ricevuta_quando', 'Ricevuta'],
  ['ricevuta_link', 'File ricevuta'],
  ['utm_source', 'Provenienza (utm_source)'],
  ['utm_medium', 'Mezzo (utm_medium)'],
  ['utm_campaign', 'Campagna (utm_campaign)'],
  ['referrer', 'Pagina di provenienza'],
  ['pagina', 'Pagina del modulo'],
  ['tempo_compilazione', 'Tempo di compilazione (s)'],
  ['primo_contatto', 'Primo contatto'],
];

function configura() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const foglio = ss.getSheetByName(NOME_FOGLIO) || ss.insertSheet(NOME_FOGLIO, 0);
  foglio.getRange(1, 1, 1, COLONNE.length).setValues([COLONNE.map(c => c[1])])
    .setFontWeight('bold').setBackground('#126644').setFontColor('#ffffff');
  foglio.setFrozenRows(1);
  foglio.setFrozenColumns(5);

  const props = PropertiesService.getScriptProperties();
  if (!props.getProperty('TOKEN')) {
    props.setProperty('TOKEN', Utilities.getUuid().replace(/-/g, '') + Utilities.getUuid().replace(/-/g, ''));
  }
  cartellaRicevute_();
  Logger.log('Token da salvare nel secret GOOGLE_SCRIPT_TOKEN: ' + props.getProperty('TOKEN'));
}

function doGet() {
  return json_({ ok: true, servizio: 'iscrizioni Abitare la parola' });
}

function doPost(e) {
  let dati;
  try {
    dati = JSON.parse(e.postData.contents);
  } catch (err) {
    return json_({ ok: false, errore: 'richiesta non valida' });
  }
  const token = PropertiesService.getScriptProperties().getProperty('TOKEN');
  if (!token || dati.token !== token) {
    return json_({ ok: false, errore: 'non autorizzato' });
  }
  if (!/^ALP-[0-9A-F]{16}$/.test(String(dati.id || ''))) {
    return json_({ ok: false, errore: 'codice non valido' });
  }

  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    if (dati.file && dati.file.base64) {
      const blob = Utilities.newBlob(Utilities.base64Decode(dati.file.base64), dati.file.tipo, dati.file.nome);
      const file = cartellaRicevute_().createFile(blob);
      file.setDescription('Iscrizione ' + dati.id + ' – ' + dati.nome + ' ' + dati.cognome);
      dati.ricevuta_link = file.getUrl();
    }

    const foglio = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(NOME_FOGLIO);
    const riga = trovaRiga_(foglio, dati.id);
    const esistente = riga ? foglio.getRange(riga, 1, 1, COLONNE.length).getValues()[0] : null;

    // Un contatto parziale non sovrascrive mai un'iscrizione già completa.
    if (esistente && esistente[1] === 'completa' && dati.stato !== 'completa') {
      return json_({ ok: true, id: dati.id, nota: 'già completa' });
    }

    const valori = COLONNE.map(function (c, i) {
      if (c[0] === 'primo_contatto') return esistente ? esistente[i] : dati.ricevuto_il;
      if (c[0] === 'ricevuta_link' && !dati.ricevuta_link && esistente) return esistente[i];
      return pulisci_(dati[c[0]]);
    });

    if (riga) {
      foglio.getRange(riga, 1, 1, COLONNE.length).setValues([valori]);
    } else {
      foglio.appendRow(valori);
    }
    return json_({ ok: true, id: dati.id });
  } finally {
    lock.releaseLock();
  }
}

function trovaRiga_(foglio, id) {
  const ultima = foglio.getLastRow();
  if (ultima < 2) return 0;
  const trovato = foglio.getRange(2, 1, ultima - 1, 1).createTextFinder(id).matchEntireCell(true).findNext();
  return trovato ? trovato.getRow() : 0;
}

function cartellaRicevute_() {
  const props = PropertiesService.getScriptProperties();
  const idCartella = props.getProperty('CARTELLA');
  if (idCartella) {
    try { return DriveApp.getFolderById(idCartella); } catch (err) {}
  }
  const cartella = DriveApp.createFolder(NOME_CARTELLA);
  props.setProperty('CARTELLA', cartella.getId());
  return cartella;
}

// Testo semplice: un valore che inizia con = + - @ non deve diventare una formula.
function pulisci_(v) {
  v = v === undefined || v === null ? '' : String(v);
  return /^[=+\-@]/.test(v) ? "'" + v : v;
}

function json_(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}
