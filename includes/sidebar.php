<?php
require_once __DIR__ . '/icons.php';
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
            'icon' => 'house',
        ],
        [
            'file' => 'billing.php',
            'label' => 'Vendite',
            'icon' => 'layout-grid',
        ],
        [
            'file' => 'storni.php',
            'label' => 'Storni',
            'icon' => 'storno',
        ],
        [
            'file' => 'stat_vendite.php',
            'label' => 'Statistiche Vendite',
            'icon' => 'chart-column',
        ],
    ],
    [
        [
            'file' => 'add_product.php',
            'label' => 'Gestione Prodotti',
            'icon' => 'clipboard-list',
        ],
        [
            'file' => 'conf_scontrino.php',
            'label' => 'Configura Scontrino',
            'icon' => 'receipt',
        ],
        [
            'file' => 'conf_casse.php',
            'label' => 'Configura Casse',
            'icon' => 'coins',
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
            <?= pos_icon('chevron-left') ?>
        </button>
    </div>
    <?php foreach ($_hNavGroups as $_hGroupIndex => $_hGroup): ?>
    <nav class="pos-sidebar__nav<?= $_hGroupIndex > 0 ? ' pos-sidebar__nav--gap' : '' ?>" aria-label="Pagine gestione">
        <?php foreach ($_hGroup as $_hItem): ?>
        <a href="<?= (!empty($_hItem['root']) ? $_hRoot : $_hPages) . $_hItem['file'] ?>"
            class="pos-sidebar__link<?= $_hCurrentPage === $_hItem['file'] ? ' is-active' : '' ?>"
            <?= $_hCurrentPage === $_hItem['file'] ? 'aria-current="page"' : '' ?>>
            <?= pos_icon($_hItem['icon']) ?>
            <span><?= $_hItem['label'] ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php endforeach; ?>
    <nav class="pos-sidebar__nav pos-sidebar__nav--gap" aria-label="Strumenti database">
        <a href="http://<?= htmlspecialchars($_hDbHost) ?>/phpmyadmin" target="_blank" rel="noopener noreferrer"
            class="pos-sidebar__link">
            <?= pos_icon('database-table') ?>
            <span>Gestione Database</span>
        </a>
        <button type="button" id="posCreateDbBtn" class="pos-sidebar__link"
            data-url="<?= $_hRoot ?>config/crea_dbtable_and_user.php">
            <?= pos_icon('list-ordered') ?>
            <span>Crea DB e Tabelle</span>
        </button>
    </nav>
    <div class="pos-sidebar__divider"></div>
    <div class="pos-sidebar__footer">
        <button type="button" id="chiudiCassaBtn" class="pos-sidebar__link"
            data-api-url="<?= $_hRoot ?>api/chiudi_cassa.php" data-stats-url="<?= $_hRoot ?>pages/stat_vendite.php">
            <?= pos_icon('logout') ?>
            <span>Chiudi Cassa</span>
        </button>
        <div class="pos-sidebar__footer-row">
            <span id="pos-cassa-badge" class="pos-badge">Cassa: N/D</span>
            <button type="button" id="theme-switch-btn" class="pos-icon-btn" title="Tema" aria-label="Cambia tema">
                <?= pos_icon('moon') ?>
            </button>
        </div>
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

            var chiudiCassaBtn = document.getElementById('chiudiCassaBtn');
            if (chiudiCassaBtn) {
                chiudiCassaBtn.addEventListener('click', function() {
                    var apiUrl = this.getAttribute('data-api-url');
                    var statsUrl = this.getAttribute('data-stats-url');
                    var cassaId = '';
                    try {
                        cassaId = localStorage.getItem('cassa_id') || '';
                    } catch (err) {
                        cassaId = '';
                    }

                    fetch(apiUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ cassa_id: cassaId })
                    })
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(data) {
                            if (!data || data.error) {
                                showToast((data && data.error) || 'Errore durante la chiusura cassa.', 'error');
                                return;
                            }

                            var euro = function(value) {
                                return (parseFloat(value || 0)).toFixed(2).replace('.', ',') + ' €';
                            };
                            var ora = new Date(String(data.ultima_chiusura).replace(' ', 'T'));
                            var oraLabel = isNaN(ora.getTime())
                                ? data.ultima_chiusura
                                : String(ora.getHours()).padStart(2, '0') + ':' + String(ora.getMinutes()).padStart(2, '0');

                            showToast(
                                'Chiusura cassa ' + cassaId + ' ore ' + oraLabel + ' · Fondo: ' + euro(data.fondo_cassa) +
                                ' · Contanti oggi: ' + euro(data.totale_contanti) + ' · Atteso: ' + euro(data.totale_atteso),
                                'info',
                                { duration: 0, action: { label: 'Vai a statistiche', href: statsUrl } }
                            );
                        })
                        .catch(function(error) {
                            console.error('Errore chiusura cassa:', error);
                            showToast('Errore durante la chiusura cassa.', 'error');
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
