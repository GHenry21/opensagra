<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/conf_scontrino.css">
<!-- Importa tutte le favicon con una sola riga -->
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <title>Configurazione Scontrino</title>
    <script src="../assets/js/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme.js"></script>
</head>

<body class="management-page canvas-page sidebar-page">
    <!-- Importa la sidebar -->
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <?php const DEFAULT_RECEIPT_HEADER = 'OPENSAGRA - Scontrino di vendita'; ?>

    <div class="pos-main-panel">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <main class="management-shell">
        <div id="message"></div>
        <div class="receipt-shell">
            <section class="receipt-card">
                <div class="panel-title-row panel-title-bordered">
                    <svg class="panel-title-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"></path><path d="M16 8h-6"></path><path d="M16 12h-6"></path><path d="M13 16H10"></path></svg>
                    <h2>Configurazione Scontrino</h2>
                </div>
                <p class="inline-muted">Questa configurazione e globale per tutte le stampanti/casse: definisci testo, cut e logo.</p>
                <div class="link-row">
                    <a href="conf_stampanti.php">Configura Stampanti</a>
                    <a href="billing.php">Vai a Vendite</a>
                </div>
            </section>

            <section class="receipt-card">
                <div class="receipt-grid">
                    <div>
                        <div class="field-wrap">
                            <label for="customHeaderText">Testo header scontrino</label>
                            <textarea id="customHeaderText" maxlength="500" placeholder="<?php echo DEFAULT_RECEIPT_HEADER; ?>"></textarea>
                            <p class="inline-muted">Supporta testo multilinea. Verra stampato in testa ai ticket item (se attivi) e sul riepilogo finale.</p>
                        </div>

                        <div class="switch-row">
                            <input type="checkbox" id="cutEachItem">
                            <label for="cutEachItem">Abilita ticket singolo + scontrino finale</label>
                        </div>
                        <p class="inline-muted">Se disattivo, viene stampato solo lo scontrino riassuntivo finale.</p>
                    </div>

                    <div>
                        <div class="field-wrap">
                            <label for="logoFile">Logo scontrino finale</label>
                            <input type="file" id="logoFile" accept=".png,.jpg,.jpeg,.gif,.webp,image/*">
                        </div>

                        <div class="switch-row">
                            <input type="checkbox" id="enableLogoPrint" checked>
                            <label for="enableLogoPrint">Abilita stampa logo sullo scontrino</label>
                        </div>
                        <p class="inline-muted">Se disattivato, il logo resta caricato ma non viene stampato.</p>

                        <div class="logo-preview-wrap">
                            <img id="logoPreview" alt="Anteprima logo">
                            <span id="logoEmptyState">Nessun logo configurato</span>
                        </div>

                        <div class="actions-row" style="justify-content:flex-start; margin-top:10px;">
                            <button type="button" id="btnRemoveLogo" class="btn-secondary">Rimuovi logo</button>
                        </div>
                    </div>
                </div>

                <div class="printer-preview" id="printPreview"></div>

                <div class="actions-row">
                    <button type="button" id="btnReload" class="btn-secondary">Ricarica Configurazione</button>
                    <button type="button" id="btnSave" class="btn-add">Salva Configurazione</button>
                </div>
            </section>
        </div>
    </main>
    </div>

    <script>
        let shouldRemoveLogo = false;
        let currentLogoPath = '';

        function toPreviewLogoSrc(value) {
            const rawValue = String(value || '').trim();
            if (!rawValue) {
                return '';
            }

            if (/^(?:data:|blob:|https?:|\/)/i.test(rawValue)) {
                return rawValue;
            }

            return '../' + rawValue.replace(/^\.\//, '');
        }

        function mostraMessaggio(messaggio, tipo) {
            window.showToast(messaggio, tipo === 'error' ? 'error' : 'success', { duration: 3200 });
        }

        function refreshPreview() {
            const headerText = ($('#customHeaderText').val() || '').trim();
            const cutEnabled = $('#cutEachItem').is(':checked');
            const logoEnabled = $('#enableLogoPrint').is(':checked');
            const header = headerText || $('#customHeaderText').attr('placeholder') || ''; // cioè che viene scritto in placeholder id="customHeaderText" maxlength="500" placeholder=

            const preview = [
                '--- SIMULAZIONE STAMPA ---',
                '',
                header.replaceAll('\n', '\\n'),
                '',
                cutEnabled
                    ? 'Ticket per ogni prodotto: ATTIVO (ticket per ogni prodotto + scontrino finale)'
                    : 'Ticket per ogni prodotto: DISATTIVO (solo scontrino finale)',
                '',
                'SCONTRINO FINALE:',
                logoEnabled
                    ? (currentLogoPath ? ('  Logo: ATTIVO (' + currentLogoPath + ')') : '  Logo: ATTIVO (nessun file caricato)')
                    : '  Logo: DISATTIVATO',
                ''
            
            ];

            $('#printPreview').text(preview.join('\n'));
        }

        function setLogoPreview(src, isPath) {
            if (!src) {
                $('#logoPreview').hide().attr('src', '');
                $('#logoEmptyState').show();
                if (!isPath) {
                    currentLogoPath = '';
                }
                refreshPreview();
                return;
            }

            $('#logoPreview').attr('src', isPath ? toPreviewLogoSrc(src) : src).show();
            $('#logoEmptyState').hide();
            if (isPath) {
                currentLogoPath = src;
            }
            refreshPreview();
        }

        function saveReceiptConfigToLocalStorage(config) {
            const safeConfig = config || {};
            const receiptConfig = {
                custom_header_text: String(safeConfig.custom_header_text || ''),
                cut_each_item: Number(safeConfig.cut_each_item) !== 0 ? '1' : '0',
                enable_logo_print: Number(safeConfig.enable_logo_print) !== 0 ? '1' : '0',
                logo_path: String(safeConfig.logo_path || ''),
                timestamp: Date.now()
            };
            localStorage.setItem('receipt_config', JSON.stringify(receiptConfig));
        }

        function loadConfig() {
            $.getJSON('../api/get_receipt_config.php')
                .done(function(response) {
                    if (!response || !response.ok) {
                        mostraMessaggio((response && response.message) ? response.message : 'Errore caricamento configurazione.', 'error');
                        return;
                    }

                    const config = response.config || {};
                    $('#customHeaderText').val(config.custom_header_text || '');
                    $('#cutEachItem').prop('checked', Number(config.cut_each_item) !== 0);
                    $('#enableLogoPrint').prop('checked', Number(config.enable_logo_print) !== 0);
                    shouldRemoveLogo = false;
                    currentLogoPath = config.logo_path || '';
                    if (currentLogoPath) {
                        setLogoPreview(currentLogoPath, true);
                    } else {
                        setLogoPreview('', true);
                    }
                    refreshPreview();
                    saveReceiptConfigToLocalStorage(config);
                })
                .fail(function(xhr) {
                    let message = 'Errore caricamento configurazione scontrino.';
                    try {
                        const response = JSON.parse(xhr.responseText || '{}');
                        if (response.message) {
                            message = response.message;
                        }
                    } catch (e) {
                    }
                    mostraMessaggio(message, 'error');
                });
        }

        function saveConfig() {
            const formData = new FormData();
            formData.append('custom_header_text', $('#customHeaderText').val() || '');
            formData.append('cut_each_item', $('#cutEachItem').is(':checked') ? '1' : '0');
            formData.append('enable_logo_print', $('#enableLogoPrint').is(':checked') ? '1' : '0');
            formData.append('remove_logo', shouldRemoveLogo ? '1' : '0');

            const logoFile = $('#logoFile')[0].files[0];
            if (logoFile) {
                formData.append('logo', logoFile);
            }

            $.ajax({
                url: '../api/save_receipt_config.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function(response) {
                if (!response || !response.ok) {
                    mostraMessaggio((response && response.message) ? response.message : 'Errore salvataggio configurazione.', 'error');
                    return;
                }

                mostraMessaggio(response.message || 'Configurazione salvata.', 'success');
                shouldRemoveLogo = false;
                $('#logoFile').val('');
                currentLogoPath = response.config && response.config.logo_path ? response.config.logo_path : '';
                if (currentLogoPath) {
                    setLogoPreview(currentLogoPath, true);
                } else {
                    setLogoPreview('', true);
                }
                refreshPreview();

                saveReceiptConfigToLocalStorage({
                    custom_header_text: response.config && response.config.custom_header_text ? response.config.custom_header_text : ($('#customHeaderText').val() || ''),
                    cut_each_item: response.config && typeof response.config.cut_each_item !== 'undefined'
                        ? response.config.cut_each_item
                        : ($('#cutEachItem').is(':checked') ? 1 : 0),
                    enable_logo_print: response.config && typeof response.config.enable_logo_print !== 'undefined'
                        ? response.config.enable_logo_print
                        : ($('#enableLogoPrint').is(':checked') ? 1 : 0),
                    logo_path: currentLogoPath
                });
            }).fail(function(xhr) {
                let message = 'Errore salvataggio configurazione scontrino.';
                try {
                    const response = JSON.parse(xhr.responseText || '{}');
                    if (response.message) {
                        message = response.message;
                    }
                } catch (e) {
                }
                mostraMessaggio(message, 'error');
            });
        }

        $(document).ready(function() {
            $('#customHeaderText').on('input', refreshPreview);
            $('#cutEachItem').on('change', refreshPreview);
            $('#enableLogoPrint').on('change', refreshPreview);

            // Default esplicito UI: taglio e logo attivi fino al caricamento config.
            $('#cutEachItem').prop('checked', true);
            $('#enableLogoPrint').prop('checked', true);
            refreshPreview();

            $('#logoFile').on('change', function() {
                const file = this.files && this.files[0] ? this.files[0] : null;
                if (!file) {
                    return;
                }

                shouldRemoveLogo = false;
                const reader = new FileReader();
                reader.onload = function(event) {
                    setLogoPreview(event.target.result, false);
                };
                reader.readAsDataURL(file);
            });

            $('#btnRemoveLogo').on('click', function() {
                shouldRemoveLogo = true;
                $('#logoFile').val('');
                setLogoPreview('', false);
                refreshPreview();
            });

            $('#btnSave').on('click', saveConfig);
            $('#btnReload').on('click', loadConfig);

            loadConfig();
        });
    </script>
</body>

</html>
