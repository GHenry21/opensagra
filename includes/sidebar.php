<?php
$_hInPages = str_contains($_SERVER['PHP_SELF'] ?? '', '/pages/');
$_hRoot = $_hInPages ? '../' : '';
$_hPages = $_hInPages ? '' : 'pages/';
$_hCurrentPage = basename(parse_url($_SERVER['PHP_SELF'] ?? '', PHP_URL_PATH) ?? '');

$_hEnv = parse_ini_file(__DIR__ . '/../config/variabili.env');
$_hDbHost = $_hEnv['DB_POS_HOST'] ?? 'localhost';

$_hNavGroups = [
    [
        [
            'file' => 'index.php',
            'root' => true,
            'label' => 'Home',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.7V21h14V9.7"></path><path d="M9 21v-6h6v6"></path></svg>',
        ],
        [
            'file' => 'billing.php',
            'label' => 'Vendite',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="7" x="3" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="14" rx="1"></rect><rect width="7" height="7" x="3" y="14" rx="1"></rect></svg>',
        ],
        [
            'file' => 'storni.php',
            'label' => 'Storni',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1z"></path><path d="M8 6h8"></path><path d="M8 10h5"></path><circle cx="17" cy="16" r="4"></circle><path d="m15.5 14.5 3 3"></path><path d="m18.5 14.5-3 3"></path></svg>',
        ],
        [
            'file' => 'stat_vendite.php',
            'label' => 'Statistiche Vendite',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path></svg>',
        ],
    ],
    [
        [
            'file' => 'add_product.php',
            'label' => 'Gestione Prodotti',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"></rect><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><path d="M12 11h4"></path><path d="M12 16h4"></path><path d="M8 11h.01"></path><path d="M8 16h.01"></path></svg>',
        ],
        [
            'file' => 'conf_scontrino.php',
            'label' => 'Configura Scontrino',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2"></path><path d="M8 7h8"></path><path d="M8 11h8"></path><path d="M8 15h5"></path></svg>',
        ],
        [
            'file' => 'conf_stampanti.php',
            'label' => 'Configura Stampanti',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"></path><rect x="6" y="14" width="12" height="8" rx="1"></rect></svg>',
        ],
    ],
];
?>
<aside class="pos-sidebar" id="posSidebar" aria-label="Navigazione principale">
    <div class="pos-sidebar__brand">
        <a href="<?= $_hRoot ?>index.php" class="brand-link" aria-label="Torna alla home">
            <img src="<?= $_hRoot ?>assets/logo.svg" alt="Logo" class="pos-logo">
            <h1 class="pos-title">
              <span class="word-open">OPEN</span> SAGRA
            </h1>
        </a>
        <button type="button" id="posSidebarClose" class="pos-icon-btn pos-sidebar-close-btn" title="Chiudi menu"
            aria-label="Chiudi il menu di navigazione">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m15 18-6-6 6-6"></path>
            </svg>
        </button>
    </div>
    <?php foreach ($_hNavGroups as $_hGroupIndex => $_hGroup): ?>
    <nav class="pos-sidebar__nav<?= $_hGroupIndex > 0 ? ' pos-sidebar__nav--gap' : '' ?>" aria-label="Pagine gestione">
        <?php foreach ($_hGroup as $_hItem): ?>
        <a href="<?= (!empty($_hItem['root']) ? $_hRoot : $_hPages) . $_hItem['file'] ?>"
            class="pos-sidebar__link<?= $_hCurrentPage === $_hItem['file'] ? ' is-active' : '' ?>"
            <?= $_hCurrentPage === $_hItem['file'] ? 'aria-current="page"' : '' ?>>
            <?= $_hItem['icon'] ?>
            <span><?= $_hItem['label'] ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php endforeach; ?>
    <nav class="pos-sidebar__nav pos-sidebar__nav--gap" aria-label="Strumenti database">
        <a href="http://<?= htmlspecialchars($_hDbHost) ?>/phpmyadmin" target="_blank" rel="noopener noreferrer"
            class="pos-sidebar__link">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <ellipse cx="12" cy="7" rx="9" ry="3"></ellipse>
                <path d="M3 7v10c0 1.66 4 3 9 3s9-1.34 9-3V7"></path>
                <path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"></path>
                <rect x="7" y="10" width="10" height="10" rx="1" fill="white" stroke-width="0"></rect>
                <path d="M10 17v-3"></path>
                <path d="M14 17v-6"></path>
                <path d="M7 10h10v10H7z" fill="none" stroke="currentColor" stroke-width="2"></path>
            </svg>
            <span>Gestione Database</span>
        </a>
        <button type="button" id="posCreateDbBtn" class="pos-sidebar__link"
            data-url="<?= $_hRoot ?>config/crea_dbtable_and_user.php">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M11 5h10"></path>
                <path d="M11 12h10"></path>
                <path d="M11 19h10"></path>
                <path d="M4 4h1v5"></path>
                <path d="M4 9h2"></path>
                <path d="M6.5 20H3.4c0-1 2.6-1.925 2.6-3.5a1.5 1.5 0 0 0-2.6-1.02"></path>
            </svg>
            <span>Crea DB e Tabelle</span>
        </button>
    </nav>
    <div class="pos-sidebar__divider"></div>
    <div class="pos-sidebar__footer">
        <span id="pos-cassa-badge" class="pos-badge">Cassa ID: N/D</span>
        <button type="button" id="theme-switch-btn" class="pos-icon-btn" title="Tema" aria-label="Cambia tema">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3a7 7 0 1 0 9 9 9 9 0 1 1-9-9"></path>
            </svg>
        </button>
    </div>
</aside>
<div class="pos-sidebar-overlay" id="posSidebarOverlay"></div>
<script src="<?= $_hRoot ?>assets/js/toast.js"></script>
<script src="<?= $_hRoot ?>assets/js/confirm-dialog.js"></script>
<script>
    (function() {
        function init() {
            var sidebar = document.getElementById('posSidebar');
            var overlay = document.getElementById('posSidebarOverlay');
            var toggle = document.getElementById('posSidebarToggle');
            var closeBtn = document.getElementById('posSidebarClose');
            if (!sidebar) {
                return;
            }

            var desktopQuery = window.matchMedia('(min-width: 993px)');

            function setToggleState(isOpen) {
                if (toggle) {
                    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                }
            }

            // Su mobile la sidebar è un cassetto fuori schermo per default (nascosta finché
            // non si apre); su desktop è visibile per default finché non la si collassa.
            function setOpen(isOpen) {
                document.body.classList.toggle('sidebar-open', isOpen);
                setToggleState(isOpen);
            }

            function setCollapsed(isCollapsed) {
                document.body.classList.toggle('sidebar-collapsed', isCollapsed);
                setToggleState(!isCollapsed);
            }

            // Chiude la sidebar comunque sia aperta: cassetto mobile o pannello desktop.
            function closeSidebar() {
                if (desktopQuery.matches) {
                    setCollapsed(true);
                } else {
                    setOpen(false);
                }
            }

            if (toggle) {
                toggle.addEventListener('click', function() {
                    if (desktopQuery.matches) {
                        setCollapsed(!document.body.classList.contains('sidebar-collapsed'));
                    } else {
                        setOpen(!document.body.classList.contains('sidebar-open'));
                    }
                });
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', closeSidebar);
            }

            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }

            // Click fuori dalla sidebar ed Esc chiudono solo il cassetto mobile: su
            // desktop la sidebar si collassa/espande solo tramite il tasto dedicato.
            document.addEventListener('click', function(event) {
                if (desktopQuery.matches || !document.body.classList.contains('sidebar-open')) {
                    return;
                }
                if (event.target.closest('.pos-sidebar') || event.target.closest('#posSidebarToggle')) {
                    return;
                }
                setOpen(false);
            });

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && !desktopQuery.matches) {
                    setOpen(false);
                }
            });

            var createDbBtn = document.getElementById('posCreateDbBtn');
            if (createDbBtn) {
                createDbBtn.addEventListener('click', function() {
                    var url = this.getAttribute('data-url');
                    this.style.pointerEvents = 'none';
                    this.style.opacity = '0.6';
                    var button = this;
                    fetch(url)
                        .then(function(response) {
                            if (!response.ok) {
                                throw new Error('Errore nella richiesta: ' + response.statusText);
                            }
                            return response.text();
                        })
                        .then(function(data) {
                            var messaggioPulito = data.replace(/<br\s*\/?>/gi, '\n');
                            showToast('Operazione completata:\n' + messaggioPulito, 'success');
                        })
                        .catch(function(error) {
                            console.error('Errore:', error);
                            showToast('Si è verificato un errore durante la creazione del database.', 'error');
                        })
                        .finally(function() {
                            button.style.pointerEvents = 'auto';
                            button.style.opacity = '1';
                        });
                });
            }
        }

        // header.php (che definisce #posSidebarToggle) viene incluso dopo questo file,
        // quindi bisogna attendere che l'intero documento sia stato parsato.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init, { once: true });
        } else {
            init();
        }
    })();
</script>
