<?php
require_once __DIR__ . '/icons.php';
$_hInPages = str_contains($_SERVER['PHP_SELF'] ?? '', '/pages/');
$_hRoot = $_hInPages ? '../' : '';
$_hPages = $_hInPages ? '' : 'pages/';
$_hCurrentPage = basename(parse_url($_SERVER['PHP_SELF'] ?? '', PHP_URL_PATH) ?? '');

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
        <a href="<?= $_hPages ?>conf_rete.php"
            class="pos-sidebar__link<?= $_hCurrentPage === 'conf_rete.php' ? ' is-active' : '' ?>"
            <?= $_hCurrentPage === 'conf_rete.php' ? 'aria-current="page"' : '' ?>>
            <?= pos_icon('network') ?>
            <span>Configurazione Rete</span>
        </a>
        <a href="/db" target="_blank" rel="noopener noreferrer"
            class="pos-sidebar__link">
            <?= pos_icon('database-table') ?>
            <span>Gestione Database</span>
        </a>
    </nav>
    <div class="pos-sidebar__divider"></div>
    <div class="pos-sidebar__footer">
        <button type="button" id="chiudiCassaBtn" class="pos-sidebar__link"
            data-api-url="<?= $_hRoot ?>api/chiudi_cassa.php" data-stats-url="<?= $_hRoot ?>pages/stat_vendite.php"
            data-push-url="<?= $_hRoot ?>api/push_local_sales.php">
            <?= pos_icon('logout') ?>
            <span>Chiudi Cassa</span>
        </button>
        <div class="pos-sidebar__footer-row">
            <span id="pos-cassa-badge" class="pos-badge">Cassa: N/D</span>
            <button type="button" id="theme-switch-btn" class="pos-icon-btn" title="Tema" aria-label="Cambia tema">
                <?= pos_icon('moon') ?>
            </button>
        </div>
        <a href="<?= $_hPages ?>conf_rete.php" class="pos-net-pill" id="pos-net-pill" title="Configurazione Rete">
            <span class="pos-net-pill__dot" id="pos-net-pill-dot"></span>
            <span id="pos-net-pill-text">Rete: verifica…</span>
            <!-- Conto alla rovescia al passaggio automatico sul DB locale (Fase 4).
                 Popolato SOLO da pages/billing.php quando il centrale non risponde;
                 lo script della pillola qui sotto non lo tocca. -->
            <span id="pos-net-pill-eta" class="pos-net-pill__eta" hidden></span>
        </a>
        <!-- Fase 4 punto 4: affordance persistente per il push delle vendite
             fatte in fallback locale al server centrale. Nascosta durante il
             servizio; compare (e sopravvive a un reload) solo dopo "Chiudi
             Cassa" se restano vendite da sincronizzare. Popolata da
             api/sync_status.php dallo script in fondo a questo file. -->
        <div class="pos-sync-pending" id="pos-sync-pending" hidden>
            <?= pos_icon('refresh-cw', ['class' => 'pos-sync-pending__icon']) ?>
            <span class="pos-sync-pending__text" id="pos-sync-pending-text">Vendite da sincronizzare</span>
            <button type="button" class="pos-sync-pending__btn" id="pos-sync-pending-btn"
                data-push-url="<?= $_hRoot ?>api/push_local_sales.php">Sincronizza ora</button>
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

            var chiudiCassaBtn = document.getElementById('chiudiCassaBtn');
            if (chiudiCassaBtn) {
                var pushUrl = chiudiCassaBtn.getAttribute('data-push-url');

                // Il push vero e proprio vive in window.posSyncLocalSales (script
                // in fondo a questo file, insieme al badge persistente): stessa
                // routine per "Chiudi Cassa", per il bottone del toast e per il
                // badge in sidebar.
                function syncLocalSales(pendingHint) {
                    if (typeof window.posSyncLocalSales === 'function') {
                        window.posSyncLocalSales(pushUrl, pendingHint);
                    }
                }

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

                            // Fase 4 punto 4: cassa in fallback locale -> spingi
                            // le vendite fatte in locale al server centrale.
                            if (pushUrl && (data.fallback_active || data.pending_sync > 0)) {
                                syncLocalSales(data.pending_sync || 0);
                            }
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

    // Pillola di stato rete: ping leggero e periodico verso il DB configurato
    // (indipendente o server centrale), visibile da qualunque pagina.
    (function() {
        var dot = document.getElementById('pos-net-pill-dot');
        var text = document.getElementById('pos-net-pill-text');
        if (!dot || !text) {
            return;
        }

        var _inPages = window.location.pathname.indexOf('/pages/') !== -1;
        var apiUrl = (_inPages ? '../' : '') + 'api/db_status.php';

        function refresh() {
            fetch(apiUrl)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    dot.className = 'pos-net-pill__dot' + (data.online ? ' is-online' : ' is-offline');
                    var shownHost = (data.display_host || data.host) + (data.hostname ? ' (' + data.hostname + ')' : '');
                    text.textContent = 'Rete: ' + shownHost + (data.online ? '' : ' (offline)');
                })
                .catch(function() {
                    dot.className = 'pos-net-pill__dot is-offline';
                    text.textContent = 'Rete: n/d';
                });
        }

        refresh();
        setInterval(refresh, 20000);
    })();

    // Fase 4 punto 4: badge persistente "N vendite da sincronizzare" + routine
    // di push condivisa. Il badge resta spento durante il servizio (marker
    // 'close_sync_pending' scritto solo da api/chiudi_cassa.php) e compare su
    // qualunque pagina, sopravvivendo a un reload, finche' restano vendite
    // locali da ricaricare sul centrale.
    (function() {
        var _inPages = window.location.pathname.indexOf('/pages/') !== -1;
        var _root = _inPages ? '../' : '';
        var statusUrl = _root + 'api/sync_status.php';

        var box = document.getElementById('pos-sync-pending');
        var boxText = document.getElementById('pos-sync-pending-text');
        var boxBtn = document.getElementById('pos-sync-pending-btn');
        var _busy = false;

        function refreshBadge() {
            fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
                .then(function(r) { return r.json(); })
                .then(function(s) {
                    if (!box) { return; }
                    var n = (s && s.pending_sync) || 0;
                    // "armed" tiene il badge nascosto durante il servizio;
                    // fallback_active da solo comparirebbe gia' alla prima vendita.
                    var show = n > 0 && (s.armed || s.fallback_active === false);
                    if (show) {
                        boxText.textContent = n + (n === 1 ? ' vendita da sincronizzare' : ' vendite da sincronizzare') +
                            ' col server centrale';
                        box.hidden = false;
                    } else {
                        box.hidden = true;
                    }
                })
                .catch(function() { /* offline o endpoint assente: non mostrare nulla */ });
        }

        // Routine di push condivisa (Chiudi Cassa, toast, badge). pushUrl arriva
        // dal chiamante (data-push-url) o, in mancanza, dal bottone del badge.
        window.posSyncLocalSales = function(pushUrl, pendingHint) {
            pushUrl = pushUrl || (boxBtn && boxBtn.getAttribute('data-push-url'));
            if (!pushUrl || _busy) { return; }
            _busy = true;
            if (boxBtn) { boxBtn.disabled = true; }
            fetch(pushUrl, { method: 'POST' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res && res.success) {
                        showToast(
                            'Vendite locali sincronizzate col server centrale (' + (res.pushed || 0) + ').',
                            'success',
                            { duration: 0 }
                        );
                        if (box) { box.hidden = true; }
                        return;
                    }
                    var n = (res && (res.remaining != null ? res.remaining : res.pending)) || pendingHint || 0;
                    showToast(
                        n + ' vendite ancora da sincronizzare col server centrale.',
                        'error',
                        {
                            duration: 0,
                            action: { label: 'Sincronizza ora', onClick: function() { window.posSyncLocalSales(pushUrl, n); } }
                        }
                    );
                })
                .catch(function(err) {
                    console.error('Sync vendite locali fallita:', err);
                    showToast(
                        'Sincronizzazione col server centrale non riuscita.',
                        'error',
                        {
                            duration: 0,
                            action: { label: 'Riprova', onClick: function() { window.posSyncLocalSales(pushUrl, pendingHint); } }
                        }
                    );
                })
                .finally(function() {
                    _busy = false;
                    if (boxBtn) { boxBtn.disabled = false; }
                    refreshBadge();
                });
        };

        if (box && boxBtn) {
            boxBtn.addEventListener('click', function() {
                window.posSyncLocalSales(boxBtn.getAttribute('data-push-url'), null);
            });
            refreshBadge();
            setInterval(refreshBadge, 20000);
        }
    })();
</script>
