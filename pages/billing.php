<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/billing.css">
    <!-- Importa tutte le favicon con una sola riga -->
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <?php require_once __DIR__ . '/../includes/icons.php'; ?>
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
                        <?= pos_icon('x', ['stroke-width' => '2.5']) ?>
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
                                    <?= pos_icon('chevron-down', ['class' => 'category-icon', 'aria-hidden' => 'true']) ?>
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
                                            <?= pos_icon('trash', ['v-if' => 'item.qty === 1', 'width' => '16', 'height' => '16']) ?>
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
                                            <?= pos_icon('percent', ['v-else' => true, 'stroke-width' => '2.5', 'class' => 'line-discount-trigger__icon']) ?>
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
                                <?= pos_icon('percent', ['stroke-width' => '2.5']) ?>
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
                        <div class="mobile-summary-control"
                            v-show="isMobileView || paymentMethod === 'contanti'">
                            <button type="button" class="mobile-summary-toggle"
                                :class="{ 'is-open': mobilePaidOpen }"
                                :aria-expanded="mobilePaidOpen ? 'true' : 'false'"
                                aria-controls="mobile-paid-panel"
                                aria-label="Mostra importo pagato" title="Importo pagato"
                                @click="toggleMobileSummaryPanel('paid')">
                                <?= pos_icon('banknote') ?>
                                <span>Pagato</span>
                            </button>
                            <div id="mobile-paid-panel" class="mobile-summary-panel"
                                :class="{ 'is-open': mobilePaidOpen }">
                                <div class="field-row">
                                    <label for="amount-paid" class="field-label">Importo pagato</label>
                                    <button v-for="amount in [5,10,20,50,100,200,500]" :key="amount" type="button"
                                        class="amount paid-preset" :class="'paid-preset--' + amount"
                                        @click="addPaidAmount(amount)">{{ amount }}€</button>
                                    <button type="button" class="paid-reset" :disabled="!amountPaid"
                                        title="Azzera importo pagato" aria-label="Azzera importo pagato"
                                        @click="resetPaidAmount">Azzera</button>
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
                                <?= pos_icon('cash', ['class' => 'pay-pill__icon']) ?>
                                <span class="pay-pill__text">Contanti</span>
                            </span>
                        </label>
                        <label v-if="paymentMethods.carta" class="pay-choice">
                            <input type="radio" id="pagamento_carta" name="metodoPagamento" value="carta"
                                autocomplete="off" v-model="paymentMethod">
                            <span class="pay-pill">
                                <?= pos_icon('credit-card', ['class' => 'pay-pill__icon']) ?>
                                <span class="pay-pill__text">Carta</span>
                            </span>
                        </label>
                        <label v-if="paymentMethods.satispay" class="pay-choice">
                            <input type="radio" id="pagamento_elettronico" name="metodoPagamento" value="elettronico"
                                autocomplete="off" v-model="paymentMethod">
                            <span class="pay-pill">
                                <?= pos_icon('satispay', ['class' => 'pay-pill__icon pay-pill__icon--satispay']) ?>
                                <span class="pay-pill__text">Satispay</span>
                            </span>
                        </label>
                    </div>
                </section>

                <section class="cart-actions">
                    <button id="open-drawer-btn" class="neutral-btn icon-btn drawer-btn" type="button"
                        title="Apri cassetto" aria-label="Apri cassetto" @click="openDrawer">
                        <?= pos_icon('open-drawer', ['width' => '18', 'height' => '18', 'class' => 'open-drawer', 'aria-hidden' => 'true']) ?>
                    </button>
                    <button id="checkout-btn" class="primary-btn" type="button" 
                        title="Stampa scontrino" aria-label="Stampa scontrino" @click="checkout">
                        <span>Stampa</span>
                        <?= pos_icon('print-receipt', ['width' => '18', 'height' => '18', 'class' => 'checkout-icon', 'aria-hidden' => 'true']) ?>
                    </button>
                    <button id="orders-btn" class="neutral-btn icon-btn" type="button"
                        title="Ordini" aria-label="Ordini" @click="openOrdersPanel">
                        <?= pos_icon('receipt-lines', ['width' => '18', 'height' => '18']) ?>
                    </button>
                    <button id="clear-cart-btn" class="danger-btn icon-btn" type="button"
                        title="Svuota carrello" aria-label="Svuota carrello" @click="clearCart">
                        <?= pos_icon('trash', ['width' => '18', 'height' => '18']) ?>
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

        <transition name="orders-backdrop">
            <div v-if="isOrdersPanelOpen" class="orders-backdrop" @click="closeOrdersPanel"></div>
        </transition>
        <aside class="orders-panel" :class="{ 'is-open': isOrdersPanelOpen, 'is-closing': isOrdersPanelClosing }"
            role="dialog" aria-modal="true" aria-label="Ordini">
            <header class="orders-panel__header">
                <h2 class="orders-panel__title">Ordini</h2>
                <button type="button" class="orders-panel__refresh" @click="loadOrders"
                    title="Aggiorna" aria-label="Aggiorna" :disabled="ordersLoading">
                    <?= pos_icon('refresh-cw', ['width' => '18', 'height' => '18']) ?>
                </button>
                <button type="button" class="modal-close" @click="closeOrdersPanel"
                    title="Chiudi" aria-label="Chiudi">
                    <?= pos_icon('x', ['stroke-width' => '2.5']) ?>
                </button>
            </header>

            <div v-if="!selectedOrder" class="orders-panel__body">
                <div class="orders-toolbar">
                    <input type="search" class="orders-search" v-model.trim="orderSearch"
                        @keyup.enter="loadOrders" placeholder="Cerca per numero ordine…"
                        inputmode="numeric" aria-label="Cerca per numero ordine">
                    <button type="button" class="neutral-btn" @click="loadOrders">Cerca</button>
                </div>

                <p v-if="ordersLoading" class="orders-empty">Caricamento…</p>
                <p v-else-if="ordersError" class="orders-empty">{{ ordersError }}</p>
                <p v-else-if="ordersList.length === 0" class="orders-empty">Nessun ordine.</p>
                <ul v-else class="orders-list">
                    <li v-for="o in ordersList" :key="o.id" class="order-card"
                        :class="{ 'order-card--void': o.stornato }">
                        <div class="order-card__top">
                            <span class="order-card__num">#{{ o.id }}</span>
                            <span class="order-badge"
                                :class="o.stornato ? 'order-badge--void' : 'order-badge--ok'">
                                {{ o.stornato ? 'Stornato' : 'Attivo' }}
                            </span>
                        </div>
                        <div class="order-card__meta">
                            <span>{{ formatOrderDateTime(o.data_ora) }}</span>
                            <span>{{ o.metodo_pagamento || '—' }}</span>
                            <span>{{ o.n_articoli }} art.</span>
                        </div>
                        <div class="order-card__bottom">
                            <strong class="order-card__total">{{ formatCurrency(o.totale) }}</strong>
                            <div class="order-card__actions">
                                <button type="button" class="neutral-btn"
                                    @click="openOrderDetail(o.id)">Dettaglio</button>
                                <button type="button" class="neutral-btn"
                                    :disabled="orderActionBusyId === o.id"
                                    @click="reprintOrder(o)">Ristampa</button>
                                <button type="button" class="danger-btn" v-if="!o.stornato"
                                    :disabled="orderActionBusyId === o.id"
                                    @click="voidOrder(o)">Storna</button>
                            </div>
                        </div>
                    </li>
                </ul>
                <p class="orders-foot">Ultimi {{ ordersLimit }} ordini di questa cassa</p>
            </div>

            <div v-else class="orders-panel__body">
                <button type="button" class="orders-back" @click="closeOrderDetail">
                    <?= pos_icon('chevron-left', ['width' => '16', 'height' => '16']) ?> Ordini
                </button>
                <div class="order-detail" v-if="orderDetailLoading">
                    <p class="orders-empty">Caricamento…</p>
                </div>
                <div class="order-detail" v-else>
                    <div class="order-card__top">
                        <span class="order-card__num">#{{ selectedOrder.ordine.id }}</span>
                        <span class="order-badge"
                            :class="selectedOrder.ordine.stornato ? 'order-badge--void' : 'order-badge--ok'">
                            {{ selectedOrder.ordine.stornato ? 'Stornato' : 'Attivo' }}
                        </span>
                    </div>
                    <div class="order-card__meta">
                        <span>{{ formatOrderDateTime(selectedOrder.ordine.data_ora) }}</span>
                        <span>{{ selectedOrder.ordine.metodo_pagamento || '—' }}</span>
                    </div>
                    <table class="order-detail__table">
                        <thead>
                            <tr><th>Articolo</th><th>Qtà</th><th>Prezzo</th><th>Sconto</th><th>Totale</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(r, i) in selectedOrder.righe" :key="i">
                                <td>{{ r.prodotto }}</td>
                                <td>{{ r.quantita }}</td>
                                <td>{{ formatCurrency(r.prezzo_unitario) }}</td>
                                <td>{{ Number(r.line_discount_value) > 0 ? '-' + formatCurrency(r.line_discount_value) : '—' }}</td>
                                <td>{{ formatCurrency(r.totale) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <dl class="order-detail__totals">
                        <div><dt>Sconto</dt><dd>{{ formatCurrency(selectedOrder.ordine.sconto) }}</dd></div>
                        <div><dt>Totale</dt><dd>{{ formatCurrency(selectedOrder.ordine.totale) }}</dd></div>
                        <div><dt>Pagato</dt><dd>{{ formatCurrency(selectedOrder.ordine.importo_pagato) }}</dd></div>
                        <div><dt>Resto</dt><dd>{{ formatCurrency(selectedOrder.ordine.resto) }}</dd></div>
                    </dl>
                    <div class="order-detail__actions">
                        <button type="button" class="neutral-btn"
                            :disabled="orderActionBusyId === selectedOrder.ordine.id"
                            @click="reprintOrder(selectedOrder.ordine)">Ristampa</button>
                        <button type="button" class="danger-btn" v-if="!selectedOrder.ordine.stornato"
                            :disabled="orderActionBusyId === selectedOrder.ordine.id"
                            @click="voidOrder(selectedOrder.ordine)">Storna</button>
                    </div>
                </div>
            </div>
        </aside>

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
        // Intervallo del polling condizionale prodotti a riposo (Fase 1 del piano di
        // migrazione): sale a backoff (5s/10s/20s/40s, cap 60s) solo sugli errori.
        const PRODUCTS_POLL_NORMAL_MS = 6000;
        // Quando l'EventSource Mercure e' connesso e sano (Fase 4) il polling resta
        // solo come rete di sicurezza: rallenta a un giro al minuto invece di uno
        // ogni 6s. Se l'SSE cade si torna subito a PRODUCTS_POLL_NORMAL_MS.
        const PRODUCTS_POLL_SLOW_MS = 60000;
        // Endpoint dell'hub Mercure: path assoluto (non relativo a /pages/) perche'
        // la route vive alla radice dell'origine, servita dallo stesso Caddy.
        const MERCURE_HUB_PATH = '/.well-known/mercure';
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
                    isOrdersPanelOpen: false,
                    isOrdersPanelClosing: false,
                    ordersList: [],
                    ordersLoading: false,
                    ordersError: '',
                    ordersLimit: 20,
                    orderSearch: '',
                    selectedOrder: null,
                    orderDetailLoading: false,
                    orderActionBusyId: null,
                    _ordersPanelCloseTimer: null,
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
                    // Polling condizionale prodotti (Fase 1 del piano di migrazione):
                    // vedi pollProductsVersion/scheduleProductsPoll/initProductsPolling.
                    _productsPollTimer: null,
                    _productsVisibilityHandler: null,
                    _pollInFlight: false,
                    _lastProductsVersion: null,
                    _lastProductsCount: null,
                    _productsPollBackoffMs: null,
                    // Realtime prodotti via Mercure (Fase 4): l'EventSource spinge
                    // gli aggiornamenti, il polling qui sopra resta come fallback.
                    // Vedi initProductsRealtime/teardownProductsRealtime.
                    _productsSse: null,
                    _productsSseHealthy: false,
                    _productsSseRetryTimer: null,
                    _productsSseRetryMs: null,
                    _categoriesInitialized: false,
                    // Inizializzato subito (non solo in mounted) cosi' la riga "Importo pagato",
                    // che su desktop compare solo con metodo "contanti", non lampeggia al primo paint su mobile.
                    isMobileView: typeof window !== 'undefined' && typeof window.matchMedia === 'function'
                        ? window.matchMedia('(max-width: 1000px), (orientation: portrait) and (max-width: 1180px)').matches
                        : false
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
                    if (event.key !== 'Escape') {
                        return;
                    }
                    if (this.isOrdersPanelOpen) {
                        event.preventDefault();
                        if (this.selectedOrder) {
                            this.closeOrderDetail();
                        } else {
                            this.closeOrdersPanel();
                        }
                        return;
                    }
                    if (this.isProductPickerOpen) {
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
                resetPaidAmount() {
                    this.amountPaid = 0;
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
                // Sostituisce this.products con l'array aggiornato mantenendo, per i prodotti
                // gia' presenti, lo stesso oggetto (Object.assign in place invece di ricrearlo):
                // evita di ricreare inutilmente tutti gli item ad ogni poll quando la maggior
                // parte dei prodotti non e' cambiata (Fase 1 del piano di migrazione).
                mergeProducts(newProducts) {
                    const existingById = new Map(this.products.map((product) => [product.id, product]));
                    const merged = newProducts.map((incoming) => {
                        const existing = existingById.get(incoming.id);
                        if (existing) {
                            Object.assign(existing, incoming);
                            return existing;
                        }
                        return incoming;
                    });
                    this.products.splice(0, this.products.length, ...merged);
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

                    const tryEndpoint = (index) => {
                        if (index >= endpoints.length) {
                            return Promise.reject(new Error('Nessun endpoint prodotti disponibile.'));
                        }
                        return $.ajax({
                            url: endpoints[index],
                            method: 'GET',
                            dataType: 'json'
                        }).then(parseProducts).catch(() => tryEndpoint(index + 1));
                    };

                    const newProducts = await tryEndpoint(0);
                    this.mergeProducts(newProducts);

                    // L'elenco delle categorie disponibili si ricalcola ad ogni caricamento
                    // (un nuovo prodotto puo' introdurre una categoria nuova), ma l'applicazione
                    // della preferenza salvata (categoryFilterMode/selectedCustomCategories) e il
                    // suo ri-salvataggio avvengono una sola volta: altrimenti ogni poll silenzioso
                    // riscriverebbe sopra un cambio di filtro appena fatto dall'utente.
                    this.allProductCategories = Array.from(new Set(this.products.map((product) => String(product.category || 'Senza categoria')).filter(Boolean)));
                    if (!this._categoriesInitialized) {
                        const savedPreference = this.loadCategoryPreference();
                        this.categoryFilterMode = ['all', 'cucina', 'bar', 'custom'].includes(savedPreference.mode) ? savedPreference.mode : 'all';
                        this.selectedCustomCategories = Array.isArray(savedPreference.categories)
                            ? savedPreference.categories.filter((category) => this.allProductCategories.includes(category))
                            : [];
                        if (this.categoryFilterMode === 'custom' && this.selectedCustomCategories.length === 0 && this.allProductCategories.length > 0) {
                            this.selectedCustomCategories = [this.allProductCategories[0]];
                        }
                        this.saveCategoryPreference();
                        this._categoriesInitialized = true;
                    }
                },
                // Interroga solo api/products_version.php (poche decine di byte) e scarica la
                // lista completa via loadProducts() unicamente se qualcosa e' davvero cambiato.
                // Si auto-pianifica da sola (setTimeout ricorsivo, non setInterval): cosi' non
                // parte mai un nuovo giro finche' il precedente non e' finito, niente richieste
                // accodate se la rete e' lenta.
                async pollProductsVersion() {
                    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
                        // Niente richieste a tab nascosto: il listener visibilitychange in
                        // mounted() rilancia un poll immediato al ritorno in primo piano.
                        return;
                    }
                    if (this._pollInFlight) {
                        return;
                    }
                    this._pollInFlight = true;
                    try {
                        const response = await $.ajax({
                            url: '../api/products_version.php',
                            method: 'GET',
                            dataType: 'json',
                            cache: false
                        });
                        await this.syncProductsFromVersion(
                            Number(response && response.version),
                            Number(response && response.count)
                        );
                        this._productsPollBackoffMs = null;
                        this.scheduleProductsPoll(this.productsPollIdleDelay());
                    } catch (err) {
                        console.warn('Poll prodotti fallito, riprovo con backoff:', err);
                        this._productsPollBackoffMs = this._productsPollBackoffMs
                            ? Math.min(this._productsPollBackoffMs * 2, 60000)
                            : 5000;
                        this.scheduleProductsPoll(this._productsPollBackoffMs);
                    } finally {
                        this._pollInFlight = false;
                    }
                },
                scheduleProductsPoll(delayMs) {
                    if (this._productsPollTimer) {
                        clearTimeout(this._productsPollTimer);
                    }
                    this._productsPollTimer = setTimeout(() => {
                        this.pollProductsVersion();
                    }, delayMs);
                },
                // Stabilisce la versione "di partenza" senza dover ripetere subito un
                // loadProducts() (gia' fatto una volta in mounted()), poi avvia il loop.
                async initProductsPolling() {
                    try {
                        const response = await $.ajax({
                            url: '../api/products_version.php',
                            method: 'GET',
                            dataType: 'json',
                            cache: false
                        });
                        this._lastProductsVersion = Number(response && response.version);
                        this._lastProductsCount = Number(response && response.count);
                    } catch (err) {
                        // Se fallisce anche questo, restano null: il primo poll vero rifara'
                        // comunque un loadProducts() (vedi "o al primo giro" in pollProductsVersion).
                        console.warn('Impossibile leggere la versione iniziale prodotti:', err);
                    }
                    this.scheduleProductsPoll(this.productsPollIdleDelay());
                },
                // Ritardo del prossimo giro di polling "a riposo": lento se il
                // realtime Mercure e' connesso e sano (fa lui il lavoro), normale
                // altrimenti. Il backoff sugli errori resta gestito a parte.
                productsPollIdleDelay() {
                    return this._productsSseHealthy ? PRODUCTS_POLL_SLOW_MS : PRODUCTS_POLL_NORMAL_MS;
                },
                // Punto unico che confronta la versione/conteggio prodotti con
                // l'ultima vista e ricarica la lista se e' cambiata. Usato sia dal
                // polling sia dai messaggi Mercure: entrambi consegnano {version,count}.
                async syncProductsFromVersion(version, count) {
                    if (!Number.isFinite(version)) {
                        return;
                    }
                    const changed = this._lastProductsVersion === null
                        || version !== this._lastProductsVersion
                        || count !== this._lastProductsCount;
                    this._lastProductsVersion = version;
                    this._lastProductsCount = count;
                    if (changed) {
                        await this.loadProducts();
                    }
                },
                // Fase 4: prova ad aprire l'EventSource verso l'hub Mercure. Se
                // l'hub non e' configurato (endpoint risponde realtime:false) o la
                // pagina e' servita in HTTP (niente cookie Secure), non fa nulla e
                // resta attivo il solo polling condizionale.
                async initProductsRealtime() {
                    if (typeof window === 'undefined' || typeof window.EventSource === 'undefined') {
                        return;
                    }
                    let info;
                    try {
                        info = await $.ajax({
                            url: '../api/mercure_subscribe_token.php',
                            method: 'GET',
                            dataType: 'json',
                            cache: false
                        });
                    } catch (err) {
                        // 503 = hub non configurato su questa installazione: normale, si resta col polling.
                        return;
                    }
                    if (!info || info.realtime !== true) {
                        return;
                    }

                    this.teardownProductsRealtime();
                    const url = MERCURE_HUB_PATH + '?topic=' + encodeURIComponent(info.topic || 'products');
                    let es;
                    try {
                        es = new EventSource(url, { withCredentials: true });
                    } catch (err) {
                        console.warn('Mercure: impossibile aprire l\'EventSource, resto sul polling:', err);
                        return;
                    }
                    this._productsSse = es;
                    let consecutiveErrors = 0;

                    es.onopen = () => {
                        this._productsSseHealthy = true;
                        this._productsSseRetryMs = null;
                        consecutiveErrors = 0;
                    };
                    // Il primo giro di polling parte da initProductsPolling(); qui
                    // ci limitiamo a NON accelerarlo finche' l'SSE non e' sano.
                    // Non si tocca il timer del polling da dentro onerror (vedi sotto).
                    es.onmessage = (event) => {
                        this._productsSseHealthy = true;
                        consecutiveErrors = 0;
                        let payload = null;
                        try {
                            payload = JSON.parse(event.data);
                        } catch (e) {
                            payload = null;
                        }
                        if (payload) {
                            this.syncProductsFromVersion(Number(payload.version), Number(payload.count));
                        } else {
                            // Messaggio non riconosciuto: ricarico comunque per sicurezza.
                            this.loadProducts();
                        }
                    };
                    es.onerror = () => {
                        // onerror puo' scattare a raffica (l'EventSource ritenta da
                        // solo ~ogni 3s se l'hub e' irraggiungibile). Qui si fa solo
                        // il minimo e NIENTE che tocchi il timer del polling: quel
                        // loop si auto-ripianifica da solo e, con _productsSseHealthy
                        // tornato false, riparte da solo alla cadenza normale (6s).
                        // Toccare il timer qui lo azzererebbe a ogni retry e il
                        // polling non scatterebbe mai - il fallback resterebbe morto.
                        const wasHealthy = this._productsSseHealthy;
                        this._productsSseHealthy = false;

                        // Solo alla transizione sano -> non sano: se il polling era
                        // rallentato a 60s, accorcia l'attesa residua a una normale.
                        if (wasHealthy && this._productsPollTimer) {
                            clearTimeout(this._productsPollTimer);
                            this._productsPollTimer = null;
                            this.scheduleProductsPoll(PRODUCTS_POLL_NORMAL_MS);
                        }

                        consecutiveErrors += 1;
                        // Chiudo e passo al mio backoff quando: (a) errore fatale
                        // (readyState CLOSED, tipicamente token scaduto - l'ES non
                        // ritenta da solo), oppure (b) troppi retry nativi a vuoto
                        // di fila (~ogni 3s): l'hub e' giu' a lungo, meglio i miei
                        // tentativi radi che il martellamento nativo.
                        if (es.readyState === EventSource.CLOSED || consecutiveErrors >= 5) {
                            this.teardownProductsRealtime();
                            this._productsSseRetryMs = this._productsSseRetryMs
                                ? Math.min(this._productsSseRetryMs * 2, 300000)
                                : 10000;
                            this._productsSseRetryTimer = setTimeout(() => {
                                this.initProductsRealtime();
                            }, this._productsSseRetryMs);
                        }
                    };
                },
                teardownProductsRealtime() {
                    if (this._productsSseRetryTimer) {
                        clearTimeout(this._productsSseRetryTimer);
                        this._productsSseRetryTimer = null;
                    }
                    if (this._productsSse) {
                        this._productsSse.onopen = null;
                        this._productsSse.onmessage = null;
                        this._productsSse.onerror = null;
                        this._productsSse.close();
                        this._productsSse = null;
                    }
                    this._productsSseHealthy = false;
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
                                    action: { label: 'Configura stampanti', href: 'conf_casse.php' }
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

                            case 'bridge_native':
                                  // Sostituto di QZ: il server ha pubblicato i byte ESC/POS su un
                                  // topic Mercure, un processo residente sul PC col cavo stampa.
                                  // Il browser non fa nulla. Se il ponte non ha ricevuto, la
                                  // vendita è comunque registrata: si ristampa dallo storico.
                                if (response.published === false) {
                                    this.showToast('Vendita registrata, ma lo scontrino NON è arrivato alla stampante (ponte non raggiungibile). Ristampalo dallo storico.', 'error', 8000);
                                }
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
                                errorMessage = err.message || err.error;
                            }
                        } catch (e) {}

                        this.showToast(errorMessage, 'error');
                        console.error("Errore Checkout AJAX:", xhr.responseText);
                    });
                },

                openOrdersPanel() {
                    if (this._ordersPanelCloseTimer) {
                        clearTimeout(this._ordersPanelCloseTimer);
                        this._ordersPanelCloseTimer = null;
                    }
                    this.isOrdersPanelClosing = false;
                    this.isOrdersPanelOpen = true;
                    this.selectedOrder = null;
                    document.body.classList.add('orders-panel-open');
                    this.$nextTick(() => document.querySelector('.orders-panel .modal-close')?.focus());
                    this.loadOrders();
                },
                closeOrdersPanel() {
                    if (!this.isOrdersPanelOpen || this.isOrdersPanelClosing) {
                        return;
                    }
                    this.isOrdersPanelClosing = true;
                    this._ordersPanelCloseTimer = setTimeout(() => {
                        this.isOrdersPanelOpen = false;
                        this.isOrdersPanelClosing = false;
                        this.selectedOrder = null;
                        this._ordersPanelCloseTimer = null;
                        document.body.classList.remove('orders-panel-open');
                        this.$nextTick(() => document.querySelector('#orders-btn')?.focus());
                    }, 300);
                },
                loadOrders() {
                    const cassaId = localStorage.getItem('cassa_id');
                    if (!cassaId) {
                        this.ordersError = 'Nessuna cassa selezionata.';
                        return;
                    }
                    this.ordersLoading = true;
                    this.ordersError = '';
                    const params = { cassa_id: cassaId, limit: this.ordersLimit };
                    if (this.orderSearch) {
                        params.q = this.orderSearch;
                    }
                    $.get('../api/get_ordini.php', params)
                        .done((response) => {
                            this.ordersList = Array.isArray(response && response.ordini) ? response.ordini : [];
                        })
                        .fail(() => {
                            this.ordersError = 'Errore nel caricamento degli ordini.';
                            this.ordersList = [];
                        })
                        .always(() => {
                            this.ordersLoading = false;
                        });
                },
                openOrderDetail(id) {
                    const cassaId = localStorage.getItem('cassa_id');
                    this.orderDetailLoading = true;
                    this.selectedOrder = { ordine: { id: id }, righe: [] };
                    $.get('../api/get_ordine_dettaglio.php', { id: id, cassa_id: cassaId })
                        .done((response) => {
                            if (response && response.ordine) {
                                this.selectedOrder = { ordine: response.ordine, righe: response.righe || [] };
                            } else {
                                this.selectedOrder = null;
                                this.showToast('Ordine non trovato.', 'error');
                            }
                        })
                        .fail(() => {
                            this.selectedOrder = null;
                            this.showToast('Errore nel caricamento del dettaglio ordine.', 'error');
                        })
                        .always(() => {
                            this.orderDetailLoading = false;
                        });
                },
                closeOrderDetail() {
                    this.selectedOrder = null;
                },
                reprintOrder(order) {
                    const cassaId = localStorage.getItem('cassa_id');
                    this.orderActionBusyId = order.id;
                    $.get('../print/print_last_receipt.php', { cassa_id: cassaId, id: order.id })
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
                                } else if (response && response.method === 'bridge_native' && response.published === false) {
                                    throw new Error('ponte di stampa non raggiungibile');
                                }

                                this.showToast('Ordine #' + order.id + ' ristampato. Metodo: ' + (response && response.method ? response.method : 'sconosciuto'), 'success');
                            } catch (bridgeErr) {
                                this.showToast('Ristampa fallita: ' + bridgeErr.message, 'error');
                            }
                        })
                        .fail((xhr) => {
                            let msg = 'Errore durante la ristampa dell\'ordine.';
                            try {
                                const err = JSON.parse(xhr.responseText);
                                if (err && err.error) { msg = err.error; }
                            } catch (e) {}
                            this.showToast(msg, 'error');
                        })
                        .always(() => {
                            this.orderActionBusyId = null;
                        });
                },
                async voidOrder(order) {
                    const confirmed = await window.showConfirm(
                        'Stornare l\'ordine #' + order.id + '? Verranno ripristinate le giacenze.',
                        { title: 'Storna ordine', confirmLabel: 'Storna', cancelLabel: 'Annulla' }
                    );
                    if (!confirmed) {
                        return;
                    }
                    this.orderActionBusyId = order.id;
                    $.post('../api/storna_scontrino.php', {
                        idScontr: String(order.id),
                        dat: String(order.data_ora || '').slice(0, 10)
                    }, null, 'json')
                        .done((response) => {
                            if (response && (response.updatedCount || 0) > 0) {
                                order.stornato = 1;
                                const inList = this.ordersList.find((o) => o.id === order.id);
                                if (inList) { inList.stornato = 1; }
                                if (this.selectedOrder && this.selectedOrder.ordine && this.selectedOrder.ordine.id === order.id) {
                                    this.selectedOrder.ordine.stornato = 1;
                                }
                                this.showToast(response.esito || 'Ordine stornato.', 'success');
                            } else {
                                this.showToast((response && response.esito) || (response && response.error) || 'Storno non riuscito.', 'error');
                            }
                        })
                        .fail(() => {
                            this.showToast('Errore durante lo storno dell\'ordine.', 'error');
                        })
                        .always(() => {
                            this.orderActionBusyId = null;
                        });
                },
                formatOrderDateTime(value) {
                    const raw = String(value || '').replace(' ', 'T');
                    const d = new Date(raw);
                    if (Number.isNaN(d.getTime())) {
                        return String(value || '');
                    }
                    const pad = (n) => String(n).padStart(2, '0');
                    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
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
                },
                paymentMethod(method) {
                    // La riga "Importo pagato" e' rilevante solo per i contanti: cambiando metodo
                    // (dove la riga su desktop e' nascosta) azzeriamo l'importo per non lasciare
                    // un valore residuo che finirebbe nel checkout o nel calcolo del resto.
                    if (method !== 'contanti') {
                        this.amountPaid = 0;
                        this.mobilePaidOpen = false;
                    }
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
                this._productsVisibilityHandler = () => {
                    if (document.visibilityState === 'visible') {
                        // Rientro in primo piano: annulla il timer eventualmente in attesa e
                        // ricontrolla subito, invece di aspettare fino al prossimo giro schedulato.
                        if (this._productsPollTimer) {
                            clearTimeout(this._productsPollTimer);
                            this._productsPollTimer = null;
                        }
                        this.pollProductsVersion();
                    }
                };
                document.addEventListener('visibilitychange', this._productsVisibilityHandler);
                this.initProductsPolling();
                this.initProductsRealtime();
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
                if (this._productsPollTimer) {
                    clearTimeout(this._productsPollTimer);
                }
                this.teardownProductsRealtime();
                if (this._productsVisibilityHandler) {
                    document.removeEventListener('visibilitychange', this._productsVisibilityHandler);
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
                if (this._ordersPanelCloseTimer) {
                    clearTimeout(this._ordersPanelCloseTimer);
                }
                if (this._productPickerResizeFallbackHandler) {
                    window.removeEventListener('resize', this._productPickerResizeFallbackHandler);
                }
                window.removeEventListener('resize', this._popoverRepositionHandler);
                window.removeEventListener('scroll', this._popoverRepositionHandler, true);
                document.body.classList.remove('product-picker-open');
                document.body.classList.remove('orders-panel-open');
            }
        });

        billingApp.mount('#app');
    </script>
</body>

</html>