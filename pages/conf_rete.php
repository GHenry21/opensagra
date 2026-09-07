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

            <section class="rete-card">
                <div class="rete-mode-row">
                    <label class="rete-mode-option">
                        <input type="radio" name="reteMode" value="indipendente" id="modeIndipendente" <?= $isIndipendente ? 'checked' : '' ?>>
                        <span>
                            <strong>Indipendente</strong>
                            <p class="inline-muted">Questo PC usa il proprio database, in locale. È già così di
                                default su ogni installazione.</p>
                            <?php if ($localIp): ?>
                            <p class="inline-muted">IP di rete di questo PC (da dare alle altre casse che vorranno
                                collegarsi qui come client): <code><?= htmlspecialchars($localIp) ?></code></p>
                            <?php endif; ?>
                        </span>
                    </label>
                    <label class="rete-mode-option">
                        <input type="radio" name="reteMode" value="client" id="modeClient" <?= !$isIndipendente ? 'checked' : '' ?>>
                        <span>
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
                    <p class="inline-muted">L'IP si trova sul PC server: da un prompt dei comandi, <code>ipconfig</code>
                        (voce "Indirizzo IPv4" della rete in uso).</p>
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
                    Ogni installazione opensagra è già pronta a fare da server per le altre: non serve
                    nessuna configurazione aggiuntiva su questo PC. Sulle altre postazioni, apri questa
                    stessa pagina (Configurazione Rete) e scegli "Client: punta a un server in rete",
                    indicando l'indirizzo IP di questo PC<?= $localIp ? " (<code>" . htmlspecialchars($localIp) . "</code>)" : '' ?>.
                </p>
                <p class="inline-muted">
                    Attenzione: se questo PC si spegne o esce dalla rete, tutte le casse collegate a lui
                    smettono di funzionare finché non torna online.
                </p>
            </section>
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

            function toggleHostField() {
                hostFieldWrap.style.display = modeClient.checked ? '' : 'none';
            }
            modeIndipendente.addEventListener('change', toggleHostField);
            modeClient.addEventListener('change', toggleHostField);

            function refreshStatus() {
                fetch('../api/db_status.php')
                    .then((r) => r.json())
                    .then((data) => {
                        const shownHost = data.display_host || data.host;
                        statusDot.className = 'rete-status-dot' + (data.online ? ' is-online' : ' is-offline');
                        statusText.textContent = data.online
                            ? `Connesso a ${shownHost} (${data.latency_ms} ms)`
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
                const confirmMessage = mode === 'indipendente'
                    ? 'Questo PC tornerà a usare il proprio database in locale. Se altre casse sono collegate a questo PC come server, smetteranno di funzionare finché non le ripunti altrove. Continuare?'
                    : `Questo PC userà d'ora in poi il database del server all'indirizzo ${host}. I dati locali di questo PC (se presenti) resteranno lì ma non verranno più usati finché non torni a "Indipendente". Continuare?`;
                const confirmed = await showConfirm(confirmMessage, {
                    title: 'Conferma cambio rete',
                    confirmLabel: 'Applica',
                    cancelLabel: 'Annulla'
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
                        refreshStatus();
                    })
                    .catch(() => showToast('Errore di rete durante il salvataggio.', 'error'))
                    .finally(() => { btnSave.disabled = false; });
            });
        })();
    </script>
</body>

</html>
