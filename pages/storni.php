<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, target-densitydpi=device-dpi">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/storni.css">

    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <title>Storni</title>

    <script src="../assets/js/vue.global.js"></script>
    <script src="../assets/js/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme.js"></script>
    <!-- jQuery incluso -->
</head>

<body class="management-page canvas-page sidebar-page">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="pos-main-panel">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <main class="management-shell">
    <div id="storniApp">
        <div id="container">
            <div id="top1">
                <h2><svg class="section-icon" style="stroke: var(--mc-primary)" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 28.17 32.25"><path fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M9.23,13.63l3.23,3.33,7.28-7.49M27.02,31.1V9.14c0-2.8,0-4.19-.53-5.26-.47-.94-1.21-1.7-2.12-2.18-1.04-.54-2.4-.54-5.11-.54h-10.35c-2.72,0-4.07,0-5.11.54-.91.48-1.65,1.24-2.12,2.18-.53,1.07-.53,2.47-.53,5.26v21.96l4.45-3.33,4.04,3.33,4.45-3.33,4.45,3.33,4.04-3.33,4.45,3.33Z"/></svg> Storna scontrino</h2>
                <label for="idScontr">Numeri scontrino (separati da virgola): </label>
                <input type="text" id="idScontr" name="idScontr" v-model.trim="stornoForm.idScontr" placeholder="101, 102, 103">
                <label for="dat">Data scontrino:</label>
                <input type="date" id="dat" name="dat" v-model="stornoForm.date">
                <button id="btnStorna" @click="stornaScontrino" :disabled="loadingStorno">
                    {{ loadingStorno ? 'Sto storando...' : 'Storna scontrini' }}
                </button>
            </div>
            <div id="esito">
                <div class="section-row">
                    <svg class="section-icon section-icon-lg" style="fill: var(--mc-primary)" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 409.6 409.6"><circle opacity=".4" cx="204.8" cy="204.8" r="204.8"/><path d="M282.6,136.35c15.81-3.31,26.88,13.37,17.11,26.45l-106.24,106.39c-7.48,5.75-14.38,6.04-22.14.43-17.11-20.7-44.71-39.82-60.67-60.53-13.51-17.4,6.61-36.09,22.14-24.15l49.6,48.74,94.02-93.88c1.58-1.29,3.88-2.88,5.89-3.31l.29-.14Z"/></svg>
                    <div class="section-col">
                        <h4>Esito storno</h4>
                        <div id="esitoStornoContent">
                            <p v-if="stornoMessage">{{ stornoMessage }}</p>
                            <p v-else>Nessuno storno eseguito.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div id="top2">
                <h2><svg class="section-icon" style="fill: var(--mc-primary)" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M9.72,2.22h12.57V.9C22.28.46,22.97.01,23.39,0c.45-.01,1.18.43,1.18.9v1.32c1.02,0,2-.04,3,.18,2.44.54,4.2,2.67,4.43,5.14v19.07c-.19,2.9-2.48,5.2-5.39,5.39H5.25C2.45,31.73.23,29.49,0,26.68V7.61C.2,4.93,2.18,2.73,4.84,2.31c.86-.14,1.72-.08,2.59-.09V.9C7.43.46,8.11.01,8.54,0c.45-.01,1.18.43,1.18.9v1.32ZM7.43,4.5c-1.81-.08-3.67-.02-4.67,1.72-.16.28-.47,1.02-.47,1.32v1.61h27.42v-1.61c0-.3-.31-1.04-.47-1.32-1-1.74-2.86-1.8-4.67-1.72v1.39c0,.06-.3.53-.39.61-.41.35-1.09.35-1.5,0-.09-.07-.39-.55-.39-.61v-1.39h-12.57v1.39c0,.06-.3.53-.39.61-.41.35-1.09.35-1.5,0-.09-.07-.39-.55-.39-.61v-1.39ZM29.71,11.43H2.29v15.32c0,1.34,1.82,2.99,3.18,2.96,7.2-.06,14.43.12,21.62-.09,1.2-.2,2.63-1.72,2.63-2.94v-15.25Z"/><path d="M21.4,15.8c1.1-.23,1.87.93,1.19,1.84l-7.39,7.4c-.52.4-1,.42-1.54.03-1.19-1.44-3.11-2.77-4.22-4.21-.94-1.21.46-2.51,1.54-1.68l3.45,3.39,6.54-6.53c.11-.09.27-.2.41-.23Z"/></svg> Elenco scontrini stornati</h2>
                <label for="from">Dalla data/ora:</label>
                <input type="datetime-local" id="from" name="from" v-model="filters.from">
                <label for="to">Alla data/ora:</label>
                <input type="datetime-local" id="to" name="to" v-model="filters.to">
                <label for="cassa">Cassa: </label>
                <input type="text" id="cassa" name="cassa" v-model.trim="filters.cassa">
                <em class="management-note">(lasciare il campo "Cassa" vuoto per indicare TUTTE le casse)</em>
                <button id="btnStatistiche" @click="ottieniStatistiche" :disabled="loadingStats">
                    {{ loadingStats ? 'Caricamento...' : 'Ottieni storni' }}
                </button>
            </div>
            <div id="bottom">
                <div class="section-row">
                    <svg class="section-icon section-icon-lg" style="fill: var(--mc-primary)" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 409.6 409.6"><circle opacity=".4" cx="204.8" cy="204.8" r="204.8"/><path d="M362,202.18v6.13c-2.5,9.3-8.83,15.65-18.7,16.53l-216.88.03c-24.91-1.96-25.52-35.93-1.23-39.26l219.35.02c9.21,1.41,15.15,7.72,17.46,16.55Z"/><path d="M112.87,130.9c-11.82-11.82-3.95-31.86,12.35-33.47h199.09c23.89,3.21,23.9,35.98,0,39.2l-197.9.03c-4.88-.38-10.08-2.28-13.55-5.75Z"/><path d="M268.05,307.34c-3.74,3.74-8.95,5.35-14.16,5.75l-127.44-.03c-24.42-2.04-25.75-34.44-2.41-39.15l132.27-.04c16.15,1.74,23.38,21.83,11.74,33.47Z"/><path d="M65.96,97.59c25.91-2.49,29.75,36.64,3.5,39.03s-28.49-36.62-3.5-39.03Z"/><path d="M65.33,185.8c16.83-2.21,28.77,17,18.8,31.05-12.54,17.67-41.12,4.02-34.77-17.62,1.96-6.66,9.07-12.52,15.97-13.43Z"/><path d="M54.06,279.59c16.48-16.48,43.74,5.36,30.31,25.11-15.76,23.18-50.04-5.39-30.31-25.11Z"/></svg>
                    <div class="section-col">
                        <h4>Risultati</h4>
                        <div id="results">
                            <p v-if="loadingStats">Caricamento storni...</p>
                            <p v-else-if="resultsError">{{ resultsError }}</p>
                            <template v-else-if="storniData.length > 0">
                                <p>Data e ora di estrazione dei dati: {{ dataOraEstr }}</p>
                                <table>
                                    <tr><th>Numero scontrino</th><th>Data / Ora</th><th>Totale</th><th>Metodo pagamento</th></tr>
                                    <tr v-for="record in storniData" :key="record.id">
                                        <td>{{ record.id }}</td>
                                        <td>{{ record.data_ora }}</td>
                                        <td>{{ formatEuro(record.totale) }}</td>
                                        <td>{{ record.metodo_pagamento }}</td>
                                    </tr>
                                </table>
                            </template>
                            <p v-else>Nessuno storno trovato.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </main>
    </div>

    <script>
        const storniApp = Vue.createApp({
            data() {
                return {
                    stornoForm: {
                        idScontr: '',
                        date: ''
                    },
                    filters: {
                        from: '',
                        to: '',
                        cassa: ''
                    },
                    storniData: [],
                    stornoMessage: '',
                    resultsError: '',
                    loadingStats: false,
                    loadingStorno: false,
                    dataOraEstr: ''
                };
            },
            methods: {
                convertToMySQLDatetime(datetimeLocal) {
                    const date = new Date(datetimeLocal);
                    const pad = (n) => n.toString().padStart(2, '0');
                    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
                        `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
                },
                convertToMySQLDate(datetimeLocal) {
                    const date = new Date(datetimeLocal);
                    const pad = (n) => n.toString().padStart(2, '0');
                    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
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
                parseReceiptIds(rawValue) {
                    return String(rawValue || '')
                        .split(/[\s,;]+/)
                        .map((value) => value.trim())
                        .filter(Boolean);
                },
                async ottieniStatistiche() {
                    const from = this.filters.from;
                    const to = this.filters.to;
                    const cassa = this.filters.cassa.trim();

                    let fromStr = '1990-01-01 00:00:00';
                    if (from && from.trim() !== '') {
                        fromStr = this.convertToMySQLDatetime(from);
                    }

                    let toStr = '3000-12-31 23:59:59';
                    if (to && to.trim() !== '') {
                        toStr = this.convertToMySQLDatetime(to);
                    }

                    this.loadingStats = true;
                    this.resultsError = '';
                    this.storniData = [];
                    this.dataOraEstr = '';

                    try {
                        const data = await $.ajax({
                            url: '../api/statistiche_storni.php',
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

                        this.storniData = Array.isArray(data.storni) ? data.storni : [];
                        this.dataOraEstr = this.ottieniDataOraAttuale();
                    } catch (error) {
                        this.resultsError = 'Errore nel recupero dei dati.';
                    } finally {
                        this.loadingStats = false;
                    }
                },
                async stornaScontrino() {
                    const ids = this.parseReceiptIds(this.stornoForm.idScontr);
                    const dat = this.stornoForm.date;

                    if (ids.length === 0) {
                        window.showToast('Inserire almeno un numero scontrino', 'error');
                        return;
                    }

                    if (!dat) {
                        window.showToast('Inserire la data', 'error');
                        return;
                    }

                    this.loadingStorno = true;
                    this.stornoMessage = '';

                    try {
                        const data = await $.ajax({
                            url: '../api/storna_scontrino.php',
                            type: 'POST',
                            data: {
                                idScontr: ids.join(','),
                                dat: this.convertToMySQLDate(dat)
                            },
                            dataType: 'json'
                        });

                        if (data.error) {
                            this.stornoMessage = `ESITO STORNO Scontrini ${ids.join(', ')}: ${data.error}`;
                            return;
                        }

                        this.stornoMessage = `ESITO STORNO Scontrini ${ids.join(', ')}: ${data.esito}`;

                        if ((data.updatedCount ?? 0) > 0) {
                            await this.ottieniStatistiche();
                        }
                    } catch (error) {
                        this.stornoMessage = `ESITO STORNO Scontrini ${ids.join(', ')}: Errore nello storno dello scontrino.`;
                    } finally {
                        this.loadingStorno = false;
                    }
                }
            },
            mounted() {
                const today = new Date();
                const yyyy = String(today.getFullYear());
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                this.stornoForm.date = `${yyyy}-${mm}-${dd}`;
            }
        });

        storniApp.mount('#storniApp');
    </script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const datInput = document.getElementById('dat');
        if (datInput && !datInput.value) {
            const today = new Date();
            const yyyy = String(today.getFullYear());
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            datInput.value = `${yyyy}-${mm}-${dd}`;
        }

    });
</script>
</body>

</html>