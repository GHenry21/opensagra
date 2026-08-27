<?php require_once __DIR__ . '/icons.php'; ?>
<div class="pos-main-topbar" aria-label="Barra azioni">
    <button type="button" id="posSidebarToggle" class="pos-icon-btn pos-sidebar-toggle-btn"
        title="Apri/chiudi menu" aria-label="Apri o chiudi il menu di navigazione" aria-expanded="false"
        aria-controls="posSidebar">
        <?= pos_icon('panel-left') ?>
    </button>
    <div class="pos-topbar-actions">
        <div id="pos-quick-actions" class="pos-icon-group" role="group" aria-label="Azioni rapide">
            <button type="button" id="fullscreen-toggle-btn" class="pos-icon-btn" title="Schermo intero"
                aria-label="Schermo intero">
                <?= pos_icon('maximize') ?>
            </button>
        </div>
        <button id="theme-toggle-btn" class="visually-hidden" type="button" aria-hidden="true"
            tabindex="-1">Tema</button>
    </div>
</div>
<script>
    (function() {
        function updateCassaBadge() {
            var badge = document.getElementById('pos-cassa-badge');
            if (!badge) {
                return;
            }

            var cassaId = '';
            try {
                cassaId = localStorage.getItem('cassa_id') || '';
            } catch (err) {
                cassaId = '';
            }

            badge.textContent = 'Cassa ID: ' + (cassaId || 'N/D');
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', updateCassaBadge, { once: true });
        } else {
            updateCassaBadge();
        }

        window.addEventListener('storage', function(event) {
            if (!event || event.key === 'cassa_id') {
                updateCassaBadge();
            }
        });

        var fullscreenBtn = document.getElementById('fullscreen-toggle-btn');
        if (fullscreenBtn) {
            fullscreenBtn.addEventListener('click', async function() {
                try {
                    if (!document.fullscreenElement) {
                        await document.documentElement.requestFullscreen();
                    } else {
                        await document.exitFullscreen();
                    }
                } catch (err) {
                    console.error('Errore fullscreen:', err);
                }
            });
        }
    })();
</script>
