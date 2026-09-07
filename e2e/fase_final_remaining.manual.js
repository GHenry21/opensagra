// Completamento mirato della verifica finale: solo PDF (fix timing) e stampa
// bridge QZ sui 3 motori (fix force:true sul radio contanti), senza ripetere
// smoke test e checkout diretto gia' riusciti in fase_final_verification.js.
const playwright = require('playwright');
const path = require('path');
const fs = require('fs');

const HTTP = 'http://localhost';
const results = [];
function log(name, ok, detail) {
  results.push({ name, ok, detail });
  console.log(`[${ok ? 'OK' : 'FAIL'}] ${name} - ${detail}`);
}

async function pdfTest() {
  console.log('\n========== PDF DOWNLOAD (flusso reale, con attesa dati) ==========');
  const browser = await playwright.chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, acceptDownloads: true });
  const page = await context.newPage();
  const logs = [];
  page.on('console', (m) => logs.push(`[${m.type()}] ${m.text()}`));
  page.on('pageerror', (e) => logs.push(`[pageerror] ${e.message}`));
  page.on('request', (r) => { if (r.url().includes('print_stat_pdf.php')) { console.log('POST DATA inviato:', JSON.stringify(r.postData()).slice(0, 200)); } });
  await page.goto(`${HTTP}/pages/stat_vendite.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#btnPdf', { timeout: 15000 });
  await page.waitForTimeout(1500);
  try {
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 15000 }),
      page.click('#btnPdf')
    ]);
    console.log('Log console/errori:', logs.join(' | ') || '(nessuno)');
    const savePath = path.join(__dirname, 'final_verify_stat.pdf');
    await download.saveAs(savePath);
    const buf = fs.readFileSync(savePath);
    const isPdf = buf.slice(0, 4).toString('ascii') === '%PDF';
    log('PDF statistiche (download reale)', isPdf, `${buf.length} byte, magic=${buf.slice(0, 8).toString('latin1')}`);
    fs.unlinkSync(savePath);
  } catch (err) {
    console.log('Log console/errori (in errore):', logs.join(' | ') || '(nessuno)');
    log('PDF statistiche (download reale)', false, 'eccezione: ' + err.message);
  }
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
  await page.waitForTimeout(200);
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('print_receipt.php'), { timeout: 15000 }).catch(() => null),
    page.click('#checkout-btn')
  ]);
  await page.waitForTimeout(3000);
  const body = resp ? await resp.text().catch(() => '') : '';
  const qzOk = logs.some((l) => l.includes('Established connection with QZ Tray'));
  log(`Checkout bridge QZ su HTTP (${engineName})`, !!resp && resp.ok() && qzOk, `qz_connesso=${qzOk}, risposta=${body.slice(0, 100)}`);
  await browser.close();
}

(async () => {
  await pdfTest();
  for (const engineName of ['chromium', 'firefox', 'webkit']) {
    await bridgePrintTest(engineName);
  }
  console.log('\n=== RIEPILOGO ===');
  results.forEach((r) => console.log(`${r.ok ? '✅' : '❌'} ${r.name}`));
})().catch((err) => {
  console.error('ERRORE SCRIPT:', err);
  process.exit(1);
});
