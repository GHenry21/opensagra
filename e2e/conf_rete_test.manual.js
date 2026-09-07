const playwright = require('playwright');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const HTTP = 'http://localhost';
const results = [];
function log(name, ok, detail) {
  results.push({ name, ok, detail });
  console.log(`[${ok ? 'OK' : 'FAIL'}] ${name} - ${detail}`);
}

async function confirmDialog(page) {
  await page.waitForSelector('.confirm-dialog', { timeout: 3000 });
  await page.click('.confirm-dialog-btn--danger, .confirm-dialog-btn:last-child');
}

(async () => {
  const browser = await playwright.chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1400, height: 900 } });
  const page = await context.newPage();
  page.on('console', (m) => { if (m.type() === 'error') console.log('[console error]', m.text()); });

  // 1. Pagina si carica, pillola sidebar visibile (mostra l'IP reale, non 127.0.0.1)
  await page.goto(`${HTTP}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  const pillTextBilling = await page.locator('#pos-net-pill-text').innerText();
  log('Pillola rete visibile su billing.php (IP reale, non loopback)', /Rete: \d+\.\d+\.\d+\.\d+/.test(pillTextBilling) && !pillTextBilling.includes('127.0.0.1'), pillTextBilling);

  // 2. Vai alla pagina Rete
  await page.goto(`${HTTP}/pages/conf_rete.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  const statusText = await page.locator('#reteStatusText').innerText();
  log('Stato iniziale: indipendente online, IP reale mostrato', !statusText.includes('127.0.0.1') && /\d+\.\d+\.\d+\.\d+/.test(statusText), statusText);
  const modeChecked = await page.locator('#modeIndipendente').isChecked();
  log('Switch "Indipendente" selezionato di default', modeChecked, `checked=${modeChecked}`);

  // 3. Prova a impostare un host non valido: NON deve scrivere il file
  await page.check('#modeClient');
  await page.fill('#serverHostInput', '10.0.0.250');
  await page.click('#btnSaveRete');
  await confirmDialog(page);
  await page.waitForTimeout(1500);
  const toastError = await page.locator('[class*="toast"]').last().innerText().catch(() => '(nessun toast trovato)');
  log('Host non valido: toast di errore mostrato', /impossibile|errore/i.test(toastError), toastError);

  // Verifica che il file NON sia cambiato (deve restare 127.0.0.1)
  const statusAfterFail = await context.request.get(`${HTTP}/api/db_status.php`);
  const statusAfterFailJson = await statusAfterFail.json();
  log('variabili.env NON modificato dopo host non valido', statusAfterFailJson.host === '127.0.0.1', JSON.stringify(statusAfterFailJson));

  // 4. Passa a modalita' client puntando al proprio IP LAN (deve riuscire: stesso db, altro host)
  await page.fill('#serverHostInput', '192.168.88.224');
  await page.click('#btnSaveRete');
  await confirmDialog(page);
  await page.waitForTimeout(1500);
  const toastSuccess = await page.locator('[class*="toast"]').last().innerText().catch(() => '(nessun toast trovato)');
  log('Switch a IP LAN valido riuscito', /collegato|192\.168/i.test(toastSuccess), toastSuccess);

  const statusAfterSwitch = await context.request.get(`${HTTP}/api/db_status.php`);
  const statusAfterSwitchJson = await statusAfterSwitch.json();
  log('db_status.php riflette il nuovo host', statusAfterSwitchJson.host === '192.168.88.224' && statusAfterSwitchJson.online, JSON.stringify(statusAfterSwitchJson));

  // 5. L'app continua a funzionare con l'host cambiato (stesso DB, altro indirizzo)
  const productsResp = await context.request.get(`${HTTP}/api/get_products.php`);
  log('App ancora funzionante col nuovo host (get_products.php)', productsResp.ok(), `HTTP ${productsResp.status()}`);

  // 6. Torna a indipendente (ripristino stato produzione)
  await page.check('#modeIndipendente');
  await page.click('#btnSaveRete');
  await confirmDialog(page);
  await page.waitForTimeout(1500);
  const statusRestored = await context.request.get(`${HTTP}/api/db_status.php`);
  const statusRestoredJson = await statusRestored.json();
  log('Ripristinato a indipendente (127.0.0.1)', statusRestoredJson.host === '127.0.0.1' && statusRestoredJson.online, JSON.stringify(statusRestoredJson));

  // 7. Endpoint di base: forma corretta anche senza connessioni esterne attive
  const baseConn = await context.request.get(`${HTTP}/api/db_connections.php`);
  const baseConnJson = await baseConn.json();
  log('db_connections.php risponde nella forma attesa', typeof baseConnJson.external_count === 'number' && Array.isArray(baseConnJson.hosts), JSON.stringify(baseConnJson));

  // 8. Avviso rafforzato: con una connessione "esterna" reale attiva (aperta
  // qui via IP di LAN invece che loopback, cosi' MariaDB non la conta come
  // locale), il dialogo di conferma deve mostrarla per nome host risolto.
  const holdScript = `<?php $c = new mysqli('192.168.88.224','pos_own','pos_own1','opensagra_pos'); $c->query('SELECT SLEEP(5)');`;
  fs.writeFileSync(path.join(__dirname, '_hold_conn.php'), holdScript);
  const holder = spawn('C:/Users/enrig/.frankenphp/frankenphp.exe', ['php-cli', path.join(__dirname, '_hold_conn.php')]);
  await page.waitForTimeout(800); // lascia stabilire la connessione prima di controllare

  await page.click('#btnSaveRete');
  await page.waitForSelector('.confirm-dialog', { timeout: 3000 });
  const dialogText = await page.locator('.confirm-dialog').innerText();
  log('Dialogo mostra l\'avviso su connessione esterna reale', /1 altra.*postazion/i.test(dialogText) || /attenzione/i.test(dialogText), dialogText.replace(/\n/g, ' | '));
  await page.click('.confirm-dialog-btn--secondary');

  await new Promise((resolve) => holder.on('exit', resolve));
  fs.unlinkSync(path.join(__dirname, '_hold_conn.php'));

  // 9. Voce di navigazione spostata sopra "Gestione Database", bottone "Crea DB e Tabelle" rimosso
  const navOrder = await page.locator('.pos-sidebar__nav--gap .pos-sidebar__link span').allInnerTexts();
  const reteIdx = navOrder.indexOf('Configurazione Rete');
  const dbIdx = navOrder.indexOf('Gestione Database');
  log('Nav "Configurazione Rete" sopra "Gestione Database"', reteIdx !== -1 && dbIdx !== -1 && reteIdx < dbIdx, JSON.stringify(navOrder));
  const createDbBtnCount = await page.locator('#posCreateDbBtn').count();
  log('Bottone "Crea DB e Tabelle" rimosso dalla sidebar', createDbBtnCount === 0, `trovati: ${createDbBtnCount}`);

  await browser.close();

  console.log('\n=== RIEPILOGO ===');
  results.forEach((r) => console.log(`${r.ok ? '✅' : '❌'} ${r.name}`));
  const allOk = results.every((r) => r.ok);
  process.exit(allOk ? 0 : 1);
})().catch((err) => {
  console.error('ERRORE SCRIPT:', err);
  process.exit(1);
});
