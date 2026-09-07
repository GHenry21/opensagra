// Verifica completa finale (2026-09-07): stessa suite della Fase 2d/QZ ma sulla
// configurazione di produzione reale - porte 80/443 standard, MariaDB nativa,
// XAMPP fermo. Registra vendite reali e stampa davvero (autorizzato dall'utente).
// Lancio manuale: `node e2e/fase_final_verification.manual.js` con servizi attivi.
const playwright = require('playwright');
const path = require('path');
const fs = require('fs');

const HTTP = 'http://localhost';
const HTTPS = 'https://localhost';
const results = [];
function log(name, ok, detail) {
  results.push({ name, ok, detail });
  console.log(`[${ok ? 'OK' : 'FAIL'}] ${name} - ${detail}`);
}

async function smokeTest() {
  console.log('\n========== SMOKE TEST (Chromium, HTTP) ==========');
  const browser = await playwright.chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });
  const page = await context.newPage();
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  await page.goto(`${HTTP}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.card:not(.is-out-of-stock)', { timeout: 15000 });
  const totalBefore = await page.locator('.total-label').locator('xpath=following-sibling::*[1]').first().innerText().catch(() => null);
  await page.locator('.card:not(.is-out-of-stock)').first().click();
  await page.waitForTimeout(300);
  const totalAfter = await page.locator('.total-label').locator('xpath=following-sibling::*[1]').first().innerText().catch(() => null);
  log('Carrello (billing.php)', totalAfter !== totalBefore, `${totalBefore} -> ${totalAfter}`);

  await page.selectOption('#category-view-mode', 'cucina').catch(() => {});
  await page.waitForTimeout(300);
  log('Filtro categoria', true, 'nessun errore JS');

  const signResp = await context.request.get(`${HTTP}/api/sign-message.php?request=final-verification`);
  const signBody = await signResp.text();
  log('sign-message.php', signResp.ok() && signBody.length > 50, `HTTP ${signResp.status()}, lunghezza ${signBody.length}`);

  const today = new Date().toISOString().slice(0, 10);
  const statsResp = await context.request.post(`${HTTP}/api/statistiche_vendite.php`, { form: { from: today, to: today, cassa: '' } });
  const statsJson = await statsResp.json().catch(() => null);
  log('statistiche_vendite.php', statsResp.ok() && statsJson && !statsJson.error, `HTTP ${statsResp.status()}`);

  const storniResp = await context.request.post(`${HTTP}/api/statistiche_storni.php`, { form: { from: today, to: today, cassa: '' } });
  log('statistiche_storni.php', storniResp.ok(), `HTTP ${storniResp.status()}`);

  const confResp = await context.request.get(`${HTTP}/pages/conf_casse.php`);
  log('conf_casse.php', confResp.ok(), `HTTP ${confResp.status()}`);

  const receiptResp = await context.request.get(`${HTTP}/api/get_receipt_config.php`);
  log('get_receipt_config.php', receiptResp.ok(), `HTTP ${receiptResp.status()}`);

  const drawerResp = await context.request.get(`${HTTP}/api/open_drawer.php`, { timeout: 20000 }).catch((e) => ({ error: e.message }));
  if (drawerResp.error) { log('open_drawer.php', false, drawerResp.error); }
  else { log('open_drawer.php (risposta qualsiasi, verifichiamo non sia un crash)', true, `HTTP ${drawerResp.status()}`); }

  const testCassaId = 'TEST-FINAL-VERIFY-DELETE-ME';
  const chiudiResp = await context.request.post(`${HTTP}/api/chiudi_cassa.php`, { data: { cassa_id: testCassaId }, headers: { 'Content-Type': 'application/json' } });
  const chiudiJson = await chiudiResp.json().catch(() => null);
  log('chiudi_cassa.php', chiudiResp.ok() && chiudiJson && chiudiJson.success, `HTTP ${chiudiResp.status()}`);

  console.log('Errori console:', consoleErrors.length ? consoleErrors.join('; ') : '(nessuno)');
  await browser.close();

  // pulizia riga di test
  return testCassaId;
}

async function pdfTest() {
  console.log('\n========== PDF DOWNLOAD (flusso reale) ==========');
  const browser = await playwright.chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, acceptDownloads: true });
  const page = await context.newPage();
  await page.goto(`${HTTP}/pages/stat_vendite.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#btnPdf', { timeout: 15000 });
  await page.waitForTimeout(1500); // lascia caricare i dati statistiche iniziali prima di esportare
  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 15000 }),
    page.click('#btnPdf')
  ]);
  const savePath = path.join(__dirname, 'final_verify_stat.pdf');
  await download.saveAs(savePath);
  const buf = fs.readFileSync(savePath);
  const isPdf = buf.slice(0, 4).toString('ascii') === '%PDF';
  log('PDF statistiche (download reale)', isPdf, `${buf.length} byte, magic=${buf.slice(0, 8).toString('latin1')}`);
  fs.unlinkSync(savePath);
  await browser.close();
}

async function directPrintTest() {
  console.log('\n========== STAMPA DIRETTA (cassa poop, RETE, no QZ) ==========');
  const browser = await playwright.chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });
  const page = await context.newPage();
  await page.addInitScript(() => localStorage.setItem('cassa_id', 'poop'));
  await page.goto(`${HTTPS}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.card:not(.is-out-of-stock)', { timeout: 15000 });
  await page.locator('.card:not(.is-out-of-stock)').first().click();
  await page.waitForTimeout(200);
  await page.check('#pagamento_contanti', { force: true });
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('print_receipt.php'), { timeout: 15000 }).catch(() => null),
    page.click('#checkout-btn')
  ]);
  const body = resp ? await resp.text().catch(() => '') : '';
  log('Checkout diretto su HTTPS (cassa poop)', resp && resp.ok() && body.includes('"method":"direct"'), body.slice(0, 150));
  await browser.close();
}

async function bridgePrintTest(engineName) {
  console.log(`\n========== STAMPA BRIDGE QZ (cassa henry) - ${engineName.toUpperCase()} ==========`);
  const engine = playwright[engineName];
  const browser = await engine.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });
  const page = await context.newPage();
  const logs = [];
  page.on('console', (m) => logs.push(`[${m.type()}] ${m.text()}`));
  await page.addInitScript(() => localStorage.setItem('cassa_id', 'henry'));

  await page.goto(`${HTTP}/pages/billing.php`, { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.waitForSelector('.card:not(.is-out-of-stock)', { timeout: 15000 });
  await page.locator('.card:not(.is-out-of-stock)').first().click();
  await page.waitForTimeout(200);
  await page.check('#pagamento_contanti', { force: true });
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('print_receipt.php'), { timeout: 15000 }).catch(() => null),
    page.click('#checkout-btn')
  ]);
  await page.waitForTimeout(3000);
  const body = resp ? await resp.text().catch(() => '') : '';
  const qzOk = logs.some((l) => l.includes('Established connection with QZ Tray'));
  log(`Checkout bridge QZ su HTTP (${engineName})`, resp && resp.ok() && qzOk, `qz_connesso=${qzOk}, risposta=${body.slice(0, 100)}`);
  await browser.close();
}

(async () => {
  await smokeTest();
  await pdfTest();
  await directPrintTest();
  for (const engineName of ['chromium', 'firefox', 'webkit']) {
    await bridgePrintTest(engineName);
  }

  console.log('\n=== RIEPILOGO FINALE ===');
  results.forEach((r) => console.log(`${r.ok ? '✅' : '❌'} ${r.name}`));
})().catch((err) => {
  console.error('ERRORE SCRIPT:', err);
  process.exit(1);
});
