<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, target-densitydpi=device-dpi">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/add_product.css">
<!-- Importa tutte le favicon con una sola riga -->
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <?php require_once __DIR__ . '/../includes/icons.php'; ?>
    <title>Inserisci Prodotto</title>
</head>

<body class="management-page canvas-page sidebar-page">
    <!-- Importa la sidebar -->
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="pos-main-panel">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <main class="management-shell add-product-layout" id="app">
        <section class="management-panel">
            <div class="panel-title-row panel-title-bordered">
                <?= pos_icon('add-box', ['class' => 'panel-title-icon']) ?>
                <h2>Inserisci prodotto</h2>
            </div>

            <form id="productForm" class="add-product-form" @submit.prevent="submitProductForm">
                <label for="new-category-input">Categoria</label>
                <input id="new-category-input" v-model="newProduct.category" type="text" name="category" list="existingCategoryList" required>
                <datalist id="existingCategoryList">
                    <option v-for="category in categories" :key="category" :value="category"></option>
                </datalist>

                <label for="new-name-input">Nome</label>
                <input id="new-name-input" v-model="newProduct.name" type="text" name="name" required>

                <label for="new-price-input">Prezzo</label>
                <input id="new-price-input" v-model="newProduct.price" type="number" step="0.01" name="price" required>

                <label for="new-image-input">Immagine</label>
                <input id="new-image-input" type="file" name="image" accept="image/*" @change="handleNewImageSelection">

                <label for="new-quantity-input">Disponibilità</label>
                <input id="new-quantity-input" v-model="newProduct.quantityAvailable" type="number" min="0" step="1" name="quantity_available" placeholder="∞">

                <button type="submit">Aggiungi prodotto</button>
                <p class="management-note">Puoi usare una categoria esistente o inserirne una nuova.</p>
                <div id="insertStatus" class="status-msg" :style="{ color: insertStatusIsError ? 'var(--mc-danger)' : 'var(--mc-text-soft)' }">{{ insertStatusMessage }}</div>
            </form>
        </section>

        <section class="management-panel">
            <div class="products-panel-title panel-title-bordered">
                <div class="panel-title-row">
                    <?= pos_icon('image', ['class' => 'panel-title-icon']) ?>
                    <h2>Prodotti esistenti</h2>
                </div>
                <button type="button" id="reloadProductsBtn" class="reload-products-btn" @click="reloadData" title="Ricarica elenco" aria-label="Ricarica elenco">
                    <?= pos_icon('refresh-ccw') ?>
                </button>
            </div>

            <form id="categoryRenameForm" class="category-tools" autocomplete="off" @submit.prevent="renameCategory">
                <div>
                    <label for="oldCategory">Categoria attuale</label>
                    <select id="oldCategory" v-model="categoryRename.oldCategory" name="old_category" required>
                        <option v-if="categories.length === 0" value="">Nessuna categoria</option>
                        <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                    </select>
                </div>
                <div>
                    <label for="newCategory">Nuovo nome categoria</label>
                    <input id="newCategory" v-model="categoryRename.newCategory" type="text" name="new_category" required>
                </div>
                <div>
                    <button type="submit">Rinomina categoria</button>
                </div>
            </form>

            <div class="products-tools" aria-label="Filtri elenco prodotti">
                <div>
                    <label for="categoryFilter">Filtro categoria</label>
                    <select id="categoryFilter" v-model="filters.category">
                        <option value="">Tutte</option>
                        <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                    </select>
                </div>
                <div>
                    <label for="productSearch">Cerca prodotto</label>
                    <input id="productSearch" v-model.trim="filters.search" type="text" placeholder="Nome o id prodotto">
                </div>
                <div>
                    <label for="includeInactiveProducts">Visibilita</label>
                    <label class="inactive-toggle" for="includeInactiveProducts">
                        <input type="checkbox" id="includeInactiveProducts" v-model="filters.includeInactive" @change="reloadData">
                        Mostra eliminati
                    </label>
                </div>
                <div class="product-bulk-actions">
                    <button type="button" @click="exportStock">
                        Esporta stock
                    </button>
                    <button type="button" :disabled="importingStock" @click="triggerImportFile">
                        {{ importingStock ? 'Importazione...' : 'Importa da file' }}
                    </button>
                    <input type="file" ref="importFileInput" accept=".csv" style="display:none" @change="handleImportFileSelected">
                    <button type="button" class="btn-delete" :disabled="selectedActiveProductIds.length === 0" v-if="selectedActiveProductIds.length > 0" @click="deleteSelectedProducts">
                        Elimina selezionati ({{ selectedActiveProductIds.length }})
                    </button>
                    <button type="button" class="bulk-restore-btn" :disabled="restoringProducts || selectedInactiveProductIds.length === 0" v-if="filters.includeInactive > 0" @click="restoreSelectedProducts">
                        {{ restoringProducts ? 'Ripristino...' : `Ripristina selezionati (${selectedInactiveProductIds.length})` }}
                    </button>
                </div>
            </div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table class="editable-table" aria-label="Tabella prodotti modificabile">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="product-select" :checked="allVisibleProductsSelected" :disabled="visibleProductIds.length === 0" @change="toggleAllVisibleProducts($event)" aria-label="Seleziona tutti i prodotti visibili"></th>
                                <!-- <th :aria-sort="sortAria('id')"><button type="button" class="table-sort-btn" @click="toggleSort('id')">ID <span aria-hidden="true" v-html="sortIcon('id')"></span></button></th> -->
                                <th :aria-sort="sortAria('editCategory')"><button type="button" class="table-sort-btn" @click="toggleSort('editCategory')">Categoria <span aria-hidden="true" v-html="sortIcon('editCategory')"></span></button></th>
                                <th :aria-sort="sortAria('editName')"><button type="button" class="table-sort-btn" @click="toggleSort('editName')">Nome <span aria-hidden="true" v-html="sortIcon('editName')"></span></button></th>
                                <th :aria-sort="sortAria('editPrice')"><button type="button" class="table-sort-btn" @click="toggleSort('editPrice')">Prezzo <span aria-hidden="true" v-html="sortIcon('editPrice')"></span></button></th>
                                <th class="sort-order-column" :aria-sort="sortAria('editSort')"><button type="button" class="table-sort-btn" @click="toggleSort('editSort')">Ordine <span aria-hidden="true" v-html="sortIcon('editSort')"></span></button></th>
                                <th :aria-sort="sortAria('editQuantityAvailable')"><button type="button" class="table-sort-btn" @click="toggleSort('editQuantityAvailable')">Disponibilità <span aria-hidden="true" v-html="sortIcon('editQuantityAvailable')"></span></button></th>
                                <th :aria-sort="sortAria('editImagePath')"><button type="button" class="table-sort-btn" @click="toggleSort('editImagePath')">Percorso immagine <span aria-hidden="true" v-html="sortIcon('editImagePath')"></span></button></th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="productTableBody">
                            <tr v-if="loadingProducts">
                                <td colspan="8">Caricamento prodotti...</td>
                            </tr>
                            <tr v-else-if="filteredProducts.length === 0">
                                <td colspan="8">Nessun prodotto trovato.</td>
                            </tr>
                            <tr v-for="product in filteredProducts" :key="product.id" :class="{ 'product-row--inactive': !product.isActive }">
                                <td><input type="checkbox" class="product-select" :value="Number(product.id)" v-model="selectedProductIds" :aria-label="`Seleziona prodotto ${product.name}`"></td>
                                <!-- <td>{{ product.id }}</td> -->
                                <td><input type="text" class="edit-category" v-model="product.editCategory"></td>
                                <td><input type="text" class="edit-name" v-model="product.editName"></td>
                                <td>
                                    <div class="price-input-wrap">
                                        <input type="number" step="0.01" class="edit-price" v-model="product.editPrice">
                                        <span class="price-suffix" aria-hidden="true">€</span>
                                    </div>
                                </td>
                                <td class="sort-order-column">
                                    <div class="quantity-direct-control">
                                        <button type="button" class="quantity-step-btn" aria-label="Diminuisci ordine" @pointerdown="startStepHold(product, -1, 'sort')" @pointerup="stopStepHold" @pointerleave="stopStepHold" @pointercancel="stopStepHold" @click="handleStepClick(product, -1, 'sort')">−</button>
                                        <input type="number" min="0" step="1" class="edit-sort" v-model="product.editSort" aria-label="Ordine">
                                        <button type="button" class="quantity-step-btn" aria-label="Aumenta ordine" @pointerdown="startStepHold(product, 1, 'sort')" @pointerup="stopStepHold" @pointerleave="stopStepHold" @pointercancel="stopStepHold" @click="handleStepClick(product, 1, 'sort')">+</button>
                                    </div>
                                </td>
                                <td class="availability-column">
                                    <div class="quantity-direct-control">
                                        <button type="button" class="quantity-step-btn" aria-label="Diminuisci quantità" @pointerdown="startStepHold(product, -1, 'quantity')" @pointerup="stopStepHold" @pointerleave="stopStepHold" @pointercancel="stopStepHold" @click="handleStepClick(product, -1, 'quantity')">−</button>
                                        <input type="number" min="0" step="1" class="edit-quantity" v-model="product.editQuantityAvailable" placeholder="∞" aria-label="Disponibilità">
                                        <button type="button" class="quantity-step-btn" aria-label="Aumenta quantità" @pointerdown="startStepHold(product, 1, 'quantity')" @pointerup="stopStepHold" @pointerleave="stopStepHold" @pointercancel="stopStepHold" @click="handleStepClick(product, 1, 'quantity')">+</button>
                                        <div v-if="quantityPopoverProductId === Number(product.id)" class="quantity-popover" @click.stop>
                                            <label :for="`quantity-popover-${product.id}`">Disponibilità</label>
                                            <input :id="`quantity-popover-${product.id}`" v-model.trim="quantityPopoverValue" type="number" min="0" step="1" placeholder="∞" @keydown.esc="closeQuantityPopover">
                                            <div class="quantity-popover-actions">
                                                <button type="button" @click="closeQuantityPopover">Annulla</button>
                                                <button type="button" @click="applyQuantityPopover(product)">Applica</button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="image-input-group">
                                        <img v-if="product.editImagePath" class="edit-image-thumb" :src="'../' + product.editImagePath" :alt="product.editName" @error="$event.target.style.visibility = 'hidden'">
                                        <span v-else class="edit-image-placeholder" aria-hidden="true">
                                            <?= pos_icon('upload') ?>
                                        </span>
                                        <input type="text" class="edit-image-path" v-model="product.editImagePath" placeholder="uploads/file.jpg">
                                        <label :for="`file-upload-${product.id}`" class="image-upload-btn" title="Scegli immagine" aria-label="Scegli immagine">
                                            <?= pos_icon('upload') ?>
                                        </label>
                                        <input :id="`file-upload-${product.id}`" type="file" class="edit-image-file visually-hidden-file" accept="image/*" @change="handleImageSelection(product, $event)">
                                    </div>
                                </td>
                                <td class="actions-cell">
                                    <button v-if="productHasChanges(product)" type="button" class="save-row" @click="saveRow(product)">Salva</button>
                                    <button type="button" class="toggle-active-row" :class="{ 'is-restore': !product.isActive, 'btn-delete': product.isActive }" :data-action="product.isActive ? 'delete' : 'restore'" @click="toggleProductActive(product)">
                                        {{ product.isActive ? 'Elimina' : 'Ripristina' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div id="tableStatus" class="status-msg" :style="{ color: tableStatusIsError ? 'var(--mc-danger)' : 'var(--mc-text-soft)' }">{{ tableStatusMessage }}</div>
        </section>
    </main>
    </div>

    <script src="../assets/js/vue.global.js"></script>
    <script src="../assets/js/jquery-3.6.0.min.js"></script>
    <script>
        const app = Vue.createApp({
            data() {
                return {
                    categories: [],
                    products: [],
                    loadingProducts: true,
                    filters: {
                        category: '',
                        search: '',
                        includeInactive: false
                    },
                    newProduct: {
                        category: '',
                        name: '',
                        price: '',
                        quantityAvailable: '',
                        imageFile: null
                    },
                    categoryRename: {
                        oldCategory: '',
                        newCategory: ''
                    },
                    insertStatusMessage: '',
                    insertStatusIsError: false,
                    tableStatusMessage: '',
                    tableStatusIsError: false,
                    sortKey: '',
                    sortDirection: 'asc',
                    selectedProductIds: [],
                    restoringProducts: false,
                    importingStock: false,
                    quantityPopoverProductId: null,
                    quantityPopoverValue: ''
                };
            },
            computed: {
                filteredProducts() {
                    const selectedCategory = (this.filters.category || '').trim();
                    const searchText = (this.filters.search || '').trim().toLowerCase();

                    const products = this.products.filter((product) => {
                        const categoryMatch = selectedCategory === '' || product.category === selectedCategory;
                        const haystack = `${product.id} ${product.name} ${product.category}`.toLowerCase();
                        const searchMatch = searchText === '' || haystack.includes(searchText);
                        return categoryMatch && searchMatch;
                    });

                    if (!this.sortKey) {
                        return products;
                    }

                    return products.sort((a, b) => this.compareValues(a[this.sortKey], b[this.sortKey]) * (this.sortDirection === 'asc' ? 1 : -1));
                },
                visibleProductIds() {
                    return this.filteredProducts
                        .map((product) => Number(product.id));
                },
                selectedActiveProductIds() {
                    const activeIds = new Set(this.products.filter((product) => product.isActive).map((product) => Number(product.id)));
                    return this.selectedProductIds.filter((id) => activeIds.has(Number(id)));
                },
                selectedInactiveProductIds() {
                    const inactiveIds = new Set(this.products.filter((product) => !product.isActive).map((product) => Number(product.id)));
                    return this.selectedProductIds.filter((id) => inactiveIds.has(Number(id)));
                },
                allVisibleProductsSelected() {
                    return this.visibleProductIds.length > 0
                        && this.visibleProductIds.every((id) => this.selectedProductIds.includes(id));
                }
            },
            methods: {
                parseJsonSafe(input) {
                    if (typeof input === 'object') {
                        return input;
                    }

                    try {
                        return JSON.parse(input);
                    } catch (err) {
                        return null;
                    }
                },
                setInsertStatus(message, isError = false) {
                    this.insertStatusMessage = message || '';
                    this.insertStatusIsError = !!isError;
                },
                setTableStatus(message, isError = false) {
                    this.tableStatusMessage = message || '';
                    this.tableStatusIsError = !!isError;
                },
                toggleSort(key) {
                    if (this.sortKey === key) {
                        this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
                        return;
                    }
                    this.sortKey = key;
                    this.sortDirection = 'asc';
                },
                compareValues(a, b) {
                    const aNumber = Number(a);
                    const bNumber = Number(b);
                    if (String(a ?? '').trim() !== '' && String(b ?? '').trim() !== '' && !Number.isNaN(aNumber) && !Number.isNaN(bNumber)) {
                        return aNumber - bNumber;
                    }
                    return String(a ?? '').localeCompare(String(b ?? ''), 'it', { numeric: true, sensitivity: 'base' });
                },
                sortAria(key) {
                    return this.sortKey !== key ? 'none' : (this.sortDirection === 'asc' ? 'ascending' : 'descending');
                },
                sortIcon(key) {
                    const iconClass = `table-sort-icon${this.sortKey === key ? ' is-active' : ''}`;
                    if (this.sortKey === key && this.sortDirection === 'asc') {
                        return `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="${iconClass}" aria-hidden="true"><path d="M12 19V5"></path><path d="m5 12 7-7 7 7"></path></svg>`;
                    }
                    if (this.sortKey === key) {
                        return `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="${iconClass}" aria-hidden="true"><path d="M12 5v14"></path><path d="m19 12-7 7-7-7"></path></svg>`;
                    }
                    return `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="${iconClass}" aria-hidden="true"><path d="m21 16-4 4-4-4"></path><path d="M17 20V4"></path><path d="m3 8 4-4 4 4"></path><path d="M7 4v16"></path></svg>`;
                },
                normalizeProduct(product) {
                    return {
                        ...product,
                        editCategory: product.category || '',
                        editName: product.name || '',
                        editPrice: product.price ?? '',
                        editSort: product.item_sort ?? product.id ?? '',
                        editQuantityAvailable: product.quantity_available ?? '',
                        editImagePath: product.image_path || '',
                        selectedFile: null,
                        originalCategory: product.category || '', originalName: product.name || '', originalPrice: product.price ?? '',
                        originalSort: product.item_sort ?? product.id ?? '',
                        originalQuantityAvailable: product.quantity_available ?? '',
                        originalImagePath: product.image_path || '',
                        isActive: Number(product.is_active ?? 1) === 1
                    };
                },
                loadCategories() {
                    const query = {
                        only_inactive: this.filters.includeInactive ? 1 : 0
                    };

                    return $.getJSON('../api/get_categories.php', query).done((categories) => {
                        this.categories = Array.isArray(categories) ? categories : [];
                    });
                },
                loadProducts() {
                    this.loadingProducts = true;
                    this.setTableStatus('Caricamento prodotti in corso...', false);

                    const query = {
                        only_inactive: this.filters.includeInactive ? 1 : 0
                    };

                    return $.getJSON('../api/get_products.php', query).done((products) => {
                        this.products = Array.isArray(products) ? products.map(this.normalizeProduct) : [];
                        const availableIds = new Set(this.products.map((product) => Number(product.id)));
                        this.selectedProductIds = this.selectedProductIds.filter((id) => availableIds.has(Number(id)));
                        this.loadingProducts = false;
                        this.setTableStatus(`Prodotti caricati: ${this.products.length}`, false);
                    }).fail(() => {
                        this.products = [];
                        this.loadingProducts = false;
                        this.setTableStatus('Impossibile leggere i prodotti dal database.', true);
                    });
                },
                reloadData() {
                    this.loadCategories().then(() => {
                        this.loadProducts();
                    });
                },
                exportStock() {
                    window.location.href = '../api/export_stock.php';
                },
                triggerImportFile() {
                    if (this.$refs.importFileInput) {
                        this.$refs.importFileInput.click();
                    }
                },
                handleImportFileSelected(event) {
                    const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
                    if (!file) {
                        return;
                    }

                    if (!confirm('Il file selezionato aggiornerà i prodotti esistenti (per id o nome) e aggiungerà quelli nuovi. Nessun prodotto esistente verrà eliminato. Confermi?')) {
                        event.target.value = '';
                        return;
                    }

                    const formData = new FormData();
                    formData.append('file', file);

                    this.importingStock = true;
                    this.setTableStatus('Importazione file in corso...', false);
                    $.ajax({
                        url: '../api/import_stock.php',
                        type: 'POST',
                        data: formData,
                        contentType: false,
                        processData: false,
                        dataType: 'json',
                        success: (response) => {
                            if (!response || !response.ok) {
                                this.setTableStatus(response && response.message ? response.message : 'Errore durante l’importazione.', true);
                                return;
                            }
                            this.setTableStatus(response.message || 'Importazione completata.', false);
                            this.reloadData();
                        },
                        error: (xhr) => {
                            const response = this.parseJsonSafe(xhr && xhr.responseText ? xhr.responseText : '');
                            this.setTableStatus(response && response.message ? response.message : 'Errore di rete durante l’importazione.', true);
                        },
                        complete: () => {
                            this.importingStock = false;
                            event.target.value = '';
                        }
                    });
                },
                handleNewImageSelection(event) {
                    const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
                    this.newProduct.imageFile = file;
                },
                handleImageSelection(product, event) {
                    const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
                    product.selectedFile = file;
                    if (file) {
                        this.setTableStatus(`Immagine selezionata per ID ${product.id}: ${file.name}. Salva la riga per caricarla.`, false);
                    }
                },
                formatQuantityAvailable(value) {
                    return value === '' || value === null || value === undefined ? '∞' : String(value);
                },
                openQuantityPopover(product) {
                    this.quantityPopoverProductId = Number(product.id);
                    this.quantityPopoverValue = product.editQuantityAvailable === null || product.editQuantityAvailable === undefined
                        ? ''
                        : String(product.editQuantityAvailable);
                    this.$nextTick(() => document.getElementById(`quantity-popover-${product.id}`)?.focus());
                },
                closeQuantityPopover() {
                    this.quantityPopoverProductId = null;
                    this.quantityPopoverValue = '';
                },
                applyQuantityPopover(product) {
                    const rawValue = String(this.quantityPopoverValue ?? '').trim();
                    if (rawValue !== '' && (!/^\d+$/.test(rawValue) || Number(rawValue) < 0)) {
                        this.setTableStatus('Inserisci una quantità intera maggiore o uguale a zero, oppure lascia il campo vuoto per quantità infinita.', true);
                        return;
                    }
                    product.editQuantityAvailable = rawValue === '' ? '' : Number(rawValue);
                    this.closeQuantityPopover();
                },
                stepQuantity(product, amount) {
                    const currentValue = Number(product.editQuantityAvailable);
                    product.editQuantityAvailable = Math.max(0, Number.isFinite(currentValue) ? currentValue + amount : 0);
                },
                stepSortOrder(product, amount) {
                    const currentValue = Number(product.editSort);
                    product.editSort = Math.max(0, Number.isFinite(currentValue) ? currentValue + amount : 0);
                },
                applyStep(product, amount, type) {
                    if (type === 'sort') {
                        this.stepSortOrder(product, amount);
                    } else {
                        this.stepQuantity(product, amount);
                    }
                },
                triggerStepFeedback() {
                    if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
                        try {
                            navigator.vibrate(8);
                        } catch (error) {
                            // vibration unsupported or blocked by the browser, ignore
                        }
                    }
                },
                startStepHold(product, amount, type) {
                    this.stopStepHold();
                    this._stepHoldFired = false;
                    this._stepHoldTimeout = setTimeout(() => {
                        this._stepHoldFired = true;
                        this.applyStep(product, amount, type);
                        this.triggerStepFeedback();
                        this._stepHoldInterval = setInterval(() => {
                            this.applyStep(product, amount, type);
                            this.triggerStepFeedback();
                        }, 100);
                    }, 400);
                },
                stopStepHold() {
                    if (this._stepHoldTimeout) {
                        clearTimeout(this._stepHoldTimeout);
                        this._stepHoldTimeout = null;
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
                productHasChanges(product) {
                    return product.selectedFile !== null
                        || (product.editCategory || '').trim() !== String(product.originalCategory || '').trim()
                        || (product.editName || '').trim() !== String(product.originalName || '').trim()
                        || String(product.editPrice ?? '').trim() !== String(product.originalPrice ?? '').trim()
                        || String(product.editSort ?? '').trim() !== String(product.originalSort ?? '').trim()
                        || String(product.editQuantityAvailable ?? '').trim() !== String(product.originalQuantityAvailable ?? '').trim()
                        || (product.editImagePath || '').trim() !== String(product.originalImagePath || '').trim();
                },
                submitProductForm() {
                    this.setInsertStatus('Inserimento prodotto in corso...', false);

                    const formData = new FormData();
                    formData.append('category', (this.newProduct.category || '').trim());
                    formData.append('name', (this.newProduct.name || '').trim());
                    formData.append('price', String(this.newProduct.price));
                    formData.append('quantity_available', String(this.newProduct.quantityAvailable ?? ''));
                    if (this.newProduct.imageFile) {
                        formData.append('image', this.newProduct.imageFile);
                    }

                    $.ajax({
                        url: '../api/insert_product.php',
                        type: 'POST',
                        data: formData,
                        contentType: false,
                        processData: false,
                        success: (response) => {
                            this.setInsertStatus(response, false);
                            this.newProduct = {
                                category: '',
                                name: '',
                                price: '',
                                quantityAvailable: '',
                                imageFile: null
                            };
                            document.getElementById('new-image-input').value = '';
                            this.reloadData();
                        },
                        error: () => {
                            this.setInsertStatus('Errore durante upload/inserimento prodotto.', true);
                        }
                    });
                },
                saveRow(product) {
                    const payload = {
                        id: Number(product.id),
                        category: (product.editCategory || '').trim(),
                        name: (product.editName || '').trim(),
                        price: Number(product.editPrice),
                        item_sort: Number(product.editSort),
                        quantity_available: product.editQuantityAvailable === '' || product.editQuantityAvailable === null || product.editQuantityAvailable === undefined ? '' : Number(product.editQuantityAvailable),
                        image_path: (product.editImagePath || '').trim()
                    };

                    if (!payload.id || !payload.category || !payload.name || Number.isNaN(payload.price) || !Number.isInteger(payload.item_sort) || payload.item_sort < 0 || (payload.quantity_available !== '' && (!Number.isInteger(payload.quantity_available) || payload.quantity_available < 0))) {
                        this.setTableStatus('Controlla i campi della riga: non possono essere vuoti o negativi.', true);
                        return;
                    }

                    this.setTableStatus(`Salvataggio prodotto ID ${payload.id}...`, false);

                    const formData = new FormData();
                    formData.append('id', String(payload.id));
                    formData.append('category', payload.category);
                    formData.append('name', payload.name);
                    formData.append('price', String(payload.price));
                    formData.append('item_sort', String(payload.item_sort));
                    formData.append('quantity_available', String(payload.quantity_available));
                    formData.append('image_path', payload.image_path);
                    if (product.selectedFile) {
                        formData.append('image', product.selectedFile);
                    }

                    $.ajax({
                        url: '../api/update_product.php',
                        type: 'POST',
                        data: formData,
                        contentType: false,
                        processData: false,
                        success: (response) => {
                            const json = this.parseJsonSafe(response);
                            if (!json || !json.ok) {
                                this.setTableStatus((json && json.message) ? json.message : 'Errore aggiornamento prodotto.', true);
                                return;
                            }

                            this.setTableStatus(json.message || 'Prodotto aggiornato.', false);
                            this.reloadData();
                        },
                        error: () => {
                            this.setTableStatus('Errore di rete durante il salvataggio prodotto.', true);
                        }
                    });
                },
                toggleProductActive(product) {
                    const id = Number(product.id);
                    const normalizedAction = product.isActive ? 'delete' : 'restore';

                    if (!id) {
                        this.setTableStatus('ID prodotto non valido.', true);
                        return;
                    }

                    const confirmMessage = normalizedAction === 'delete'
                        ? 'Confermi di nascondere questo prodotto?'
                        : 'Confermi di ripristinare questo prodotto?';

                    if (!confirm(confirmMessage)) {
                        return;
                    }

                    this.setTableStatus(normalizedAction === 'delete' ? 'Eliminazione logica in corso...' : 'Ripristino prodotto in corso...', false);

                    $.ajax({
                        url: '../api/soft_delete_product.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            id,
                            action: normalizedAction
                        },
                        success: (response) => {
                            if (!response || !response.ok) {
                                this.setTableStatus(response && response.message ? response.message : 'Errore aggiornamento stato prodotto.', true);
                                return;
                            }

                            this.selectedProductIds = this.selectedProductIds.filter((selectedId) => Number(selectedId) !== id);
                            this.setTableStatus(response.message || 'Stato prodotto aggiornato.', false);
                            this.reloadData();
                        },
                        error: (xhr) => {
                            const response = this.parseJsonSafe(xhr && xhr.responseText ? xhr.responseText : '');
                            this.setTableStatus(response && response.message ? response.message : 'Errore di rete durante aggiornamento stato prodotto.', true);
                        }
                    });
                },
                toggleAllVisibleProducts(event) {
                    const visibleIds = this.visibleProductIds;
                    if (event.target.checked) {
                        this.selectedProductIds = [...new Set([...this.selectedProductIds, ...visibleIds])];
                        return;
                    }
                    this.selectedProductIds = this.selectedProductIds.filter((id) => !visibleIds.includes(Number(id)));
                },
                deleteSelectedProducts() {
                    const ids = [...new Set(this.selectedActiveProductIds.map(Number).filter((id) => Number.isInteger(id) && id > 0))];
                    if (ids.length === 0) {
                        this.setTableStatus('Seleziona almeno un prodotto da eliminare.', true);
                        return;
                    }

                    if (!confirm(`Confermi di nascondere ${ids.length} prodotti selezionati?`)) {
                        return;
                    }

                    this.setTableStatus(`Eliminazione logica di ${ids.length} prodotti in corso...`, false);
                    $.ajax({
                        url: '../api/soft_delete_product.php',
                        type: 'POST',
                        dataType: 'json',
                        data: { ids, action: 'delete' },
                        success: (response) => {
                            if (!response || !response.ok) {
                                this.setTableStatus(response && response.message ? response.message : 'Errore eliminazione prodotti.', true);
                                return;
                            }
                            this.selectedProductIds = [];
                            this.setTableStatus(response.message || 'Prodotti nascosti con successo.', false);
                            this.reloadData();
                        },
                        error: (xhr) => {
                            const response = this.parseJsonSafe(xhr && xhr.responseText ? xhr.responseText : '');
                            this.setTableStatus(response && response.message ? response.message : 'Errore di rete durante eliminazione prodotti.', true);
                        }
                    });
                },
                restoreSelectedProducts() {
                    const ids = [...new Set(this.selectedInactiveProductIds.map(Number).filter((id) => Number.isInteger(id) && id > 0))];
                    if (ids.length === 0) {
                        this.setTableStatus('Seleziona almeno un prodotto eliminato da ripristinare.', true);
                        return;
                    }

                    if (!confirm(`Confermi di ripristinare ${ids.length} prodotti selezionati?`)) {
                        return;
                    }

                    this.restoringProducts = true;
                    this.setTableStatus(`Ripristino di ${ids.length} prodotti in corso...`, false);
                    $.ajax({
                        url: '../api/soft_delete_product.php',
                        type: 'POST',
                        dataType: 'json',
                        data: { ids, action: 'restore' },
                        success: (response) => {
                            if (!response || !response.ok) {
                                this.setTableStatus(response && response.message ? response.message : 'Errore ripristino prodotti.', true);
                                return;
                            }
                            this.selectedProductIds = this.selectedProductIds.filter((id) => !ids.includes(Number(id)));
                            this.setTableStatus(response.message || 'Prodotti ripristinati con successo.', false);
                            this.reloadData();
                        },
                        error: (xhr) => {
                            const response = this.parseJsonSafe(xhr && xhr.responseText ? xhr.responseText : '');
                            this.setTableStatus(response && response.message ? response.message : 'Errore di rete durante ripristino prodotti.', true);
                        },
                        complete: () => {
                            this.restoringProducts = false;
                        }
                    });
                },
                renameCategory() {
                    const oldCategory = (this.categoryRename.oldCategory || '').trim();
                    const newCategory = (this.categoryRename.newCategory || '').trim();

                    if (!oldCategory || !newCategory) {
                        this.setTableStatus('Seleziona categoria attuale e nuovo nome.', true);
                        return;
                    }

                    $.ajax({
                        url: '../api/update_category.php',
                        type: 'POST',
                        data: {
                            old_category: oldCategory,
                            new_category: newCategory
                        },
                        success: (response) => {
                            const json = this.parseJsonSafe(response);
                            if (!json || !json.ok) {
                                this.setTableStatus((json && json.message) ? json.message : 'Errore aggiornamento categoria.', true);
                                return;
                            }

                            this.categoryRename.newCategory = '';
                            this.setTableStatus(json.message || 'Categoria aggiornata.', false);
                            this.reloadData();
                        },
                        error: () => {
                            this.setTableStatus('Errore di rete durante aggiornamento categoria.', true);
                        }
                    });
                },
            },
            mounted() {
                this.reloadData();
            }
        });

        app.mount('#app');
    </script>
    <script src="../assets/js/theme.js"></script>
</body>

</html>
