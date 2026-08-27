<div class="pos-main-topbar" aria-label="Barra azioni">
    <button type="button" id="posSidebarToggle" class="pos-icon-btn pos-sidebar-toggle-btn"
        title="Apri/chiudi menu" aria-label="Apri o chiudi il menu di navigazione" aria-expanded="false"
        aria-controls="posSidebar">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect width="18" height="18" x="3" y="3" rx="2"></rect>
            <path d="M9 3v18"></path>
        </svg>
    </button>
    <div class="pos-topbar-actions">
        <div id="pos-quick-actions" class="pos-icon-group" role="group" aria-label="Azioni rapide">
            <button type="button" id="fullscreen-toggle-btn" class="pos-icon-btn" title="Schermo intero"
                aria-label="Schermo intero">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M8 3H5a2 2 0 0 0-2 2v3"></path>
                    <path d="M21 8V5a2 2 0 0 0-2-2h-3"></path>
                    <path d="M3 16v3a2 2 0 0 0 2 2h3"></path>
                    <path d="M16 21h3a2 2 0 0 0 2-2v-3"></path>
                </svg>
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
