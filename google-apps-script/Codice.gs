/**
 * Abitare la parola – iscrizioni nel foglio Google, email di conferma e promemoria.
 *
 * Installazione (una volta sola, istruzioni complete nel README). Conviene usare l'account
 * abitarelaparola@gmail.com: le email ai partecipanti partono da lì.
 * 1. Crea un foglio Google, apri Estensioni → Apps Script e incolla questo file al posto del codice esistente.
 * 2. Esegui `configura` e autorizza: crea il foglio «Iscrizioni», la cartella Drive per le ricevute,
 *    il promemoria giornaliero e un token segreto, che trovi nel registro di esecuzione.
 * 3. Esegui il deployment come «App web», esegui come «Me», accesso «Chiunque».
 * 4. Salva URL dell'app web e token nei secret del repo GitHub (GOOGLE_SCRIPT_URL, GOOGLE_SCRIPT_TOKEN).
 *
 * Ogni iscrizione ha un codice (ALP-…) e una sola riga, aggiornata a ogni passo:
 *   contatto → in_attesa_pagamento → ricevuta_inviata → confermato (lo scrivete voi dopo aver verificato il pagamento).
 * Uno stato scritto a mano (confermato, annullato…) non viene mai sovrascritto dal modulo.
 */

const NOME_FOGLIO = 'Iscrizioni';
const NOME_CARTELLA = 'Abitare la parola – ricevute di pagamento';
const SCADENZA = new Date('2026-10-28T23:59:59+01:00');
const SCADENZA_TESTO = 'mercoledì 28 ottobre';
const NOME_MITTENTE = 'Abitare la parola';
const EMAIL_RISPOSTE = 'abitarelaparola@gmail.com';
const ORE_PRIMA_DEL_PROMEMORIA = 20;

const COLONNE = [
  ['id', 'Codice'],
  ['stato', 'Stato'],
  ['aggiornato', 'Ultimo aggiornamento'],
  ['nome', 'Nome'],
  ['cognome', 'Cognome'],
  ['email', 'Email'],
  ['telefono', 'Telefono'],
  ['pagamento', 'Pagamento'],
  ['importo', 'Importo (€)'],
  ['ricevuta_link', 'Ricevuta'],
  ['questionario', 'Domande integrative'],
  ['fascia', 'Fascia Palazzo Butera'],
  ['professione', 'Professione'],
  ['professione_altro', 'Professione (altro)'],
  ['anni', 'Anni di professione'],
  ['ambito', 'Ambito di studi'],
  ['ambito_altro', 'Ambito (altro)'],
  ['istituto', 'Istituto'],
  ['intolleranze', 'Intolleranze/allergie'],
  ['accompagnatore', 'Si iscrive con'],
  ['note', 'Note'],
  ['consenso', 'Consenso privacy'],
  ['link_personale', 'Link personale'],
  ['email_inviate', 'Email inviate'],
  ['utm_source', 'Provenienza (utm_source)'],
  ['utm_medium', 'Mezzo (utm_medium)'],
  ['utm_campaign', 'Campagna (utm_campaign)'],
  ['referrer', 'Pagina di provenienza'],
  ['primo_contatto', 'Primo contatto'],
];
const STATI_MODULO = { contatto: 1, in_attesa_pagamento: 2, ricevuta_inviata: 3 };

function configura() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const foglio = ss.getSheetByName(NOME_FOGLIO) || ss.insertSheet(NOME_FOGLIO, 0);
  foglio.getRange(1, 1, 1, COLONNE.length).setValues([COLONNE.map(c => c[1])])
    .setFontWeight('bold').setBackground('#126644').setFontColor('#ffffff');
  foglio.setFrozenRows(1);
  foglio.setFrozenColumns(5);
  const stato = foglio.getRange(2, 2, foglio.getMaxRows() - 1, 1);
  foglio.setConditionalFormatRules([
    regolaColore_(stato, 'confermato', '#D5E3DA'),
    regolaColore_(stato, 'ricevuta_inviata', '#FFF4CC'),
    regolaColore_(stato, 'in_attesa_pagamento', '#FCE4EC'),
  ]);

  const props = PropertiesService.getScriptProperties();
  if (!props.getProperty('TOKEN')) {
    props.setProperty('TOKEN', Utilities.getUuid().replace(/-/g, '') + Utilities.getUuid().replace(/-/g, ''));
  }
  cartellaRicevute_();
  installaPromemoria_();
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
  if (!token || dati.token !== token) return json_({ ok: false, errore: 'non autorizzato' });
  if (!/^ALP-[0-9A-F]{16}$/.test(String(dati.id || ''))) return json_({ ok: false, errore: 'codice non valido' });

  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  let riga;
  try {
    if (dati.file && dati.file.base64) {
      const blob = Utilities.newBlob(Utilities.base64Decode(dati.file.base64), dati.file.tipo, dati.file.nome);
      const file = cartellaRicevute_().createFile(blob);
      file.setDescription('Iscrizione ' + dati.id + ' – ' + dati.nome + ' ' + dati.cognome);
      dati.ricevuta_link = file.getUrl();
    }
    riga = scriviRiga_(dati);
  } finally {
    lock.releaseLock();
  }
  try {
    inviaEmailDelPasso_(riga, dati.evento);
  } catch (err) {
    console.error('Email non inviata per ' + dati.id + ': ' + err);
  }
  return json_({ ok: true, id: dati.id });
}

// Aggiorna (o crea) la riga dell'iscrizione: i campi vuoti non cancellano quelli già presenti.
function scriviRiga_(dati) {
  const foglio = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(NOME_FOGLIO);
  const n = trovaRiga_(foglio, dati.id);
  const esistente = n ? foglio.getRange(n, 1, 1, COLONNE.length).getValues()[0] : COLONNE.map(() => '');
  const valori = COLONNE.map(function (c, i) {
    const nuovo = dati[c[0]];
    if (c[0] === 'stato') {
      const attuale = String(esistente[i] || '');
      if (attuale && !(attuale in STATI_MODULO)) return attuale; // stato scritto a mano
      return (STATI_MODULO[nuovo] || 0) >= (STATI_MODULO[attuale] || 0) ? nuovo : attuale;
    }
    if (c[0] === 'email_inviate') return esistente[i];
    if (c[0] === 'primo_contatto') return esistente[i] || dati.primo_contatto || dati.aggiornato;
    return nuovo === undefined || nuovo === null || nuovo === '' ? esistente[i] : pulisci_(nuovo);
  });
  if (n) {
    foglio.getRange(n, 1, 1, COLONNE.length).setValues([valori]);
    return { foglio: foglio, numero: n, valori: valori };
  }
  foglio.appendRow(valori);
  return { foglio: foglio, numero: foglio.getLastRow(), valori: valori };
}

// --- Email -----------------------------------------------------------------------------------

function inviaEmailDelPasso_(riga, evento) {
  const r = oggetto_(riga.valori);
  if (evento === 'pagamento' && r.stato === 'in_attesa_pagamento') {
    inviaUnaVolta_(riga, 'prenotazione', 'Il tuo posto è prenotato – completa il pagamento', [
      'Ciao ' + r.nome + ',',
      'il tuo posto ad <b>Abitare la parola</b> (Palermo, 7–8 novembre 2026) è prenotato.',
      'Per confermarlo, completa il pagamento e carica la ricevuta entro <b>' + SCADENZA_TESTO + '</b>.',
      istruzioniPagamento_(r),
      bottone_(r.link_personale, 'Carica la ricevuta'),
    ]);
  }
  if (evento === 'pagamento' && r.stato === 'ricevuta_inviata' && r.questionario !== 'si') {
    inviaUnaVolta_(riga, 'ricevuta', 'Ricevuta arrivata – ultimo passo per la tua iscrizione', [
      'Ciao ' + r.nome + ',',
      'abbiamo ricevuto la tua ricevuta: la verifichiamo e ti confermiamo il posto.',
      'Se non l’hai già fatto, rispondi a sei domande veloci: ci servono per organizzare laboratori, pranzo e turni a Palazzo Butera.',
      bottone_(r.link_personale, 'Rispondi alle domande'),
    ]);
  }
}

// Promemoria giornaliero: a chi ha prenotato ma non ha ancora caricato la ricevuta (una sola volta).
function promemoria() {
  if (new Date() > SCADENZA) return;
  const foglio = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(NOME_FOGLIO);
  const ultima = foglio.getLastRow();
  if (ultima < 2) return;
  const righe = foglio.getRange(2, 1, ultima - 1, COLONNE.length).getValues();
  const limite = Date.now() - ORE_PRIMA_DEL_PROMEMORIA * 3600 * 1000;
  righe.forEach(function (valori, i) {
    const r = oggetto_(valori);
    if (r.stato !== 'contatto' && r.stato !== 'in_attesa_pagamento') return;
    const primo = r.primo_contatto instanceof Date ? r.primo_contatto : new Date(String(r.primo_contatto).replace(' ', 'T') + '+01:00');
    if (isNaN(primo) || primo.getTime() > limite) return;
    inviaUnaVolta_({ foglio: foglio, numero: i + 2, valori: valori }, 'promemoria', 'Il tuo posto ad Abitare la parola ti aspetta', [
      'Ciao ' + r.nome + ',',
      'hai iniziato l’iscrizione ad <b>Abitare la parola</b> (Palermo, 7–8 novembre 2026) ma non abbiamo ancora ricevuto il pagamento.',
      'Le iscrizioni chiudono <b>' + SCADENZA_TESTO + '</b> e i posti sono limitati.',
      r.pagamento ? istruzioniPagamento_(r) : 'Puoi pagare con bonifico (100 €) oppure con Carta del Docente o Carta Cultura (122 €).',
      bottone_(r.link_personale, r.pagamento ? 'Carica la ricevuta' : 'Completa l’iscrizione'),
    ]);
  });
}

function inviaUnaVolta_(riga, tipo, oggettoEmail, paragrafi) {
  const r = oggetto_(riga.valori);
  const inviate = String(r.email_inviate || '');
  if (!r.email || inviate.split(', ').some(v => v.indexOf(tipo + ' ') === 0)) return;
  const html = '<div style="font-family:Arial,sans-serif;font-size:16px;line-height:1.55;color:#232323;max-width:560px">' +
    paragrafi.map(p => p.indexOf('<') === 0 ? p : '<p>' + p + '</p>').join('') +
    '<p style="color:#3F4A44;font-size:14px">Per qualsiasi domanda rispondi a questa email.<br>' +
    'Abitare la parola · Centro di Poesia Contemporanea Catania</p></div>';
  MailApp.sendEmail({ to: r.email, subject: oggettoEmail, htmlBody: html, name: NOME_MITTENTE, replyTo: EMAIL_RISPOSTE });
  const colonna = COLONNE.findIndex(c => c[0] === 'email_inviate') + 1;
  const timbro = tipo + ' ' + Utilities.formatDate(new Date(), 'Europe/Rome', 'dd/MM HH:mm');
  riga.foglio.getRange(riga.numero, colonna).setValue(inviate ? inviate + ', ' + timbro : timbro);
}

function istruzioniPagamento_(r) {
  if (r.pagamento === 'Bonifico') {
    return '<p style="background:#F2F7F4;padding:14px;border-radius:10px">Bonifico di <b>100 €</b> a <b>Centro di Poesia Contemporanea di Catania</b><br>' +
      'IBAN <b>IT94E0306967684510718988465</b><br>Causale: Iscrizione corso di formazione Abitare la parola – ' + r.nome + ' ' + r.cognome + '</p>';
  }
  return '<p style="background:#F2F7F4;padding:14px;border-radius:10px">Genera un buono da <b>122 €</b> sulla piattaforma ' +
    (r.pagamento === 'Carta cultura' ? 'Carta Cultura' : 'Carta del Docente') + ' con ambito <b>Formazione e aggiornamento</b> e bene ' +
    '<b>Corsi aggiornamento enti accreditati/qualificati ai sensi della direttiva 170/2016</b>.</p>';
}

function bottone_(link, testo) {
  return '<p><a href="' + link + '" style="display:inline-block;background:#D5075E;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 26px;border-radius:999px">' + testo + '</a></p>';
}

// --- Utilità ---------------------------------------------------------------------------------

function installaPromemoria_() {
  ScriptApp.getProjectTriggers().filter(t => t.getHandlerFunction() === 'promemoria').forEach(t => ScriptApp.deleteTrigger(t));
  ScriptApp.newTrigger('promemoria').timeBased().everyDays(1).atHour(10).inTimezone('Europe/Rome').create();
}

function oggetto_(valori) {
  const o = {};
  COLONNE.forEach((c, i) => { o[c[0]] = valori[i]; });
  return o;
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

function regolaColore_(intervallo, testo, colore) {
  return SpreadsheetApp.newConditionalFormatRule().whenTextEqualTo(testo).setBackground(colore).setRanges([intervallo]).build();
}

// Testo semplice: un valore che inizia con = + - @ non deve diventare una formula.
function pulisci_(v) {
  v = String(v);
  return /^[=+\-@]/.test(v) ? "'" + v : v;
}

function json_(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}
