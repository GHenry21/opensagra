// Test di stampa REALE (Fase 2d + Appendice C) con stampante fisica collegata e QZ Tray
// attivo. Registra una vendita vera nel DB (autorizzato esplicitamente dall'utente).
// Lancio manuale: `node e2e/fase2d_real_print.manual.js` con FrankenPHP gia' attivo.
const { chromium } = require('playwright');
const path = require('path');

const BASE = 'https://localhost:8443';
const CASSA_ID = "poop"; // BRIDGE, nome_indirizzo=POS-80C, qz_host=192.168.88.224 (= questo PC)

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });
  const page = await context.newPage();

  const logs = [];
  page.on('console', (m) => logs.push(`[${m.type()}] ${m.text()}`));
  page.on('pageerror', (e) => logs.push(`[pageerror] ${e.message}`));
  page.on('requestfailed', (r) => logs.push(`[requestfailed] ${r.url()} - ${r.failure()?.errorText}`));

  await page.addInitScript((cassaId) => {
    localStorage.setItem('cassa_id', cassaId);
  }, CASSA_ID);

  console.log('=== TEST 1: checkout reale + stampa scontrino (bridge_qz) ===');
  await page.goto(`${BASE}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.card:not(.is-out-of-stock)', { timeout: 15000 });

  await page.locator('.card:not(.is-out-of-stock)').first().click();
  await page.waitForTimeout(200);

  // Metodo di pagamento: per 'henry' solo contanti e' abilitato (radio #pagamento_contanti)
  await page.check('#pagamento_contanti');
  await page.waitForTimeout(200);

  const [checkoutResp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('print_receipt.php'), { timeout: 15000 }).catch(() => null),
    page.click('#checkout-btn')
  ]);

  if (checkoutResp) {
    const body = await checkoutResp.text().catch(() => '(non leggibile)');
    console.log('Risposta print_receipt.php:', checkoutResp.status(), body.slice(0, 300));
  } else {
    console.log('Nessuna risposta da print_receipt.php entro il timeout.');
  }

  // Tempo per completare l'eventuale connessione/stampa QZ (asincrona)
  await page.waitForTimeout(4000);

  await page.screenshot({ path: path.join(__dirname, 'fase2d_real_print_billing.png') });

  console.log('\n--- Log console/pagina durante il checkout ---');
  console.log(logs.join('\n') || '(nessuno)');

  await browser.close();
})().catch((err) => {
  console.error('ERRORE SCRIPT:', err);
  process.exit(1);
});
