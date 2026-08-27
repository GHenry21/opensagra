<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/conf_stampanti.css">
    <!-- Importa tutte le favicon con una sola riga -->
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <title>Configurazione Stampanti</title>
    <script src="../assets/js/vue.global.js"></script>
    <script src="../assets/js/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme.js"></script>
    <script src="../assets/js/qz-tray.js"></script>

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
                        <svg class="panel-title-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect width="12" height="8" x="6" y="14"></rect></svg>
                        <h2>Configurazione Stampanti</h2>
                    </div>
                    <div id="message"></div>
                    <div class="printer-toolbar">
                        <div class="search-wrap">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                aria-hidden="true">
                                <circle cx="11" cy="11" r="8"></circle>
                                <path d="m21 21-4.3-4.3"></path>
                            </svg>
                            <input type="text" id="searchPrinter" placeholder="Cerca stampanti..."
                                aria-label="Cerca stampanti" v-model="searchQuery">
                        </div>
                        <button class="btn-add" id="btnAddRow" @click="openModal(null)">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                aria-hidden="true">
                                <path d="M12 5v14"></path>
                                <path d="M5 12h14"></path>
                            </svg>
                            Nuova Stampante
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
                                            <th :aria-sort="sortAria('qz_host')"><button type="button"
                                                    class="table-sort-btn" @click="toggleSort('qz_host')">QZ Host <span
                                                        aria-hidden="true" v-html="sortIcon('qz_host')"></span></button>
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
                                        <tr v-for="item in filteredStampanti" :key="item.record.cassa_id || item.index" class="printer-card-row">
                                            <td class="printer-col-actions" data-label="AZIONI">
                                                <button type="button" class="row-test-btn" title="Test stampa"
                                                    aria-label="Test stampa" @click="testPrinterConfig(item.record)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                        fill="none" stroke="currentColor" stroke-width="2"
                                                        stroke-linecap="round" stroke-linejoin="round"
                                                        aria-hidden="true">
                                                        <path d="M6 9V2h12v7"></path>
                                                        <path
                                                            d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2">
                                                        </path>
                                                        <path d="M6 14h12v8H6z"></path>
                                                    </svg>
                                                </button>
                                                <button type="button" class="row-edit-btn" title="Modifica"
                                                    aria-label="Modifica stampante" @click="openModal(item.index)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                        fill="none" stroke="currentColor" stroke-width="2"
                                                        stroke-linecap="round" stroke-linejoin="round"
                                                        aria-hidden="true">
                                                        <path d="M12 20h9"></path>
                                                        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                                                    </svg>
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
                                                        <span>Contanti</span>
                                                    </label>
                                                    <label class="payment-toggle" title="Abilita Carta">
                                                        <input type="checkbox" :checked="isPaymentEnabled(item.record, 'abilita_carta')"
                                                            @change="updatePaymentMethod(item.record, 'abilita_carta', $event.target.checked)">
                                                        <span>Carta</span>
                                                    </label>
                                                    <label class="payment-toggle" title="Abilita Satispay">
                                                        <input type="checkbox" :checked="isPaymentEnabled(item.record, 'abilita_satispay')"
                                                            @change="updatePaymentMethod(item.record, 'abilita_satispay', $event.target.checked)">
                                                        <span>Satispay</span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td data-label="Nome/IP">{{ item.record.nome_indirizzo || '-' }}</td>
                                            <td data-label="QZ Host">{{ item.record.qz_host || '-' }}</td>
                                            <td class="printer-col-porta" data-label="Porta">{{ item.record.porta || '0' }}</td>

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
                    <h3 id="modalTitle">{{ isCreatingRecord ? 'Nuova Stampante' : 'Modifica Stampante' }}</h3>
                    <button type="button" class="modal-close" id="modalClose" aria-label="Chiudi" @click="closeModal">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 6 6 18"></path>
                            <path d="m6 6 12 12"></path>
                        </svg>
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
                            <option value="WIN_USB">WINDOWS USB</option>
                            <option value="LINUX_USB">LINUX USB</option>
                            <option value="RETE">RETE</option>
                            <option value="BRIDGE">BRIDGE</option>
                            <option value="BLUETOOTH">BLUETOOTH</option>
                        </select>
                    </div>
                    <!-- Mostra il campo Host QZ Tray solo se il tipo è BRIDGE -->
                    <div class="modal-field" id="modalBridgeHostField"
                        :style="{ display: modalData.tipo_stampante === 'BRIDGE' ? '' : 'none' }">
                        <label>Host QZ Tray *</label>
                        <div id="modalBridgeHostWrap">
                            <input type="text" id="modalQzHost" v-model.trim="modalData.qz_host"
                                placeholder="es. 192.168.1.50 o pc-cassa.local">
                        </div>
                    </div>
   

                    <!-- Button per refresh stampanti Bridge-->
                    <div class="modal-field" v-if="modalData.tipo_stampante !== 'BLUETOOTH'">
                        <label>{{ nomeIndirizzoLabel }} *</label>
                        <button v-if="modalData.tipo_stampante === 'BRIDGE'" type="button" id="refreshBridgePrintersBtn"
                            @click="refreshBridgePrinters" :disabled="loadingQzPrinters"
                            title="Ricerca Stampanti Bridge">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                aria-hidden="true" class="icon-refresh"
                                :class="{ 'spin-animation': loadingQzPrinters }">
                                <path d="M21 2v6h-6"></path>
                                <path d="M3 12a9 9 0 0 1 14.5-7.5L21 8"></path>
                                <path d="M3 22v-6h6"></path>
                                <path d="M21 12a9 9 0 0 1-14.5 7.5L3 16"></path>
                            </svg>
                            {{ loadingQzPrinters ? 'Ricerca in corso...' : 'Ricerca Stampanti' }}
                        </button>

                        <!-- Mostra il select per le stampanti WIN_USB -->
                        <div id="modalNomeIndirizzoWrap">
                            <div v-if="modalData.tipo_stampante === 'WIN_USB'" class="win-printer-wrap">
                                <select id="modalNomeIndirizzoSelect" :class="{ 'is-placeholder': !winSelectValue }"
                                    v-model="winSelectValue" @change="onWinPrinterSelectChange">
                                    <!-- placeholder per selezione stampanti Windows -->
                                    <option value="" disabled selected hidden>Seleziona la stampante dall'elenco
                                    </option>
                                    <option value="__manual__">Manuale...</option>
                                    <option v-for="printer in winPrinters" :key="printer" :value="printer">{{ printer }}
                                    </option>
                                </select>
                                <input type="text" id="modalNomeIndirizzoManual" ref="manualInput"
                                    v-model="modalData.nome_indirizzo" v-show="winSelectValue === '__manual__'"
                                    placeholder="Inserisci nome stampante Windows">
                            </div>
                            <!-- Mostra il select per le stampanti Linux solo se il tipo è LINUX_USB -->
                            <div v-else-if="modalData.tipo_stampante === 'LINUX_USB'" class="win-printer-wrap">
                                <select id="modalNomeIndirizzoSelect" :class="{ 'is-placeholder': !linuxSelectValue }"
                                    v-model="linuxSelectValue" @change="onLinuxPrinterSelectChange">
                                    <option value="" disabled selected hidden>Seleziona la stampante dall'elenco
                                    </option>
                                    <option value="__manual__">Manuale...</option>
                                    <option v-for="printer in linuxPrinters" :key="printer" :value="printer">{{ printer
                                        }}</option>
                                </select>
                                <input type="text" id="modalNomeIndirizzoManual" ref="linuxManualInput"
                                    v-model="modalData.nome_indirizzo" v-show="linuxSelectValue === '__manual__'"
                                    placeholder="Inserisci nome stampante Linux o device path">
                            </div>
                            <!-- Mostra il select per le stampanti Bridge solo se il tipo è BRIDGE -->
                            <div v-else-if="modalData.tipo_stampante === 'BRIDGE'" class="win-printer-wrap">
                                <select id="modalNomeIndirizzoSelect" v-model="bridgeSelectValue"
                                    :class="{ 'is-placeholder': !bridgeSelectValue, 'field-highlight': highlightBridgeSelect }"
                                    @change="onBridgePrinterSelectChange">
                                    <option value="" disabled selected hidden>Seleziona la stampante dall'elenco
                                    </option>
                                    <option value="__manual__">Manuale...</option>
                                    <option v-for="printer in bridgePrinters" :key="printer" :value="printer">{{ printer
                                        }}</option>
                                </select>
                                <!-- Mostra il campo di input manuale solo se l'utente seleziona "Manuale..." nel select -->
                                <input type="text" id="modalNomeIndirizzoManual" ref="bridgeManualInput"
                                    v-model="modalData.nome_indirizzo" v-show="bridgeSelectValue === '__manual__'"
                                    placeholder="Inserisci nome stampante QZ">
                            </div>
                            <input v-else type="text" id="modalNomeIndirizzo" v-model="modalData.nome_indirizzo"
                                :placeholder="nomeIndirizzoPlaceholder">
                        </div>
                    </div>
                    <!-- Mostra il campo Porta solo se il tipo RETE -->
                    <div class="modal-field"
                        v-if="modalData.tipo_stampante === 'RETE'">
                        <label for="modalPorta">Porta *</label>
                        <input type="number" id="modalPorta" min="0" v-model.number="modalData.porta">
                    </div>
                    <div class="modal-field payment-config-field">
                        <label>Metodi di pagamento abilitati</label>
                        <div class="payment-toggles payment-toggles--modal">
                            <label class="payment-toggle">
                                <input type="checkbox" v-model="modalData.abilita_contanti"
                                    @click="preventModalCashDisable">
                                <span>Contanti</span>
                            </label>
                            <label class="payment-toggle">
                                                        <input type="checkbox" v-model="modalData.abilita_carta"
                                                            @change="ensureModalCashFallback">
                                <span>Carta</span>
                            </label>
                            <label class="payment-toggle">
                                                        <input type="checkbox" v-model="modalData.abilita_satispay"
                                                            @change="ensureModalCashFallback">
                                <span>Satispay</span>
                            </label>
                        </div>
                    </div>
                </div>


                <div class="printer-modal-foot">
                    <button type="button" class="btn-danger" id="modalDeleteBtn"
                        :disabled="isCreatingRecord || !modalData.cassa_id" @click="deleteRecord">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 6h18"></path>
                            <path d="M8 6V4h8v2"></path>
                            <path d="m19 6-1 14H6L5 6"></path>
                            <path d="M10 11v6"></path>
                            <path d="M14 11v6"></path>
                        </svg>
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

    <script src="../assets/js/qz-helper.js"></script>
    <script>

        let qzScriptLoadPromise = null;
        let qzConnectionTarget = null;

        function loadScript(url) {
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = url;
                script.async = true;
                script.onload = () => resolve(url);
                script.onerror = () => reject(new Error('Load failed: ' + url));
                document.head.appendChild(script);
            });
        }

        function normalizeQzHost(host) {
            return String(host || '')
                .trim()
                .replace(/^https?:\/\//i, '')
                .replace(/\/$/, '');
        }

        async function ensureQzLibraryLoaded() {
            if (window.qz) {
                return;
            }

            if (!qzScriptLoadPromise) {
                qzScriptLoadPromise = (async () => {
                    const candidates = [
                        'qz-tray.js',
                        '/qz-tray.js',
                        './qz-tray.js',
                        'http://localhost:8182/qz-tray.js',
                        'http://127.0.0.1:8182/qz-tray.js'
                    ];

                    const errors = [];
                    for (const url of candidates) {
                        try {
                            await loadScript(url);
                            if (window.qz) {
                                return;
                            }
                        } catch (err) {
                            errors.push(err.message);
                        }
                    }

                    throw new Error('QZ script non raggiungibile. Tentativi: ' + errors.join(' | '));
                })();
            }

            await qzScriptLoadPromise;

            if (!window.qz) {
                throw new Error('QZ Tray non disponibile nel browser.');
            }
        }
        // Carica la funziona JS per firmare i messaggi di QZ
        document.addEventListener("DOMContentLoaded", function () {
            setupQzSecurity();
        });

        async function ensureQzConnected(host, port = 8182) {
            await ensureQzLibraryLoaded();
            setupQzSecurity();

            const cleanHost = normalizeQzHost(host);
            if (!cleanHost) {
                throw new Error('Host QZ mancante nella configurazione stampante.');
            }

            const targetKey = cleanHost + ':' + Number(port || 8182);
            if (qz.websocket.isActive() && qzConnectionTarget === targetKey) {
                return;
            }

            if (qz.websocket.isActive()) {
                try {
                    await qz.websocket.disconnect();
                } catch (err) {
                    console.warn('Disconnessione QZ precedente fallita:', err);
                }
            }

            await qz.websocket.connect({
                host: cleanHost,
                usingSecure: false,
                retries: 2,
                delay: 0.25
            });

            qzConnectionTarget = targetKey;
        }

        // Invia i byte ESC/POS in Base64 all'app RawBT tramite il suo URI scheme dedicato
        function inviaBase64ARawBT(base64Data) {
            window.location.href = 'rawbt:base64,' + base64Data;
        }

        async function printBridgeViaQz(response) {
            const payload = response && typeof response === 'object' ? response : null;
            if (!payload) {
                throw new Error('Risposta bridge non valida.');
            }

            const printerName = String(payload.qz_printer_name || payload.printer || '').trim();
            const qzHost = String(payload.qz_host || payload.bridge_host || payload.host || '').trim();
            const qzPort = Number(payload.qz_port || 8182);
            const rawBase64 = String(payload.qz_data_base64 || '').trim();

            if (!printerName) {
                throw new Error('Nome stampante bridge mancante.');
            }
            if (!qzHost) {
                throw new Error('Host QZ bridge mancante.');
            }
            if (!rawBase64) {
                throw new Error('Dati ESC/POS bridge mancanti.');
            }

            await ensureQzConnected(qzHost, qzPort);
            await qz.printers.find(printerName);

            const config = qz.configs.create(printerName, {
                encoding: 'ISO-8859-1'
            });

            const data = [{
                type: 'raw',
                format: 'command',
                flavor: 'base64',
                data: rawBase64
            }];

            await qz.print(config, data);
        }

        const app = Vue.createApp({
            data() {
                return {
                    stampantiData: [],
                    winPrinters: [],
                    linuxPrinters: [],
                    bridgePrinters: [],
                    bluetoothPrinters: [],
                    linuxSelectValue: '',
                    bridgeSelectValue: '',
                    highlightBridgeSelect: false,
                    loadingQzPrinters: false,
                    searchQuery: '',
                    sortKey: '',
                    sortDirection: 'asc',
                    modalOpen: false,
                    isCreatingRecord: false,
                    currentEditIndex: null,
                    modalData: { cassa_id: '', tipo_stampante: 'WIN_USB', nome_indirizzo: '', porta: 0, qz_host: '', abilita_contanti: true, abilita_carta: false, abilita_satispay: false },
                    winSelectValue: '__manual__',
                    _windowsPrintersPromise: null,
                    _linuxPrintersPromise: null
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
                            const haystack = [record.cassa_id, record.tipo_stampante, record.nome_indirizzo, record.qz_host, record.porta]
                                .map(this.normalizzaTesto)
                                .join(' ');
                            return haystack.includes(filtro);
                        });

                    if (!this.sortKey) {
                        return stampanti;
                    }

                    return stampanti.sort((a, b) => this.compareValues(a.record[this.sortKey], b.record[this.sortKey]) * (this.sortDirection === 'asc' ? 1 : -1));
                },
                nomeIndirizzoPlaceholder() {
                    const tipo = this.modalData.tipo_stampante;
                    if (tipo === 'BRIDGE') {
                        return 'Nome stampante QZ Tray';
                    }
                    return tipo === 'RETE' ? 'Indirizzo IP stampante' : 'Nome stampante o device path';
                },
                nomeIndirizzoLabel() {
                    return this.modalData.tipo_stampante === 'RETE' ? 'Indirizzo IP' : 'Nome Stampante';
                },
                resolvedNomeIndirizzo() {
                    // Per BLUETOOTH, non serve nome_indirizzo, quindi restituisce '-'
                    if (this.modalData.tipo_stampante === 'BLUETOOTH') {
                        return '-';
                    }
                    if (this.modalData.tipo_stampante === 'WIN_USB' && this.winSelectValue !== '__manual__') {
                        return (this.winSelectValue || '').trim();
                    }
                    if (this.modalData.tipo_stampante === 'LINUX_USB' && this.linuxSelectValue && this.linuxSelectValue !== '__manual__') {
                        return (this.linuxSelectValue || '').trim();
                    }
                    if (this.modalData.tipo_stampante === 'BRIDGE' && this.bridgeSelectValue && this.bridgeSelectValue !== '__manual__') {
                        return (this.bridgeSelectValue || '').trim();
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
                                qz_host: record.qz_host || '',
                                abilita_contanti: nextRecord.abilita_contanti,
                                abilita_carta: nextRecord.abilita_carta,
                                abilita_satispay: nextRecord.abilita_satispay
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
                        'WIN_USB': 'WINDOWS USB',
                        'LINUX_USB': 'LINUX USB',
                        'RETE': 'RETE',
                        'BRIDGE': 'BRIDGE',
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
                    if (tipoStampante === 'WIN_USB') {
                        await this.caricaStampantiWindows();
                        this.winSelectValue = this.winPrinters.includes(this.modalData.nome_indirizzo)
                            ? this.modalData.nome_indirizzo
                            : '';
                        return;
                    }

                    if (tipoStampante === 'LINUX_USB') {
                        await this.caricaStampantiLinux();
                        this.linuxSelectValue = this.linuxPrinters.includes(this.modalData.nome_indirizzo)
                            ? this.modalData.nome_indirizzo
                            : '';
                    }
                },

                // Cerca le stampanti QZ Tray sull'host specificato
                async fetchQzPrinters() {
                    const host = (this.modalData.qz_host || '').trim();
                    if (!host) {
                        this.mostraMessaggio("Inserisci prima l'Host QZ Tray (es. IP o hostname).", 'error');
                        return;
                    }

                    this.loadingQzPrinters = true;
                    try {
                        // Si connette al QZ Tray remoto (usa la porta 8182 standard)
                        await ensureQzConnected(host, 8182);

                        // Esegue la discovery di tutte le stampanti installate sul PC remoto
                        const printers = await qz.printers.find();
                        const normalizedPrinters = (Array.isArray(printers) ? printers : [])
                            .map((printer) => String(printer || '').trim())
                            .filter((printer) => printer.length > 0);

                        this.bridgePrinters = normalizedPrinters;

                        if (normalizedPrinters.length === 0) {
                            this.bridgeSelectValue = '';
                            this.mostraMessaggio('Nessuna stampante trovata su QZ Tray. Puoi inserire il nome manualmente.', 'error');
                            return;
                        }

                        const preview = normalizedPrinters.slice(0, 8).join(', ');
                        const extra = normalizedPrinters.length > 8 ? ` (+${normalizedPrinters.length - 8} altre)` : '';
                        this.mostraMessaggio(`Trovate ${normalizedPrinters.length} stampanti: ${preview}${extra}`, 'success');
                        this.triggerBridgeSelectHighlight();

                        // Imposta il valore della select in base al nome salvato
                        if (this.bridgePrinters.includes(this.modalData.nome_indirizzo)) {
                            this.bridgeSelectValue = this.modalData.nome_indirizzo;
                        } else if (normalizedPrinters.length === 1) {
                            this.bridgeSelectValue = normalizedPrinters[0];
                            this.modalData.nome_indirizzo = normalizedPrinters[0];
                        } else {
                            this.bridgeSelectValue = '';
                        }
                    } catch (err) {
                        console.error('Errore fetchQzPrinters:', err);
                        this.bridgePrinters = [];
                        this.mostraMessaggio("Errore nel recupero delle stampanti QZ Tray: " + (err && err.message ? err.message : 'Controlla che QZ Tray sia in esecuzione e raggiungibile all\'host specificato.'), 'error');
                    }
                    finally {
                        this.loadingQzPrinters = false;
                    }
                },
                async refreshBridgePrinters() {
                    if (this.loadingQzPrinters) {
                        return;
                    }
                    await this.fetchQzPrinters();
                },
                triggerBridgeSelectHighlight() {
                    this.highlightBridgeSelect = false;
                    if (this._bridgeHighlightTimer) {
                        clearTimeout(this._bridgeHighlightTimer);
                    }
                    this.$nextTick(() => {
                        this.highlightBridgeSelect = true;
                        this._bridgeHighlightTimer = setTimeout(() => {
                            this.highlightBridgeSelect = false;
                        }, 4200);
                    });
                },

                onBridgePrinterSelectChange() {
                    this.highlightBridgeSelect = false;
                    if (this.bridgeSelectValue && this.bridgeSelectValue !== '__manual__') {
                        this.modalData.nome_indirizzo = this.bridgeSelectValue;
                    } else if (this.bridgeSelectValue === '__manual__') {
                        this.$nextTick(() => {
                            this.$refs.bridgeManualInput && this.$refs.bridgeManualInput.focus();
                        });
                    } else {
                        this.modalData.nome_indirizzo = '';
                    }
                },
                onLinuxPrinterSelectChange() {
                    if (this.linuxSelectValue && this.linuxSelectValue !== '__manual__') {
                        this.modalData.nome_indirizzo = this.linuxSelectValue;
                    } else if (this.linuxSelectValue === '__manual__') {
                        this.$nextTick(() => {
                            this.$refs.linuxManualInput && this.$refs.linuxManualInput.focus();
                        });
                    } else {
                        this.modalData.nome_indirizzo = '';
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
                    if (tipoStampante === 'BRIDGE') {
                        return '../print/test_print_bridge.php';
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

                    if (config.tipo_stampante === 'BRIDGE' && !config.qz_host) {
                        this.mostraMessaggio("Config BRIDGE incompleta: manca l'host QZ Tray.", 'error');
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
                                qz_host: config.qz_host || ''
                            })
                        });

                        if (config.tipo_stampante === 'BRIDGE') {
                            await printBridgeViaQz(response);
                        }

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
                        qz_host: (this.modalData.qz_host || '').trim()
                    };

                    void this.testPrinterConfig(config);
                },
                onTipoStampanteChange() {
                    if (this.modalData.tipo_stampante === 'RETE') {
                        this.modalData.porta = 9100;
                    } else {
                        this.modalData.porta = 0;
                    }
                    // Aggiorna i valori delle select in base al tipo di stampante selezionato
                    void this.caricaStampantiPerTipo(this.modalData.tipo_stampante);
                    if (this.modalData.tipo_stampante === 'BRIDGE') {
                        this.bridgeSelectValue = this.bridgePrinters.includes(this.modalData.nome_indirizzo)
                            ? this.modalData.nome_indirizzo
                            : '';
                    }
                },
                onWinPrinterSelectChange() {
                    if (this.winSelectValue !== '__manual__') {
                        this.modalData.nome_indirizzo = this.winSelectValue;
                    } else {
                        this.$nextTick(() => {
                            this.$refs.manualInput && this.$refs.manualInput.focus();
                        });
                    }
                },
                openModal(index) {
                    this.isCreatingRecord = index === null;
                    this.currentEditIndex = index;

                    this.modalData = this.isCreatingRecord
                        ? { cassa_id: '', tipo_stampante: 'WIN_USB', nome_indirizzo: '', porta: 0, qz_host: '', abilita_contanti: true, abilita_carta: false, abilita_satispay: false }
                        : {
                            ...this.stampantiData[index],
                            abilita_contanti: Number(this.stampantiData[index].abilita_contanti) === 1,
                            abilita_carta: Number(this.stampantiData[index].abilita_carta) === 1,
                            abilita_satispay: Number(this.stampantiData[index].abilita_satispay) === 1
                        };

                    this.modalOpen = true;

                    void this.caricaStampantiPerTipo(this.modalData.tipo_stampante);

                    this.bridgeSelectValue = this.modalData.tipo_stampante === 'BRIDGE' && this.bridgePrinters.includes(this.modalData.nome_indirizzo)
                        ? this.modalData.nome_indirizzo
                        : '';

                    // Se stiamo modificando una stampante BRIDGE, carichiamo le sue stampanti
                    if (this.modalData.tipo_stampante === 'BRIDGE' && this.modalData.qz_host) {
                        this.fetchQzPrinters();
                    }
                    // Per Linux avvia discovery solo quando il tipo selezionato è LINUX_USB.
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
                    const qzHost = (this.modalData.qz_host || '').trim();
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

                    if (tipoStampante === 'BRIDGE' && !qzHost) {
                        this.mostraMessaggio("Per BRIDGE devi indicare l'host QZ Tray.", 'error');
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
                                qz_host: qzHost,
                                abilita_contanti: this.modalData.abilita_contanti ? 1 : 0,
                                abilita_carta: this.modalData.abilita_carta ? 1 : 0,
                                abilita_satispay: this.modalData.abilita_satispay ? 1 : 0
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
                if (this._bridgeHighlightTimer) {
                    clearTimeout(this._bridgeHighlightTimer);
                }
                document.removeEventListener('keydown', this.handleEscapeKey);
            }
        });

        app.mount('#app');
    </script>
</body>

</html>