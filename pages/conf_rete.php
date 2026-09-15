<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/conf_rete.css">
    <!-- Importa tutte le favicon con una sola riga -->
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <?php require_once __DIR__ . '/../includes/icons.php'; ?>
    <?php require_once __DIR__ . '/../config/env_reader.php'; ?>
    <?php require_once __DIR__ . '/../config/local_ip.php'; ?>
    <title>Configurazione Rete</title>
    <script src="../assets/js/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme.js"></script>
    <script src="../assets/js/qrcode.min.js"></script>
</head>

<body class="management-page canvas-page sidebar-page">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="pos-main-panel">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <main class="management-shell">
        <?php
        $env = loadPosEnvVars();
        $isIndipendente = ($env['host'] === '127.0.0.1' || $env['host'] === 'localhost');
        $localIp = detectLocalLanIp();
        $localHostname = detectLocalHostname();
        // "192.168.88.224 (HENRY)" quando abbiamo entrambi, altrimenti solo quello disponibile.
        $localLabel = $localIp
            ? htmlspecialchars($localIp) . ($localHostname ? ' (' . htmlspecialchars($localHostname) . ')' : '')
            : ($localHostname ? htmlspecialchars($localHostname) : null);
        // URL della pagina cert/ (../cert/ da qui) risolto in assoluto sull'IP di rete:
        // e' quello che finisce nel QR, quindi deve funzionare per un cellulare che
        // parte da zero, non solo relativo alla pagina corrente. In HTTP puro (non
        // HTTPS) apposta: e' il primo contatto di un dispositivo che non si fida
        // ancora del certificato, quindi non deve mostrare l'avviso "sito non sicuro"
        // proprio nel passo pensato per risolverlo.
        // dirname() due volte per risalire da pages/conf_rete.php alla radice
        // dell'app. Passaggio intermedio con str_replace: su Windows, quando non
        // resta più nulla da risalire, dirname() ripiega su "\" (il separatore
        // dell'OS) invece di "/" anche per un path in stile URL come questo,
        // e rtrim('/') da solo non lo ripulirebbe.
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $appBasePath = rtrim(str_replace('\\', '/', dirname($scriptDir)), '/');
        $certUrl = $localIp ? ('http://' . $localIp . $appBasePath . '/cert/link.php') : null;
        ?>
        <div class="rete-shell">
            <section class="rete-card">
                <div class="panel-title-row panel-title-bordered">
                    <?= pos_icon('network', ['class' => 'panel-title-icon']) ?>
                    <h2>Configurazione Rete</h2>
                </div>
                <p class="inline-muted">
                    Decide se questo PC gestisce i propri dati per conto suo, oppure si collega al database
                    di un'altra installazione opensagra sulla stessa rete locale. Il cambio ha effetto subito,
                    non serve riavviare nulla.
                </p>

                <div class="rete-status-row">
                    <span class="rete-status-dot" id="reteStatusDot"></span>
                    <span id="reteStatusText">Verifica in corso…</span>
                </div>
            </section>

            <!-- Fase 4 punto 4: vendite fatte in fallback locale, ancora da
                 ricaricare sul server centrale. Visibile solo se ce ne sono. -->
            <section class="rete-card rete-card--warn" id="syncPendingCard" hidden>
                <div class="panel-title-row">
                    <?= pos_icon('refresh-cw', ['class' => 'panel-title-icon']) ?>
                    <h3>Vendite locali da sincronizzare</h3>
                </div>
                <p class="inline-muted" id="syncPendingText">
                    Questa postazione ha lavorato in autonomia mentre il server centrale non era
                    raggiungibile. Le vendite fatte in quel periodo sono salvate qui in locale e vanno
                    ricaricate sul server centrale.
                </p>
                <div class="actions-row">
                    <button class="btn-add" id="btnSyncNow">
                        <?= pos_icon('refresh-cw') ?>
                        Sincronizza ora
                    </button>
                </div>
            </section>

            <section class="rete-card">
                <div class="rete-mode-row">
                    <label class="rete-mode-option">
                        <span class="rete-switch">
                            <input type="radio" name="reteMode" value="indipendente" id="modeIndipendente" <?= $isIndipendente ? 'checked' : '' ?>>
                            <span></span>
                        </span>
                        <span class="rete-mode-option__text">
                            <strong>Indipendente</strong>
                            <p class="inline-muted">Questo PC usa il proprio database, in locale. È già così di
                                default su ogni installazione.</p>
                        </span>
                    </label>
                    <label class="rete-mode-option">
                        <span class="rete-switch">
                            <input type="radio" name="reteMode" value="client" id="modeClient" <?= !$isIndipendente ? 'checked' : '' ?>>
                            <span></span>
                        </span>
                        <span class="rete-mode-option__text">
                            <strong>Client: punta a un server in rete</strong>
                            <p class="inline-muted">Usa il database di un'altra installazione opensagra
                                raggiungibile in rete locale (es. un PC "server" con più casse collegate).</p>
                        </span>
                    </label>
                </div>

                <div class="field-wrap" id="hostFieldWrap" <?= $isIndipendente ? 'style="display:none"' : '' ?>>
                    <label for="serverHostInput">Indirizzo del server</label>
                    <input type="text" id="serverHostInput" placeholder="es. 192.168.1.10"
                        value="<?= $isIndipendente ? '' : htmlspecialchars($env['host']) ?>">
                </div>

                <div class="actions-row">
                    <button class="btn-add" id="btnSaveRete">
                        <?= pos_icon('network') ?>
                        Salva
                    </button>
                </div>
            </section>

            <section class="rete-card">
                <div class="panel-title-row">
                    <h3>Come collegare altre casse a questo PC come server</h3>
                </div>
                <p class="inline-muted">
                    Ogni installazione OpenSagra è già pronta a fare da server per le altre: non serve
                    nessuna configurazione aggiuntiva su questo PC. 
                    Sul PC che fungerà da client, apri questa
                    stessa pagina (Configurazione Rete) e scegli "Client: punta a un server in rete",
                    indicando l'indirizzo IP di questo PC: <?= $localIp ? " <code>{$localIp}</code>" : '' ?>.
                </p>
                <p class="inline-muted">
                    Attenzione: se questo PC si spegne o esce dalla rete, le casse collegate a lui passano
                    da sole a lavorare in locale dopo una breve attesa (in genere entro una trentina di
                    secondi) — nessuna vendita viene persa. Per tornare a usare questo PC come server basta
                    riselezionare "Client" dalla loro pagina Configurazione Rete quando è di nuovo raggiungibile.
                </p>
            </section>

            <?php if ($certUrl): ?>
            <section class="rete-card">
                <div class="panel-title-row">
                    <?= pos_icon('qr-code', ['class' => 'panel-title-icon']) ?>
                    <h3>Collega un cellulare o tablet</h3>
                </div>
                <p class="inline-muted">
                    Inquadra questo codice con la fotocamera del cellulare: si apre una pagina per
                    scaricare il certificato di sicurezza di questo PC e poi aprire OpenSagra. Va
                    fatto una sola volta per dispositivo, così il browser non segnala più il sito
                    come "non sicuro".
                </p>
                <div class="cert-qr-row">
                    <div id="certQrCode" class="cert-qr-box"></div>
                    <p class="inline-muted cert-qr-url"><?= htmlspecialchars($certUrl) ?></p>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </main>
    </div>

    <script src="../assets/js/toast.js"></script>
    <script>
        (function() {
            const modeIndipendente = document.getElementById('modeIndipendente');
            const modeClient = document.getElementById('modeClient');
            const hostFieldWrap = document.getElementById('hostFieldWrap');
            const serverHostInput = document.getElementById('serverHostInput');
            const btnSave = document.getElementById('btnSaveRete');
            const statusDot = document.getElementById('reteStatusDot');
            const statusText = document.getElementById('reteStatusText');
            const syncCard = document.getElementById('syncPendingCard');
            const syncText = document.getElementById('syncPendingText');
            const btnSyncNow = document.getElementById('btnSyncNow');

            // Ultimo conteggio noto di vendite locali da sincronizzare: usato
            // sia per mostrare la card, sia per avvisare prima di cambiare rete.
            let pendingSync = 0;

            // "YYYY-MM-DD HH:MM:SS" (dal DB) -> data/ora leggibile; Safari non
            // parsa quel formato senza la "T".
            function formatWhen(iso) {
                const d = new Date(String(iso).replace(' ', 'T'));
                if (isNaN(d.getTime())) {
                    return iso;
                }
                return d.toLocaleString('it-IT', {
                    day: '2-digit', month: '2-digit', year: 'numeric',
                    hour: '2-digit', minute: '2-digit'
                });
            }

            // Fase 4 punto 4: "Annulla" del toast dopo un ripristino automatico del
            // catalogo - richiama il batch di sicurezza preso appena prima di quel
            // ripristino (config/catalog_backup.php::restoreCatalogBackupBatch).
            //
            // Ricorsiva di proposito (corretto 2026-09-13, collaudo VM): ogni ripristino,
            // "Annulla" incluso, salva SEMPRE un nuovo backup di sicurezza dello stato
            // appena sostituito (config/catalog_backup.php lo fa già lato dati) - qui si
            // riflette la stessa cosa lato interfaccia, cosi' non si resta mai bloccati
            // dopo un solo "Annulla": ognuno apre la porta per tornare indietro di un
            // altro passo ancora, all'infinito.
            function undoCatalogRestore(safetyBatchId) {
                fetch('../api/restore_catalog_backup.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ batch_id: safetyBatchId })
                    })
                    .then((r) => r.json())
                    .then((data) => {
                        if (!data || !data.success) {
                            showToast((data && data.error) || 'Annullamento non riuscito.', 'error');
                            return;
                        }
                        const nextSafetyId = data.safety_backup_id;
                        showToast(
                            'Fatto: il catalogo è tornato quello di un attimo fa.',
                            'success',
                            nextSafetyId ? {
                                duration: 0,
                                action: { label: 'Annulla', onClick: () => undoCatalogRestore(nextSafetyId) }
                            } : undefined
                        );
                    })
                    .catch(() => showToast('Errore di rete durante l\'annullamento.', 'error'));
            }

            function refreshSyncStatus() {
                return fetch('../api/sync_status.php')
                    .then((r) => r.json())
                    .then((s) => {
                        pendingSync = (s && s.pending_sync) || 0;
                        if (pendingSync > 0) {
                            const plural = pendingSync === 1 ? 'vendita' : 'vendite';
                            let msg = `Questa postazione ha ${pendingSync} ${plural} fatte in autonomia mentre il ` +
                                'server centrale non era raggiungibile, ancora da ricaricare sul server centrale.';
                            if (s.origin_host) {
                                msg += ` Server di destinazione: ${s.origin_host}.`;
                            }
                            syncText.textContent = msg;
                            syncCard.hidden = false;
                        } else {
                            syncCard.hidden = true;
                        }
                    })
                    .catch(() => { /* endpoint assente o offline: lascia la card com'è */ });
            }
            refreshSyncStatus();
            setInterval(refreshSyncStatus, 15000);

            if (btnSyncNow) {
                btnSyncNow.addEventListener('click', function() {
                    btnSyncNow.disabled = true;
                    // Routine condivisa definita da includes/sidebar.php.
                    if (typeof window.posSyncLocalSales === 'function') {
                        window.posSyncLocalSales('../api/push_local_sales.php', pendingSync);
                        setTimeout(function() {
                            btnSyncNow.disabled = false;
                            refreshSyncStatus();
                            refreshStatus();
                        }, 2500);
                    } else {
                        btnSyncNow.disabled = false;
                    }
                });
            }

            const certQrCode = document.getElementById('certQrCode');
            if (certQrCode) {
                new QRCode(certQrCode, {
                    text: <?= json_encode($certUrl) ?>,
                    width: 150,
                    height: 150,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }

            function toggleHostField() {
                hostFieldWrap.style.display = modeClient.checked ? '' : 'none';
            }
            modeIndipendente.addEventListener('change', toggleHostField);
            modeClient.addEventListener('change', toggleHostField);

            function refreshStatus() {
                fetch('../api/db_status.php')
                    .then((r) => r.json())
                    .then((data) => {
                        const shownHost = (data.display_host || data.host) + (data.hostname ? ` (${data.hostname})` : '');
                        statusDot.className = 'rete-status-dot' + (data.online ? ' is-online' : ' is-offline');
                        statusText.textContent = data.online
                            ? `Connesso a ${shownHost}`
                            : `Non raggiungibile: ${shownHost}`;
                    })
                    .catch(() => {
                        statusDot.className = 'rete-status-dot is-offline';
                        statusText.textContent = 'Impossibile verificare lo stato.';
                    });
            }
            refreshStatus();
            setInterval(refreshStatus, 15000);

            btnSave.addEventListener('click', async function() {
                const mode = modeClient.checked ? 'client' : 'indipendente';
                const host = serverHostInput.value.trim();

                if (mode === 'client' && host === '') {
                    showToast('Indica l\'indirizzo del server.', 'error');
                    return;
                }

                // Conferma sempre, anche se la modalità sembra già quella attuale:
                // è un'operazione che cambia il database usato da tutta l'app, va
                // fatta con intenzione, non con un click distratto.
                // Rafforzata con un dato reale (non un avviso generico sempre
                // uguale): quante altre postazioni sono davvero connesse in
                // questo momento al database locale di questo PC.
                let externalWarning = '';
                try {
                    const connResp = await fetch('../api/db_connections.php');
                    const connData = await connResp.json();
                    if (connData.external_count > 0) {
                        const elenco = connData.hosts.join(', ');
                        externalWarning = `\n\n Attenzione: in questo momento ${connData.external_count} altra/e postazione/i ` +
                            `(${elenco}) risulta/no collegata/e al database locale di questo PC — probabilmente lo usano ` +
                            `già come server condiviso.`;
                    }
                } catch (e) {
                    // Controllo best-effort: se fallisce, il dialogo prosegue senza quel dettaglio.
                }

                // Fase 4 punto 4: cambiare rete azzera il marker di fallback, quindi
                // se ci sono vendite locali non ancora sincronizzate va detto forte.
                await refreshSyncStatus();
                let syncWarning = '';
                if (pendingSync > 0) {
                    const plural = pendingSync === 1 ? 'vendita locale' : 'vendite locali';
                    syncWarning = `\n\n⚠ Ci sono ${pendingSync} ${plural} non ancora sincronizzate col server centrale. ` +
                        'Se cambi rete ora, dovrai ricaricarle con "Sincronizza ora" (il pulsante qui sopra) a centrale ' +
                        'raggiungibile. Meglio sincronizzarle prima.';
                }

                const confirmMessage = (mode === 'indipendente'
                    ? 'Questo PC tornerà a usare il proprio database in locale, invece di quello del server a cui punta ora.'
                    : `Questo PC userà d'ora in poi il database del server all'indirizzo ${host}, invece del proprio database locale.`)
                    + externalWarning
                    + syncWarning
                    + '\n\nContinuare?';
                const confirmed = await showConfirm(confirmMessage, {
                    title: 'Conferma cambio rete',
                    confirmLabel: 'Applica',
                    cancelLabel: 'Annulla',
                    confirmVariant: 'primary'
                });
                if (!confirmed) {
                    return;
                }

                btnSave.disabled = true;
                fetch('../api/set_network_config.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ mode, host })
                    })
                    .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
                    .then(({ ok, data }) => {
                        if (!ok || !data.success) {
                            showToast((data && data.error) || 'Errore durante il salvataggio.', 'error');
                            return;
                        }
                        showToast(
                            mode === 'indipendente'
                                ? 'Modalità indipendente attiva: questo PC usa il proprio database.'
                                : `Collegato al server ${data.host}.`,
                            'success'
                        );
                        // Fase 4 punto 4: il locale aveva un catalogo proprio non vuoto -
                        // e' stato salvato prima di passare a client (verra' sovrascritto
                        // dal centrale entro pochi secondi). Solo informativo, non blocca
                        // nulla: nessuna pagina da visitare, il salvataggio e' gia' fatto.
                        if (data.catalog_backup_id) {
                            showToast(
                                'Il catalogo locale che avevi è stato salvato: tornerà com\'era appena passerai di nuovo a Indipendente.',
                                'info',
                                { duration: 0 }
                            );
                        }
                        // Fase 4 punto 4: switch pulito verso Indipendente - se c'era un
                        // catalogo salvato da un passaggio a Client precedente, e' stato
                        // rimesso a posto in automatico (righe intere, quantita' comprese).
                        // "Annulla" richiama lo stesso safety_backup_id appena creato: non
                        // si perde mai nulla, nemmeno il catalogo del centrale appena
                        // sostituito.
                        if (data.catalog_restored) {
                            const when = formatWhen(data.catalog_restored.created_at);
                            const safetyId = data.catalog_restored.safety_backup_id;
                            showToast(
                                `Il tuo catalogo di prima (${when}) è stato rimesso a posto.`,
                                'info',
                                {
                                    duration: 0,
                                    action: safetyId ? {
                                        label: 'Annulla',
                                        onClick: () => undoCatalogRestore(safetyId)
                                    } : undefined
                                }
                            );
                        }
                        refreshStatus();
                    })
                    .catch(() => showToast('Errore di rete durante il salvataggio.', 'error'))
                    .finally(() => { btnSave.disabled = false; });
            });
        })();
    </script>
</body>

</html>
