'use strict';

// Standalone design preview: invented records, no network or ERP writes.
const $ = id => document.getElementById(id);
const services = {reparatur: 'Reparatur', wartung: 'Wartung', reverse_engineering: 'Reverse Engineering', individualisierung: 'Individualisierung'};
const jobs = [
  {id: 'REP-2026-0108', customer: 'Beispielkunde A', customerNo: 'DEMO-1001', service: 'reparatur', manufacturer: 'Bambu Lab', model: 'X1C', serial: 'BEISPIEL-X1C-108', status: 'Repariert', tone: 'green', date: '19.10.2026', express: true, fee: 4900, issue: 'Fehlerhafte Kommunikation zum TH Board', limit: 'Vor jeder Reparatur Rücksprache', warranty: '', diagnosis: 'TH Board und Verkabelung geprüft.', notes: 'Kunde möchte vor Auftragserteilung telefonisch informiert werden.', scope: '- Fehlerbehebung TH Board\n- Wechsel Messer', price: 18900,
    publicDiagnosis: '- Sichtprüfung auf mechanische Beschädigungen und Verschleiß\n- Prüfung von Verkabelung, Steckverbindungen und Elektronik\n- Kontrolle von Hotend, Extruder und Filamentführung\n- Prüfung von Achsen, Führungen und Riemenspannung\n- Kontrolle von Firmware und Druckereinstellungen',
    finalCheck: '- Reinigung und Schmierung der Achsen und Führungen\n- Kalibrierung\n- Funktionstest aller Komponenten\n- Qualitätskontrolle mit Testdruck\n- Endreinigung des Geräts'},
  {id: 'REP-2026-0107', customer: 'Beispielfirma B', customerNo: 'DEMO-1002', service: 'wartung', manufacturer: 'Bambu Lab', model: 'P1S', serial: '', status: 'Wartung geplant', tone: 'blue', date: '18.10.2026', express: false, package: 'Vollwartung', packagePrice: 12900, issue: 'Bitte auf das Schleifgeräusch der Y-Achse achten. Der Drucker wird täglich genutzt.', diagnosis: '', notes: '', scope: 'Vollwartung mit Reinigung, Schmierung und Kontrolle der Mechanik. Riemenspannung prüfen und Testdruck durchführen.', price: 12900},
  {id: 'REP-2026-0106', customer: 'Beispielkunde C', customerNo: 'DEMO-1003', service: 'reparatur', manufacturer: 'Prusa', model: 'MK4', serial: 'BEISPIEL-MK4-106', status: 'Angebot gesendet', tone: 'amber', date: '17.10.2026', express: false, fee: 4900, issue: 'Die erste Schicht haftet nicht zuverlässig. Druckplatte wurde gereinigt. Bitte den Sensor und die Kalibrierung prüfen.', limit: 'Rücksprache bei Überschreitung des Kostenrahmens', warranty: 'Nein', diagnosis: 'Sensorprüfung und Kalibrierung erforderlich.', notes: '', scope: 'Sensor und Druckbett prüfen. Kalibrierung und Testdruck durchführen.', price: 9900},
  {id: 'REP-2026-0105', customer: 'Beispielkunde D', customerNo: 'DEMO-1004', service: 'reparatur', manufacturer: 'Creality', model: 'K1', serial: 'BEISPIEL-K1-105', status: 'Versendet', tone: 'neutral', date: '15.10.2026', express: false, fee: 3900, issue: 'Hotend heizt nicht mehr auf. Fehlermeldung erscheint beim Start.', limit: 'Vor jeder Reparatur Rücksprache', warranty: '', diagnosis: 'Heizpatrone defekt; nach Austausch erfolgreich geprüft.', notes: '', scope: 'Heizpatrone ersetzt, Verkabelung geprüft und Funktionstest durchgeführt.', price: 11900},
  {id: 'REP-2026-0104', customer: 'Beispielkunde E', customerNo: 'DEMO-1005', service: 'wartung', manufacturer: 'Prusa', model: 'MK3S+', serial: 'BEISPIEL-MK3S-104', status: 'Abgeschlossen', tone: 'neutral', complete: true, date: '12.10.2026', express: false, package: 'Basiswartung', packagePrice: 7900, issue: 'Regelmäßige Wartung nach längerer Nutzung.', diagnosis: 'Wartung abgeschlossen.', notes: '', scope: 'Basiswartung mit Reinigung, Schmierung und Funktionstest durchgeführt.', price: 7900},
  {id: 'REP-2026-0103', customer: 'Beispielfirma F', customerNo: 'DEMO-1006', service: 'reparatur', manufacturer: 'Bambu Lab', model: 'X1 Carbon', serial: 'BEISPIEL-X1C-103', status: 'Abgeschlossen', tone: 'neutral', complete: true, date: '10.10.2026', express: false, fee: null, issue: 'Unregelmäßige Extrusion bei längeren Druckaufträgen.', limit: 'Vor jeder Reparatur Rücksprache', warranty: '', diagnosis: 'Antrieb gereinigt und geprüft.', notes: '', scope: 'Filamentantrieb gereinigt und Testdruck durchgeführt.', price: 14900}
];
const defaults = {
  reparatur: 'Gerät: {hersteller} {modell}\nFehlerbild: {fehlerbeschreibung}\n\nEingangsdiagnose:\n{eingangsdiagnose}\n\nGeplante Reparatur- und Wartungsarbeiten:\n{reparaturarbeiten}\n\nVorgesehene Endkontrolle:\n{endkontrolle}',
  reparatur_rechnung: 'Gerät: {hersteller} {modell}\nFehlerbild: {fehlerbeschreibung}\n\nEingangsdiagnose:\n{eingangsdiagnose}\n\nDurchgeführte Reparatur- und Wartungsarbeiten:\n{reparaturarbeiten}\n\nEndkontrolle:\n{endkontrolle}',
  fee: 'Gerät: {hersteller} {modell}\nFehlerbild: {fehlerbeschreibung}\n\nEingangsdiagnose:\n{eingangsdiagnose}\n\nDiagnose und Erstellung des Kostenvoranschlags. Keine Reparatur durchgeführt. Abrechnung der im Formular vereinbarten KVA-Gebühr.',
  wartung: 'Wartung: {hersteller} {modell}\nWartungspaket: {wartungspaket}\n\nKundenhinweise: {kundenhinweise}\n\nLeistungsumfang: {leistungsbeschreibung}\n\nVorgang: {vorgangsnummer}',
  reverse_engineering: 'Reverse Engineering: {hersteller} {modell}\nAnforderung: {fehlerbeschreibung}\n\nLeistungsumfang: {leistungsbeschreibung}\n\nVorgang: {vorgangsnummer}',
  individualisierung: 'Individualisierung: {hersteller} {modell}\nAnforderung: {fehlerbeschreibung}\n\nLeistungsumfang: {leistungsbeschreibung}\n\nVorgang: {vorgangsnummer}'
};
const variableLabels = {
  hersteller: 'Hersteller', modell: 'Modell', seriennummer: 'Seriennummer', fehlerbeschreibung: 'Fehlerbeschreibung / Anforderung', eingangsdiagnose: 'Eingangsdiagnose für den Kunden', reparaturarbeiten: 'Reparatur- und Wartungsarbeiten', endkontrolle: 'Endkontrolle für den Kunden', kundenhinweise: 'Kundenhinweise aus Formular', wartungspaket: 'Wartungspaket', leistungsbeschreibung: 'Leistungsbeschreibung für Kunden', vorgangsnummer: 'Vorgangsnummer', kva_preis: 'KVA-Preis aus Formular', paketpreis: 'Paketpreis aus Formular', service: 'Service'
};
const settings = {articleNumber: '100003', articleName: '3D-Drucker Reparaturservice & Wartung (nach Aufwand)', templates: {...defaults}};
let templateDrafts = {...defaults};
let selectedTemplate = 'reparatur';
let currentId = jobs[0].id;
let currentMode = 'service';
let currentView = 'list';
let currentDocument = null;
let documentSequence = 0;
let toastTimer;
const drafts = new Map();
const documents = [];

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}
function icon(name) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  use.setAttribute('href', '#i-' + name);
  svg.setAttribute('aria-hidden', 'true');
  svg.append(use);
  return svg;
}
const money = cents => cents === null || cents === undefined ? 'Nicht übermittelt' : new Intl.NumberFormat('de-DE', {style: 'currency', currency: 'EUR'}).format(cents / 100);
const priceInput = cents => cents === null ? '' : (cents / 100).toFixed(2).replace('.', ',');
function parsePrice(value) {
  let text = value.trim().replace(/\s/g, '');
  if (text.includes(',')) {
    if (!/^(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}$/.test(text)) return null;
    text = text.replaceAll('.', '').replace(',', '.');
  }
  if (!/^\d+(?:\.\d{1,2})?$/.test(text)) return null;
  const [whole, fraction = ''] = text.split('.');
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
  return Number.isSafeInteger(cents) && cents <= 9999999999 ? cents : null;
}
function selectedJob() { return jobs.find(job => job.id === currentId); }
function draftKey(job, mode) { return job.id + ':' + mode; }
function activeTemplate(job, mode) { return mode === 'fee' ? 'fee' : job.service; }
function variables(job, scope, content = {}) {
  return {hersteller: job.manufacturer, modell: job.model, seriennummer: job.serial,
    fehlerbeschreibung: job.service === 'wartung' ? '' : job.issue,
    kundenhinweise: job.issue, wartungspaket: job.package || '', leistungsbeschreibung: scope,
    eingangsdiagnose: content.intake ?? job.publicDiagnosis ?? '', reparaturarbeiten: scope,
    endkontrolle: content.finalCheck ?? job.finalCheck ?? '',
    vorgangsnummer: job.id, kva_preis: job.fee == null ? '' : money(job.fee),
    paketpreis: job.packagePrice == null ? '' : money(job.packagePrice), service: services[job.service]};
}
function resolveTemplate(template, job, scope, content = {}) {
  const values = variables(job, scope, content);
  const missing = new Set();
  const unknown = new Set();
  const text = template.replace(/\{([^{}\r\n]*)\}/g, (match, name) => {
    if (!Object.hasOwn(values, name)) { unknown.add(name); return match; }
    const value = values[name];
    if (value === '' || value === null || value === undefined) {
      missing.add(variableLabels[name]);
      return '[nicht übermittelt]';
    }
    return String(value);
  });
  return {text, missing: [...missing], unknown: [...unknown]};
}
function getDraft(job, mode) {
  const key = draftKey(job, mode);
  if (!drafts.has(key)) {
    const draft = {scope: job.scope, intake: job.publicDiagnosis ?? '', finalCheck: job.finalCheck ?? '', price: mode === 'fee' ? job.fee ?? null : job.price, text: '', textDirty: false};
    draft.text = resolveTemplate(settings.templates[activeTemplate(job, mode)], job, draft.scope, draft).text;
    drafts.set(key, draft);
  }
  return drafts.get(key);
}
function toast(message) {
  clearTimeout(toastTimer);
  $('toast').textContent = message;
  $('toast').hidden = false;
  toastTimer = setTimeout(() => { $('toast').hidden = true; }, 4500);
}
function showView(view) {
  currentView = view;
  document.body.dataset.action = view === 'list' ? 'list' : view === 'settings' ? 'einstellungen' : 'edit';
  ['list', 'detail', 'settings', 'document'].forEach(name => { $('view-' + name).hidden = name !== view; });
  document.querySelectorAll('.tabs button').forEach(button => {
    const active = button.dataset.view === view || (view === 'document' && button.dataset.view === 'detail');
    button.classList.toggle('active', active);
    if (active) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current');
  });
  if (view === 'list') renderList();
  if (view === 'detail') renderDetail();
  if (view === 'settings') renderSettings();
  if (view === 'document') renderDocument();
}
function renderList() {
  const search = $('search').value.trim().toLocaleLowerCase('de');
  const includeCompleted = $('show-completed').checked;
  const matching = jobs.filter(job => (includeCompleted || !job.complete)
    && (!$('filter-service').value || job.service === $('filter-service').value)
    && (!$('filter-status').value || job.status === $('filter-status').value)
    && [job.id, job.customer, job.manufacturer, job.model, services[job.service], job.serial].join(' ').toLocaleLowerCase('de').includes(search));
  $('jobs').replaceChildren();
  for (const job of matching) {
    const row = element('tr', job.complete ? 'completed-row' : '');
    const reference = element('td');
    const link = element('button', 'job-button', job.id);
    link.type = 'button';
    link.addEventListener('click', () => openJob(job.id));
    reference.append(link, element('span', 'job-customer', job.customer));
    const device = element('td');
    device.append(element('strong', '', job.model), element('span', 'job-manufacturer', job.manufacturer));
    const service = element('td', '', services[job.service]);
    if (job.express) { service.append(document.createTextNode(' '), element('span', 'badge express', 'Express')); }
    const status = element('td');
    status.append(element('span', 'badge ' + job.tone, job.status));
    const action = element('td');
    const open = element('button', 'row-open');
    open.type = 'button';
    open.setAttribute('aria-label', job.id + ' öffnen');
    open.append(icon('arrow'));
    open.addEventListener('click', () => openJob(job.id));
    action.append(open);
    row.append(reference, device, service, status, element('td', '', job.date), action);
    $('jobs').append(row);
  }
  $('list-empty').hidden = matching.length > 0;
  $('list-count').textContent = matching.length + ' von ' + jobs.length + ' Vorgängen' + (includeCompleted ? ' · inklusive abgeschlossener Vorgänge' : ' · 2 abgeschlossene Vorgänge ausgeblendet');
}
function openJob(id) {
  currentId = id;
  currentMode = 'service';
  showView('detail');
  window.scrollTo({top: 0});
}
function addFact(label, value) {
  if (!value) return;
  const pair = element('tr');
  pair.append(element('td', '', label + ':'), element('td', '', value));
  $('intake-facts').append(pair);
}
function priceLine(label, value) {
  const line = element('div', 'form-price-line');
  line.append(element('span', '', label), element('strong', '', money(value)));
  return line;
}
function renderArticle() {
  document.querySelectorAll('.article-name').forEach(node => { node.textContent = settings.articleName; });
  document.querySelectorAll('.article-number').forEach(node => { node.textContent = settings.articleNumber; });
}
function renderDetail() {
  const job = selectedJob();
  $('detail-title').textContent = job.manufacturer + ' ' + job.model;
  $('detail-subtitle').textContent = job.id + ' · ' + job.customer + ' · Eingang ' + job.date;
  $('detail-badges').replaceChildren(element('span', 'badge ' + job.tone, job.status));
  if (job.express) $('detail-badges').append(element('span', 'badge express', 'Express'));
  $('intake-facts').replaceChildren();
  addFact('Service', services[job.service]);
  addFact('Hersteller', job.manufacturer);
  addFact('Modell', job.model);
  addFact('Seriennummer', job.serial);
  if (job.service === 'reparatur') {
    addFact('Kostenfreigabe', job.limit);
    addFact('Garantie laut Formular', job.warranty);
  }
  if (job.service === 'wartung') addFact('Wartungspaket', job.package);
  addFact('Express', job.express ? 'Ja' : 'Nein');
  $('form-price').replaceChildren();
  if (job.service === 'reparatur') {
    $('form-price').append(priceLine('KVA-Preis laut Formular', job.fee));
    $('form-price').append(element('p', 'help', job.fee == null ? 'Kein ursprünglicher Preis übermittelt. Nicht aus heutigen Preisen ergänzen.' : 'Ursprünglicher Formularpreis. Wird bei Reparatur verrechnet und nicht zusätzlich berechnet.'));
  } else if (job.service === 'wartung') {
    $('form-price').append(priceLine('Paketpreis laut Formular', job.packagePrice));
    $('form-price').append(element('p', 'help', 'Ursprünglicher Preis zum Zeitpunkt der Anfrage.'));
  }
  if (job.express) $('form-price').append(element('p', 'help', 'Express beauftragt · Zuschlag in diesem Beispiel nicht übermittelt.'));
  $('customer-description-label').textContent = job.service === 'wartung' ? 'Zusätzliche Anmerkungen des Kunden' : 'Fehlerbeschreibung des Kunden';
  $('customer-description').textContent = job.issue || 'Nicht übermittelt';
  $('diagnosis').value = job.diagnosis;
  $('internal-notes').value = job.notes;
  $('customer-account').textContent = job.customer + ' (' + job.customerNo + ')';
  const serviceOption = $('billing-mode').options[0];
  serviceOption.textContent = job.service === 'reparatur' ? 'Reparatur durchführen' : services[job.service] + ' durchführen';
  $('billing-mode').options[1].hidden = job.service !== 'reparatur';
  $('billing-mode').options[1].disabled = job.service !== 'reparatur';
  if (job.service !== 'reparatur') currentMode = 'service';
  $('billing-mode').value = currentMode;
  renderArticle();
  renderPreparation();
  renderLinkedDocuments();
}
function renderPreparation() {
  const job = selectedJob();
  const feeOnly = currentMode === 'fee';
  const draft = getDraft(job, currentMode);
  $('mode-hint').textContent = feeOnly ? 'Nur Diagnose und KVA-Erstellung abrechnen. Derselbe Artikel, angepasster Text; keine Reparaturleistung.' : job.service === 'reparatur' ? 'Der KVA wird als Angebot angelegt. Der Gesamtpreis enthält die verrechnete KVA-Gebühr.' : 'Leistung als Angebot vorbereiten und anschließend in Auftrag und Rechnung übernehmen.';
  $('scope-label').hidden = feeOnly;
  $('final-check-row').hidden = feeOnly;
  $('public-diagnosis').value = draft.intake;
  $('public-final-check').value = draft.finalCheck;
  $('service-scope').value = draft.scope;
  $('position-price').value = priceInput(draft.price);
  $('position-text').value = draft.text;
  $('use-form-price').hidden = !feeOnly;
  $('use-form-price').disabled = job.fee == null;
  $('price-hint').textContent = feeOnly ? job.fee == null ? 'Kein Formular-KVA-Preis vorhanden. Betrag manuell prüfen und eintragen.' : 'KVA-Preis aus dem Formular: ' + money(job.fee) + '. Übernehmen oder bewusst anpassen.' : job.service === 'wartung' ? 'Paketpreis als Vorschlag übernommen; vor dem Angebot anpassen.' : 'Gesamtpreis der angebotenen Leistung. Kein zusätzlicher KVA-Kostenpunkt.';
  $('price-error').hidden = true;
  updatePreparationState();
}
function updatePreparationState() {
  const job = selectedJob();
  const draft = getDraft(job, currentMode);
  $('position-paper-text').textContent = draft.text;
  const remaining = [...draft.text.matchAll(/\{([^{}\r\n]*)\}/g)].map(match => match[0]);
  const missing = draft.text.includes('[nicht übermittelt]');
  $('template-warning').hidden = !remaining.length && !missing;
  $('template-warning').textContent = remaining.length ? 'Nicht ausgefüllte Platzhalter: ' + [...new Set(remaining)].join(', ') + '. Bitte vor der Übernahme ersetzen.' : 'Die Beschreibung enthält fehlende Angaben. Bitte ergänzen oder die betreffende Zeile entfernen.';
  const existing = documents.find(doc => doc.jobId === job.id && doc.mode === currentMode && doc.type === (currentMode === 'fee' ? 'rechnung' : 'angebot'));
  $('create-document').disabled = !existing && (draft.price === null || !draft.text.trim() || remaining.length > 0 || missing);
  $('create-document').replaceChildren(document.createTextNode(existing ? 'Entwurf öffnen' : currentMode === 'fee' ? 'Rechnung vorbereiten' : 'Angebot vorbereiten'), icon('arrow'));
  $('create-hint').textContent = existing ? 'Vorhandenen Beispielentwurf öffnen.' : 'Eine Position · Menge 1 · Text und Preis im Beleg bearbeitbar';
}
function renderLinkedDocuments() {
  const linked = documents.filter(doc => doc.jobId === currentId);
  $('linked-documents').replaceChildren();
  if (!linked.length) { $('linked-documents').append(element('p', 'linked-empty', 'Noch keine Belegentwürfe in dieser Vorschau.')); return; }
  for (const doc of linked) {
    const row = element('div', 'linked-row');
    const label = element('div', 'linked-name');
    label.append(element('strong', '', documentLabel(doc.type) + ' · ' + doc.id), element('small', '', doc.mode === 'fee' ? 'Nur KVA-Abrechnung · Beispielentwurf' : 'Reparatur / Service · Beispielentwurf'));
    const open = element('button', 'button button-secondary', 'Öffnen');
    open.type = 'button';
    open.addEventListener('click', () => { currentDocument = doc; showView('document'); });
    row.append(icon('file'), label, element('span', 'linked-price', money(doc.price)), open);
    $('linked-documents').append(row);
  }
}
function documentLabel(type) { return {angebot: 'Angebot', auftrag: 'Auftrag', rechnung: 'Rechnung'}[type]; }
function newDocument(type, source) {
  const job = selectedJob();
  const draft = getDraft(job, currentMode);
  const doc = {id: 'VORSCHAU-' + {angebot: 'ANG', auftrag: 'AUF', rechnung: 'RE'}[type] + '-' + String(++documentSequence).padStart(2, '0'), jobId: job.id,
    mode: source ? source.mode : currentMode, type, name: source ? source.name : settings.articleName,
    article: source ? source.article : settings.articleNumber, text: source ? source.text : draft.text,
    price: source ? source.price : draft.price, parent: source ? source.id : null};
  documents.push(doc);
  return doc;
}
function renderDocument() {
  if (!currentDocument) { showView('detail'); return; }
  const doc = currentDocument;
  const job = jobs.find(item => item.id === doc.jobId);
  $('document-title').textContent = documentLabel(doc.type) + ' · ' + doc.id;
  $('document-subtitle').textContent = doc.parent ? 'Übernommen aus ' + doc.parent : 'Vorbereitet aus ' + job.id;
  $('document-customer').textContent = job.customer;
  $('document-reference').textContent = job.id;
  $('document-type-title').textContent = doc.type === 'angebot' ? 'Kostenvoranschlag / Angebot' : documentLabel(doc.type);
  $('document-position-name').value = doc.name;
  $('document-position-description').value = doc.text;
  $('document-price').value = priceInput(doc.price);
  $('document-article-number').textContent = doc.article;
  refreshDocumentPaper();
  $('document-error').hidden = true;
  const canUseInvoiceTemplate = doc.type === 'rechnung' && doc.mode !== 'fee' && job.service === 'reparatur';
  $('use-invoice-template').hidden = !canUseInvoiceTemplate;
  $('invoice-template-hint').hidden = !canUseInvoiceTemplate;
  const nextType = doc.type === 'angebot' ? 'auftrag' : 'rechnung';
  const existing = documents.find(item => item.parent === doc.id && item.type === nextType);
  $('continue-document').hidden = doc.type === 'rechnung';
  $('continue-document').textContent = existing ? documentLabel(nextType) + ' öffnen' : 'Als ' + documentLabel(nextType) + ' übernehmen';
  $('document-next-title').textContent = doc.type === 'rechnung' ? 'Rechnung vorbereitet' : 'Nächster Schritt';
  $('document-flow-hint').textContent = doc.type === 'rechnung' ? 'In der echten Anwendung wird der Beleg nach deiner Prüfung freigegeben. Diese Vorschau erstellt und versendet keine echten Rechnungen.' : doc.type === 'angebot' ? 'Nach der Kundenfreigabe wird das Angebot als Auftrag weitergeführt.' : 'Nach Abschluss der Arbeiten wird der Auftrag in eine Rechnung übernommen.';
  $('document-fee-hint').textContent = doc.mode === 'fee' ? 'Keine Reparatur: ausschließlich die vereinbarte KVA-Leistung. Kein zusätzlicher Reparaturbetrag.' : job.service === 'reparatur' ? 'Die KVA-Gebühr ist im vereinbarten Reparaturpreis berücksichtigt. Es wird kein zusätzlicher KVA-Kostenpunkt angelegt.' : 'Abgerechnet wird die angebotene Serviceleistung. Der übernommene Positionstext beschreibt den vereinbarten Leistungsumfang.';
  updateDocumentTotals();
}
function updateDocumentTotals() {
  const gross = currentDocument.price;
  const net = Math.round(gross / 1.19);
  $('document-net').textContent = money(net);
  $('document-vat').textContent = money(gross - net);
  $('document-total').textContent = money(gross);
}
function refreshDocumentPaper() {
  $('document-paper-number').textContent = currentDocument.article;
  $('document-paper-name').textContent = $('document-position-name').value;
  $('document-paper-text').textContent = $('document-position-description').value;
}
function validateDocument() {
  refreshDocumentPaper();
  const price = parsePrice($('document-price').value);
  const text = $('document-position-description').value.trim();
  const unresolved = /\{[^{}\r\n]*\}|\[nicht übermittelt\]/.test(text);
  const valid = price !== null && text !== '' && $('document-position-name').value.trim() !== '' && !unresolved;
  $('document-error').hidden = valid;
  $('document-error').textContent = 'Bitte einen gültigen Preis sowie Artikelbezeichnung und vollständige Beschreibung eintragen.';
  $('continue-document').disabled = !valid;
  if (valid) {
    currentDocument.price = price;
    currentDocument.name = $('document-position-name').value.trim();
    currentDocument.text = $('document-position-description').value;
    updateDocumentTotals();
  }
  return valid;
}
function renderSettings() {
  $('article-name').value = settings.articleName;
  $('article-number').value = settings.articleNumber;
  $('template-type').value = selectedTemplate;
  $('template-text').value = templateDrafts[selectedTemplate];
  $('preview-job').replaceChildren();
  for (const job of jobs) {
    const option = element('option', '', job.id + ' · ' + services[job.service] + ' · ' + job.model);
    option.value = job.id;
    $('preview-job').append(option);
  }
  choosePreviewJob();
  renderArticle();
  renderSettingsPreview();
}
function choosePreviewJob() {
  const desired = selectedTemplate === 'fee' || selectedTemplate === 'reparatur_rechnung' ? 'reparatur' : selectedTemplate;
  const example = jobs.find(job => job.service === desired);
  $('preview-job').value = (example || selectedJob()).id;
}
function renderSettingsPreview() {
  const job = jobs.find(item => item.id === $('preview-job').value) || selectedJob();
  const draft = getDraft(job, 'service');
  const resolved = resolveTemplate($('template-text').value, job, draft.scope, draft);
  $('settings-preview').textContent = resolved.text;
  $('view-settings').querySelector('.product-paper .article-name').textContent = $('article-name').value || 'Artikelbezeichnung fehlt';
  $('settings-paper-number').textContent = $('article-number').value;
  $('preview-missing').hidden = !resolved.missing.length;
  $('preview-missing').textContent = 'In diesem Beispiel nicht vorhanden: ' + resolved.missing.join(', ') + '. Vor der Belegerstellung ergänzen oder aus dem Text entfernen.';
  $('settings-validation').hidden = !resolved.unknown.length;
  $('settings-validation').textContent = 'Unbekannte Platzhalter: ' + resolved.unknown.map(name => '{' + name + '}').join(', ') + '. Bitte einen verfügbaren Platzhalter wählen.';
  $('apply-settings').disabled = resolved.unknown.length > 0 || !$('template-text').value.trim() || !$('article-name').value.trim() || !$('article-number').value.trim();
}

document.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => {
  if (currentView === 'document' && !validateDocument()) return;
  showView(button.dataset.view);
}));
for (const status of [...new Set(jobs.map(job => job.status))]) {
  const option = element('option', '', status); option.value = status; $('filter-status').append(option);
}
['search', 'filter-service', 'filter-status', 'show-completed'].forEach(id => $(id).addEventListener(id === 'search' ? 'input' : 'change', renderList));
$('reset-filters').addEventListener('click', () => {
  $('search').value = ''; $('filter-service').value = ''; $('filter-status').value = ''; $('show-completed').checked = false; renderList();
});
$('diagnosis').addEventListener('input', () => { selectedJob().diagnosis = $('diagnosis').value; });
$('internal-notes').addEventListener('input', () => { selectedJob().notes = $('internal-notes').value; });
$('billing-mode').addEventListener('change', () => { currentMode = $('billing-mode').value; renderPreparation(); });
for (const [id, field, jobField] of [['service-scope', 'scope', 'scope'], ['public-diagnosis', 'intake', 'publicDiagnosis'], ['public-final-check', 'finalCheck', 'finalCheck']]) {
  $(id).addEventListener('input', () => {
    const job = selectedJob();
    job[jobField] = $(id).value;
    // Leistungsdaten gehoeren zum Vorgang, auch beim Wechsel der Abrechnung.
    for (const mode of ['service', 'fee']) {
      const draft = drafts.get(draftKey(job, mode));
      if (!draft) continue;
      draft[field] = $(id).value;
      if (!draft.textDirty) {
        draft.text = resolveTemplate(settings.templates[activeTemplate(job, mode)], job, draft.scope, draft).text;
      }
    }
    $('position-text').value = getDraft(job, currentMode).text;
    updatePreparationState();
  });
}
$('position-text').addEventListener('input', () => {
  const draft = getDraft(selectedJob(), currentMode); draft.text = $('position-text').value; draft.textDirty = true; updatePreparationState();
});
$('position-price').addEventListener('input', () => {
  const draft = getDraft(selectedJob(), currentMode); draft.price = parsePrice($('position-price').value);
  $('price-error').hidden = draft.price !== null || $('position-price').value === '';
  $('price-error').textContent = 'Bitte einen positiven Betrag oder 0,00 mit höchstens zwei Nachkommastellen eingeben, z. B. 149,90.';
  updatePreparationState();
});
$('use-form-price').addEventListener('click', () => {
  const job = selectedJob(); if (job.fee == null || currentMode !== 'fee') return;
  const draft = getDraft(job, currentMode); draft.price = job.fee; $('position-price').value = priceInput(job.fee); $('price-error').hidden = true; updatePreparationState(); toast('Ursprünglichen KVA-Preis aus dem Formular übernommen.');
});
$('regenerate-text').addEventListener('click', () => {
  const job = selectedJob(); const draft = getDraft(job, currentMode);
  draft.text = resolveTemplate(settings.templates[activeTemplate(job, currentMode)], job, draft.scope, draft).text;
  draft.textDirty = false; $('position-text').value = draft.text; updatePreparationState(); toast('Positionsbeschreibung aus der aktuellen Vorlage eingesetzt.');
});
$('create-document').addEventListener('click', () => {
  if ($('create-document').disabled) return;
  const type = currentMode === 'fee' ? 'rechnung' : 'angebot';
  currentDocument = documents.find(doc => doc.jobId === currentId && doc.mode === currentMode && doc.type === type) || newDocument(type);
  showView('document'); window.scrollTo({top: 0});
});
['document-price', 'document-position-name', 'document-position-description'].forEach(id => $(id).addEventListener('input', validateDocument));
$('continue-document').addEventListener('click', () => {
  if (!validateDocument() || currentDocument.type === 'rechnung') return;
  const type = currentDocument.type === 'angebot' ? 'auftrag' : 'rechnung';
  currentDocument = documents.find(doc => doc.parent === currentDocument.id && doc.type === type) || newDocument(type, currentDocument);
  renderDocument(); toast('Beispielentwurf geöffnet. Text und Preis wurden übernommen.');
});
$('use-invoice-template').addEventListener('click', () => {
  if (!currentDocument || currentDocument.type !== 'rechnung' || currentDocument.mode === 'fee') return;
  const job = jobs.find(item => item.id === currentDocument.jobId);
  const draft = getDraft(job, 'service');
  currentDocument.text = resolveTemplate(settings.templates.reparatur_rechnung, job, draft.scope, draft).text;
  renderDocument();
  validateDocument();
  toast('Rechnungstext aus den Leistungsdaten eingesetzt. Durchgeführte Arbeiten und Endkontrolle prüfen.');
});
for (const [name, label] of Object.entries(variableLabels)) {
  const button = element('button', '', '{' + name + '}'); button.type = 'button'; button.title = label;
  button.addEventListener('click', () => {
    const input = $('template-text'); const start = input.selectionStart; const end = input.selectionEnd;
    input.setRangeText('{' + name + '}', start, end, 'end'); templateDrafts[selectedTemplate] = input.value; input.focus(); renderSettingsPreview();
  });
  $('variable-buttons').append(button);
}
$('template-type').addEventListener('change', () => {
  templateDrafts[selectedTemplate] = $('template-text').value;
  selectedTemplate = $('template-type').value;
  $('template-text').value = templateDrafts[selectedTemplate]; choosePreviewJob(); renderSettingsPreview();
});
$('template-text').addEventListener('input', () => { templateDrafts[selectedTemplate] = $('template-text').value; renderSettingsPreview(); });
$('preview-job').addEventListener('change', renderSettingsPreview);
['article-name', 'article-number'].forEach(id => $(id).addEventListener('input', renderSettingsPreview));
$('reset-template').addEventListener('click', () => {
  templateDrafts[selectedTemplate] = defaults[selectedTemplate]; $('template-text').value = defaults[selectedTemplate]; renderSettingsPreview();
});
$('apply-settings').addEventListener('click', () => {
  if ($('apply-settings').disabled) return;
  settings.articleName = $('article-name').value.trim(); settings.articleNumber = $('article-number').value.trim();
  // Apply only the selected template, so an invalid draft in another tab cannot leak.
  settings.templates[selectedTemplate] = $('template-text').value;
  for (const job of jobs) for (const mode of ['service', 'fee']) {
    const draft = drafts.get(draftKey(job, mode));
    if (draft && !draft.textDirty && activeTemplate(job, mode) === selectedTemplate) {
      draft.text = resolveTemplate(settings.templates[selectedTemplate], job, draft.scope, draft).text;
    }
  }
  renderArticle(); toast('Artikelzuordnung und ausgewählte Vorlage in der Vorschau angewendet. Bestehende Belege bleiben unverändert.');
});
renderArticle();
showView('list');
openJob(jobs[0].id);
