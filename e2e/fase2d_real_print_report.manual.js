// Test di stampa REALE del report statistiche (Fase 2d + Appendice C).
// Lancio manuale: `node e2e/fase2d_real_print_report.manual.js` con FrankenPHP gia' attivo.
const { chromium } = require('playwright');
const path = require('path');

const BASE = 'https://localhost:8443';
const CASSA_ID = 'henry';

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 900 } });
  const page = await context.newPage();

  const logs = [];
  page.on('console', (m) => logs.push(`[${m.type()}] ${m.text()}`));
  page.on('pageerror', (e) => logs.push(`[pageerror] ${e.message}`));

  await page.addInitScript((cassaId) => localStorage.setItem('cassa_id', cassaId), CASSA_ID);

  await page.goto(`${BASE}/pages/stat_vendite.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#btnReceipt', { timeout: 15000 });
  await page.waitForTimeout(1000); // lascia caricare i dati statistiche iniziali

  const [printResp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('print_stat_receipt.php'), { timeout: 15000 }).catch(() => null),
    page.click('#btnReceipt')
  ]);

  if (printResp) {
    const body = await printResp.text().catch(() => '(non leggibile)');
    console.log('Risposta print_stat_receipt.php:', printResp.status(), body.slice(0, 300));
  } else {
    console.log('Nessuna risposta da print_stat_receipt.php entro il timeout.');
  }

  await page.waitForTimeout(4000);
  await page.screenshot({ path: path.join(__dirname, 'fase2d_real_print_report.png') });

  console.log('\n--- Log console/pagina ---');
  console.log(logs.join('\n') || '(nessuno)');

  await browser.close();
})().catch((err) => {
  console.error('ERRORE SCRIPT:', err);
  process.exit(1);
});
