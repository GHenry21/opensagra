<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/conf_casse.css">
    <!-- Importa tutte le favicon con una sola riga -->
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <?php require_once __DIR__ . '/../includes/icons.php'; ?>
    <title>Configurazione Casse</title>
    <script src="../assets/js/vue.global.js"></script>
    <script src="../assets/js/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme.js"></script>

</head>

<body class="management-page canvas-page sidebar-page">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="pos-main-panel">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div id="app">
        <main class="management-shell">
            <div id="container">
                <div id="top" class="management-panel">
                    <div class="panel-title-row">
                        <?= pos_icon('coins', ['class' => 'panel-title-icon']) ?>
                        <h2>Configurazione Casse</h2>
                    </div>
                    <div id="message"></div>
                    <div class="printer-toolbar">
                        <div class="search-wrap">
                            <?= pos_icon('search') ?>
                            <input type="text" id="searchPrinter" placeholder="Cerca casse..."
                                aria-label="Cerca casse" v-model="searchQuery">
                        </div>
                        <button class="btn-add" id="btnAddRow" @click="openModal(null)">
                            <?= pos_icon('plus') ?>
                            Nuova Cassa
                        </button>
                    </div>

                </div>
                <div id="bottom" class="management-panel">
                    <div id="tableContainer">
                        <div class="printer-table-shell">
                            <div class="printer-table-wrap">
                                <table class="printer-table">
                                    <thead>
                                        <tr>
                                            <th class="printer-col-actions">Azioni</th>
                                            <th class="printer-col-id" :aria-sort="sortAria('cassa_id')"><button
                                                    type="button" class="table-sort-btn"
                                                    @click="toggleSort('cassa_id')">Cassa ID <span aria-hidden="true"
                                                        v-html="sortIcon('cassa_id')"></span></button></th>
                                            <th class="printer-col-type" :aria-sort="sortAria('tipo_stampante')"><button
                                                    type="button" class="table-sort-btn"
                                                    @click="toggleSort('tipo_stampante')">Tipo <span aria-hidden="true"
                                                        v-html="sortIcon('tipo_stampante')"></span></button></th>
                                           <th class="payment-methods-col">Pagamenti</th>
                                           <th :aria-sort="sortAria('nome_indirizzo')"><button type="button"
                                                    class="table-sort-btn"
                                                    @click="toggleSort('nome_indirizzo')">Nome/IP <span
                                                        aria-hidden="true"
                                                        v-html="sortIcon('nome_indirizzo')"></span></button></th>
                                            <th :aria-sort="sortAria('bridge_host')"><button type="button"
                                                    class="table-sort-btn" @click="toggleSort('bridge_host')">IP ponte <span
                                                        aria-hidden="true" v-html="sortIcon('bridge_host')"></span></button>
                                            </th>
                                            <th class="printer-col-porta" :aria-sort="sortAria('porta')"><button
                                                    type="button" class="table-sort-btn"
                                                    @click="toggleSort('porta')">Porta <span aria-hidden="true"
                                                        v-html="sortIcon('porta')"></span></button></th>
                                            
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-if="filteredStampanti.length === 0">
                                            <td colspan="7" class="empty-state">Nessuna stampante trovata.</td>
                                        </tr>
                                        <tr v-for="item in filteredStampanti" :key="item.record.cassa_id || item.index" class="printer-card-row"
                                            :class="{ 'is-row-expanded': isRowExpanded(item.record.cassa_id) }">
                                            <td class="printer-col-actions" data-label="AZIONI">
                                                <button type="button" class="row-test-btn" title="Test stampa"
                                                    aria-label="Test stampa" @click="testPrinterConfig(item.record)">
                                                    <?= pos_icon('printer-test') ?>
                                                </button>
                                                <button type="button" class="row-edit-btn" title="Modifica"
                                                    aria-label="Modifica stampante" @click="openModal(item.index)">
                                                    <?= pos_icon('pencil-line') ?>
                                                </button>
                                                <button type="button" class="row-toggle-btn"
                                                    :class="{ 'is-open': isRowExpanded(item.record.cassa_id) }"
                                                    :aria-expanded="isRowExpanded(item.record.cassa_id) ? 'true' : 'false'"
                                                    aria-label="Espandi dettagli cassa"
                                                    @click="toggleRowExpanded(item.record.cassa_id)">
                                                    <?= pos_icon('chevron-down', ['class' => 'row-toggle-icon']) ?>
                                                </button>
                                            </td>
                                            <td data-label="Cassa ID">{{ item.record.cassa_id || '-' }}</td>
                                            <td class="printer-col-type" data-label="✔ Tipo"><span class="tipo-pill">{{
                                                    formatTipoStampante(item.record.tipo_stampante) }}</span></td>
                                            <td class="payment-methods-col" data-label="PAGAMENTI">
                                                <div class="payment-toggles" @click.stop>
                                                    <label class="payment-toggle" title="Abilita Contanti">
                                                        <input type="checkbox" :checked="isPaymentEnabled(item.record, 'abilita_contanti')"
                                                            @click="preventCashDisable(item.record, $event)"
                                                            @change="updatePaymentMethod(item.record, 'abilita_contanti', $event.target.checked)">
                                                        <span><?= pos_icon('cash', ['class' => 'payment-toggle-icon']) ?>Contanti</span>
                                                    </label>
                                                    <label class="payment-toggle" title="Abilita Carta">
                                                        <input type="checkbox" :checked="isPaymentEnabled(item.record, 'abilita_carta')"
                                                            @change="updatePaymentMethod(item.record, 'abilita_carta', $event.target.checked)">
                                                        <span><?= pos_icon('credit-card', ['class' => 'payment-toggle-icon']) ?>Carta</span>
                                                    </label>
                                                    <label class="payment-toggle" title="Abilita Satispay">
                                                        <input type="checkbox" :checked="isPaymentEnabled(item.record, 'abilita_satispay')"
                                                            @change="updatePaymentMethod(item.record, 'abilita_satispay', $event.target.checked)">
                                                        <span><?= pos_icon('satispay', ['class' => 'payment-toggle-icon payment-toggle-icon--satispay']) ?>Satispay</span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td data-label="Nome/IP">{{ item.record.nome_indirizzo || '-' }}</td>
                                            <td data-label="IP ponte" :class="{ 'field-not-applicable': item.record.tipo_stampante !== 'BRIDGE_NATIVE' }">{{ item.record.bridge_host || '-' }}</td>
                                            <td class="printer-col-porta" data-label="Porta" :class="{ 'field-not-applicable': item.record.tipo_stampante !== 'RETE' && !(item.record.tipo_stampante === 'BRIDGE_NATIVE' && item.record.bridge_printer_type === 'RETE') }">{{ item.record.porta || '0' }}</td>

                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <!-- Modal per aggiungere/modificare stampante -->
        <div id="modalBackdrop" class="modal-backdrop" :class="{ 'is-open': modalOpen }"
            :aria-hidden="modalOpen ? 'false' : 'true'" @click.self="closeModal">
            <div class="printer-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
                <div class="printer-modal-head">
                    <h3 id="modalTitle">{{ isCreatingRecord ? 'Nuova Cassa' : 'Modifica Cassa' }}</h3>
                    <button type="button" class="modal-close" id="modalClose" aria-label="Chiudi" @click="closeModal">
                        <?= pos_icon('x') ?>
                    </button>
                </div>
                <!-- Corpo del modal con i campi di input -->
                <div class="printer-modal-body">
                    <div class="modal-field">
                        <label for="modalCassaId">Cassa ID *</label>
                        <input type="text" id="modalCassaId" autocomplete="off" v-model.trim="modalData.cassa_id">
                    </div>
                    <!-- Campo Tipo Stampante con selezione a discesa -->
                    <div class="modal-field">
                        <label for="modalTipoStampante">Tipo Stampante *</label>
                        <select id="modalTipoStampante" v-model="modalData.tipo_stampante"
                            @change="onTipoStampanteChange">
                            <option value="USB">USB / STAMPANTE LOCALE</option>
                            <option value="RETE">RETE</option>
                            <option value="BRIDGE_NATIVE">BRIDGE NATIVO</option>
                            <option value="BLUETOOTH">BLUETOOTH</option>
                        </select>
                    </div>
                    <!-- BRIDGE NATIVO: i byte ESC/POS vengono pubblicati su Mercure e il
                         processo opensagra-print-bridge sul PC-ponte li stampa. Modello
                         "a una riga": qui si configura direttamente la stampante del ponte
                         (tipo + nome/IP) e l'ID-topic; la discovery interroga il ponte via
                         proxy server-to-server (niente QZ Tray, niente IP a runtime). -->
                    <template v-if="modalData.tipo_stampante === 'BRIDGE_NATIVE'">
                        <div class="modal-field">
                            <label for="modalBridgeTopic">ID ponte (topic) *</label>
                            <input type="text" id="modalBridgeTopic" v-model.trim="modalData.bridge_topic"
                                :placeholder="modalData.cassa_id || 'es. bridge_cucina'">
                            <p class="modal-field-hint">
                                Deve combaciare con <code>PRINT_BRIDGE_CASSE</code> / <code>--cassa=</code>
                                sul PC-ponte. Vuoto = usa l'ID cassa.
                            </p>
                        </div>
                        <div class="modal-field">
                            <label for="modalBridgePrinterType">Stampante del ponte *</label>
                            <select id="modalBridgePrinterType" v-model="modalData.bridge_printer_type"
                                @change="onBridgePrinterTypeChange">
                                <option value="USB">USB (locale sul ponte)</option>
                                <option value="RETE">RETE (IP)</option>
                            </select>
                        </div>
                        <div class="modal-field" v-if="modalData.bridge_printer_type !== 'RETE'">
                            <label for="modalBridgeIp">IP del PC-ponte (per la ricerca)</label>
                            <div class="win-printer-wrap">
                                <input type="text" id="modalBridgeIp" v-model.trim="modalData.bridge_host"
                                    placeholder="es. 192.168.1.50">
                                <button type="button" id="discoverBridgeNativeBtn"
                                    @click="discoverBridgeNativePrinters" :disabled="loadingBridgeNativePrinters"
                                    title="Cerca le stampanti sul PC-ponte">
                                    <?= pos_icon('refresh-cw', ['class' => 'icon-refresh', ':class' => "{ 'spin-animation': loadingBridgeNativePrinters }"]) ?>
                                    {{ loadingBridgeNativePrinters ? 'Ricerca in corso...' : 'Ricerca Stampanti' }}
                                </button>
                            </div>
                            <p class="modal-field-hint">
                                Basta che opensagra sia avviato sul PC-ponte. L'IP serve solo ora, non in stampa.
                            </p>
                        </div>
                        <div class="modal-field">
                            <label for="modalBridgePrinter">
                                {{ modalData.bridge_printer_type === 'RETE' ? 'Indirizzo IP stampante *' : 'Nome stampante sul ponte *' }}
                            </label>
                            <div v-if="modalData.bridge_printer_type !== 'RETE'" class="win-printer-wrap">
                                <select id="modalBridgePrinter" v-model="bridgeNativeSelectValue"
                                    :class="{ 'is-placeholder': !bridgeNativeSelectValue }"
                                    @change="onBridgeNativePrinterSelectChange">
                                    <option value="" disabled hidden>Cerca o inserisci manualmente</option>
                                    <option value="__manual__">Manuale...</option>
                                    <option v-for="p in bridgeNativePrinters" :key="p" :value="p">{{ p }}</option>
                                </select>
                                <input type="text" id="modalBridgePrinterManual" ref="bridgeNativeManualInput"
                                    v-model="modalData.nome_indirizzo" v-show="bridgeNativeSelectValue === '__manual__'"
                                    placeholder="Nome stampante Windows / coda CUPS">
                            </div>
                            <input v-else type="text" id="modalBridgePrinterRete" v-model.trim="modalData.nome_indirizzo"
                                placeholder="Indirizzo IP della stampante di rete">
                        </div>
                    </template>

                    <div class="modal-field"
                        v-if="modalData.tipo_stampante !== 'BLUETOOTH' && modalData.tipo_stampante !== 'BRIDGE_NATIVE'">
                        <label>{{ nomeIndirizzoLabel }} *</label>

                        <!-- USB / stampante locale: un solo select, la discovery e l'hint
                             seguono l'OS dell'host (serverIsWindows). Copre anche le righe
                             legacy WIN_USB / LINUX_USB quando si modifica una cassa vecchia. -->
                        <div id="modalNomeIndirizzoWrap">
                            <div v-if="isUsbFamilyType" class="win-printer-wrap">
                                <select id="modalNomeIndirizzoSelect" :class="{ 'is-placeholder': !usbSelectValue }"
                                    v-model="usbSelectValue" @change="onUsbPrinterSelectChange">
                                    <option value="" disabled selected hidden>Seleziona la stampante dall'elenco
                                    </option>
                                    <option value="__manual__">Manuale...</option>
                                    <option v-for="printer in usbPrinters" :key="printer" :value="printer">{{ printer }}
                                    </option>
                                </select>
                                <input type="text" id="modalNomeIndirizzoManual" ref="usbManualInput"
                                    v-model="modalData.nome_indirizzo" v-show="usbSelectValue === '__manual__'"
                                    :placeholder="serverIsWindows ? 'Inserisci nome stampante Windows' : 'Inserisci nome stampante Linux o device path'">
                            </div>
                            <input v-else type="text" id="modalNomeIndirizzo" v-model="modalData.nome_indirizzo"
                                :placeholder="nomeIndirizzoPlaceholder">
                        </div>
                    </div>

                    <!-- Mostra il campo Porta per RETE (anche stampante RETE dietro un ponte). -->
                    <div class="modal-field"
                        v-if="modalData.tipo_stampante === 'RETE' || (modalData.tipo_stampante === 'BRIDGE_NATIVE' && modalData.bridge_printer_type === 'RETE')">
                        <label for="modalPorta">Porta *</label>
                        <input type="number" id="modalPorta" min="0" v-model.number="modalData.porta">
                    </div>
                    <div class="modal-field payment-config-field">
                        <label>Metodi di pagamento abilitati</label>
                        <div class="payment-toggles">
                            <label class="payment-toggle">
                                <input type="checkbox" v-model="modalData.abilita_contanti"
                                    @click="preventModalCashDisable">
                                <span><?= pos_icon('cash', ['class' => 'payment-toggle-icon']) ?>Contanti</span>
                            </label>
                            <label class="payment-toggle">
                                                        <input type="checkbox" v-model="modalData.abilita_carta"
                                                            @change="ensureModalCashFallback">
                                <span><?= pos_icon('credit-card', ['class' => 'payment-toggle-icon']) ?>Carta</span>
                            </label>
                            <label class="payment-toggle">
                                                        <input type="checkbox" v-model="modalData.abilita_satispay"
                                                            @change="ensureModalCashFallback">
                                <span><?= pos_icon('satispay', ['class' => 'payment-toggle-icon payment-toggle-icon--satispay']) ?>Satispay</span>
                            </label>
                        </div>
                    </div>
                    <div class="modal-field">
                        <label for="modalFondoCassa">Fondo cassa (€)</label>
                        <input type="number" id="modalFondoCassa" min="0" step="0.01" v-model.number="modalData.fondo_cassa">
                    </div>
                </div>


                <div class="printer-modal-foot">
                    <button type="button" class="btn-danger" id="modalDeleteBtn"
                        :disabled="isCreatingRecord || !modalData.cassa_id" @click="deleteRecord">
                        <?= pos_icon('trash-simple') ?>
                        Elimina
                    </button>
                    <div class="modal-actions-right">
                        <button type="button" class="btn-secondary" id="modalTestBtn"
                            @click="testCurrentModalPrinter">Test</button>
                        <button type="button" class="btn-primary" id="modalSaveBtn" @click="saveRecord">Salva</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <script>
        // OS dell'host che serve questa pagina (== host che stampa nel modello wrapper).
        // Serve al modal per decidere quale discovery USB lanciare e che hint mostrare.
        window.__OPENSAGRA_SERVER_OS__ = <?= json_encode(PHP_OS_FAMILY) ?>;

        // Invia i byte ESC/POS in Base64 all'app RawBT tramite il suo URI scheme dedicato
        function inviaBase64ARawBT(base64Data) {
            window.location.href = 'rawbt:base64,' + base64Data;
        }

        const app = Vue.createApp({
            data() {
                return {
                    stampantiData: [],
                    winPrinters: [],
                    linuxPrinters: [],
                    bridgeNativePrinters: [],
                    bridgeNativeSelectValue: '',
                    loadingBridgeNativePrinters: false,
                    bluetoothPrinters: [],
                    // OS dell'host che stampa: decide discovery e hint del tipo USB unificato.
                    serverOs: String(window.__OPENSAGRA_SERVER_OS__ || 'Windows'),
                    usbSelectValue: '',
                    searchQuery: '',
                    sortKey: '',
                    sortDirection: 'asc',
                    modalOpen: false,
                    isCreatingRecord: false,
                    currentEditIndex: null,
                    modalData: { cassa_id: '', tipo_stampante: 'USB', nome_indirizzo: '', porta: 0, bridge_host: '', bridge_printer_type: '', bridge_topic: '', abilita_contanti: true, abilita_carta: false, abilita_satispay: false, fondo_cassa: 0 },
                    _windowsPrintersPromise: null,
                    _linuxPrintersPromise: null,
                    expandedRows: {}
                };
            },
            computed: {
                filteredStampanti() {
                    const filtro = this.normalizzaTesto(this.searchQuery);
                    const stampanti = this.stampantiData
                        .map((record, index) => ({ record, index }))
                        .filter(({ record }) => {
                            if (!filtro) {
                                return true;
                            }
                            const haystack = [record.cassa_id, record.tipo_stampante, record.nome_indirizzo, record.bridge_host, record.porta]
                                .map(this.normalizzaTesto)
                                .join(' ');
                            return haystack.includes(filtro);
                        });

                    if (!this.sortKey) {
                        return stampanti;
                    }

                    return stampanti.sort((a, b) => this.compareValues(a.record[this.sortKey], b.record[this.sortKey]) * (this.sortDirection === 'asc' ? 1 : -1));
                },
                serverIsWindows() {
                    return String(this.serverOs || '').toLowerCase() === 'windows';
                },
                // Tipo USB unificato + alias legacy: stesso trattamento lato UI.
                isUsbFamilyType() {
                    return ['USB', 'WIN_USB', 'LINUX_USB'].includes(this.modalData.tipo_stampante);
                },
                // Elenco per il select USB: segue l'OS dell'host che stampa.
                usbPrinters() {
                    return this.serverIsWindows ? this.winPrinters : this.linuxPrinters;
                },
                nomeIndirizzoPlaceholder() {
                    return this.modalData.tipo_stampante === 'RETE' ? 'Indirizzo IP stampante' : 'Nome stampante o device path';
                },
                nomeIndirizzoLabel() {
                    return this.modalData.tipo_stampante === 'RETE' ? 'Indirizzo IP' : 'Nome Stampante';
                },
                resolvedNomeIndirizzo() {
                    // Per BLUETOOTH, non serve nome_indirizzo, quindi restituisce '-'
                    if (this.modalData.tipo_stampante === 'BLUETOOTH') {
                        return '-';
                    }
                    if (this.isUsbFamilyType && this.usbSelectValue && this.usbSelectValue !== '__manual__') {
                        return (this.usbSelectValue || '').trim();
                    }
                    return (this.modalData.nome_indirizzo || '').trim();
                }
            },
            methods: {
                normalizzaTesto(value) {
                    return String(value || '').toLowerCase().trim();
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

                isPaymentEnabled(record, key) {
                    return Number(record && record[key]) === 1;
                },

                isRowExpanded(cassaId) {
                    return Boolean(this.expandedRows[cassaId]);
                },

                toggleRowExpanded(cassaId) {
                    this.expandedRows[cassaId] = !this.isRowExpanded(cassaId);
                },

                ensureModalCashFallback() {
                    if (!this.modalData.abilita_carta && !this.modalData.abilita_satispay) {
                        this.modalData.abilita_contanti = true;
                    }
                },

                preventCashDisable(record, event) {
                    if (this.isPaymentEnabled(record, 'abilita_contanti')
                        && !this.isPaymentEnabled(record, 'abilita_carta')
                        && !this.isPaymentEnabled(record, 'abilita_satispay')) {
                        event.preventDefault();
                        this.mostraMessaggio('Attivare Carta o Satispay prima di disabilitare Contanti.', 'error');
                    }
                },

                preventModalCashDisable(event) {
                    if (this.modalData.abilita_contanti
                        && !this.modalData.abilita_carta
                        && !this.modalData.abilita_satispay) {
                        event.preventDefault();
                        this.mostraMessaggio('Attivare Carta o Satispay prima di disabilitare Contanti.', 'error');
                    }
                },

                async updatePaymentMethod(record, key, enabled) {
                    const nextRecord = { ...record, [key]: enabled ? 1 : 0 };
                    if (!nextRecord.abilita_carta && !nextRecord.abilita_satispay) {
                        nextRecord.abilita_contanti = 1;
                    }

                    try {
                        const data = await $.ajax({
                            url: '../api/stampanti.php',
                            type: 'POST',
                            contentType: 'application/json',
                            dataType: 'json',
                            data: JSON.stringify({
                                action: 'update',
                                cassa_id_old: record.cassa_id,
                                cassa_id: record.cassa_id,
                                tipo_stampante: record.tipo_stampante,
                                nome_indirizzo: record.nome_indirizzo,
                                porta: record.porta,
                                bridge_host: record.bridge_host || '',
                                abilita_contanti: nextRecord.abilita_contanti,
                                abilita_carta: nextRecord.abilita_carta,
                                abilita_satispay: nextRecord.abilita_satispay,
                                fondo_cassa: record.fondo_cassa
                            })
                        });
                        if (data.error) {
                            this.mostraMessaggio(data.error, 'error');
                            await this.caricaStampanti();
                            return;
                        }
                        Object.assign(record, nextRecord);
                       // this.mostraMessaggio('Metodi di pagamento aggiornati.', 'success');
                    } catch (xhr) {
                        this.mostraMessaggio('Errore nel salvataggio dei metodi di pagamento.', 'error');
                        await this.caricaStampanti();
                    }
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

                formatTipoStampante(tipo) {
                    const mappaTipi = {
                        'USB': 'USB / LOCALE',
                        'WIN_USB': 'USB (Windows)',
                        'LINUX_USB': 'USB (Linux)',
                        'RETE': 'RETE',
                        'BRIDGE_NATIVE': 'BRIDGE NATIVO',
                        'BLUETOOTH': 'BLUETOOTH'
                    };
                    return mappaTipi[tipo] || tipo || '-';
                },

                async caricaStampantiWindows() {
                    if (!this._windowsPrintersPromise) {
                        this._windowsPrintersPromise = $.ajax({
                            url: "../api/stampanti.php?action=list_win_printers",
                            type: "GET",
                            dataType: "json"
                        }).then((data) => {
                            this.winPrinters = Array.isArray(data.printers) ? data.printers : [];
                            return this.winPrinters;
                        }).catch(() => {
                            this.winPrinters = [];
                            return this.winPrinters;
                        });
                    }

                    return this._windowsPrintersPromise;
                },
                async caricaStampantiLinux() {
                    if (!this._linuxPrintersPromise) {
                        this._linuxPrintersPromise = $.ajax({
                            url: "../api/stampanti.php",
                            type: "GET",
                            dataType: "json",
                            data: { action: "list_linux_printers" }
                        }).then((data) => {
                            const printers = Array.isArray(data.printers) ? data.printers : [];
                            this.linuxPrinters = printers;

                            if (Array.isArray(data.debug)) {
                                console.log('Diagnostica discovery stampanti Linux:', data.debug);
                            }
                            if (printers.length === 0) {
                                this.mostraMessaggio('Nessuna stampante CUPS rilevata. Vedi console per la diagnostica.', 'error');
                            }
                            if (data.warning) {
                                this.mostraMessaggio(data.warning, 'error');
                            }

                            return this.linuxPrinters;
                        }).catch(() => {
                            this.linuxPrinters = [];
                            this.mostraMessaggio('Errore nel recupero stampanti Linux. Verifica CUPS o i device /dev/usb/lp*.', 'error');
                            return this.linuxPrinters;
                        });
                    }

                    return this._linuxPrintersPromise;
                },
                async caricaStampantiPerTipo(tipoStampante) {
                    // USB unificato (+ alias legacy WIN_USB / LINUX_USB): la discovery
                    // segue l'OS dell'host che serve la pagina, non il valore salvato.
                    if (['USB', 'WIN_USB', 'LINUX_USB'].includes(tipoStampante)) {
                        if (this.serverIsWindows) {
                            await this.caricaStampantiWindows();
                        } else {
                            await this.caricaStampantiLinux();
                        }
                        this.usbSelectValue = this.usbPrinters.includes(this.modalData.nome_indirizzo)
                            ? this.modalData.nome_indirizzo
                            : (this.modalData.nome_indirizzo ? '__manual__' : '');
                    }
                },

                // Select stampante USB locale: propaga la scelta in nome_indirizzo
                // (o apre il campo manuale).
                onUsbPrinterSelectChange() {
                    if (this.usbSelectValue && this.usbSelectValue !== '__manual__') {
                        this.modalData.nome_indirizzo = this.usbSelectValue;
                    } else if (this.usbSelectValue === '__manual__') {
                        this.$nextTick(() => {
                            this.$refs.usbManualInput && this.$refs.usbManualInput.focus();
                        });
                    } else {
                        this.modalData.nome_indirizzo = '';
                    }
                },

                // --- BRIDGE NATIVO: stampante del ponte (modello "a una riga") ---
                onBridgePrinterTypeChange() {
                    if (this.modalData.bridge_printer_type === 'RETE') {
                        if (!this.modalData.porta) {
                            this.modalData.porta = 9100;
                        }
                    } else {
                        this.modalData.porta = 0;
                    }
                    this.modalData.nome_indirizzo = '';
                    this.bridgeNativeSelectValue = '';
                    this.bridgeNativePrinters = [];
                },
                onBridgeNativePrinterSelectChange() {
                    if (this.bridgeNativeSelectValue && this.bridgeNativeSelectValue !== '__manual__') {
                        this.modalData.nome_indirizzo = this.bridgeNativeSelectValue;
                    } else if (this.bridgeNativeSelectValue === '__manual__') {
                        this.$nextTick(() => {
                            this.$refs.bridgeNativeManualInput && this.$refs.bridgeNativeManualInput.focus();
                        });
                    } else {
                        this.modalData.nome_indirizzo = '';
                    }
                },
                applyBridgeNativePrinter(name) {
                    this.modalData.nome_indirizzo = name;
                    this.bridgeNativeSelectValue = this.bridgeNativePrinters.includes(name) ? name : '__manual__';
                },
                // Discovery via proxy server-to-server: chiede al PC-ponte il suo
                // elenco stampanti reali (prima Windows, poi Linux). Nessun QZ, nessun IP a runtime.
                async discoverBridgeNativePrinters() {
                    if (this.loadingBridgeNativePrinters) {
                        return;
                    }
                    const host = (this.modalData.bridge_host || '').trim();
                    if (!host) {
                        this.mostraMessaggio("Inserisci prima l'IP del PC-ponte.", 'error');
                        return;
                    }

                    this.loadingBridgeNativePrinters = true;
                    try {
                        let result = null;
                        for (const os of ['win', 'linux']) {
                            const res = await $.ajax({
                                url: '../api/stampanti.php',
                                type: 'GET',
                                dataType: 'json',
                                data: { action: 'remote_list_printers', host: host, os: os }
                            }).catch((xhr) => {
                                let msg = '';
                                try { msg = (JSON.parse(xhr.responseText) || {}).error || ''; } catch (e) { }
                                return { error: msg || 'Richiesta al ponte fallita.' };
                            });
                            if (!result) {
                                result = res;
                            }
                            if (res && Array.isArray(res.printers) && res.printers.length) {
                                result = res;
                                break;
                            }
                        }

                        const printers = (result && Array.isArray(result.printers)) ? result.printers : [];
                        this.bridgeNativePrinters = printers;

                        if (result && result.bridge_id && !(this.modalData.bridge_topic || '').trim()) {
                            this.modalData.bridge_topic = result.bridge_id;
                        }

                        if (!printers.length) {
                            this.mostraMessaggio((result && result.error) ? result.error : 'Nessuna stampante reale trovata sul ponte.', 'error');
                            return;
                        }

                        if (printers.length === 1) {
                            this.applyBridgeNativePrinter(printers[0]);
                            this.mostraMessaggio('Stampante del ponte: ' + printers[0], 'success');
                            return;
                        }

                        window.showToast('Stampanti trovate su ' + host, 'info', {
                            duration: 0,
                            title: 'Scegli la stampante del ponte',
                            actions: printers.map((p) => ({
                                label: p,
                                onClick: () => this.applyBridgeNativePrinter(p)
                            }))
                        });
                    } finally {
                        this.loadingBridgeNativePrinters = false;
                    }
                },

                async caricaStampanti() {
                    try {
                        const data = await $.ajax({
                            url: "../api/stampanti.php",
                            type: "GET",
                            dataType: "json"
                        });
                        if (data.error) {
                            this.mostraMessaggio(data.error, 'error');
                            return;
                        }
                        this.stampantiData = data;
                    } catch (xhr) {
                        let errorMsg = "Errore nel caricamento dei dati";
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) {
                                errorMsg += ": " + response.error;
                            }
                        } catch (e) {
                            errorMsg += ": " + (xhr && xhr.responseText ? xhr.responseText : '');
                        }
                        this.mostraMessaggio(errorMsg, 'error');
                    }
                },
                getTestEndpointByType(tipoStampante) {
                    if (tipoStampante === 'RETE') {
                        return '../print/test_print_lan.php';
                    }
                    if (tipoStampante === 'BRIDGE_NATIVE') {
                        return '../print/test_print_bridge_native.php';
                    }
                    if (tipoStampante === 'BLUETOOTH') {
                        return '../print/test_print_bluetooth.php';
                    }
                    return '../print/test_print_usb.php';
                },
                async testPrinterConfig(config) {
                    // Per Bluetooth non serve nome_indirizzo
                    if (!config || !config.tipo_stampante || (!config.nome_indirizzo && config.tipo_stampante !== 'BLUETOOTH')) {
                        this.mostraMessaggio('Config stampante incompleta per il test.', 'error');
                        return;
                    }

                    this.mostraMessaggio('Invio test a ' + config.cassa_id + '...', 'success');

                    try {
                        const endpoint = this.getTestEndpointByType(config.tipo_stampante);
                        const response = await $.ajax({
                            url: endpoint,
                            type: 'POST',
                            contentType: 'application/json',
                            dataType: 'json',
                            data: JSON.stringify({
                                tipo_stampante: config.tipo_stampante,
                                nome_indirizzo: config.nome_indirizzo,
                                porta: config.porta,
                                cassa_id: config.cassa_id,
                                bridge_host: config.bridge_host || '',
                                bridge_printer_type: config.bridge_printer_type || '',
                                bridge_topic: config.bridge_topic || ''
                            })
                        });

                        if (config.tipo_stampante === 'BLUETOOTH' && response && response.success) {
                            if (response.base64) {
                                inviaBase64ARawBT(response.base64);
                            } else {
                                this.mostraMessaggio('Dati stampa RawBT non disponibili', 'error');
                                return;
                            }
                        }

                        if (response && response.success) {
                            const message = response.message || 'Test stampa inviato con successo.';
                            this.mostraMessaggio(message, 'success');
                            if (response.debug) {
                                console.log('Test stampa debug:', response.debug);
                            }
                            return;
                        }

                        this.mostraMessaggio((response && response.error) ? response.error : 'Test stampa fallito.', 'error');
                    } catch (err) {
                        console.error('Errore test stampa:', err);
                        this.mostraMessaggio(err && err.message ? err.message : 'Errore durante il test stampa.', 'error');
                    }
                },
                testCurrentModalPrinter() {
                    const config = {
                        cassa_id: (this.modalData.cassa_id || '').trim() || 'cassa_test',
                        tipo_stampante: this.modalData.tipo_stampante,
                        nome_indirizzo: this.resolvedNomeIndirizzo,
                        porta: Number(this.modalData.porta || 0),
                        bridge_host: (this.modalData.bridge_host || '').trim(),
                        bridge_printer_type: this.modalData.tipo_stampante === 'BRIDGE_NATIVE' ? (this.modalData.bridge_printer_type || '') : '',
                        bridge_topic: this.modalData.tipo_stampante === 'BRIDGE_NATIVE' ? ((this.modalData.bridge_topic || '').trim() || (this.modalData.cassa_id || '').trim()) : ''
                    };

                    void this.testPrinterConfig(config);
                },
                onTipoStampanteChange() {
                    if (this.modalData.tipo_stampante === 'RETE') {
                        this.modalData.porta = 9100;
                    } else {
                        this.modalData.porta = 0;
                    }
                    if (this.modalData.tipo_stampante === 'BRIDGE_NATIVE') {
                        // Modello "a una riga": si configura direttamente la stampante
                        // del ponte. Default sensati e pulizia dei valori ereditati.
                        if (!this.modalData.bridge_printer_type) {
                            this.modalData.bridge_printer_type = 'USB';
                        }
                        if (!this.modalData.bridge_topic) {
                            this.modalData.bridge_topic = (this.modalData.cassa_id || '').trim();
                        }
                        if (this.modalData.bridge_printer_type === 'RETE' && !this.modalData.porta) {
                            this.modalData.porta = 9100;
                        }
                        this.bridgeNativePrinters = [];
                        this.bridgeNativeSelectValue = this.modalData.nome_indirizzo ? '__manual__' : '';
                    }
                    // Aggiorna i valori delle select in base al tipo di stampante selezionato
                    void this.caricaStampantiPerTipo(this.modalData.tipo_stampante);
                },
                openModal(index) {
                    this.isCreatingRecord = index === null;
                    this.currentEditIndex = index;

                    this.modalData = this.isCreatingRecord
                        ? { cassa_id: '', tipo_stampante: 'USB', nome_indirizzo: '', porta: 0, bridge_host: '', bridge_printer_type: '', bridge_topic: '', abilita_contanti: true, abilita_carta: false, abilita_satispay: false, fondo_cassa: 0 }
                        : {
                            ...this.stampantiData[index],
                            abilita_contanti: Number(this.stampantiData[index].abilita_contanti) === 1,
                            abilita_carta: Number(this.stampantiData[index].abilita_carta) === 1,
                            abilita_satispay: Number(this.stampantiData[index].abilita_satispay) === 1
                        };

                    // Migrazione pigra: le righe legacy WIN_USB / LINUX_USB si presentano
                    // come 'USB' nel modal. Il DB cambia solo se l'utente salva.
                    if (['WIN_USB', 'LINUX_USB'].includes(this.modalData.tipo_stampante)) {
                        this.modalData.tipo_stampante = 'USB';
                    }

                    this.modalOpen = true;

                    void this.caricaStampantiPerTipo(this.modalData.tipo_stampante);

                    if (this.modalData.tipo_stampante === 'BRIDGE_NATIVE') {
                        if (!this.modalData.bridge_printer_type) {
                            this.modalData.bridge_printer_type = 'USB';
                        }
                        if (!this.modalData.bridge_topic) {
                            this.modalData.bridge_topic = (this.modalData.cassa_id || '').trim();
                        }
                        this.bridgeNativePrinters = [];
                        this.bridgeNativeSelectValue = this.modalData.nome_indirizzo ? '__manual__' : '';
                    }
                    // La discovery USB (Windows o Linux) parte da caricaStampantiPerTipo,
                    // che sceglie in base a serverIsWindows.
                },

                closeModal() {
                    this.modalOpen = false;
                    this.currentEditIndex = null;
                    this.isCreatingRecord = false;
                },
                handleEscapeKey(event) {
                    if (event.key === 'Escape' && this.modalOpen) {
                        this.closeModal();
                    }
                },
                async saveRecord() {
                    const cassaId = (this.modalData.cassa_id || '').trim();
                    const tipoStampante = this.modalData.tipo_stampante;
                    const nomeIndirizzo = this.resolvedNomeIndirizzo;
                    const bridgeHost = (this.modalData.bridge_host || '').trim();
                    const porta = this.modalData.porta;
                    const record = this.isCreatingRecord ? null : this.stampantiData[this.currentEditIndex];
                    const isBluetooth = tipoStampante === 'BLUETOOTH';

                    if (!cassaId || !tipoStampante || (!nomeIndirizzo && !isBluetooth)) {
                        this.mostraMessaggio("Tutti i campi sono obbligatori.", 'error');
                        return;
                    }

                    if (!isBluetooth && (porta === '' || porta === null || typeof porta === 'undefined' || isNaN(porta) || porta < 0)) {
                        this.mostraMessaggio("La porta deve essere un numero valido.", 'error');
                        return;
                    }

                    if (tipoStampante === 'BRIDGE_NATIVE' && !(this.modalData.bridge_topic || '').trim() && !cassaId) {
                        this.mostraMessaggio("Indica l'ID ponte (topic) oppure l'ID cassa.", 'error');
                        return;
                    }

                    if (!this.modalData.abilita_contanti && !this.modalData.abilita_carta && !this.modalData.abilita_satispay) {
                        this.mostraMessaggio('Definire almeno un metodo di pagamento', 'error');
                        return;
                    }

                    const action = record && record.cassa_id ? 'update' : 'create';

                    try {
                        const data = await $.ajax({
                            url: "../api/stampanti.php",
                            type: "POST",
                            contentType: "application/json",
                            dataType: "json",
                            data: JSON.stringify({
                                action: action,
                                cassa_id_old: record ? (record.cassa_id || null) : null,
                                cassa_id: cassaId,
                                tipo_stampante: tipoStampante,
                                nome_indirizzo: nomeIndirizzo,
                                porta: porta,
                                bridge_host: bridgeHost,
                                bridge_printer_type: tipoStampante === 'BRIDGE_NATIVE' ? (this.modalData.bridge_printer_type || '') : '',
                                bridge_topic: tipoStampante === 'BRIDGE_NATIVE' ? ((this.modalData.bridge_topic || '').trim() || cassaId) : '',
                                abilita_contanti: this.modalData.abilita_contanti ? 1 : 0,
                                abilita_carta: this.modalData.abilita_carta ? 1 : 0,
                                abilita_satispay: this.modalData.abilita_satispay ? 1 : 0,
                                fondo_cassa: Number(this.modalData.fondo_cassa) || 0
                            })
                        });

                        if (data.error) {
                            this.mostraMessaggio(data.error, 'error');
                        } else {
                            this.mostraMessaggio(data.message, 'success');
                            this.closeModal();
                            await this.caricaStampanti();
                        }
                    } catch (xhr) {
                        let errorMsg = "Errore nel salvataggio dei dati";
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) {
                                errorMsg += ": " + response.error;
                            }
                        } catch (e) {
                            errorMsg += ": " + (xhr && xhr.responseText ? xhr.responseText : '');
                        }
                        this.mostraMessaggio(errorMsg, 'error');
                    }
                },
                async deleteRecord() {
                
                    if (this.isCreatingRecord || this.currentEditIndex === null) {
                        this.mostraMessaggio("Impossibile eliminare un record non salvato.", 'error');
                        return;
                    }

                    const record = this.stampantiData[this.currentEditIndex];
                    console.log("Record estratto dall'array:", record);
                    console.log("Valore di record.cassa_id:", record ? record.cassa_id : 'RECORD MANCANTE');

                    if (!record || !record.cassa_id) {
                        this.mostraMessaggio("Impossibile eliminare un record non salvato.", 'error');
                        return;
                    }
                    

                    try {
                        const data = await $.ajax({
                            url: "../api/stampanti.php",
                            type: "POST",
                            contentType: "application/json",
                            dataType: "json",
                            data: JSON.stringify({
                                action: "delete",
                                cassa_id: record.cassa_id
                            })
                        });
                        

                        if (data.error) {
                        } else {
                            this.mostraMessaggio(data.message, 'success');
                            this.closeModal();
                            await this.caricaStampanti();
                        }
                    } catch (xhr) {
                        let errorMsg = "Errore nell'eliminazione del record";
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) {
                                errorMsg += ": " + response.error;
                            }
                        } catch (e) {
                            errorMsg += ": " + (xhr && xhr.responseText ? xhr.responseText : '');
                        }
                        this.mostraMessaggio(errorMsg, 'error');
                    }
                },
                mostraMessaggio(messaggio, tipo) {
                    window.showToast(messaggio, tipo === 'error' ? 'error' : 'success', { duration: 5200 });
                }
            },
            mounted() {
                document.addEventListener('keydown', this.handleEscapeKey);
                this.caricaStampanti();
            },
            beforeUnmount() {
                document.removeEventListener('keydown', this.handleEscapeKey);
            }
        });

        app.mount('#app');
    </script>
</body>

</html>