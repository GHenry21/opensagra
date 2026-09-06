// Verifica una tantum (Fase 2d) del download PDF statistiche via il vero flusso utente
// (form HTML classico in stat_vendite.php, non fetch/AJAX). curl e l'API di richieste
// dirette di Playwright falliscono su risposte Content-Disposition:attachment (0 byte,
// limite dello strumento) - questo script guida un browser vero fino al click reale.
// Lancio manuale: `node e2e/fase2d_pdf_download.manual.js` con FrankenPHP gia' attivo.
const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, acceptDownloads: true });
  const page = await context.newPage();

  await page.goto('https://localhost:8443/pages/stat_vendite.php', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#btnPdf', { timeout: 15000 });

  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 15000 }),
    page.click('#btnPdf')
  ]);

  const savePath = path.join(__dirname, 'downloaded_stat.pdf');
  await download.saveAs(savePath);
  console.log('Download suggestedFilename:', download.suggestedFilename());
  console.log('Salvato in:', savePath);

  const fs = require('fs');
  const buf = fs.readFileSync(savePath);
  console.log('Dimensione file:', buf.length, 'byte');
  console.log('Magic bytes:', buf.slice(0, 8).toString('latin1'));
  console.log('E\' un PDF valido:', buf.slice(0, 4).toString('ascii') === '%PDF');

  await browser.close();
})().catch((err) => {
  console.error('ERRORE:', err.message);
  process.exit(1);
});
