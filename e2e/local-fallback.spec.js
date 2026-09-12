// Fase 4 punto 4 - "Fallback locale una-via + push a chiusura cassa", lato
// browser, contro il server di sviluppo (https://localhost).
//
// Tutte le chiamate che muterebbero config/variabili.env o il DB
// (api/enter_local_fallback.php, api/push_local_sales.php, api/chiudi_cassa.php)
// sono INTERCETTATE da Playwright: il server PHP non le esegue mai, quindi
// nessun effetto collaterale sull'ambiente. Si verifica solo il comportamento
// del client:
//   - dopo N ms di server centrale irraggiungibile parte lo swap automatico
//     (POST enter_local_fallback.php) e la pagina si ricarica, in silenzio;
//   - l'hook di test window.__opensagraForceLocalFallback() forza lo swap;
//   - a "Chiudi Cassa", se la cassa era in fallback, parte il push e - se il
//     centrale e' ancora giu' - compare il bottone persistente "Sincronizza ora";
//   - il badge persistente in sidebar (#pos-sync-pending), guidato da
//     api/sync_status.php, compare su qualunque pagina e sopravvive a un reload
//     finche' restano vendite locali da sincronizzare.

const { test, expect } = require('@playwright/test');

const BASE = 'https://localhost';
const CASSA_ID = 'pixel';

test.use({ ignoreHTTPSErrors: true });

async function openBilling(browser, { fallbackAfterMs } = {}) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.addInitScript((cfg) => {
    try {
      localStorage.setItem('cassa_id', cfg.cassa);
      if (cfg.fallbackAfterMs) {
        localStorage.setItem('fallback_after_ms', String(cfg.fallbackAfterMs));
      }
    } catch (e) {}
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
  }, { cassa: CASSA_ID, fallbackAfterMs });
  await page.goto(`${BASE}/pages/billing.php`, { waitUntil: 'domcontentloaded' });
  return { context, page };
}

function toastSeen(page, text) {
  return expect
    .poll(() => page.evaluate(() => window.__toasts || []), { timeout: 30000 })
    .toEqual(expect.arrayContaining([expect.stringContaining(text)]));
}

test('soglia superata -> POST enter_local_fallback.php + reload, in silenzio', async ({ browser }) => {
  const { context, page } = await openBilling(browser); // soglia di default (150s)

  let fallbackPosts = 0;

  await page.route('**/api/products_version.php', (route) =>
    route.fulfill({ status: 503, contentType: 'application/json', body: '{}' }));
  // enter_local_fallback stubbato: non tocca variabili.env. Dopo il primo swap
  // rispondiamo "gia' in fallback" cosi' l'eventuale reload non fa un loop.
  await page.route('**/api/enter_local_fallback.php', async (route) => {
    fallbackPosts += 1;
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, already: fallbackPosts > 1, origin: '192.168.1.50' }) });
  });

  await page.waitForFunction(() => typeof window.__opensagraTestSimulateServerDown === 'function', { timeout: 15000 });

  // Centrale giu' da meno della soglia -> NON deve scattare.
  await page.evaluate(() => window.__opensagraTestSimulateServerDown(1000));
  await page.waitForTimeout(1500);
  expect(fallbackPosts, 'sotto soglia: nessuno swap').toBe(0);

  // Centrale giu' da oltre la soglia -> swap + reload della pagina.
  const reloaded = page.waitForEvent('load', { timeout: 15000 });
  await page.evaluate(() => window.__opensagraTestSimulateServerDown(200000));
  await expect.poll(() => fallbackPosts, { timeout: 10000 }).toBeGreaterThanOrEqual(1);
  await reloaded; // window.location.reload() eseguito

  // Silenzioso: nessun toast legato al fallback
  const toasts = await page.evaluate(() => window.__toasts || []);
  expect(toasts.join(' | ')).not.toMatch(/local|fallback|sincron/i);

  await context.close();
});

test('hook di test window.__opensagraForceLocalFallback() forza lo swap subito', async ({ browser }) => {
  const { context, page } = await openBilling(browser); // nessuna soglia bassa

  let fallbackPosts = 0;
  await page.route('**/api/enter_local_fallback.php', async (route) => {
    fallbackPosts += 1;
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, origin: '10.0.0.9' }) });
  });
  // evita il reload loop: dopo lo swap i version-poll "vanno bene"
  await page.route('**/api/products_version.php', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ version: 1, count: 1 }) }));

  await page.waitForFunction(() => typeof window.__opensagraForceLocalFallback === 'function', { timeout: 15000 });
  await page.evaluate(() => window.__opensagraForceLocalFallback());

  await expect.poll(() => fallbackPosts, { timeout: 10000 }).toBe(1);

  await context.close();
});

test('Chiudi Cassa in fallback: push al centrale, poi bottone "Sincronizza ora" se il centrale e\' giu\'', async ({ browser }) => {
  const { context, page } = await openBilling(browser);

  await page.route('**/api/chiudi_cassa.php', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        cassa_id: CASSA_ID,
        fondo_cassa: 0,
        totale_contanti: 0,
        totale_vendite: 0,
        totale_atteso: 0,
        ultima_chiusura: '2026-09-09 20:00:00',
        fallback_active: true,
        pending_sync: 2,
      }),
    }));

  let pushCalls = 0;
  await page.route('**/api/push_local_sales.php', async (route) => {
    pushCalls += 1;
    if (pushCalls === 1) {
      // centrale ancora giu'
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: false, server_online: false, pending: 2 }) });
    } else {
      // secondo tentativo (bottone "Sincronizza ora"): ok
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, pushed: 2, skipped: 0, server_online: true, back_to_network: true }) });
    }
  });

  const btn = page.locator('#chiudiCassaBtn');
  await btn.click({ force: true });

  await expect.poll(() => pushCalls, { timeout: 15000 }).toBe(1);
  await toastSeen(page, 'da sincronizzare');

  // il toast persistente porta un bottone "Sincronizza ora"
  const syncBtn = page.locator('.toast-action-btn', { hasText: 'Sincronizza ora' });
  await expect(syncBtn).toBeVisible();
  await syncBtn.click();

  await expect.poll(() => pushCalls, { timeout: 15000 }).toBe(2);
  await toastSeen(page, 'sincronizzate col server centrale');

  await context.close();
});

test('badge persistente in sidebar: compare da sync_status.php e sparisce a push riuscito', async ({ browser }) => {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.addInitScript((cassa) => {
    try { localStorage.setItem('cassa_id', cassa); } catch (e) {}
  }, CASSA_ID);

  // Evita che parta la rilevazione del fallback durante il test.
  await page.route('**/api/products_version.php', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ version: 1, count: 1 }) }));

  // Stato: cassa gia' chiusa (armed), centrale non ancora ricontattato, 2 pendenti.
  let pending = 2;
  await page.route('**/api/sync_status.php', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ fallback_active: true, origin_host: '192.168.1.50', pending_sync: pending, pending_known: true, armed: true }),
    }));

  let pushCalls = 0;
  await page.route('**/api/push_local_sales.php', async (route) => {
    pushCalls += 1;
    pending = 0; // il push va a buon fine
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, pushed: 2, skipped: 0, server_online: true, back_to_network: true }) });
  });

  await page.goto(`${BASE}/pages/billing.php`, { waitUntil: 'domcontentloaded' });

  const badge = page.locator('#pos-sync-pending');
  await expect(badge).toBeVisible({ timeout: 15000 });
  await expect(badge).toContainText('2 vendite da sincronizzare');

  // Sopravvive a un reload (il conteggio arriva dal server, non da stato in pagina).
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('#pos-sync-pending')).toBeVisible({ timeout: 15000 });

  await page.locator('#pos-sync-pending-btn').click();
  await expect.poll(() => pushCalls, { timeout: 15000 }).toBe(1);
  // A push riuscito il badge si nasconde da solo.
  await expect(page.locator('#pos-sync-pending')).toBeHidden({ timeout: 15000 });

  await context.close();
});
