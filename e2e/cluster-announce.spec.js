// Fase 4 punto 3 - avviso "il server sta per fermarsi" (topic Mercure
// cluster/announce). Verifica end-to-end contro il server di sviluppo
// (https://localhost): billing.php sottoscrive il topic sullo stesso
// EventSource dei prodotti, un annuncio pubblicato dal CLI
// bin/opensagra-announce.php fa comparire un toast persistente (window.
// showToast, non piu' una barra .server-announce dal 2026-09-14), e
// kind=back / la x del toast / un evento non-announce lo fanno sparire.
//
// NB: cluster/announce e' un topic BROADCAST: un annuncio raggiunge ogni
// billing.php aperto sul server, anche una cassa reale in uso. Per questo il
// test gira su un solo browser e chiude sempre con kind=back (afterAll).

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = 'https://localhost';
const REPO_ROOT = path.resolve(__dirname, '..');
const PHP = process.env.PHP_BIN || 'php';

test.use({ ignoreHTTPSErrors: true });
test.describe.configure({ mode: 'serial' });
// Topic globale: un browser per volta, altrimenti gli annunci di un progetto
// (chromium) fanno sparire/comparire la barra mentre un altro (firefox/webkit)
// sta asserendo.
test.skip(({ browserName }) => browserName !== 'chromium', 'cluster/announce e\' broadcast: un solo browser');

function announce(...args) {
  return execFileSync(PHP, ['bin/opensagra-announce.php', ...args], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
  });
}

async function openCassa(browser) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.addInitScript(() => {
    try { localStorage.setItem('cassa_id', 'RT_TEST'); } catch (e) {}
  });
  const sig = { sseStarted: false, tokenAsked: false };
  page.on('request', (req) => {
    const u = req.url();
    if (u.includes('/.well-known/mercure')) sig.sseStarted = true;
    if (u.includes('mercure_subscribe_token.php')) sig.tokenAsked = true;
  });
  await page.goto(`${BASE}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.product-grid .card .product-name', { timeout: 15000 });
  await expect.poll(() => sig.tokenAsked, { timeout: 10000 }).toBe(true);
  await expect.poll(() => sig.sseStarted, { timeout: 10000 }).toBe(true);
  // lascia stabilizzare la connessione SSE (onopen)
  await page.waitForTimeout(1500);
  return { context, page, sig };
}

// Rete di sicurezza: qualunque cosa vada storta, togli la barra dalle casse.
test.afterAll(() => {
  try { announce('--kind=back'); } catch (e) { /* hub giu': pazienza */ }
});

test('kind=shutdown fa comparire il toast con eta e messaggio; kind=back lo rimuove', async ({ browser }) => {
  const { context, page } = await openCassa(browser);
  // Filtrato per testo: RT_TEST non e' una cassa reale, quindi
  // loadPaymentMethods() mostra gia' di suo un toast.error persistente
  // ("non e' associata a nessuna stampante...") indipendente dall'announce -
  // un selettore .toast.error nudo lo intercetterebbe per sbaglio.
  const toast = page.locator('#message .toast.error', { hasText: 'sta per fermarsi' });

  await expect(toast).toHaveCount(0);

  announce('--kind=shutdown', '--eta=30', '--message=Manutenzione serale');

  await expect(toast).toBeVisible({ timeout: 6000 });
  await expect(toast).toContainText('sta per fermarsi');
  await expect(toast).toContainText('30s');
  await expect(toast).toContainText('Manutenzione serale');

  announce('--kind=back');

  await expect(toast).toHaveCount(0, { timeout: 6000 });

  await context.close();
});

test('la x del toast lo nasconde', async ({ browser }) => {
  const { context, page } = await openCassa(browser);
  // Filtrato per testo: RT_TEST non e' una cassa reale, quindi
  // loadPaymentMethods() mostra gia' di suo un toast.error persistente
  // ("non e' associata a nessuna stampante...") indipendente dall'announce -
  // un selettore .toast.error nudo lo intercetterebbe per sbaglio.
  const toast = page.locator('#message .toast.error', { hasText: 'sta per fermarsi' });

  announce('--kind=shutdown');
  await expect(toast).toBeVisible({ timeout: 6000 });

  await toast.locator('.toast-close-btn').click();
  await expect(toast).toHaveCount(0);

  announce('--kind=back');
  await context.close();
});

test('un secondo annuncio shutdown dopo un back ricompare (nuova sottoscrizione viva)', async ({ browser }) => {
  const { context, page } = await openCassa(browser);
  // Filtrato per testo: RT_TEST non e' una cassa reale, quindi
  // loadPaymentMethods() mostra gia' di suo un toast.error persistente
  // ("non e' associata a nessuna stampante...") indipendente dall'announce -
  // un selettore .toast.error nudo lo intercetterebbe per sbaglio.
  const toast = page.locator('#message .toast.error', { hasText: 'sta per fermarsi' });

  announce('--kind=shutdown');
  await expect(toast).toBeVisible({ timeout: 6000 });
  announce('--kind=back');
  await expect(toast).toHaveCount(0, { timeout: 6000 });

  // stessa pagina, stessa connessione SSE: deve ricevere ancora
  announce('--kind=shutdown', '--eta=10');
  await expect(toast).toBeVisible({ timeout: 6000 });
  await expect(toast).toContainText('10s');

  announce('--kind=back');
  await expect(toast).toHaveCount(0, { timeout: 6000 });

  await context.close();
});
