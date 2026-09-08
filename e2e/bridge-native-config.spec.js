// Verifica UI del modello BRIDGE_NATIVE "a una riga" in conf_casse.php:
// campi IP ponte + "Ricerca Stampanti" (proxy) + toast "Usa questa" + tipo
// stampante + ID-topic, salvataggio, riapertura in modifica.
//
// Usa il server di sviluppo (https://localhost) e la stampante POS-80C reale
// condivisa su questo PC (discovery via proxy contro l'istanza locale).
// Pulisce la cassa di prova prima e dopo.

const { test, expect } = require('@playwright/test');

const BASE = 'https://localhost';
const CASSA_ID = '__BN_TEST__';

test.use({ ignoreHTTPSErrors: true });
test.describe.configure({ mode: 'serial' });

async function deleteCassa(request) {
  await request.post(`${BASE}/api/stampanti.php`, {
    headers: { 'content-type': 'application/json' },
    data: JSON.stringify({ action: 'delete', cassa_id: CASSA_ID }),
  });
}
async function readCassa(request) {
  const res = await request.get(`${BASE}/api/stampanti.php?action=list`);
  const rows = await res.json();
  return rows.find((r) => r.cassa_id === CASSA_ID) || null;
}

test.beforeAll(async ({ request }) => { await deleteCassa(request); });
test.afterAll(async ({ request }) => { await deleteCassa(request); });

test('BRIDGE_NATIVO: discovery via proxy + toast + salvataggio + modifica', async ({ page, request }) => {
  await page.goto(`${BASE}/pages/conf_casse.php`, { waitUntil: 'domcontentloaded' });

  // --- Nuova cassa ---
  await page.click('#btnAddRow');
  await page.fill('#modalCassaId', CASSA_ID);
  await page.selectOption('#modalTipoStampante', 'BRIDGE_NATIVE');

  // Campi del modello "a una riga"
  await expect(page.locator('#modalBridgeTopic')).toBeVisible();
  await expect(page.locator('#modalBridgePrinterType')).toBeVisible();
  await expect(page.locator('#modalBridgeIp')).toBeVisible();
  await expect(page.locator('#discoverBridgeNativeBtn')).toBeVisible();
  // ID-topic auto-compilato con il cassa_id
  await expect(page.locator('#modalBridgeTopic')).toHaveValue(CASSA_ID);
  // default tipo = USB
  await expect(page.locator('#modalBridgePrinterType')).toHaveValue('USB');

  // --- Discovery via proxy contro l'istanza locale (piu' stampanti -> toast) ---
  await page.fill('#modalBridgeIp', '127.0.0.1');
  await page.click('#discoverBridgeNativeBtn');

  const usaQuestaPOS = page.locator('#message .toast-action-btn', { hasText: 'POS-80C' }).first();
  await expect(usaQuestaPOS).toBeVisible({ timeout: 15000 });
  await usaQuestaPOS.click();

  // La stampante scelta finisce in nome_indirizzo (via select o input manuale)
  await expect
    .poll(async () => {
      const sel = await page.locator('#modalBridgePrinter').inputValue().catch(() => '');
      const man = await page.locator('#modalBridgePrinterManual').inputValue().catch(() => '');
      return sel === 'POS-80C' || man === 'POS-80C';
    }, { timeout: 5000 })
    .toBe(true);

  // --- Switch a RETE: compare Porta, sparisce la discovery, input IP diretto ---
  await page.selectOption('#modalBridgePrinterType', 'RETE');
  await expect(page.locator('#modalBridgeIp')).toBeHidden();
  await expect(page.locator('#modalPorta')).toBeVisible();
  await expect(page.locator('#modalPorta')).toHaveValue('9100');
  await expect(page.locator('#modalBridgePrinterRete')).toBeVisible();

  // --- Torna a USB e reimposta la stampante ---
  await page.selectOption('#modalBridgePrinterType', 'USB');
  await page.fill('#modalBridgeIp', '127.0.0.1');
  await page.click('#discoverBridgeNativeBtn');
  await expect(usaQuestaPOS).toBeVisible({ timeout: 15000 });
  await usaQuestaPOS.click();

  // --- Salva ---
  await page.click('#modalSaveBtn');
  await expect(page.locator('#message .toast.success, #message .toast')).toBeVisible({ timeout: 8000 });

  // Verifica lato DB
  await expect
    .poll(async () => await readCassa(request), { timeout: 8000 })
    .toMatchObject({
      tipo_stampante: 'BRIDGE_NATIVE',
      bridge_printer_type: 'USB',
      bridge_topic: CASSA_ID,
      nome_indirizzo: 'POS-80C',
    });

  // --- Riapri in modifica: i campi si ripopolano ---
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.click(`tr:has-text("${CASSA_ID}") [aria-label="Modifica stampante"]`);
  await expect(page.locator('#modalTipoStampante')).toHaveValue('BRIDGE_NATIVE');
  await expect(page.locator('#modalBridgeTopic')).toHaveValue(CASSA_ID);
  await expect(page.locator('#modalBridgePrinterType')).toHaveValue('USB');
  await expect
    .poll(async () => {
      const sel = await page.locator('#modalBridgePrinter').inputValue().catch(() => '');
      const man = await page.locator('#modalBridgePrinterManual').inputValue().catch(() => '');
      return sel === 'POS-80C' || man === 'POS-80C';
    }, { timeout: 5000 })
    .toBe(true);
});
