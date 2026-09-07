<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, target-densitydpi=device-dpi">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/stat_vendite.css">
<!-- Importa tutte le favicon con una sola riga -->
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <?php require_once __DIR__ . '/../includes/icons.php'; ?>
    <title>Statistiche vendite</title>

    <script src="../assets/js/vue.global.js"></script>
    <script src="../assets/js/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme.js"></script>
    <script src="../assets/js/qz-tray.js"></script>
    <script src="../assets/js/qz-helper.js"></script>
    <script src="../assets/js/chart.min.js"></script>
    <!-- jQuery incluso -->
</head>

<body class="management-page canvas-page sidebar-page">

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="pos-main-panel">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <main class="management-shell">
    <div id="statVenditeApp">
        <h2 id="page-title">
            <?= pos_icon('stat-bars', ['class' => 'title-icon', 'stroke' => 'var(--mc-primary)']) ?>
            Statistiche vendite
        </h2>

        <div id="dashboard">
            <!-- Riga 1: pillole range rapido -->
            <div class="filter-pills" role="group" aria-label="Filtri rapidi">
                <button type="button" class="filter-pill" :class="{ 'is-active': activePreset === 'oggi' }" @click="applyPreset('oggi')">Oggi</button>
                <button type="button" class="filter-pill" :class="{ 'is-active': activePreset === 'ieri' }" @click="applyPreset('ieri')">Ieri</button>
                <button type="button" class="filter-pill" :class="{ 'is-active': activePreset === '7g' }" @click="applyPreset('7g')">Ultimi 7 giorni</button>
                <button type="button" class="filter-pill" :class="{ 'is-active': activePreset === '30g' }" @click="applyPreset('30g')">Ultimi 30 giorni</button>
            </div>

            <!-- Riga 2: filtri custom + azioni -->
            <div class="filter-bar">
                <div class="filter-bar__field">
                    <label for="from">Dalla data/ora:</label>
                    <input type="datetime-local" id="from" name="from" v-model="filters.from" @change="onCustomRangeChange">
                </div>
                <div class="filter-bar__field">
                    <label for="to">Alla data/ora:</label>
                    <input type="datetime-local" id="to" name="to" v-model="filters.to" @change="onCustomRangeChange">
                </div>
                <div class="filter-bar__actions">
                    <button id="btnPdf" @click="exportPdf">Scarica PDF</button>
                    <button id="btnReceipt" @click="stampaReceipt">Stampa Scontrino Vendite</button>
                </div>
            </div>
            <p v-if="resultsError" class="filter-bar__status filter-bar__status--error">{{ resultsError }}</p>

            <!-- Riga 3: card KPI -->
            <div class="kpi-row">
                <div class="stat-card">
                    <span class="stat-card__label">Ricavo Totale</span>
                    <span class="stat-card__value">{{ formatEuro(totaleComplessivo) }}</span>
                </div>
                <div class="stat-card">
                    <span class="stat-card__label">Ordini Totali</span>
                    <span class="stat-card__value">{{ ordiniTotali }}</span>
                </div>
                <div class="stat-card">
                    <span class="stat-card__label">Valore Medio Ordine</span>
                    <span class="stat-card__value">{{ formatEuro(valoreMedioOrdine) }}</span>
                </div>
            </div>

            <!-- Riga 4: sidebar + grafico -->
            <div class="dashboard-main">
                <aside class="dashboard-sidebar">
                    <div class="side-panel">
                        <h3 class="side-panel__title">
                            <?= pos_icon('card-line', ['class' => 'panel-icon']) ?>
                            Casse
                        </h3>
                        <ul class="side-list" v-if="casse.length">
                            <li class="side-list__item side-list__item--selectable" :class="{ 'is-active': isCassaActive(c.cassa) }" v-for="c in casse" :key="c.cassa" @click="toggleCassaFilter(c.cassa)">
                                <div class="side-list__row">
                                    <span class="side-list__name">{{ c.cassa }}</span>
                                    <span class="side-list__value">{{ formatEuro(c.totale) }}</span>
                                </div>
                                <div class="side-list__meter">
                                    <div class="side-list__meter-fill" :style="{ width: cassaSharePercent(c) + '%' }"></div>
                                </div>
                                <span class="side-list__sub">{{ c.ordini }} ordini &middot; {{ cassaSharePercent(c) }}%</span>
                                <span class="side-list__sub">Fondo: {{ formatEuro(c.fondo_cassa) }} &middot; Atteso: {{ formatEuro(c.totale_atteso) }} &middot; Ultima chiusura: {{ formatDataOra(c.ultima_chiusura) }}</span>
                            </li>
                        </ul>
                        <p v-else class="side-panel__empty">Nessun dato</p>
                    </div>

                    <div class="side-panel">
                        <div class="side-panel__header">
                            <h3 class="side-panel__title">
                                <?= pos_icon('target', ['class' => 'panel-icon']) ?>
                                Top Pietanze
                            </h3>
                            <div class="side-panel__toggle">
                                <button type="button" :class="{ 'is-active': topPietanzeSort === 'totale' }" @click="topPietanzeSort = 'totale'">Ricavo</button>
                                <button type="button" :class="{ 'is-active': topPietanzeSort === 'quantita' }" @click="topPietanzeSort = 'quantita'">Quantit&agrave;</button>
                            </div>
                        </div>
                        <input type="text" class="side-panel__search" v-model.trim="topPietanzeSearch" placeholder="Cerca prodotto...">
                        <ul class="side-list" v-if="topPietanze.length">
                            <li class="side-list__item" v-for="p in topPietanze" :key="p.prodotto">
                                <div class="side-list__row">
                                    <span class="side-list__name">{{ p.prodotto }}</span>
                                    <span class="side-list__value">{{ topPietanzeSort === 'totale' ? formatEuro(p.totale) : p.quantita }}</span>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="side-panel__empty">Nessun dato</p>
                    </div>
                </aside>

                <div class="chart-card">
                    <div class="chart-card__header">
                        <h3 class="chart-card__title">
                            <?= pos_icon('trending-up', ['class' => 'panel-icon']) ?>
                            Andamento Ricavi
                        </h3>
                        <div class="active-filters" v-if="activeFilters.length">
                            <span class="active-filter-chip" v-for="f in activeFilters" :key="f.type">
                                <?= pos_icon('filter', ['class' => 'filter-icon']) ?>
                                {{ f.value }}
                            </span>
                        </div>
                        <div class="chart-tabs">
                            <button type="button" class="chart-tab" :class="{ 'is-active': activeChartTab === 'ricavo' }" @click="setActiveChartTab('ricavo')">Ricavo</button>
                            <button type="button" class="chart-tab" :class="{ 'is-active': activeChartTab === 'ordini' }" @click="setActiveChartTab('ordini')">Ordini</button>
                            <button type="button" class="chart-tab" :class="{ 'is-active': activeChartTab === 'metodo' }" @click="setActiveChartTab('metodo')">Ricavi per Metodo di Pagamento</button>
                        </div>
                    </div>
                    <div class="chart-card__body">
                        <canvas id="andamentoChart" ref="andamentoChart" v-if="andamento.length"></canvas>
                        <div class="results-empty" v-else>
                            <strong>Nessun dato da mostrare</strong>
                            <p>Modifica i filtri per visualizzare l'andamento.</p>
                        </div>
                    </div>
                </div>
            </div>

        <!--   Dettaglio completo 
            <details class="detail-panel" open>
                <summary class="detail-panel__summary">Dettaglio completo prodotti</summary>
                <div id="results">
                    <template v-if="venditeData.length > 0">
                        <p>Data e ora di estrazione dei dati: {{ dataOraEstr }}</p>
                        <table>
                            <tr><th>Quantità</th><th>Prodotto</th><th>Totale</th></tr>
                            <tr v-for="record in venditeData" :key="record.prodotto + '-' + record.quantita">
                                <td>{{ record.quantita }}</td>
                                <td>{{ record.prodotto }}</td>
                                <td>{{ formatEuro(record.totale) }}</td>
                            </tr>
                            <tr>
                                <td colspan="2">Sconti applicati</td>
                                <td>-{{ formatEuro(sconti) }}</td>
                            </tr>
                            <tr>
                                <td colspan="2"><strong>Totale complessivo</strong></td>
                                <td><strong>{{ formatEuro(totaleComplessivo) }}</strong></td>
                            </tr>
                        </table>
                        <br>
                        <div>Metodo pagamento:
                            <ul>
                                <li>Contanti {{ formatEuro(paymentTotals.contanti) }}</li>
                                <li>Carta {{ formatEuro(paymentTotals.carta) }}</li>
                                <li>Satispay {{ formatEuro(paymentTotals.satispay) }}</li>
                                <li>ND {{ formatEuro(paymentTotals.nd) }}</li>
                            </ul>
                        </div>
                    </template>
                    <div v-else class="results-empty">
                        <strong>Nessun risultato da mostrare</strong>
                        <p>Imposta i filtri per visualizzare le statistiche.</p>
                    </div>
                </div>
            </details> -->

        </div>
    </div>
    </main>
    </div>

    <script>
        const statVenditeApp = Vue.createApp({
            data() {
                return {
                    filters: {
                        from: '',
                        to: '',
                        cassa: ''
                    },
                    loadingStats: false,
                    resultsError: '',
                    venditeData: [],
                    totaleComplessivo: 0,
                    sconti: 0,
                    dataOraEstr: '',
                    paymentTotals: {
                        contanti: 0,
                        carta: 0,
                        satispay: 0,
                        nd: 0
                    },
                    fromStat: null,
                    toStat: null,
                    cassaStat: null,
                    qzScriptLoadPromise: null,
                    qzConnectionTarget: null,
                    activePreset: 'oggi',
                    ordiniTotali: 0,
                    valoreMedioOrdine: 0,
                    casse: [],
                    andamento: [],
                    bucketType: 'day',
                    activeChartTab: 'ricavo',
                    topPietanzeSearch: '',
                    topPietanzeSort: 'totale',
                    chartInstance: null
                };
            },
            computed: {
                topPietanze() {
                    const search = this.topPietanzeSearch.toLowerCase();
                    const sortKey = this.topPietanzeSort;
                    return this.venditeData
                        .filter(r => !search || r.prodotto.toLowerCase().includes(search))
                        .slice()
                        .sort((a, b) => parseFloat(b[sortKey]) - parseFloat(a[sortKey]))
                        .slice(0, 10);
                },
                // Elenco filtri attivi mostrato accanto al titolo "Andamento Ricavi". Per ora
                // esiste solo il filtro cassa; quando saranno aggiunti filtri su prodotto o
                // categoria basterà spingere altre voci {type, label, value} in questo array.
                activeFilters() {
                    const filters = [];
                    if (this.filters.cassa) {
                        filters.push({ type: 'cassa', label: 'Cassa', value: this.filters.cassa });
                    }
                    return filters;
                }
            },
            methods: {
                loadScript(url) {
                    return new Promise((resolve, reject) => {
                        const script = document.createElement('script');
                        script.src = url;
                        script.async = true;
                        script.onload = () => resolve(url);
                        script.onerror = () => reject(new Error('Load failed: ' + url));
                        document.head.appendChild(script);
                    });
                },
                normalizeQzHost(host) {
                    return String(host || '').trim().replace(/^https?:\/\//i, '').replace(/\/$/, '');
                },
                async ensureQzLibraryLoaded() {
                    if (window.qz) {
                        return;
                    }

                    if (!this.qzScriptLoadPromise) {
                        this.qzScriptLoadPromise = (async () => {
                            const candidates = ['qz-tray.js', '/qz-tray.js', './qz-tray.js', 'http://localhost:8182/qz-tray.js', 'http://127.0.0.1:8182/qz-tray.js'];
                            const errors = [];
                            for (const url of candidates) {
                                try {
                                    await this.loadScript(url);
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

                    await this.qzScriptLoadPromise;

                    if (!window.qz) {
                        throw new Error('QZ Tray non disponibile nel browser.');
                    }
                },
                setupQzSecurity() {
                    // La configurazione certificato/firma (necessaria perché QZ Tray accetti
                    // il job senza mostrare il popup di conferma non presidiato) è definita
                    // globalmente in assets/js/qz-helper.js, la stessa usata da billing.php.
                    if (typeof window.setupQzSecurity === 'function') {
                        window.setupQzSecurity();
                    }
                },
                async ensureQzConnected(host, port = 8182) {
                    console.debug('[QZ] ensureQzConnected: caricamento libreria...');
                    await this.ensureQzLibraryLoaded();
                    console.debug('[QZ] libreria caricata, versione:', window.qz && qz.VERSION);
                    this.setupQzSecurity();
                    console.debug('[QZ] setupQzSecurity eseguito');

                    const cleanHost = this.normalizeQzHost(host);
                    if (!cleanHost) {
                        throw new Error('Host QZ mancante nella configurazione stampante.');
                    }

                    const targetKey = cleanHost + ':' + Number(port || 8182);
                    if (qz.websocket.isActive() && this.qzConnectionTarget === targetKey) {
                        console.debug('[QZ] connessione già attiva verso', targetKey);
                        return;
                    }

                    if (qz.websocket.isActive()) {
                        try {
                            console.debug('[QZ] disconnessione connessione precedente...');
                            await qz.websocket.disconnect();
                        } catch (err) {
                            console.warn('Disconnessione QZ precedente fallita:', err);
                        }
                    }

                    console.debug('[QZ] connessione a', cleanHost, 'porta', port);
                    await qz.websocket.connect({
                        host: cleanHost,
                        usingSecure: false,
                        port: {
                            secure: [],
                            insecure: [Number(port || 8182)]
                        },
                        retries: 2,
                        delay: 0.25
                    });
                    console.debug('[QZ] connesso');

                    this.qzConnectionTarget = targetKey;
                },
                async printBridgeViaQz(response) {
                    console.debug('[QZ] printBridgeViaQz payload:', response);
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

                    await this.ensureQzConnected(qzHost, qzPort);

                    try {
                        const found = await qz.printers.find(printerName);
                        console.debug('[QZ] stampante trovata:', found);
                    } catch (err) {
                        // Non bloccante: qz.configs.create funziona anche senza una find() riuscita,
                        // e billing.php (che stampa correttamente) non la usa affatto.
                        console.warn('[QZ] qz.printers.find fallita, si prosegue comunque:', err);
                    }

                    const config = qz.configs.create(printerName, {
                        encoding: 'ISO-8859-1'
                    });
                    console.debug('[QZ] config creata per', printerName, 'invio dati raw base64, lunghezza', rawBase64.length);

                    const data = [{
                        type: 'raw',
                        format: 'command',
                        flavor: 'base64',
                        data: rawBase64
                    }];

                    console.debug('[QZ] invio qz.print...');
                    await qz.print(config, data);
                    console.debug('[QZ] qz.print completato senza errori');
                },
                convertToMySQLDatetime(datetimeLocal) {
                    const date = new Date(datetimeLocal);
                    const pad = (n) => n.toString().padStart(2, '0');
                    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
                        `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
                },
                toDateTimeLocalValue(date) {
                    const d = new Date(date.getTime());
                    d.setSeconds(0, 0);
                    const tzOffsetMs = d.getTimezoneOffset() * 60000;
                    return new Date(d.getTime() - tzOffsetMs).toISOString().slice(0, 16);
                },
                getCurrentDateTimeLocalValue() {
                    return this.toDateTimeLocalValue(new Date());
                },
                startOfDay(date) {
                    const d = new Date(date);
                    d.setHours(0, 0, 0, 0);
                    return d;
                },
                endOfDay(date) {
                    const d = new Date(date);
                    d.setHours(23, 59, 0, 0);
                    return d;
                },
                applyPreset(preset) {
                    const now = new Date();
                    let from, to;
                    switch (preset) {
                        case 'oggi':
                            from = this.startOfDay(now);
                            to = now;
                            break;
                        case 'ieri': {
                            const yesterday = new Date(now);
                            yesterday.setDate(yesterday.getDate() - 1);
                            from = this.startOfDay(yesterday);
                            to = this.endOfDay(yesterday);
                            break;
                        }
                        case '7g':
                            from = this.startOfDay(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 6));
                            to = now;
                            break;
                        case '30g':
                            from = this.startOfDay(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 29));
                            to = now;
                            break;
                        default:
                            return;
                    }
                    this.activePreset = preset;
                    this.filters.from = this.toDateTimeLocalValue(from);
                    this.filters.to = this.toDateTimeLocalValue(to);
                    this.ottieniStatistiche();
                },
                onCustomRangeChange() {
                    this.activePreset = 'custom';
                    this.ottieniStatistiche();
                },
                ottieniDataOraAttuale() {
                    const oraAttuale = new Date();
                    const giorno = String(oraAttuale.getDate()).padStart(2, '0');
                    const mese = String(oraAttuale.getMonth() + 1).padStart(2, '0');
                    const anno = oraAttuale.getFullYear();
                    const ore = String(oraAttuale.getHours()).padStart(2, '0');
                    const minuti = String(oraAttuale.getMinutes()).padStart(2, '0');
                    return `${giorno}/${mese}/${anno} ${ore}:${minuti}`;
                },
                formatEuro(value) {
                    const parsed = parseFloat(value);
                    if (Number.isNaN(parsed)) {
                        return '0,00 €';
                    }
                    return `${parsed.toFixed(2).replace('.', ',')} €`;
                },
                formatDataOra(value) {
                    if (!value) return 'mai';
                    const d = new Date(String(value).replace(' ', 'T'));
                    if (Number.isNaN(d.getTime())) return 'mai';
                    const giorno = String(d.getDate()).padStart(2, '0');
                    const mese = String(d.getMonth() + 1).padStart(2, '0');
                    const ore = String(d.getHours()).padStart(2, '0');
                    const minuti = String(d.getMinutes()).padStart(2, '0');
                    return `${giorno}/${mese} ${ore}:${minuti}`;
                },
                cassaSharePercent(c) {
                    const totaleCasse = this.casse.reduce((sum, item) => sum + parseFloat(item.totale || 0), 0);
                    if (!totaleCasse) return 0;
                    return Math.round((parseFloat(c.totale) / totaleCasse) * 100);
                },
                cssVar(name) {
                    const scope = document.getElementById('statVenditeApp') || document.documentElement;
                    return getComputedStyle(scope).getPropertyValue(name).trim();
                },
                isCassaActive(cassa) {
                    return !!this.filters.cassa && this.filters.cassa.trim().toUpperCase() === String(cassa).toUpperCase();
                },
                findCassaInfo(cassaId) {
                    if (!cassaId) return null;
                    const needle = String(cassaId).trim().toUpperCase();
                    return this.casse.find(c => String(c.cassa).trim().toUpperCase() === needle) || null;
                },
                toggleCassaFilter(cassa) {
                    if (!cassa || cassa === 'N/D') {
                        return;
                    }
                    this.filters.cassa = this.isCassaActive(cassa) ? '' : cassa;
                    this.ottieniStatistiche();
                },
                setActiveChartTab(tab) {
                    this.activeChartTab = tab;
                    this.renderChart();
                },
                renderChart() {
                    if (!this.andamento.length) {
                        if (this.chartInstance) {
                            this.chartInstance.destroy();
                            this.chartInstance = null;
                        }
                        return;
                    }

                    const canvas = this.$refs.andamentoChart;
                    if (!canvas || !window.Chart) return;

                    const labels = this.andamento.map(b => b.label);
                    let chartType = 'line';
                    let datasets;
                    if (this.activeChartTab === 'ricavo') {
                        datasets = [{ label: 'Ricavo (€)', data: this.andamento.map(b => b.ricavo), borderColor: this.cssVar('--chart-ricavo'), backgroundColor: this.cssVar('--chart-ricavo-fill'), tension: 0.3, fill: true }];
                    } else if (this.activeChartTab === 'ordini') {
                        chartType = 'bar';
                        datasets = [{ label: 'Ordini', data: this.andamento.map(b => b.ordini), backgroundColor: this.cssVar('--chart-ordini'), borderColor: this.cssVar('--chart-ordini'), borderRadius: parseFloat(this.cssVar('--ui-radius-sm')) || 0 }];
                    } else {
                        datasets = [
                            { label: 'Contanti', data: this.andamento.map(b => b.contanti), borderColor: this.cssVar('--chart-contanti'), backgroundColor: this.cssVar('--chart-contanti-fill'), tension: 0.3, fill: true },
                            { label: 'Carta', data: this.andamento.map(b => b.carta), borderColor: this.cssVar('--chart-carta'), backgroundColor: this.cssVar('--chart-carta-fill'), tension: 0.3, fill: true },
                            { label: 'Satispay', data: this.andamento.map(b => b.elettronico), borderColor: this.cssVar('--chart-satispay'), backgroundColor: this.cssVar('--chart-satispay-fill'), tension: 0.3, fill: true }
                        ];
                    }

                    // Con un solo punto non ha senso disegnare una linea (non c'è nulla da collegare):
                    // si mostra solo un marker grande, senza linea né riempimento. La scala a
                    // categorie di default piazza l'unico punto tutto a sinistra (offset:false);
                    // con offset:true la categoria diventa una "fascia" e il punto viene centrato.
                    const isSinglePoint = chartType === 'line' && labels.length === 1;
                    if (isSinglePoint) {
                        datasets = datasets.map(ds => ({ ...ds, showLine: false, fill: false, pointRadius: 8, pointHoverRadius: 10 }));
                    }

                    const gridColor = this.cssVar('--mc-border');
                    const axis = {
                        grid: { color: gridColor, borderDash: [4, 4] },
                        border: { color: gridColor, dash: [4, 4] }
                    };

                    if (this.chartInstance) {
                        this.chartInstance.destroy();
                    }
                    this.chartInstance = Vue.markRaw(new Chart(canvas.getContext('2d'), {
                        type: chartType,
                        data: { labels, datasets },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: this.activeChartTab === 'metodo' } },
                            scales: {
                                // offset va forzato a true solo per il punto singolo su linea (per
                                // centrarlo): sulle barre non va toccato, altrimenti si perde il
                                // centraggio automatico che Chart.js applica di default ai bar chart.
                                x: { ...(isSinglePoint ? { offset: true } : {}), ...axis },
                                y: { beginAtZero: true, ...axis }
                            }
                        }
                    }));
                },
                async ottieniStatistiche() {
                    const from = this.filters.from;
                    const to = this.filters.to;
                    const cassa = this.filters.cassa.trim();

                    let fromStr = '1990-01-01 00:00:00';
                    if (from && from.trim() !== '') {
                        fromStr = this.convertToMySQLDatetime(from);
                    }

                    let toStr = this.convertToMySQLDatetime(this.getCurrentDateTimeLocalValue());
                    if (to && to.trim() !== '') {
                        toStr = this.convertToMySQLDatetime(to);
                    }

                    // Non azzeriamo i dati già mostrati prima della risposta: sostituirli solo a
                    // richiesta completata evita che la pagina "sfarfalli" (liste/grafico/card che
                    // spariscono e ricompaiono) ad ogni cambio di filtro, mantenendo però il
                    // risultato finale corretto.
                    this.loadingStats = true;
                    this.resultsError = '';

                    try {
                        const data = await $.ajax({
                            url: '../api/statistiche_vendite.php',
                            type: 'POST',
                            data: {
                                from: fromStr,
                                to: toStr,
                                cassa: cassa
                            },
                            dataType: 'json'
                        });

                        if (data.error) {
                            this.resultsError = data.error;
                            return;
                        }

                        this.fromStat = fromStr;
                        this.toStat = toStr;
                        this.cassaStat = cassa;
                        this.venditeData = Array.isArray(data.vendite) ? data.vendite : [];
                        this.totaleComplessivo = parseFloat(data.totale_complessivo || 0);
                        this.sconti = parseFloat(data.sconti || 0);
                        this.paymentTotals = {
                            contanti: parseFloat(data.tot_contanti || 0),
                            carta: parseFloat(data.tot_carta || 0),
                            satispay: parseFloat(data.tot_satispay || 0),
                            nd: parseFloat(data.tot_nd || 0)
                        };
                        this.dataOraEstr = this.ottieniDataOraAttuale();
                        this.ordiniTotali = parseInt(data.ordini_totali || 0, 10);
                        this.valoreMedioOrdine = parseFloat(data.valore_medio_ordine || 0);
                        this.casse = Array.isArray(data.casse) ? data.casse : [];
                        this.andamento = Array.isArray(data.andamento) ? data.andamento : [];
                        this.bucketType = data.bucket_type || 'day';
                        this.$nextTick(() => this.renderChart());
                    } catch (error) {
                        this.resultsError = 'Errore nel recupero dei dati.';
                    } finally {
                        this.loadingStats = false;
                    }
                },
                async exportPdf() {
                    let fromValue = this.filters.from || '(vuoto)';
                    let toValue = this.filters.to || '(vuoto)';
                    let cassaValue = this.filters.cassa || '(tutte)';

                    if (!fromValue.trim()) {
                        fromValue = '(vuoto)';
                    }
                    if (!toValue.trim()) {
                        toValue = '(vuoto)';
                    }
                    if (!cassaValue.trim()) {
                        cassaValue = '(tutte)';
                    }

                    const cassaInfo = this.filters.cassa ? this.findCassaInfo(this.filters.cassa) : null;
                    const chiusuraLine = cassaInfo
                        ? `<h3>Chiusura cassa ${cassaValue} ore: ${this.formatDataOra(cassaInfo.ultima_chiusura)}</h3>
    <h3>Fondo cassa: ${this.formatEuro(cassaInfo.fondo_cassa)} &middot; Totale atteso: ${this.formatEuro(cassaInfo.totale_atteso)}</h3>`
                        : '';

                    let updatedHtml = `<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <style>
        html, body {
            height: 100%;
            margin-left: 20px;
            font-family: Arial, sans-serif;
        }
        #results { padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; padding: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #eee; }
    </style>
</head>
<body>
    <h2>FILTRI APPLICATI</h2>
    <h3>Dalla data/ora: ${fromValue}</h3>
    <h3>Alla data/ora: ${toValue}</h3>
    <h3>Cassa: ${cassaValue}</h3>
    ${chiusuraLine}
    <hr>`;

                    updatedHtml += `<div id="results">`;
                    updatedHtml += `<p>Data e ora di estrazione dei dati: ${this.dataOraEstr || ''}</p>`;
                    updatedHtml += '<table><tr><th>Quantità</th><th>Prodotto</th><th>Totale</th></tr>';
                    this.venditeData.forEach((record) => {
                        updatedHtml += `<tr><td>${record.quantita}</td><td>${record.prodotto}</td><td>${this.formatEuro(record.totale)}</td></tr>`;
                    });
                    updatedHtml += `<tr><td colspan="2">Sconti applicati</td><td>-${this.formatEuro(this.sconti)}</td></tr>`;
                    updatedHtml += `<tr><td colspan="2"><strong>Totale complessivo</strong></td><td><strong>${this.formatEuro(this.totaleComplessivo)}</strong></td></tr>`;
                    updatedHtml += '</table>';
                    updatedHtml += '<br><div>Metodo pagamento:<ul>';
                    updatedHtml += `<li>Contanti ${this.formatEuro(this.paymentTotals.contanti)}</li>`;
                    updatedHtml += `<li>Carta ${this.formatEuro(this.paymentTotals.carta)}</li>`;
                    updatedHtml += `<li>Satispay ${this.formatEuro(this.paymentTotals.satispay)}</li>`;
                    updatedHtml += '</ul></div></div></body></html>';

                    // Nota: NON usiamo più il submit nativo del <form> (POST + navigazione).
                    // Chrome, quando una risposta POST arriva con Content-Disposition:
                    // attachment, a volte ripete la richiesta per "materializzare" il
                    // download e la ripetizione perde il body, generando un PDF vuoto/rotto.
                    // Con fetch() la richiesta è unica e il salvataggio è gestito da noi.
                    try {
                        const resp = await fetch('../print/print_stat_pdf.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'htmlContent=' + encodeURIComponent(updatedHtml)
                        });
                        if (!resp.ok) {
                            throw new Error('HTTP ' + resp.status);
                        }
                        const blob = await resp.blob();
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'statistiche_vendite.pdf';
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                        URL.revokeObjectURL(url);
                    } catch (err) {
                        console.error('[exportPdf] errore download PDF:', err);
                        this.resultsError = 'Errore durante la generazione del PDF.';
                    }
                },
                async stampaReceipt() {
                    const cassa_id = localStorage.getItem('cassa_id');
                    const cassaInfo = this.cassaStat ? this.findCassaInfo(this.cassaStat) : null;
                    console.debug('[QZ] stampaReceipt: cassa_id=', cassa_id, 'cassaStat=', this.cassaStat);

                    try {
                        const response = await $.ajax({
                            url: '../print/print_stat_receipt.php',
                            method: 'POST',
                            contentType: 'application/json',
                            dataType: 'json',
                            data: JSON.stringify({
                                from: this.fromStat,
                                to: this.toStat,
                                cassa: this.cassaStat,
                                vendite: this.venditeData,
                                totale: this.totaleComplessivo,
                                sconti: this.sconti,
                                dataEstr: this.dataOraEstr,
                                cassaId: cassa_id,
                                ultimaChiusura: cassaInfo ? cassaInfo.ultima_chiusura : null,
                                fondoCassa: cassaInfo ? cassaInfo.fondo_cassa : null,
                                totaleAtteso: cassaInfo ? cassaInfo.totale_atteso : null
                            })
                        });

                        console.debug('[QZ] risposta print_stat_receipt.php:', response);

                        if (response && response.method === 'bridge_qz') {
                            await this.printBridgeViaQz(response);
                        }

                        const method = response && response.method ? response.method : 'sconosciuto';
                        const printer = response && response.printer ? ' (' + response.printer + ')' : '';
                        window.showToast('Scontrino stampato con successo! Metodo: ' + method + printer, 'success');
                    } catch (error) {
                        console.error('[QZ] Errore stampa scontrino:', error);
                        window.showToast('Errore durante la stampa dello scontrino: ' + (error && error.message ? error.message : error), 'error');
                    }
                }
            },
            mounted() {
                this.applyPreset('oggi');
            }
        });

        statVenditeApp.mount('#statVenditeApp');
    </script>
</body>

</html>
