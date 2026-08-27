<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/home.css">

<!-- Importa tutte le favicon con una sola riga -->
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>

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
                <div
                    class="w-9 h-9 rounded-lg bg-orange-50 dark:bg-orange-950/20 flex items-center justify-center mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-layout-grid h-4 w-4 sm:h-5 sm:w-5 text-orange-600" aria-hidden="true">
                        <rect width="7" height="7" x="3" y="3" rx="1"></rect>
                        <rect width="7" height="7" x="14" y="3" rx="1"></rect>
                        <rect width="7" height="7" x="14" y="14" rx="1"></rect>
                        <rect width="7" height="7" x="3" y="14" rx="1"></rect>
                    </svg>
                </div>
                <span class="home-action__title">Vendite</span>
                <span class="home-action__desc">Nuovo scontrino, gestione carrello e stampa ricevuta.</span>
            </a>

            <!-- Storni -->
            <a href="storni.php" class="home-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-storno h-4 w-4 sm:h-5 sm:w-5 " aria-hidden="true">
                    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1z"></path>
                    <path d="M8 6h8"></path>
                    <path d="M8 10h5"></path>
                    <circle cx="17" cy="16" r="4"></circle>
                    <path d="m15.5 14.5 3 3"></path>
                    <path d="m18.5 14.5-3 3"></path>
                </svg>

                <span class="home-action__title">Storni</span>
                <span class="home-action__desc">Ricerca e storno rapido degli scontrini emessi.</span>
            </a>

            <!-- Gestione Prodotti -->
            <a href="add_product.php" class="home-action">
                <div class="w-9 h-9 rounded-lg bg-pink-50 dark:bg-pink-950/20 flex items-center justify-center mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-clipboard-list h-4 w-4 sm:h-5 sm:w-5 text-pink-600" aria-hidden="true">
                        <rect width="8" height="4" x="8" y="2" rx="1" ry="1"></rect>
                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                        <path d="M12 11h4"></path>
                        <path d="M12 16h4"></path>
                        <path d="M8 11h.01"></path>
                        <path d="M8 16h.01"></path>
                    </svg>
                </div>
                <span class="home-action__title">Gestione Prodotti</span>
                <span class="home-action__desc">Aggiungi nuovi articoli al catalogo con immagine, categoria, prezzo e
                    gestisci gli esistenti.</span>
            </a>

            <!-- Statistiche Vendite -->
            <a href="stat_vendite.php" class="home-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-chart-column h-4 w-4 sm:h-5 sm:w-5 text-violet-600" aria-hidden="true">
                    <path d="M3 3v16a2 2 0 0 0 2 2h16"></path>
                    <path d="M18 17V9"></path>
                    <path d="M13 17V5"></path>
                    <path d="M8 17v-3"></path>
                </svg>
                <span class="home-action__title">Statistiche Vendite</span>
                <span class="home-action__desc">Analisi importi e volumi venduti della giornata.</span>
            </a>

            <!-- Configura Stampanti -->
            <a href="conf_stampanti.php" class="home-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-printer h-4 w-4 sm:h-5 sm:w-5 text-blue-600" aria-hidden="true">
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"></path>
                    <rect x="6" y="14" width="12" height="8" rx="1"></rect>
                </svg>
                <span class="home-action__title">Configura Stampanti</span>
                <span class="home-action__desc">Imposta stampante ricevute e dispositivi della cassa.</span>
            </a>

            <!-- Configura Scontrino -->
            <a href="conf_scontrino.php" class="home-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-receipt h-4 w-4 sm:h-5 sm:w-5 text-emerald-600" aria-hidden="true">
                    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2"></path>
                    <path d="M8 7h8"></path>
                    <path d="M8 11h8"></path>
                    <path d="M8 15h5"></path>
                </svg>
                <span class="home-action__title">Configura Scontrino</span>
                <span class="home-action__desc">Definisci testo custom e cut per-item in modo globale per tutte le
                    casse.</span>
            </a>
        </nav>
    </main>
    </div>
</body>

</html>