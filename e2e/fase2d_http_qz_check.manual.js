// Ripete il test di checkout reale + stampa via bridge QZ su tutti e tre i motori
// browser di Playwright (chromium/firefox/webkit), per verificare se il comportamento
// di mixed-content sul websocket ws:// verso host non-loopback (192.168.88.224) e'
// uguale ovunque o solo un comportamento di Chromium.
// ATTENZIONE: ogni esecuzione registra una vendita reale sul DB condiviso (autorizzato).
// Lancio manuale: `node e2e/fase2d_real_print_multibrowser.manual.js` con FrankenPHP attivo.
const playwright = require('playwright');
const path = require('path');

const BASE = 'http://localhost:8080';
const CASSA_ID = 'henry';
const ENGINES = ['chromium', 'firefox', 'webkit'];

async function runFor(engineName) {
  console.log(`\n========== ${engineName.toUpperCase()} ==========`);
  const engine = playwright[engineName];
  const browser = await engine.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });
  const page = await context.newPage();

  const logs = [];
  page.on('console', (m) => logs.push(`[${m.type()}] ${m.text()}`));
  page.on('pageerror', (e) => logs.push(`[pageerror] ${e.message}`));

  await page.addInitScript((cassaId) => localStorage.setItem('cassa_id', cassaId), CASSA_ID);

  try {
    await page.goto(`${BASE}/pages/billing.php`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.waitForSelector('.card:not(.is-out-of-stock)', { timeout: 15000 });
    await page.locator('.card:not(.is-out-of-stock)').first().click();
    await page.waitForTimeout(200);
    await page.check('#pagamento_contanti');
    await page.waitForTimeout(200);

    const [checkoutResp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('print_receipt.php'), { timeout: 15000 }).catch(() => null),
      page.click('#checkout-btn')
    ]);

    if (checkoutResp) {
      const body = await checkoutResp.text().catch(() => '(non leggibile)');
      console.log('Risposta print_receipt.php:', checkoutResp.status(), body.slice(0, 200));
    } else {
      console.log('Nessuna risposta da print_receipt.php entro il timeout.');
    }

    await page.waitForTimeout(4000);
    await page.screenshot({ path: path.join(__dirname, `fase2d_multibrowser_${engineName}.png`) });
  } catch (e) {
    console.log('ECCEZIONE durante il test:', e.message);
  }

  console.log(`--- Log console (${engineName}) ---`);
  console.log(logs.join('\n') || '(nessuno)');

  await browser.close();
}

(async () => {
  for (const engineName of ENGINES) { // salta chromium, gia' testato in precedenza
    await runFor(engineName);
  }
})().catch((err) => {
  console.error('ERRORE SCRIPT:', err);
  process.exit(1);
});
