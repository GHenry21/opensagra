// Fase 4 - verifica del realtime prodotti via Mercure contro il server di
// sviluppo (https://localhost). NON serve una VM: bastano due client
// indipendenti sullo stesso server. Un client e' una cassa con billing.php
// aperto; la "seconda cassa" e' sostituita da una POST diretta all'endpoint
// di mutazione reale (che chiama publishProductsChanged() identico).
//
// Lascia righe con category = '__RT_TEST__': ripulite prima/dopo da
// scratchpad/rt_cleanup.php lanciato a parte.

const { test, expect } = require('@playwright/test');

const BASE = 'https://localhost';
const TEST_CATEGORY = '__RT_TEST__';

test.use({ ignoreHTTPSErrors: true });
// Seriale: i due test mutano la stessa categoria di prova, non devono sovrapporsi.
test.describe.configure({ mode: 'serial' });

async function openCassa(browser, sig) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.addInitScript(() => {
    try { localStorage.setItem('cassa_id', 'RT_TEST'); } catch (e) {}
  });
  page.on('request', (req) => {
    const u = req.url();
    if (u.includes('products_version.php')) sig.versionPolls.push(Date.now());
    if (u.includes('/.well-known/mercure')) sig.sseStarted = true;
    if (u.includes('mercure_subscribe_token.php')) sig.tokenAsked = true;
  });
  return { context, page };
}

async function insertTestProduct(request, name) {
  const body = new URLSearchParams({ category: TEST_CATEGORY, name, price: '1.23' }).toString();
  const res = await request.post(`${BASE}/api/insert_product.php`, {
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    data: body,
  });
  expect(res.ok(), `insert_product.php HTTP ${res.status()}`).toBeTruthy();
}

test('un cambio prodotto raggiunge una cassa aperta via push Mercure, senza polling', async ({ browser, request }) => {
  const sig = { versionPolls: [], sseStarted: false, tokenAsked: false };
  const { context, page } = await openCassa(browser, sig);

  await page.goto(`${BASE}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.product-grid .card .product-name', { timeout: 15000 });

  await expect.poll(() => sig.tokenAsked, { timeout: 10000 }).toBe(true);
  await expect.poll(() => sig.sseStarted, { timeout: 10000 }).toBe(true);

  // stabilizza e azzera il contatore dei poll
  await page.waitForTimeout(2000);
  const pollsBefore = sig.versionPolls.length;

  const name = `${TEST_CATEGORY} push`;
  const t0 = Date.now();
  await insertTestProduct(request, name);

  await page.waitForSelector(
    `.product-grid .card .product-name:text-is("${name}")`,
    { timeout: 4000 }
  );
  const elapsed = Date.now() - t0;
  const pollsDuring = sig.versionPolls.length - pollsBefore;
  console.log(`[push] comparso in ${elapsed} ms | giri products_version.php nella finestra: ${pollsDuring}`);

  expect(elapsed, 'la comparsa deve essere quasi immediata (push, non poll)').toBeLessThan(3000);
  expect(pollsDuring, 'nessun poll deve essere servito: l\'aggiornamento e\' arrivato dal push').toBe(0);

  await context.close();
});

test('con l\'hub irraggiungibile la cassa si aggiorna comunque via polling (fallback)', async ({ browser, request }) => {
  const sig = { versionPolls: [], sseStarted: false, tokenAsked: false };
  const { context, page } = await openCassa(browser, sig);

  // L'endpoint token risponde (PHP e' su), ma l'hub e' irraggiungibile: e' il
  // caso piu' insidioso - l'EventSource viene creato e ritenta a vuoto. Il
  // polling deve continuare lo stesso.
  await context.route('**/.well-known/mercure**', (r) => r.abort());

  await page.goto(`${BASE}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.product-grid .card .product-name', { timeout: 15000 });
  // lascia passare qualche ciclo di errore/retry dell'EventSource
  await page.waitForTimeout(4000);

  const name = `${TEST_CATEGORY} fallback`;
  const t0 = Date.now();
  await insertTestProduct(request, name);

  // L'hub e' bloccato: il push non puo' consegnare nulla. Se il prodotto
  // compare, e' stato il polling condizionale.
  await page.waitForSelector(
    `.product-grid .card .product-name:text-is("${name}")`,
    { timeout: 15000 }
  );
  const elapsed = Date.now() - t0;
  console.log(`[fallback] comparso in ${elapsed} ms | giri products_version.php totali: ${sig.versionPolls.length}`);

  expect(sig.tokenAsked, 'il token viene comunque richiesto').toBe(true);
  expect(sig.versionPolls.length, 'il polling condizionale deve essere attivo').toBeGreaterThan(0);
  expect(elapsed, 'il polling condizionale deve recuperare in pochi giri').toBeLessThan(14000);

  await context.close();
});
