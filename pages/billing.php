<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/billing.css">
    <!-- Importa tutte le favicon con una sola riga -->
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <title>Billing</title>
</head>

<body class="billing-page sidebar-page">

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="pos-main-panel">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div id="app">
        <main class="billing-shell" :class="{ 'product-picker-open': isProductPickerOpen }">
            <section class="billing-panel products-panel" id="products" aria-label="Catalogo prodotti"
                :class="{ 'is-open': isProductPickerOpen, 'is-closing': isProductPickerClosing }"
                :aria-hidden="isProductPickerMobile() && !isProductPickerOpen ? 'true' : 'false'"
                @touchstart="startProductPickerSwipe" @touchend="endProductPickerSwipe">
                <header class="products-panel__header">
                    <h2 class="products-panel__title">Prodotti</h2>
                    <button type="button" class="modal-close" aria-label="Chiudi catalogo prodotti"
                        title="Chiudi catalogo prodotti" @click="closeProductPicker">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                    <div class="products-tools">
                        <select id="category-view-mode" class="products-tools__select"
                            aria-label="Filtra categorie prodotti" v-model="categoryFilterMode">
                            <option value="all">Tutte</option>
                            <option value="cucina">Solo Cucina</option>
                            <option value="bar">Solo Bar</option>
                            <option value="custom">Selezione categorie</option>
                        </select>
                    </div>
                </header>
                <div class="products-scroll">
                    <div id="category-custom-tools" class="category-custom-tools"
                        :hidden="categoryFilterMode !== 'custom'">
                        <div class="category-custom-tools__title">Categorie visibili</div>
                        <div id="category-checklist" class="category-checklist">
                            <label v-for="categoryName in allProductCategories" :key="categoryName"
                                class="category-checklist__item">
                                <input type="checkbox" :value="categoryName" v-model="selectedCustomCategories">
                                <span>{{ categoryName }}</span>
                            </label>
                            <div v-if="allProductCategories.length === 0" class="category-checklist__empty">Nessuna
                                categoria disponibile.</div>
                        </div>
                    </div>
                    <div id="product-list">
                        <template v-if="products.length === 0">
                            <div class="empty-state">Nessun prodotto disponibile.</div>
                        </template>
                        <template v-else v-for="([categoryName, categoryProducts]) in groupedProducts"
                            :key="categoryName">
                            <section class="category-block" :data-category="categoryName"
                                :class="{ 'filtered-out': !isCategoryVisible(categoryName), 'is-collapsed': isCategoryCollapsed(categoryName) }">
                                <button type="button" class="category-toggle"
                                    :style='{ "font-variation-settings": "\"opsz\" 14", "font-weight": "700" }'
                                    :aria-expanded="isCategoryCollapsed(categoryName) ? 'false' : 'true'"
                                    @click="toggleCategoryCollapse(categoryName)">
                                    <span class="category-title">{{ categoryName }}</span>
                                    <svg class="category-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="6 9 12 15 18 9"></polyline>
                                    </svg>
                                </button>
                                <div class="product-grid">
                                    <div v-for="product in categoryProducts" :key="product.id" class="card"
                                        :class="{ 'is-out-of-stock': isProductOutOfStock(product) }"
                                        @click="addProductToCart(product)">
                                        <img :src="'../' + (product.image_path || '')" :alt="product.name">
                                        <div v-if="isProductOutOfStock(product)" class="out-of-stock-badge">ESAURITO
                                        </div>
                                        <div class="product-name">{{ product.name }}</div>
                                        <div class="product-price">€ {{ Number(product.price || 0).toFixed(2) }}</div>
                                    </div>
                                </div>
                            </section>
                        </template>
                    </div>
                </div>
            </section>

            <aside class="billing-panel cart-panel" id="bill" aria-label="Carrello e checkout">
                <header class="cart-head">
                    <h2 class="cart-title">Carrello</h2>
                    <button id="clear-cart-btn" class="danger-btn icon-btn cart-head__clear" type="button"
                        title="Svuota carrello" aria-label="Svuota carrello" @click="clearCart">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 6h18"></path>
                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                            <path d="M10 11v6"></path>
                            <path d="M14 11v6"></path>
                        </svg>
                    </button>
                </header>

                <section class="cart-items">
                    <table v-if="billItems.length > 0" class="bill-table">
                        <thead>
                            <tr>
                                <th>Articolo</th>
                                <th>Quantità</th>
                                <th>Prezzo</th>
                                <th>Totale</th>
                                <th>Sconto</th>
                            </tr>
                        </thead>
                        <tbody id="bill-body">
                            <tr v-for="item in billItems" :key="item.id">
                                <td :class="bill-item-cell">{{ item.name }}</td>
                                <td>
                                    <div class="qty-control" role="group" aria-label="Controllo quantità">
                                        <button type="button" class="qty-step-btn"
                                            :class="{ 'qty-step-btn--remove': item.qty === 1 }"
                                            :title="item.qty === 1 ? 'Rimuovi articolo' : 'Diminuisci quantità'"
                                            :aria-label="item.qty === 1 ? 'Rimuovi articolo' : 'Diminuisci quantità'"
                                            @pointerdown="startStepHold(item, -1, 'quantity')" @pointerup="stopStepHold" @pointerleave="stopStepHold" @pointercancel="stopStepHold" @click="handleStepClick(item, -1, 'quantity')">
                                            <svg v-if="item.qty === 1" viewBox="0 0 24 24" width="16" height="16"
                                                fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M3 6h18"></path>
                                                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                                <path d="M10 11v6"></path>
                                                <path d="M14 11v6"></path>
                                            </svg>
                                            <template v-else>-</template>
                                        </button>
                                        <input type="text" inputmode="numeric" class="qty-value"
                                            :value="getQtyInputValue(item)"
                                            @input="onQtyInput(item.id, $event.target.value)"
                                            @blur="commitQtyInput(item.id)"
                                            @keydown.enter.prevent="commitQtyInput(item.id, $event)">
                                        <button type="button" class="qty-step-btn" title="Aumenta quantità"
                                            aria-label="Aumenta quantità" @pointerdown="startStepHold(item, 1, 'quantity')" @pointerup="stopStepHold" @pointerleave="stopStepHold" @pointercancel="stopStepHold" @click="handleStepClick(item, 1, 'quantity')">+</button>
                                    </div>
                                </td>
                                <td class="bill-price-cell">€ {{ Number(item.price || 0).toFixed(2) }}</td>
                                <td class="bill-total-cell">
                                    <div class="bill-line-total">€ {{ getLineTotalAfterDiscount(item).toFixed(2) }}
                                    </div>
                                    <div v-if="getLineDiscountPercent(item) > 0" class="bill-line-discount-note">-€ {{
                                        getLineDiscountValue(item).toFixed(2) }} su € {{
                                        getLineSubtotal(item).toFixed(2) }}</div>
                                </td>
                                <td>
                                    <div class="bill-line-meta">
                                        <button type="button" class="line-discount-trigger"
                                            :data-line-discount-item="String(item.id)"
                                            :title="getLineDiscountPercent(item) > 0 ? 'Sconto riga: ' + getLineDiscountPercent(item) + '%' : 'Imposta sconto riga'"
                                            :aria-label="getLineDiscountPercent(item) > 0 ? 'Sconto riga: ' + getLineDiscountPercent(item) + '%' : 'Imposta sconto riga'"
                                            @click="openLineDiscountPopover(item, $event)">
                                            <template v-if="getLineDiscountPercent(item) > 0">
                                                Sct {{ getLineDiscountPercent(item) + '%' }}
                                            </template>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2.5"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="line-discount-trigger__icon" aria-hidden="true">
                                                <line x1="19" x2="5" y1="5" y2="19"></line>
                                                <circle cx="6.5" cy="6.5" r="2.5"></circle>
                                                <circle cx="17.5" cy="17.5" r="2.5"></circle>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else id="empty-cart" class="empty-cart show">
                        <svg class="empty-cart__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" aria-hidden="true">
                            <g style="isolation:isolate">
                                <g id="Livello_1">
                                    <path class="empty-cart__outline" d="M4.78 11.02h4.06c1.56 0 2.73.94 3.02 2.15l5.36 28.25a4.81 4.81 0 0 0 4.69 3.71h24.46" style="stroke-miterlimit:10;fill:none;stroke:currentColor;stroke-linecap:round;stroke-width:5px"/>
                                    <circle class="empty-cart__wheel" cx="22.29" cy="55.11" r="4.64" fill="currentColor"/>
                                    <circle class="empty-cart__wheel" cx="43.91" cy="55.11" r="4.64" fill="currentColor"/>
                                    <path class="empty-cart__cross" d="M34.66 19.68v12.07m5.76-6.04H28.87" style="fill:none;stroke:currentColor;stroke-linecap:round;stroke-width:5px;stroke-linejoin:round"/>
                                    <path class="empty-cart__panel" d="M14.39 13.17h39.16c2.3 0 4.48 1.08 3.51 4.46l-2.94 16.01c-.55 2.53-2.86 4.4-5.54 4.4H19.12z" style="opacity:.4;fill:currentColor"/>
                                </g>
                            </g>
                        </svg>
                        <span class="empty-cart__label">Carrello vuoto</span>
                    </div>
                </section>

                <button type="button" class="cart-add-btn ghost-btn" aria-label="Aggiungi prodotto"
                    @click="openProductPicker">+ Aggiungi</button>

                <section class="cart-totals" id="billing-summary">
                    <div v-if="hasAnyDiscountApplied" class="summary-grid__row">
                        <span class="summary-grid__label">Subtotale</span>
                        <span class="summary-grid__value">{{ formatCurrency(subtotalBeforeDiscount) }}</span>
                    </div>
                    <div class="total-row">
                        <span class="total-label">TOTALE:</span>
                        <span id="grand-total" class="total-value">{{ formatCurrency(grandTotal) }}</span>
                    </div>
                    <div class="summary-grid">
                        <div class="mobile-summary-control">
                            <button type="button" class="mobile-summary-toggle"
                                :class="{ 'is-open': mobileDiscountOpen }"
                                :aria-expanded="mobileDiscountOpen ? 'true' : 'false'"
                                aria-controls="mobile-discount-panel"
                                aria-label="Mostra sconto totale" title="Sconto totale"
                                @click="toggleMobileSummaryPanel('discount')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <line x1="19" y1="5" x2="5" y2="19"></line>
                                    <circle cx="6.5" cy="6.5" r="2.5"></circle>
                                    <circle cx="17.5" cy="17.5" r="2.5"></circle>
                                </svg>
                                <span>Sconto</span>
                            </button>
                            <div id="mobile-discount-panel" class="mobile-summary-panel"
                                :class="{ 'is-open': mobileDiscountOpen }">
                                <div class="field-row">
                                    <div class="discount-label-wrap">
                                        <label for="discount" class="field-label">Sconto Totale</label>
                                        <button type="button" id="edit-discount-presets-btn" class="edit-presets-btn"
                                            title="Modifica preset sconto" aria-label="Modifica preset sconto"
                                            @click="editDiscountPresets">✎</button>
                                    </div>
                                    <button v-for="preset in discountPresetPercents" :key="preset" type="button"
                                        class="discount-preset" @click="applyDiscountPreset(preset)">{{
                                        formatPercentValue(preset) }}%</button>
                                    <input type="number" id="discount" min="0" step="0.01" class="field-input"
                                        placeholder="€ 0.00" v-model.number="discount">
                                </div>
                            </div>
                        </div>
                        <div class="mobile-summary-control">
                            <button type="button" class="mobile-summary-toggle"
                                :class="{ 'is-open': mobilePaidOpen }"
                                :aria-expanded="mobilePaidOpen ? 'true' : 'false'"
                                aria-controls="mobile-paid-panel"
                                aria-label="Mostra importo pagato" title="Importo pagato"
                                @click="toggleMobileSummaryPanel('paid')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                    <path d="M7 9h10M7 13h4"></path>
                                    <circle cx="17" cy="14" r="1"></circle>
                                </svg>
                                <span>Pagato</span>
                            </button>
                            <div id="mobile-paid-panel" class="mobile-summary-panel"
                                :class="{ 'is-open': mobilePaidOpen }">
                                <div class="field-row">
                                    <label for="amount-paid" class="field-label">Importo pagato</label>
                                    <button v-for="amount in [5,10,20,50,100]" :key="amount" type="button"
                                        class="amount paid-preset" @click="addPaidAmount(amount)">{{ amount }}€</button>
                                    <input type="number" id="amount-paid" min="0" step="0.01" class="field-input"
                                        placeholder="€ 0.00" v-model.number="amountPaid">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="change-due" :class="changeClass">{{ changeMessage }}</div>
                </section>

                <section class="cart-payment">
                    <span class="field-label">Metodo Pagamento</span>
                    <div class="pay-group" role="radiogroup" aria-label="Metodo pagamento">
                        <label v-if="paymentMethods.contanti" class="pay-choice">
                            <input type="radio" id="pagamento_contanti" name="metodoPagamento" value="contanti"
                                autocomplete="off" v-model="paymentMethod">
                            <span class="pay-pill">
                                <svg class="pay-pill__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" aria-hidden="true">
                                    <rect width="20" height="12" x="2" y="6" rx="2"></rect>
                                    <circle cx="12" cy="12" r="2"></circle>
                                    <path d="M6 12h.01M18 12h.01"></path>
                                </svg>
                                <span class="pay-pill__text">Contanti</span>
                            </span>
                        </label>
                        <label v-if="paymentMethods.carta" class="pay-choice">
                            <input type="radio" id="pagamento_carta" name="metodoPagamento" value="carta"
                                autocomplete="off" v-model="paymentMethod">
                            <span class="pay-pill">
                                <svg class="pay-pill__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" aria-hidden="true">
                                    <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                                    <line x1="2" y1="10" x2="22" y2="10"></line>
                                    <line x1="6" y1="15" x2="10" y2="15"></line>
                                </svg>
                                <span class="pay-pill__text">Carta</span>
                            </span>
                        </label>
                        <label v-if="paymentMethods.satispay" class="pay-choice">
                            <input type="radio" id="pagamento_elettronico" name="metodoPagamento" value="elettronico"
                                autocomplete="off" v-model="paymentMethod">
                            <span class="pay-pill">
                                <svg class="pay-pill__icon pay-pill__icon--satispay" xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 358.62668 370.25333" aria-hidden="true">
                                    <path fill="currentColor"
                                        d="M89.742 332.459h85.442l135.021-123.251c6.752-6.163 10.624-14.941 10.624-24.083 0-9.143-3.872-17.92-10.624-24.084L175.184 37.79H89.742c-3.284 0-5.288 2.145-6.066 4.153-.78 2.009-.747 4.941 1.678 7.157l144.633 132.005c1.121 1.024 1.765 2.481 1.765 4.001 0 1.519-.644 2.976-1.765 4L85.354 321.112c-2.425 2.215-2.458 5.149-1.678 7.156.778 2.008 2.782 4.153 6.066 4.153" />
                                    <path fill="currentColor"
                                        d="M115.75 99.583 48.42 161.045c-6.751 6.164-10.625 14.941-10.625 24.084 0 9.141 3.874 17.92 10.625 24.082l67.33 61.46 51.11-46.655-38.221-34.888c-1.122-1.024-1.766-2.48-1.766-4 0-1.52.644-2.977 1.766-4.001l38.221-34.888-51.11-46.656" />
                                    <path fill="currentColor"
                                        d="M268.885 37.79h-68.793l42.785 39.063 30.393-27.744c2.425-2.216 2.459-5.149 1.68-7.157-.78-2.008-2.782-4.153-6.065-4.153M242.877 293.439l-42.785 39.02h68.793c3.283 0 5.285-2.145 6.065-4.153.779-2.007.745-4.941-1.68-7.156l-30.393-27.711" />
                                </svg>
                                <span class="pay-pill__text">Satispay</span>
                            </span>
                        </label>
                    </div>
                </section>

                <section class="cart-actions">
                    <button id="open-drawer-btn" class="neutral-btn drawer-btn" type="button"
                        @click="openDrawer">Apri Cassetto</button>
                    <button id="checkout-btn" class="primary-btn" type="button" @click="checkout">
                        <span>Stampa Scontrino</span>
                        <svg class="checkout-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24"
                            height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path d="M6 9V4h12v5" />
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                            <path d="M18 14H6v7l3-1.5 3 1.5 3-1.5 3 1.5z" />
                            <line x1="9" y1="17" x2="15" y2="17" stroke-width="1.5" />
                            <circle cx="18" cy="11" r="0.5" fill="currentColor" />
                        </svg>
                    </button>
                    <button id="print-last-receipt-btn" class="neutral-btn icon-btn print-last-btn" type="button"
                        title="Ristampa ultimo scontrino" aria-label="Ristampa ultimo scontrino"
                        @click="printLastReceipt">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <polyline points="6 9 6 2 18 2 18 9"></polyline>
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                            <rect x="6" y="14" width="12" height="8"></rect>
                        </svg>
                    </button>
                </section>

                <div v-if="lineDiscountPopover.visible" class="line-discount-popover"
                    :style="{ top: lineDiscountPopover.top + 'px', left: lineDiscountPopover.left + 'px' }">
                    <div class="line-discount-popover__title">Sconto riga</div>
                    <div class="line-discount-popover__actions">
                        <button v-for="preset in discountPresetPercents" :key="preset" type="button"
                            class="line-discount-choice" @click="applyLineDiscount(preset)">{{
                            formatPercentValue(preset) }}%</button>
                        <button type="button" class="line-discount-choice" @click="applyLineDiscount(100)">100%</button>
                        <button type="button" class="line-discount-choice line-discount-choice--custom"
                            @click="applyCustomLineDiscount">Personale</button>
                        <button type="button" class="line-discount-choice line-discount-choice--zero"
                            @click="applyLineDiscount(0)">Azzera</button>
                    </div>
                </div>
            </aside>
        </main>

        <div id="message"></div>
    </div>
    </div>

    <script src="../assets/js/vue.global.js"></script>
    <script src="../assets/js/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme.js"></script>
    <script src="../assets/js/qz-tray.js"></script>
    <script src="../assets/js/qz-helper.js"></script>
    <script>
        const DEFAULT_DISCOUNT_PRESETS = [5, 10, 15];
        let qzScriptLoadPromise = null;
        let qzConnectionTarget = null;
        let qzLoadedFromHost = null;
        let lastQzHost = null;

        function normalizeJsonResponse(response) {
            if (response && typeof response === 'object') {
                return response;
            }
            if (typeof response === 'string') {
                try {
                    return JSON.parse(response);
                } catch (e) {
                    return null;
                }
            }
            return null;
        }

        function normalizeQzHost(host) {
            return String(host || '')
                .trim()
                .replace(/^https?:\/\//i, '')
                .replace(/\/$/, '');
        }

        function loadScript(url) {
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = url + (url.includes('?') ? '&' : '?') + '_t=' + Date.now();
                script.async = true;
                script.onload = () => resolve(url);
                script.onerror = () => reject(new Error('Load failed: ' + url));
                document.head.appendChild(script);
            });
        }

        async function ensureQzLibraryLoaded(qzHost = null) {
            if (window.qz) {
                return;
            }

            if (!qzScriptLoadPromise) {
                qzScriptLoadPromise = (async () => {
                    const candidates = [
                        '../assets/js/qz-tray.js',
                        '/opensagra/assets/js/qz-tray.js',
                        '/assets/js/qz-tray.js'
                    ];
                    const errors = [];
                    for (const url of candidates) {
                        try {
                            await loadScript(url);
                            if (window.qz) {
                                if (qzHost) {
                                    qzLoadedFromHost = normalizeQzHost(qzHost);
                                }
                                return;
                            }
                        } catch (err) {
                            errors.push(url + ' (' + err.message + ')');
                        }
                    }
                    throw new Error('QZ script non raggiungibile. Tentativi: ' + errors.join(' | '));
                })();
            }

            await qzScriptLoadPromise;

            if (!window.qz || !window.qz.websocket) {
                throw new Error('QZ Tray non disponibile nel browser.');
            }
        }
        
        // Firma certificati messaggi                                      
        document.addEventListener("DOMContentLoaded", function () {
            setupQzSecurity();
        });

        async function ensureQzConnected(host, port = 8182) {
            const cleanHost = normalizeQzHost(host);
            if (!cleanHost) {
                throw new Error('Host QZ mancante nella configurazione stampante.');
            }

            let connectHost = cleanHost;
         /*   const clientHostname = window.location.hostname;

            if (clientHostname === cleanHost) {
                connectHost = 'localhost';
            }*/

            if (lastQzHost !== connectHost) {
                if (qz && qz.websocket && qz.websocket.isActive && qz.websocket.isActive()) {
                    try {
                        await qz.websocket.disconnect();
                    } catch (err) {
                        console.warn('Disconnect precedente fallito:', err);
                    }
                }

                window.qz = null;
                qzScriptLoadPromise = null;
                qzConnectionTarget = null;
                qzLoadedFromHost = null;
                lastQzHost = connectHost;
            }

            await ensureQzLibraryLoaded(connectHost);
            setupQzSecurity();

            const targetKey = connectHost + ':' + Number(port || 8182);
            if (qz.websocket.isActive && qz.websocket.isActive() && qzConnectionTarget === targetKey) {
                return;
            }

            if (qz.websocket.isActive && qz.websocket.isActive()) {
                try {
                    await qz.websocket.disconnect();
                } catch (err) {
                    console.warn('Disconnessione precedente fallita:', err);
                }
            }

            try {
                await qz.websocket.connect({
                    host: connectHost,
                    usingSecure: false,
                    port: {
                        secure: [],
                        insecure: [Number(port || 8182)]
                    }
                });
                qzConnectionTarget = targetKey;
            } catch (connectErr) {
                throw new Error('Impossibile connettersi a QZ Tray su ' + connectHost + ':' + port + '. Errore: ' + (connectErr.message || connectErr));
            }
        }

        async function printBridgeViaQz(response) {
            const payload = normalizeJsonResponse(response);
            if (!payload) {
                throw new Error('Risposta bridge non valida.');
            }

            const printerName = String(payload.qz_printer_name || payload.printer || '').trim();
            const qzHost = String(payload.qz_host || payload.bridge_host || payload.host || '').trim();
            const rawBase64 = String(payload.qz_data_base64 || '').trim();

            if (!printerName || !qzHost || !rawBase64) {
                throw new Error('Configurazione stampante incompleta.');
            }

            await ensureQzConnected(qzHost);

            const config = qz.configs.create(printerName, { encoding: 'ISO-8859-1' });
            const data = ['\x1B' + '\x40', '\x1B' + '\x61' + '\x31', '\x1B' + '\x61' + '\x30'];

            try {
                data.push(atob(rawBase64));
            } catch (err) {
                throw new Error('Decodifica base64 fallita.');
            }

            await qz.print(config, data);
        }
        // Invia i byte ESC/POS in Base64 all'app RawBT tramite il suo URI scheme dedicato
        function inviaBase64ARawBT(base64Data) {
            window.location.href = 'rawbt:base64,' + base64Data;
        }

        const billingApp = Vue.createApp({
            data() {
                return {
                    billItems: [],
                    products: [],
                    allProductCategories: [],
                    selectedCustomCategories: [],
                    categoryFilterMode: 'all',
                    categoryCollapseState: {},
                    discount: 0,
                    amountPaid: 0,
                    customer: '',
                    paymentMethod: '',
                    paymentMethods: {
                        contanti: true,
                        carta: false,
                        satispay: false
                    },
                    currentCassaId: '',
                    discountPresetPercents: DEFAULT_DISCOUNT_PRESETS.slice(),
                    qtyInputById: {},
                    mobileDiscountOpen: false,
                    mobilePaidOpen: false,
                    isProductPickerOpen: false,
                    isProductPickerClosing: false,
                    productPickerSwipeStartY: null,
                    _productPickerCloseTimer: null,
                    lineDiscountPopover: {
                        visible: false,
                        itemId: null,
                        currentPercent: 0,
                        top: 0,
                        left: 0
                    },
                    _documentClickHandler: null,
                    _popoverRepositionHandler: null,
                    _productPickerKeydownHandler: null,
                    _productPickerMql: null,
                    _productPickerMqlHandler: null,
                    _productPickerResizeFallbackHandler: null,
                    isMobileView: false
                };
            },
            computed: {
                groupedProducts() {
                    const grouped = new Map();
                    this.products.forEach((product) => {
                        const categoryName = String(product.category || 'Senza categoria');
                        if (!grouped.has(categoryName)) {
                            grouped.set(categoryName, []);
                        }
                        grouped.get(categoryName).push(product);
                    });
                    return Array.from(grouped.entries());
                },
                visibleCategories() {
                    if (this.categoryFilterMode === 'custom') {
                        return new Set(this.selectedCustomCategories.filter(Boolean));
                    }

                    if (this.categoryFilterMode === 'all') {
                        return new Set(this.allProductCategories);
                    }

                    return new Set(this.allProductCategories.filter((categoryName) => this.classifyCategoryMacro(categoryName) === this.categoryFilterMode));
                },
                subtotalBeforeDiscount() {
                    return this.billItems.reduce((sum, item) => sum + this.getLineSubtotal(item), 0);
                },
                lineDiscountTotal() {
                    return this.billItems.reduce((sum, item) => sum + this.getLineDiscountValue(item), 0);
                },
                orderDiscountAmount() {
                    return Math.max(this.discount, 0);
                },
                totalDiscountApplied() {
                    return this.lineDiscountTotal + this.orderDiscountAmount;
                },
                hasAnyDiscountApplied() {
                    return this.totalDiscountApplied > 0;
                },
                grandTotal() {
                    return Math.max(this.subtotalBeforeDiscount - this.totalDiscountApplied, 0);
                },
                changeAmount() {
                    if (!Number.isFinite(this.amountPaid)) {
                        return 0;
                    }
                    return this.amountPaid - this.grandTotal;
                },
                changeMessage() {
                    if (!this.amountPaid) {
                        return '';
                    }
                    return this.changeAmount >= 0 ? 'Resto: € ' + this.changeAmount.toFixed(2) : 'Importo insufficiente';
                },
                changeClass() {
                    if (!this.amountPaid) {
                        return '';
                    }
                    return this.changeAmount >= 0 ? 'positive' : 'negative';
                }
            },
            methods: {
                formatCurrency(value) {
                    return '€ ' + Number(value || 0).toFixed(2);
                },
                normalizeDiscountPresetValues(values) {
                    if (!Array.isArray(values)) {
                        return DEFAULT_DISCOUNT_PRESETS.slice();
                    }

                    const normalized = values
                        .map((value) => parseFloat(value))
                        .filter((value) => Number.isFinite(value) && value > 0)
                        .map((value) => Math.min(value, 100));

                    while (normalized.length < 3) {
                        normalized.push(DEFAULT_DISCOUNT_PRESETS[normalized.length]);
                    }

                    return normalized.slice(0, 3);
                },
                loadDiscountPresetValues() {
                    try {
                        const rawValue = localStorage.getItem(this.getDiscountPresetPreferenceKey());
                        if (!rawValue) {
                            return DEFAULT_DISCOUNT_PRESETS.slice();
                        }

                        const parsed = JSON.parse(rawValue);
                        return this.normalizeDiscountPresetValues(parsed);
                    } catch (err) {
                        console.warn('Impossibile leggere preset sconto:', err);
                        return DEFAULT_DISCOUNT_PRESETS.slice();
                    }
                },
                saveDiscountPresetValues(values) {
                    try {
                        localStorage.setItem(this.getDiscountPresetPreferenceKey(), JSON.stringify(this.normalizeDiscountPresetValues(values)));
                    } catch (err) {
                        console.warn('Impossibile salvare preset sconto:', err);
                    }
                },
                formatPercentValue(value) {
                    return Number.isInteger(value) ? String(value) : value.toFixed(1);
                },
                getCategoryPreferenceKey() {
                    const cassa = this.currentCassaId || 'ND';
                    return 'billing_category_filter_' + cassa;
                },
                getDiscountPresetPreferenceKey() {
                    const cassa = this.currentCassaId || 'ND';
                    return 'billing_discount_preset_' + cassa;
                },
                loadCategoryPreference() {
                    try {
                        const rawValue = localStorage.getItem(this.getCategoryPreferenceKey());
                        if (!rawValue) {
                            return { mode: 'all', categories: [] };
                        }

                        if (rawValue[0] !== '{') {
                            return { mode: rawValue ? 'custom' : 'all', categories: rawValue ? [rawValue] : [] };
                        }

                        return JSON.parse(rawValue);
                    } catch (err) {
                        return { mode: 'all', categories: [] };
                    }
                },
                saveCategoryPreference() {
                    try {
                        const state = { mode: this.categoryFilterMode, categories: this.selectedCustomCategories };
                        localStorage.setItem(this.getCategoryPreferenceKey(), JSON.stringify(state));
                    } catch (err) {
                        console.warn('Impossibile salvare preferenza categoria:', err);
                    }
                },
                classifyCategoryMacro(categoryName) {
                    const normalized = String(categoryName || '')
                        .toLowerCase()
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .trim();
                    const barKeywords = ['analcolici', 'alcolici'];
                    const cucinaKeywords = ['antipasti', 'primi', 'secondi', 'dolce', 'dolci', 'dessert'];

                    if (barKeywords.some((keyword) => normalized.includes(keyword))) {
                        return 'bar';
                    }

                    if (cucinaKeywords.some((keyword) => normalized.includes(keyword))) {
                        return 'cucina';
                    }

                    return 'unknown';
                },
                isCategoryVisible(categoryName) {
                    if (this.categoryFilterMode === 'all') {
                        return true;
                    }

                    if (this.categoryFilterMode === 'custom') {
                        return this.selectedCustomCategories.includes(categoryName);
                    }

                    return this.classifyCategoryMacro(categoryName) === this.categoryFilterMode;
                },
                isCategoryCollapsed(categoryName) {
                    return Boolean(this.categoryCollapseState[categoryName]);
                },
                toggleCategoryCollapse(categoryName) {
                    this.categoryCollapseState[categoryName] = !this.isCategoryCollapsed(categoryName);
                },
                getLineDiscountPercent(item) {
                    const percent = parseFloat(item && item.line_discount_percent);
                    if (Number.isNaN(percent) || percent < 0) {
                        return 0;
                    }
                    return Math.min(percent, 100);
                },
                getLineSubtotal(item) {
                    return (parseFloat(item.price) || 0) * (parseInt(item.qty, 10) || 0);
                },
                getUnitDiscountValue(item) {
                    const unitPrice = parseFloat(item && item.price) || 0;
                    const percent = this.getLineDiscountPercent(item);
                    return Number((unitPrice * (percent / 100)).toFixed(2));
                },
                getLineDiscountValue(item) {
                    const quantity = parseInt(item && item.qty, 10) || 0;
                    return Number((this.getUnitDiscountValue(item) * quantity).toFixed(2));
                },
                getLineTotalAfterDiscount(item) {
                    const quantity = parseInt(item && item.qty, 10) || 0;
                    const unitPrice = parseFloat(item && item.price) || 0;
                    const discountedUnitPrice = Math.max(unitPrice - this.getUnitDiscountValue(item), 0);
                    return Number((discountedUnitPrice * quantity).toFixed(2));
                },
                isProductOutOfStock(product) {
                    if (product.quantity_available === null || product.quantity_available === '' || product.quantity_available === undefined) {
                        return false;
                    }
                    return Number(product.quantity_available) <= 0;
                },
                showMaxAvailableNotice(available) {
                    this.showToast(`Q.tà massima disponibile: ${available}`, 'info');
                },
                toggleMobileSummaryPanel(panel) {
                    if (panel === 'discount') {
                        this.mobileDiscountOpen = !this.mobileDiscountOpen;
                        return;
                    }
                    this.mobilePaidOpen = !this.mobilePaidOpen;
                },
                isProductPickerMobile() {
                    return this.isMobileView;
                },
                // Prints the live breakpoint/state values so the picker open/close flow can be diagnosed from the device console
                logProductPickerDebug(label) {
                    console.debug('[product-picker]', label, {
                        innerWidth: window.innerWidth,
                        innerHeight: window.innerHeight,
                        mqlMatches: this._productPickerMql ? this._productPickerMql.matches : null,
                        isMobileView: this.isMobileView,
                        isProductPickerOpen: this.isProductPickerOpen,
                        isProductPickerClosing: this.isProductPickerClosing
                    });
                },
                syncProductPickerState() {
                    if (!this.isProductPickerMobile()) {
                        if (this.isProductPickerOpen) {
                            this.logProductPickerDebug('sync: forcing close (not mobile)');
                        }
                        this.isProductPickerOpen = false;
                        this.isProductPickerClosing = false;
                    }
                    document.body.classList.toggle('product-picker-open', this.isProductPickerOpen);
                },

                openProductPicker() {
                  /*  if (!this.isProductPickerMobile()) { //rimosso per problemi di conflitto risoluzioni tablet
                        return;
                    } */
                    this.logProductPickerDebug('openProductPicker: before');
                    if (this._productPickerCloseTimer) {
                        clearTimeout(this._productPickerCloseTimer);
                        this._productPickerCloseTimer = null;
                    }
                    this.isProductPickerClosing = false;
                    this.isProductPickerOpen = true;
                    this.syncProductPickerState();
                    this.logProductPickerDebug('openProductPicker: after');
                    this.$nextTick(() => document.querySelector('#products .modal-close')?.focus());
                },

                closeProductPicker() {
                    if (!this.isProductPickerOpen || this.isProductPickerClosing) {
                        return;
                    }
                    this.isProductPickerClosing = true;
                    this._productPickerCloseTimer = setTimeout(() => {
                        this.isProductPickerOpen = false;
                        this.isProductPickerClosing = false;
                        this._productPickerCloseTimer = null;
                        this.syncProductPickerState();
                        this.$nextTick(() => document.querySelector('.cart-add-btn')?.focus());
                    }, 280);
                },
                startProductPickerSwipe(event) {
                    this.productPickerSwipeStartY = event.touches[0]?.clientY ?? null;
                },
                endProductPickerSwipe(event) {
                    if (this.productPickerSwipeStartY === null) {
                        return;
                    }
                    const endY = event.changedTouches[0]?.clientY ?? this.productPickerSwipeStartY;
                    const distance = endY - this.productPickerSwipeStartY;
                    this.productPickerSwipeStartY = null;
                    if (distance > 70) {
                        this.closeProductPicker();
                    }
                },
                handleProductPickerKeydown(event) {
                    if (event.key === 'Escape' && this.isProductPickerOpen) {
                        event.preventDefault();
                        this.closeProductPicker();
                    }
                },
                addProductToCart(product) {
                    if (this.isProductOutOfStock(product)) {
                        this.showToast('prodotto esaurito', 'error');
                        return;
                    }

                    const available = (product.quantity_available !== null && product.quantity_available !== '' && product.quantity_available !== undefined)
                        ? Number(product.quantity_available)
                        : null;

                    const existingItem = this.billItems.find((item) => String(item.id) === String(product.id));
                    if (existingItem) {
                        if (available !== null && existingItem.qty >= available) {
                            this.showMaxAvailableNotice(available);
                            return;
                        }
                        existingItem.qty += 1;
                        this.qtyInputById[String(existingItem.id)] = String(existingItem.qty);
                    } else {
                        this.billItems.push({
                            id: product.id,
                            name: product.name,
                            price: parseFloat(product.price) || 0,
                            qty: 1,
                            line_discount_percent: 0,
                            quantity_available: available
                        });
                        this.qtyInputById[String(product.id)] = '1';
                    }
                },
                removeItem(itemId) {
                    this.billItems = this.billItems.filter((item) => String(item.id) !== String(itemId));
                    delete this.qtyInputById[String(itemId)];
                },
                getQtyInputValue(item) {
                    const key = String(item.id);
                    if (Object.prototype.hasOwnProperty.call(this.qtyInputById, key)) {
                        return this.qtyInputById[key];
                    }
                    const fallback = String(Math.max(1, parseInt(item.qty, 10) || 1));
                    this.qtyInputById[key] = fallback;
                    return fallback;
                },
                onQtyInput(itemId, rawValue) {
                    const key = String(itemId);
                    this.qtyInputById[key] = String(rawValue || '').replace(/[^0-9]/g, '');
                },
                commitQtyInput(itemId, event = null) {
                    const key = String(itemId);
                    const item = this.billItems.find((currentItem) => String(currentItem.id) === key);
                    if (!item) {
                        return;
                    }

                    const product = this.products.find((p) => String(p.id) === key);
                    const available = (product && product.quantity_available !== null && product.quantity_available !== '' && product.quantity_available !== undefined)
                        ? Number(product.quantity_available)
                        : null;

                    const parsed = parseInt(this.qtyInputById[key], 10);
                    let normalized = Number.isFinite(parsed) && parsed > 0 ? parsed : 1;

                    if (available !== null && normalized > available) {
                        this.showMaxAvailableNotice(available);
                        normalized = available > 0 ? available : 1;
                    }

                    item.qty = normalized;
                    this.qtyInputById[key] = String(normalized);

                    if (event && event.target) {
                        event.target.select();
                    }
                },

                startStepHold(product, amount, type) {
                    this.stopStepHold();
                    this._stepHoldFired = false;
                    this._stepHoldTimer = setTimeout(() => {
                        this._stepHoldFired = true;
                        this.applyStep(product, amount, type);
                        this.triggerStepFeedback();
                        this._stepHoldInterval = setInterval(() => {
                            const applied = this.applyStep(product, amount, type);
                            if (!applied) {
                                this.stopStepHold();
                                return;
                            }
                            this.triggerStepFeedback();
                        }, 100);
                    }, 400);
                },
                stopStepHold() {
                    if (this._stepHoldTimer) {
                        clearTimeout(this._stepHoldTimer);
                        this._stepHoldTimer = null;
                    }
                    if (this._stepHoldInterval) {
                        clearInterval(this._stepHoldInterval);
                        this._stepHoldInterval = null;
                    }
                },
                handleStepClick(product, amount, type) {
                    if (this._stepHoldFired) {
                        this._stepHoldFired = false;
                        return;
                    }
                    this.applyStep(product, amount, type);
                    this.triggerStepFeedback();
                },
                // Applies a +1/-1 quantity step, respecting stock limits; returns whether the qty actually changed
                applyStep(item, amount, type) {
                    if (type !== 'quantity') {
                        return false;
                    }

                    const key = String(item.id);
                    const currentItem = this.billItems.find((billItem) => String(billItem.id) === key);
                    if (!currentItem) {
                        return false;
                    }

                    const currentQty = parseInt(currentItem.qty, 10) || 0;
                    const nextQty = currentQty + amount;

                    if (nextQty <= 0) {
                        this.removeItem(currentItem.id);
                        return true;
                    }

                    if (amount > 0) {
                        const product = this.products.find((p) => String(p.id) === key);
                        const available = (product && product.quantity_available !== null && product.quantity_available !== '' && product.quantity_available !== undefined)
                            ? Number(product.quantity_available)
                            : null;

                        if (available !== null && nextQty > available) {
                            this.showMaxAvailableNotice(available);
                            return false;
                        }
                    }

                    currentItem.qty = nextQty;
                    this.qtyInputById[key] = String(nextQty);
                    return true;
                },
                // Short vibration to confirm a quantity step on touch devices
                triggerStepFeedback() {
                    if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
                        navigator.vibrate(15);
                    }
                },

                increaseQty(itemId) {
                    const item = this.billItems.find((currentItem) => String(currentItem.id) === String(itemId));
                    if (!item) {
                        return;
                    }
                    const product = this.products.find((p) => String(p.id) === String(itemId));
                    const available = (product && product.quantity_available !== null && product.quantity_available !== '' && product.quantity_available !== undefined)
                        ? Number(product.quantity_available)
                        : null;

                    if (available !== null && item.qty >= available) {
                        this.showMaxAvailableNotice(available);
                        return;
                    }

                    item.qty = Math.max(1, (parseInt(item.qty, 10) || 0) + 1);
                    this.qtyInputById[String(item.id)] = String(item.qty);
                },
                decreaseQty(itemId) {
                    const item = this.billItems.find((currentItem) => String(currentItem.id) === String(itemId));
                    if (!item) {
                        return;
                    }
                    const currentQty = parseInt(item.qty, 10) || 0;
                    if (currentQty <= 1) {
                        this.removeItem(itemId);
                        return;
                    }
                    item.qty = currentQty - 1;
                    this.qtyInputById[String(item.id)] = String(item.qty);
                },
                openLineDiscountPopover(item, event) {
                    event.stopPropagation();

                    const rect = event.currentTarget.getBoundingClientRect();
                    this.lineDiscountPopover = {
                        visible: true,
                        itemId: item.id,
                        currentPercent: this.getLineDiscountPercent(item),
                        top: rect.bottom + 6,
                        left: rect.left
                    };

                    this.$nextTick(() => {
                        const popover = document.querySelector('.line-discount-popover');
                        if (!popover) {
                            return;
                        }

                        const margin = 12;
                        const gap = 6;
                        const anchorRect = event.currentTarget.getBoundingClientRect();
                        const popoverRect = popover.getBoundingClientRect();
                        const viewportWidth = window.innerWidth;
                        const viewportHeight = window.innerHeight;

                        let nextLeft = anchorRect.left;
                        if (nextLeft + popoverRect.width + margin > viewportWidth) {
                            nextLeft = anchorRect.right - popoverRect.width;
                        }
                        nextLeft = Math.max(margin, Math.min(nextLeft, viewportWidth - popoverRect.width - margin));

                        let nextTop = anchorRect.bottom + gap;
                        if (nextTop + popoverRect.height + margin > viewportHeight) {
                            nextTop = anchorRect.top - popoverRect.height - gap;
                        }
                        nextTop = Math.max(margin, Math.min(nextTop, viewportHeight - popoverRect.height - margin));

                        this.lineDiscountPopover.top = nextTop;
                        this.lineDiscountPopover.left = nextLeft;
                    });
                },
                repositionLineDiscountPopover() {
                    if (!this.lineDiscountPopover.visible || this.lineDiscountPopover.itemId === null) {
                        return;
                    }

                    const trigger = document.querySelector('[data-line-discount-item="' + String(this.lineDiscountPopover.itemId) + '"]');
                    if (!trigger) {
                        this.closeLineDiscountPopover();
                        return;
                    }

                    const margin = 12;
                    const gap = 6;
                    const rect = trigger.getBoundingClientRect();
                    const popover = document.querySelector('.line-discount-popover');
                    const popoverWidth = popover ? popover.getBoundingClientRect().width : 180;
                    const popoverHeight = popover ? popover.getBoundingClientRect().height : 120;
                    const viewportWidth = window.innerWidth;
                    const viewportHeight = window.innerHeight;

                    let nextLeft = rect.left;
                    if (nextLeft + popoverWidth + margin > viewportWidth) {
                        nextLeft = rect.right - popoverWidth;
                    }
                    nextLeft = Math.max(margin, Math.min(nextLeft, viewportWidth - popoverWidth - margin));

                    let nextTop = rect.bottom + gap;
                    if (nextTop + popoverHeight + margin > viewportHeight) {
                        nextTop = rect.top - popoverHeight - gap;
                    }
                    nextTop = Math.max(margin, Math.min(nextTop, viewportHeight - popoverHeight - margin));

                    this.lineDiscountPopover.top = nextTop;
                    this.lineDiscountPopover.left = nextLeft;
                },
                closeLineDiscountPopover() {
                    this.lineDiscountPopover.visible = false;
                },
                applyLineDiscount(percent) {
                    const item = this.billItems.find((currentItem) => String(currentItem.id) === String(this.lineDiscountPopover.itemId));
                    if (!item) {
                        return;
                    }
                    const numericPercent = parseFloat(percent);
                    if (Number.isNaN(numericPercent) || numericPercent < 0) {
                        this.showToast('Inserisci una percentuale valida tra 0 e 100.', 'error');
                        return;
                    }
                    item.line_discount_percent = Math.min(numericPercent, 100);
                    this.closeLineDiscountPopover();
                },
                applyCustomLineDiscount() {
                    const customValue = prompt('Sconto percentuale sulla riga (0-100)', String(this.lineDiscountPopover.currentPercent || 0));
                    if (customValue !== null) {
                        this.applyLineDiscount(customValue);
                    }
                    this.closeLineDiscountPopover();
                },
                applyDiscountPreset(percent) {
                    const subtotal = this.billItems.reduce((sum, item) => sum + this.getLineTotalAfterDiscount(item), 0);
                    const discountValue = subtotal * (percent / 100);
                    this.discount = Number(discountValue.toFixed(2));
                },
                addPaidAmount(amount) {
                    const current = Number.isFinite(this.amountPaid) ? this.amountPaid : (parseFloat(this.amountPaid) || 0);
                    const increment = parseFloat(amount);
                    if (!Number.isFinite(increment) || increment <= 0) {
                        return;
                    }
                    this.amountPaid = Number((current + increment).toFixed(2));
                },
                async clearCart() {
                    const shouldClear = await window.showConfirm('Sei sicuro di voler svuotare il carrello?', {
                        title: 'Svuota carrello',
                        confirmLabel: 'Svuota',
                        cancelLabel: 'Annulla'
                    });
                    if (!shouldClear) {
                        return;
                    }
                    this.billItems = [];
                    this.discount = 0;
                    this.amountPaid = 0;
                    this.customer = '';
                    this.paymentMethod = '';
                    this.resetPaymentMethodSelection();
                },
                editDiscountPresets() {
                    const nextValues = [];
                    for (let index = 0; index < 3; index += 1) {
                        const currentValue = this.discountPresetPercents[index] ?? DEFAULT_DISCOUNT_PRESETS[index];
                        const input = prompt(`Preset sconto ${index + 1} (%)`, String(currentValue));
                        if (input === null) {
                            return;
                        }

                        const parsed = parseFloat(input);
                        if (!Number.isFinite(parsed) || parsed <= 0) {
                            this.showToast('Inserisci un valore valido maggiore di 0.', 'error');
                            return;
                        }

                        nextValues.push(Math.min(parsed, 100));
                    }

                    this.discountPresetPercents = this.normalizeDiscountPresetValues(nextValues);
                    this.saveDiscountPresetValues(this.discountPresetPercents);
                },
                showToast(message, type = 'info', duration = 3000) {
                    return window.showToast(message, type, { duration });
                },
                async loadProducts() {
                    const endpoints = ['../api/get_products.php'];
                    const parseProducts = (response) => {
                        if (Array.isArray(response)) {
                            return response;
                        }
                        if (response && typeof response === 'object') {
                            if (Array.isArray(response.products)) {
                                return response.products;
                            }
                            return Object.values(response);
                        }
                        if (typeof response === 'string') {
                            return JSON.parse(response);
                        }
                        return [];
                    };

                    const loadFromEndpoint = (index) => {
                        if (index >= endpoints.length) {
                            return;
                        }

                        $.ajax({
                            url: endpoints[index],
                            method: 'GET',
                            dataType: 'json'
                        }).done((data) => {
                            const newProducts = parseProducts(data);
                            this.products = newProducts;
                            this.allProductCategories = Array.from(new Set(this.products.map((product) => String(product.category || 'Senza categoria')).filter(Boolean)));
                            const savedPreference = this.loadCategoryPreference();
                            this.categoryFilterMode = ['all', 'cucina', 'bar', 'custom'].includes(savedPreference.mode) ? savedPreference.mode : 'all';
                            this.selectedCustomCategories = Array.isArray(savedPreference.categories)
                                ? savedPreference.categories.filter((category) => this.allProductCategories.includes(category))
                                : [];
                            if (this.categoryFilterMode === 'custom' && this.selectedCustomCategories.length === 0 && this.allProductCategories.length > 0) {
                                this.selectedCustomCategories = [this.allProductCategories[0]];
                            }
                            this.saveCategoryPreference();
                        }).fail(() => {
                            loadFromEndpoint(index + 1);
                        });
                    };

                    loadFromEndpoint(0);
                },
                async loadPaymentMethods() {
                    const cassaId = this.currentCassaId || localStorage.getItem('cassa_id') || 'ND';
                    try {
                        const config = await $.ajax({
                            url: '../api/stampanti.php',
                            method: 'GET',
                            dataType: 'json',
                            data: { action: 'payment_config', cassa_id: cassaId }
                        });
                        const hasAlternative = Number(config.abilita_carta) === 1 || Number(config.abilita_satispay) === 1;
                        this.paymentMethods = {
                            contanti: Number(config.abilita_contanti) === 1 || !hasAlternative,
                            carta: Number(config.abilita_carta) === 1,
                            satispay: Number(config.abilita_satispay) === 1
                        };
                        this.resetPaymentMethodSelection();
                        if (!config.configured) {
                            window.showToast(
                                `La cassa "${cassaId}" non è associata a nessuna stampante: pagamenti e stampa useranno le impostazioni predefinite.`,
                                'error',
                                {
                                    duration: 0,
                                    action: { label: 'Configura stampanti', href: 'conf_stampanti.php' }
                                }
                            );
                        }
                    } catch (err) {
                        this.paymentMethods = { contanti: true, carta: false, satispay: false };
                        this.resetPaymentMethodSelection();
                        this.showToast('Impossibile caricare i metodi della cassa: attivo Contanti.', 'error');
                    }
                },
                // Keeps `paymentMethod` valid against the currently enabled methods without ever guessing on the
                // cashier's behalf: only auto-picks a value when there's truly nothing to choose (a single method
                // enabled) - otherwise it's cleared and printing stays blocked until one is picked explicitly.
                resetPaymentMethodSelection() {
                    const enabledValues = [];
                    if (this.paymentMethods.contanti) enabledValues.push('contanti');
                    if (this.paymentMethods.carta) enabledValues.push('carta');
                    if (this.paymentMethods.satispay) enabledValues.push('elettronico');

                    if (!enabledValues.includes(this.paymentMethod)) {
                        this.paymentMethod = enabledValues.length === 1 ? enabledValues[0] : '';
                    }
                },
                checkout() {
                    const metodoPag = this.paymentMethod || 'seleziona';
                    if (metodoPag === 'seleziona') {
                        this.showToast('Selezionare il metodo di pagamento', 'error');
                        return;
                    }

                    const methodEnabled = metodoPag === 'contanti'
                        ? this.paymentMethods.contanti
                        : metodoPag === 'carta'
                            ? this.paymentMethods.carta
                            : metodoPag === 'elettronico' && this.paymentMethods.satispay;
                    if (!methodEnabled) {
                        this.showToast('Metodo di pagamento non abilitato per questa cassa.', 'error');
                        return;
                    }

                    const cassa_id = localStorage.getItem('cassa_id');
                    const amountPaid = parseFloat(this.amountPaid) || 0;
                    const grandTotal = this.grandTotal;
                    const change = amountPaid - grandTotal;

                    const itemsToSend = this.billItems.map((item) => ({
                        id: item.id,
                        name: item.name,
                        quantity: item.qty,
                        price: item.price,
                        total: this.getLineTotalAfterDiscount(item),
                        line_discount_percent: this.getLineDiscountPercent(item),
                        line_discount_unit_value: this.getUnitDiscountValue(item),
                        line_discount_value: this.getLineDiscountValue(item),
                        line_total_before_discount: this.getLineSubtotal(item),
                        line_unit_price_after_discount: Math.max((parseFloat(item.price) || 0) - this.getUnitDiscountValue(item), 0),
                        line_total_after_discount: this.getLineTotalAfterDiscount(item)
                    }));
                    const payload = {
                        cassa_id: cassa_id,
                        items: itemsToSend,
                        totale: grandTotal,
                        sconto: this.totalDiscountApplied || 0,
                        pagato: amountPaid,
                        resto: change >  0 ? change : 0,
                        metodoPag: metodoPag
                    };                              
                
                // Invia i dati al server per generare lo scontrino e stampare
                    $.ajax({
                        url: '../print/print_receipt.php',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(payload),
                        dataType: 'json',                        
                    }).done(async(response) => {
                        if (!response || !response.success) {
                            this.showToast('Errore: ' + (response ? response.error:  'Risposta non valida'), 'error');
                            return;
                        }                        

                        // --- SMISTAMENTO METODO DI STAMPA ---
                        switch (response.method) {
                            case 'bluetooth_rawbt':
                                if (response.base64) {
                                    inviaBase64ARawBT(response.base64);
                                } else {
                                    this.showToast('Dati stampa RawBT non disponibili', 'error');
                                } 
                                break;

                            case 'bridge_qz':
                                  // Chiamata alla tua funzione WebSocket / QZ Tray
                                if (typeof printBridgeViaQz === 'function') {
                                    await printBridgeViaQz(response);
                                }
                                break;

                            case 'direct':
                                  // La stampa è già stata eseguita dal server (USB Windows/Linux o Rete)
                                console.log("Scontrino inviato con successo dalla stampante di rete/USB del server.");
                                break;

                            default:
                                console.warn("Metodo di stampa sconosciuto:", response.method);
                                break;
                        }        

                        this.showToast('Vendita registrata e inviata alla stampa!', 'success');

                        // Svuota il carrello dopo il checkout
                        this.billItems = [];
                        this.discount = 0;
                        this.amountPaid = 0;
                        this.customer = '';
                        // Blank unless only one payment method is enabled, in which case there was never a real
                        // choice to make and re-forcing it every order would just be a redundant click.
                        this.paymentMethod = '';
                        this.resetPaymentMethodSelection();


                    }).fail((xhr) => {
                        let errorMessage = 'Errore durante la registrazione della vendita.';
                        try {
                            const err = JSON.parse(xhr.responseText); 
                            if (err && (err.message || err.error)) {
                                errorMsg = err.message || err.error;
                            }
                        } catch (e) {}
                        
                        this.showToast(msg, 'error');
                        console.error("Errore Checkout AJAX:", xhr.responseText);
                    });
                },

                printLastReceipt() {
                    const cassaId = localStorage.getItem('cassa_id');
                    $.get('../print/print_last_receipt.php', { cassa_id: cassaId })
                        .done(async (response) => {
                            try {
                                if (response && response.method === 'bridge_qz') {
                                    await printBridgeViaQz(response);
                                } else if (response && response.method === 'bluetooth_rawbt') {
                                    if (response.base64) {
                                        inviaBase64ARawBT(response.base64);
                                    } else {
                                        throw new Error('Dati stampa RawBT non disponibili');
                                    }
                                }
                            
                                this.showToast('Ultimo scontrino stampato con successo! Metodo: ' + (response && response.method ? response.method : 'sconosciuto'), 'success');
                            } catch (bridgeErr) {
                                this.showToast('Ristampa bridge QZ fallita: ' + bridgeErr.message, 'error');
                            }
                        })
                        .fail(() => this.showToast('Errore durante la stampa dell\'ultimo scontrino.', 'error'));
                },
                openDrawer() {
                    $.post('../api/open_drawer.php')
                        .done(() => this.showToast('Cassetto aperto!', 'success'))
                        .fail(() => this.showToast('Errore apertura cassetto.', 'error'));
                },
                handleGlobalClick(event) {
                    if (!this.lineDiscountPopover || !this.lineDiscountPopover.visible) {
                        return;
                    }
                    const popover = document.querySelector('.line-discount-popover');
                    if (popover && !popover.contains(event.target)) {
                        this.closeLineDiscountPopover();
                    }
                }
            },
            watch: {
                categoryFilterMode() {
                    this.saveCategoryPreference();
                },
                selectedCustomCategories() {
                    this.saveCategoryPreference();
                }
            },
            mounted() {
                this.currentCassaId = localStorage.getItem('cassa_id') || 'ND';
                this.discountPresetPercents = this.loadDiscountPresetValues();
                this._documentClickHandler = (event) => this.handleGlobalClick(event);
                this._popoverRepositionHandler = () => this.repositionLineDiscountPopover();
                this._productPickerKeydownHandler = (event) => this.handleProductPickerKeydown(event);
                document.addEventListener('click', this._documentClickHandler);
                document.addEventListener('keydown', this._productPickerKeydownHandler);
                window.addEventListener('resize', this._popoverRepositionHandler);
                window.addEventListener('scroll', this._popoverRepositionHandler, true);
                this.loadProducts();
                this.loadPaymentMethods();
                this._productPollInterval = setInterval(() => {
                    this.loadProducts();
                }, 3000);
                // matchMedia reacts immediately to viewport/orientation changes, unlike resize + getComputedStyle
                // which can race with layout. Must stay in sync with the CSS breakpoint that switches
                // .billing-shell to a single column (see billing.css): a plain width check would misfire on
                // landscape tablets (e.g. iPad Air is 1180px wide in landscape) that have plenty of room for
                // products+cart side by side, so landscape gets a lower, phone-safe width floor instead.
                this._productPickerMql = window.matchMedia('(max-width: 1000px), (orientation: portrait) and (max-width: 1180px)');
                this._productPickerMqlHandler = (event) => {
                    this.isMobileView = event.matches;
                    this.logProductPickerDebug('mql change');
                    this.syncProductPickerState();
                };
                this.isMobileView = this._productPickerMql.matches;
                if (this._productPickerMql.addEventListener) {
                    this._productPickerMql.addEventListener('change', this._productPickerMqlHandler);
                } else {
                    this._productPickerMql.addListener(this._productPickerMqlHandler);
                }
                // Fallback for WebViews where matchMedia's change event is unreliable (e.g. older Amazon Silk/Chrome WebView)
                this._productPickerResizeFallbackHandler = () => {
                    if (this._productPickerMql && this._productPickerMql.matches !== this.isMobileView) {
                        this.isMobileView = this._productPickerMql.matches;
                        this.logProductPickerDebug('resize fallback: mismatch corrected');
                        this.syncProductPickerState();
                    }
                };
                window.addEventListener('resize', this._productPickerResizeFallbackHandler);
                this.logProductPickerDebug('mounted: initial state');
            },

            beforeUnmount() {
                if (this._productPickerMql && this._productPickerMqlHandler) {
                    if (this._productPickerMql.removeEventListener) {
                        this._productPickerMql.removeEventListener('change', this._productPickerMqlHandler);
                    } else {
                        this._productPickerMql.removeListener(this._productPickerMqlHandler);
                    }
                }
                if (this._productPollInterval) {
                    clearInterval(this._productPollInterval);
                }
                if (this._documentClickHandler) {
                    document.removeEventListener('click', this._documentClickHandler);
                }
                if (this._productPickerKeydownHandler) {
                    document.removeEventListener('keydown', this._productPickerKeydownHandler);
                }
                if (this._productPickerCloseTimer) {
                    clearTimeout(this._productPickerCloseTimer);
                }
                if (this._productPickerResizeFallbackHandler) {
                    window.removeEventListener('resize', this._productPickerResizeFallbackHandler);
                }
                window.removeEventListener('resize', this._popoverRepositionHandler);
                window.removeEventListener('scroll', this._popoverRepositionHandler, true);
                document.body.classList.remove('product-picker-open');
            }
        });

        billingApp.mount('#app');
    </script>
</body>

</html>