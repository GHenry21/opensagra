// Verifica una tantum della Fase 2d (smoke test FrankenPHP) - non e' una suite CI,
// non e' wired a `npx playwright test`. Lancio manuale: `node e2e/fase2d_smoke.manual.js`
// con FrankenPHP gia' attivo su https://localhost:8443 (frankenphp run --config Caddyfile).
// Scrive UNA riga di test in casse_stampanti (cassa_id di test, rimossa a mano dopo la
// corsa) - non tocca vendite reali, nessun checkout automatico. Vedi
// docs/PIANO-MIGRAZIONE-FRANKENPHP.md, Fase 2d, per il contesto ed i risultati.
const { chromium } = require('playwright');
const path = require('path');

const BASE = 'https://localhost:8443';
const results = [];
function log(name, ok, detail) {
  results.push({ name, ok, detail });
  console.log(`[${ok ? 'OK' : 'FAIL'}] ${name} - ${detail}`);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });
  const page = await context.newPage();
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => consoleErrors.push('[pageerror] ' + e.message));

  // ===== 1. billing.php: carrello, sconto, filtro categoria =====
  await page.goto(`${BASE}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.card', { timeout: 15000 });

  const totalBefore = await page.locator('.total-label').locator('xpath=following-sibling::*[1]').first().innerText().catch(() => null);
  const firstCard = page.locator('.card:not(.is-out-of-stock)').first();
  const productName = await firstCard.locator('.product-name').innerText();
  await firstCard.click();
  await page.waitForTimeout(300);
  const totalAfterAdd = await page.locator('.total-label').locator('xpath=following-sibling::*[1]').first().innerText().catch(() => null);
  log('Aggiunta al carrello', totalAfterAdd !== totalBefore, `prodotto "${productName}", totale ${totalBefore} -> ${totalAfterAdd}`);

  const discountBtn = page.locator('.discount-preset').first();
  const discountLabel = await discountBtn.innerText();
  await discountBtn.click();
  await page.waitForTimeout(300);
  const totalAfterDiscount = await page.locator('.total-label').locator('xpath=following-sibling::*[1]').first().innerText().catch(() => null);
  log('Sconto preimpostato', totalAfterDiscount !== totalAfterAdd, `preset "${discountLabel}", totale ${totalAfterAdd} -> ${totalAfterDiscount}`);

  await page.selectOption('#category-view-mode', 'cucina');
  await page.waitForTimeout(300);
  const visibleSections = await page.locator('.category-block:not(.filtered-out)').count();
  log('Filtro categoria', true, `sezioni visibili con filtro "cucina": ${visibleSections}`);

  await page.screenshot({ path: path.join(__dirname, 'fase2d_billing.png') });

  // ===== 2. api/sign-message.php (openssl, no hardware) =====
  const signResp = await context.request.get(`${BASE}/api/sign-message.php?request=fase2-smoke-test`);
  const signBody = await signResp.text();
  log('sign-message.php (openssl)', signResp.ok() && signBody.length > 50 && !signBody.includes('Error'), `HTTP ${signResp.status()}, lunghezza firma: ${signBody.length}`);

  // ===== 3. print/print_stat_pdf.php (dompdf, no DB) =====
  try {
    const pdfResp = await context.request.post(`${BASE}/print/print_stat_pdf.php`, {
      form: { htmlContent: '<h1>Test Fase 2</h1><p>Report di prova.</p>' }
    });
    const ct = pdfResp.headers()['content-type'];
    const cl = pdfResp.headers()['content-length'];
    let isPdf = false;
    try {
      const pdfBuf = await pdfResp.body();
      isPdf = pdfBuf.slice(0, 4).toString('ascii') === '%PDF';
    } catch (bodyErr) {
      isPdf = ct === 'application/pdf'; // fallback sugli header se la lettura del body fallisce lato client
    }
    log('print_stat_pdf.php (dompdf)', pdfResp.ok() && (isPdf || ct === 'application/pdf'), `HTTP ${pdfResp.status()}, content-type: ${ct}, content-length: ${cl}`);
  } catch (e) {
    log('print_stat_pdf.php (dompdf)', false, `eccezione: ${e.message}`);
  }

  // ===== 4. api/statistiche_vendite.php (lettura DB, range odierno) =====
  try {
    const today = new Date().toISOString().slice(0, 10);
    const statsResp = await context.request.post(`${BASE}/api/statistiche_vendite.php`, {
      form: { from: today, to: today, cassa: '' }
    });
    const statsJson = await statsResp.json().catch(() => null);
    log('statistiche_vendite.php', statsResp.ok() && statsJson && !statsJson.error, `HTTP ${statsResp.status()}, risposta: ${JSON.stringify(statsJson).slice(0, 150)}`);
  } catch (e) { log('statistiche_vendite.php', false, `eccezione: ${e.message}`); }

  // ===== 5. api/get_receipt_config.php (lettura, no scrittura) =====
  try {
    const receiptResp = await context.request.get(`${BASE}/api/get_receipt_config.php`);
    const receiptJson = await receiptResp.json().catch(() => null);
    log('get_receipt_config.php', receiptResp.ok() && receiptJson !== null, `HTTP ${receiptResp.status()}, config: ${JSON.stringify(receiptJson).slice(0, 150)}`);
  } catch (e) { log('get_receipt_config.php', false, `eccezione: ${e.message}`); }

  // ===== 6. pages/conf_casse.php (solo caricamento, nessun salvataggio) =====
  try {
    const confResp = await context.request.get(`${BASE}/pages/conf_casse.php`);
    log('conf_casse.php (solo GET)', confResp.ok(), `HTTP ${confResp.status()}`);
  } catch (e) { log('conf_casse.php (solo GET)', false, `eccezione: ${e.message}`); }

  // ===== 7. api/open_drawer.php (nessuna stampante reale: atteso errore pulito, non un crash) =====
  try {
    const drawerResp = await context.request.get(`${BASE}/api/open_drawer.php`, { timeout: 20000 });
    const drawerJson = await drawerResp.json().catch(() => null);
    log('open_drawer.php (atteso: errore pulito, niente hardware)', true, `HTTP ${drawerResp.status()}, risposta: ${JSON.stringify(drawerJson)}`);
  } catch (e) { log('open_drawer.php', false, `richiesta fallita/timeout: ${e.message}`); }

  // ===== 8. api/chiudi_cassa.php con cassa_id di test, poi pulizia =====
  try {
    const testCassaId = 'TEST-CLAUDE-FASE2-DELETE-ME';
    const chiudiResp = await context.request.post(`${BASE}/api/chiudi_cassa.php`, {
      data: { cassa_id: testCassaId },
      headers: { 'Content-Type': 'application/json' }
    });
    const chiudiJson = await chiudiResp.json().catch(() => null);
    log('chiudi_cassa.php (cassa di test)', chiudiResp.ok() && chiudiJson && chiudiJson.success, `HTTP ${chiudiResp.status()}, risposta: ${JSON.stringify(chiudiJson)}`);
  } catch (e) { log('chiudi_cassa.php (cassa di test)', false, `eccezione: ${e.message}`); }

  console.log('\n--- Errori console durante il test billing.php ---');
  console.log(consoleErrors.length ? consoleErrors.join('\n') : '(nessuno)');

  await browser.close();

  console.log('\n=== RIEPILOGO ===');
  results.forEach((r) => console.log(`${r.ok ? '✅' : '❌'} ${r.name}`));

  console.log("\nATTENZIONE: il test chiudi_cassa.php scrive una riga in casse_stampanti con");
  console.log("cassa_id='TEST-CLAUDE-FASE2-DELETE-ME' - rimuoverla a mano dopo la corsa:");
  console.log("  DELETE FROM casse_stampanti WHERE cassa_id = 'TEST-CLAUDE-FASE2-DELETE-ME';");
})().catch((err) => {
  console.error('ERRORE SCRIPT:', err);
  process.exit(1);
});
