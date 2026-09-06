// Test rapido: Edge (Chromium reale installato sul sistema, non il Chromium bundled
// di Playwright) si comporta come Chromium/Brave sul mixed-content QZ?
const { chromium } = require('playwright');
const path = require('path');

const BASE = 'https://localhost:8443';
const CASSA_ID = 'henry';

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });
  const page = await context.newPage();
  const logs = [];
  page.on('console', (m) => logs.push(`[${m.type()}] ${m.text()}`));
  page.on('pageerror', (e) => logs.push(`[pageerror] ${e.message}`));

  await page.addInitScript((cassaId) => localStorage.setItem('cassa_id', cassaId), CASSA_ID);
  await page.goto(`${BASE}/pages/billing.php`, { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.waitForSelector('.card:not(.is-out-of-stock)', { timeout: 15000 });
  await page.locator('.card:not(.is-out-of-stock)').first().click();
  await page.waitForTimeout(200);
  await page.check('#pagamento_contanti');
  await page.waitForTimeout(200);

  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('print_receipt.php'), { timeout: 15000 }).catch(() => null),
    page.click('#checkout-btn')
  ]);
  console.log('Risposta print_receipt.php:', resp ? resp.status() : '(nessuna)');
  await page.waitForTimeout(4000);
  await page.screenshot({ path: path.join(__dirname, 'fase2d_edge.png') });

  console.log('--- Log console (Edge) ---');
  console.log(logs.join('\n') || '(nessuno)');
  await browser.close();
})().catch((err) => { console.error('ERRORE:', err.message); process.exit(1); });
