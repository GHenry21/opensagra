<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, target-densitydpi=device-dpi">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/storni.css">

    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <?php require_once __DIR__ . '/../includes/icons.php'; ?>
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
                <h2><?= pos_icon('storno-doc', ['class' => 'section-icon', 'style' => 'stroke: var(--mc-primary)']) ?> Storna scontrino</h2>
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
                    <?= pos_icon('badge-check-soft', ['class' => 'section-icon section-icon-lg', 'style' => 'fill: var(--mc-primary)']) ?>
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
                <h2><?= pos_icon('receipt-check', ['class' => 'section-icon', 'style' => 'fill: var(--mc-primary)']) ?> Elenco scontrini stornati</h2>
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
                    <?= pos_icon('badge-list-soft', ['class' => 'section-icon section-icon-lg', 'style' => 'fill: var(--mc-primary)']) ?>
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