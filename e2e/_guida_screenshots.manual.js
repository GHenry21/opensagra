// Script una-tantum: ricattura 06-add-product.png e 09-conf-casse-mobile.png
// dopo i fix CSS di add_product.css/conf_casse.css. Sola navigazione GET.
const { chromium } = require('playwright');
const path = require('path');

const OUT = path.resolve(__dirname, '..', 'docs', 'img', 'guida');
const BASE = 'http://localhost';

(async () => {
  const browser = await chromium.launch();

  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await page.addInitScript(() => localStorage.setItem('cassa_id', 'GUIDA_DEMO'));
  await page.goto(`${BASE}/pages/add_product.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(OUT, '06-add-product.png') });
  await page.close();

  const mobile = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await mobile.addInitScript(() => localStorage.setItem('cassa_id', 'GUIDA_DEMO'));
  await mobile.goto(`${BASE}/pages/conf_casse.php`, { waitUntil: 'networkidle' });
  await mobile.waitForTimeout(400);
  await mobile.screenshot({ path: path.join(OUT, '09-conf-casse-mobile.png') });
  await mobile.close();

  await browser.close();
  console.log('Screenshot salvati in', OUT);
})();
