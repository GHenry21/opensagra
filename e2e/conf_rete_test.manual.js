const playwright = require('playwright');

const HTTP = 'http://localhost';
const results = [];
function log(name, ok, detail) {
  results.push({ name, ok, detail });
  console.log(`[${ok ? 'OK' : 'FAIL'}] ${name} - ${detail}`);
}

(async () => {
  const browser = await playwright.chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1400, height: 900 } });
  const page = await context.newPage();
  page.on('console', (m) => { if (m.type() === 'error') console.log('[console error]', m.text()); });

  // 1. Pagina si carica, pillola sidebar visibile e "online" ovunque
  await page.goto(`${HTTP}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  const pillTextBilling = await page.locator('#pos-net-pill-text').innerText();
  log('Pillola rete visibile su billing.php', pillTextBilling.includes('127.0.0.1'), pillTextBilling);

  // 2. Vai alla pagina Rete
  await page.goto(`${HTTP}/pages/conf_rete.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  const statusText = await page.locator('#reteStatusText').innerText();
  log('Stato iniziale: indipendente online', statusText.includes('127.0.0.1'), statusText);
  const modeChecked = await page.locator('#modeIndipendente').isChecked();
  log('Radio "Indipendente" selezionato di default', modeChecked, `checked=${modeChecked}`);

  // 3. Prova a impostare un host non valido: NON deve scrivere il file
  await page.check('#modeClient');
  await page.fill('#serverHostInput', '10.0.0.250');
  page.once('dialog', (d) => d.dismiss());
  await page.click('#btnSaveRete');
  await page.waitForTimeout(1500);
  const toastError = await page.locator('.toast, [class*="toast"]').first().innerText().catch(() => '(nessun toast trovato)');
  log('Host non valido: toast di errore mostrato', /impossibile|errore/i.test(toastError), toastError);

  // Verifica che il file NON sia cambiato (deve restare 127.0.0.1)
  const statusAfterFail = await context.request.get(`${HTTP}/api/db_status.php`);
  const statusAfterFailJson = await statusAfterFail.json();
  log('variabili.env NON modificato dopo host non valido', statusAfterFailJson.host === '127.0.0.1', JSON.stringify(statusAfterFailJson));

  // 4. Passa a modalita' client puntando al proprio IP LAN (deve riuscire: stesso db, altro host)
  await page.fill('#serverHostInput', '192.168.88.224');
  await page.click('#btnSaveRete');
  await page.waitForTimeout(1500);
  const toastSuccess = await page.locator('.toast, [class*="toast"]').first().innerText().catch(() => '(nessun toast trovato)');
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
  await page.waitForTimeout(1500);
  const statusRestored = await context.request.get(`${HTTP}/api/db_status.php`);
  const statusRestoredJson = await statusRestored.json();
  log('Ripristinato a indipendente (127.0.0.1)', statusRestoredJson.host === '127.0.0.1' && statusRestoredJson.online, JSON.stringify(statusRestoredJson));

  await browser.close();

  console.log('\n=== RIEPILOGO ===');
  results.forEach((r) => console.log(`${r.ok ? '✅' : '❌'} ${r.name}`));
  const allOk = results.every((r) => r.ok);
  process.exit(allOk ? 0 : 1);
})().catch((err) => {
  console.error('ERRORE SCRIPT:', err);
  process.exit(1);
});
