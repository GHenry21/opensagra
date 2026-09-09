// Fase 4 - Scalino 0 (fallimento esplicito + retry client) e Scalino 1
// (idempotency_key riusata a ogni retry) lato browser, contro il server di
// sviluppo (https://localhost).
//
// Tutte le POST a print_receipt.php sono INTERCETTATE da Playwright: il server
// PHP e MariaDB non vengono mai toccati, quindi nessuna vendita di prova finisce
// nel DB condiviso e non serve pulizia. La guardia server (tryIdempotentReplay)
// e' verificata a parte via curl - qui si verifica solo il comportamento del
// client: retry con backoff, chiave stabile, carrello non distrutto, toast.

const { test, expect } = require('@playwright/test');

const BASE = 'https://localhost';
const CASSA_ID = 'pixel'; // configurata: nessun toast "cassa non associata"

test.use({ ignoreHTTPSErrors: true });

// I toast si auto-chiudono dopo pochi secondi: registriamo ogni messaggio in
// window.__toasts appena compare, cosi' le asserzioni non sono in corsa con il
// timeout di dismissione.
async function openBilling(browser) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.addInitScript((cassa) => {
    try { localStorage.setItem('cassa_id', cassa); } catch (e) {}
    window.__toasts = [];
    const seen = new WeakSet();
    const scan = () => {
      document.querySelectorAll('#message .toast-message').forEach((el) => {
        if (!seen.has(el)) { seen.add(el); window.__toasts.push(el.textContent); }
      });
    };
    const mo = new MutationObserver(scan);
    const start = () => mo.observe(document.body, { childList: true, subtree: true });
    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
  }, CASSA_ID);
  await page.goto(`${BASE}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.product-grid .card:not(.is-out-of-stock) .product-name', { timeout: 15000 });
  return { context, page };
}

async function addProductAndPickCash(page) {
  await page.locator('.product-grid .card:not(.is-out-of-stock)').first().click();
  await expect(page.locator('.bill-table')).toBeVisible();
  // il radio e' nascosto dietro una pill: check forzato, poi v-model si aggiorna
  await page.locator('#pagamento_contanti').check({ force: true });
}

function toastSeen(page, text) {
  return expect
    .poll(() => page.evaluate(() => window.__toasts || []), { timeout: 30000 })
    .toEqual(expect.arrayContaining([expect.stringContaining(text)]));
}

function keysFrom(attempts) {
  return new Set(attempts.map((a) => a.idempotency_key));
}

test('Scalino 0: 2 fallimenti retriabili poi successo - stessa chiave, carrello svuotato', async ({ browser }) => {
  const { context, page } = await openBilling(browser);
  const attempts = [];

  await page.route('**/print/print_receipt.php', async (route) => {
    let body = {};
    try { body = JSON.parse(route.request().postData() || '{}'); } catch (e) {}
    attempts.push(body);
    if (attempts.length < 3) {
      await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'DB irraggiungibile' }) });
    } else {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, method: 'direct' }) });
    }
  });

  await addProductAndPickCash(page);
  await page.click('#checkout-btn');

  await expect.poll(() => attempts.length, { timeout: 15000 }).toBe(3);
  await toastSeen(page, 'Vendita registrata e inviata alla stampa');

  expect(keysFrom(attempts).size, 'idempotency_key identica in tutti i tentativi').toBe(1);
  expect(attempts[0].idempotency_key, 'idempotency_key presente').toBeTruthy();

  await expect(page.locator('.bill-table')).toBeHidden();
  await expect(page.locator('#checkout-btn')).toBeEnabled();

  await context.close();
});

test('Scalino 0: tutti i tentativi falliscono - toast "NON registrata", carrello intatto', async ({ browser }) => {
  const { context, page } = await openBilling(browser);
  const attempts = [];

  await page.route('**/print/print_receipt.php', async (route) => {
    let body = {};
    try { body = JSON.parse(route.request().postData() || '{}'); } catch (e) {}
    attempts.push(body);
    await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'DB irraggiungibile' }) });
  });

  await addProductAndPickCash(page);
  await page.click('#checkout-btn');

  // durante la finestra di retry il pulsante e' bloccato
  await expect(page.locator('#checkout-btn')).toBeDisabled();

  await toastSeen(page, 'vendita NON registrata');

  expect(attempts.length, 'piu\' tentativi nella finestra di ~20s').toBeGreaterThanOrEqual(3);
  expect(keysFrom(attempts).size, 'stessa idempotency_key su tutti i retry').toBe(1);

  // non distruttivo: carrello ancora presente, pulsante di nuovo premibile
  await expect(page.locator('.bill-table')).toBeVisible();
  await expect(page.locator('#checkout-btn')).toBeEnabled();

  await context.close();
});

test('Scalino 0: errore di business (409) non si ritenta, carrello intatto', async ({ browser }) => {
  const { context, page } = await openBilling(browser);
  let attempts = 0;

  await page.route('**/print/print_receipt.php', async (route) => {
    attempts += 1;
    await route.fulfill({
      status: 409,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'Prodotto esaurito o non piu\' disponibile.' }),
    });
  });

  await addProductAndPickCash(page);
  await page.click('#checkout-btn');

  await toastSeen(page, 'Prodotto esaurito');
  await page.waitForTimeout(3000); // finestra in cui un retry, se ci fosse, partirebbe

  expect(attempts, 'un 409 e\' definitivo: un solo tentativo').toBe(1);
  await expect(page.locator('.bill-table')).toBeVisible();
  await expect(page.locator('#checkout-btn')).toBeEnabled();

  await context.close();
});
