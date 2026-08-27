<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/home.css">

<!-- Importa tutte le favicon con una sola riga -->
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <?php require_once __DIR__ . '/../includes/icons.php'; ?>

    <title>Home</title>
</head>

<body class="home-page sidebar-page">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="pos-main-panel">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <script src="../assets/js/theme.js"></script>

    <div id="cassaIdModal" class="cassa-id-modal-backdrop" role="presentation" hidden>
        <div class="cassa-id-modal" role="dialog" aria-modal="true" aria-labelledby="cassaIdModalTitle">
            <h2 id="cassaIdModalTitle">Configura questa cassa</h2>
            <p>Inserisci l'ID di questa cassa per continuare (es. cassa_bar, cassa_ristorante). Verrà salvato in
                questo browser.</p>
            <form id="cassaIdForm" autocomplete="off">
                <input type="text" id="cassaIdInput" name="cassaId" placeholder="cassa_bar" autocomplete="off"
                    aria-label="ID cassa">
                <button type="submit">Conferma</button>
            </form>
        </div>
    </div>
    <script>
        // Chiedi ID cassa una volta sola e salvalo nel browser
        (function () {
            if (localStorage.getItem('cassa_id')) {
                return;
            }

            const backdrop = document.getElementById('cassaIdModal');
            const form = document.getElementById('cassaIdForm');
            const input = document.getElementById('cassaIdInput');
            if (!backdrop || !form || !input) {
                return;
            }

            backdrop.hidden = false;
            input.focus();

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                const value = input.value.trim();
                if (!value) {
                    showToast('ID cassa obbligatorio per continuare.', 'error');
                    input.focus();
                    return;
                }

                localStorage.setItem('cassa_id', value);
                backdrop.hidden = true;

                const badge = document.getElementById('pos-cassa-badge');
                if (badge) {
                    badge.textContent = 'Cassa ID: ' + value;
                }
            });
        })();
    </script>

    <main class="home-shell">
        <header class="home-header">
            <!-- logo affiancato al titolo -->
            <div class="home-logo-title">
                <img src="../assets/logo.svg" alt="OpenSagra Logo" class="home-logo">
                <div class="home-title-group">
                    <p class="home-title">Benvenuti in Open Sagra!</p>
                    <p class="home-subtitle">Gestisci vendite, storni, statistiche e impostazioni cassa da una dashboard unica.</p>
                </div>
            </div>
        </header>

        <nav class="home-grid" aria-label="Navigazione principale POS">

            <!-- Vendite -->
            <a href="billing.php" class="home-action">
                <span class="home-action__icon home-action__icon--vendite"><?= pos_icon('layout-grid') ?></span>
                <span class="home-action__title">Vendite</span>
                <span class="home-action__desc">Nuovo scontrino, gestione carrello e stampa ricevuta.</span>
            </a>

            <!-- Storni -->
            <a href="storni.php" class="home-action">
                <span class="home-action__icon home-action__icon--storni"><?= pos_icon('storno') ?></span>
                <span class="home-action__title">Storni</span>
                <span class="home-action__desc">Ricerca e storno rapido degli scontrini emessi.</span>
            </a>

            <!-- Gestione Prodotti -->
            <a href="add_product.php" class="home-action">
                <span class="home-action__icon home-action__icon--prodotti"><?= pos_icon('clipboard-list') ?></span>
                <span class="home-action__title">Gestione Prodotti</span>
                <span class="home-action__desc">Aggiungi nuovi articoli al catalogo con immagine, categoria, prezzo e
                    gestisci gli esistenti.</span>
            </a>

            <!-- Statistiche Vendite -->
            <a href="stat_vendite.php" class="home-action">
                <span class="home-action__icon home-action__icon--statistiche"><?= pos_icon('chart-column') ?></span>
                <span class="home-action__title">Statistiche Vendite</span>
                <span class="home-action__desc">Analisi importi e volumi venduti della giornata.</span>
            </a>

            <!-- Configura Stampanti -->
            <a href="conf_stampanti.php" class="home-action">
                <span class="home-action__icon home-action__icon--stampanti"><?= pos_icon('printer') ?></span>
                <span class="home-action__title">Configura Stampanti</span>
                <span class="home-action__desc">Imposta stampante ricevute e dispositivi della cassa.</span>
            </a>

            <!-- Configura Scontrino -->
            <a href="conf_scontrino.php" class="home-action">
                <span class="home-action__icon home-action__icon--scontrino"><?= pos_icon('receipt') ?></span>
                <span class="home-action__title">Configura Scontrino</span>
                <span class="home-action__desc">Definisci testo custom e cut per-item in modo globale per tutte le
                    casse.</span>
            </a>
        </nav>
    </main>
    </div>
</body>

</html>