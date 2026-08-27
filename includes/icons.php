<?php
/**
 * Registry centralizzato delle icone UI (in prevalenza stile Lucide, griglia 24x24, stroke).
 *
 * Ogni voce e' o una stringa (contenuto interno dell'<svg>, attributi standard) oppure
 * ['inner' => '...', 'attrs' => [...]] per icone con viewBox/fill non standard.
 * Il tag <svg> viene costruito da pos_icon(): defaults <- attrs della voce <- attrs della chiamata.
 * Nessuna dipendenza esterna, nessun file da scaricare: l'SVG finisce inline nell'HTML.
 *
 * Uso:   <?= pos_icon('printer') ?>
 *        <?= pos_icon('x', ['class' => 'modal-close__icon', 'stroke-width' => '2.5']) ?>
 *
 * Aggiungere un'icona: copiare il markup da https://lucide.dev ("Copy SVG"), tenere solo il
 * contenuto interno e aggiungere una voce. Icone custom/Flaticon: sistemare prima fill/viewBox.
 */

if (!function_exists('pos_icon')) {

    /** @var array<string,string|array{inner:string,attrs:array<string,string>}> */
    $GLOBALS['POS_ICONS'] = [

        // === navigazione / pagine (sidebar + home) ========================
        'house' =>
            '<path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.7V21h14V9.7"></path><path d="M9 21v-6h6v6"></path>',
        'layout-grid' =>
            '<rect width="7" height="7" x="3" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="14" rx="1"></rect><rect width="7" height="7" x="3" y="14" rx="1"></rect>',
        'storno' =>
            '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1z"></path><path d="M8 6h8"></path><path d="M8 10h5"></path><circle cx="17" cy="16" r="4"></circle><path d="m15.5 14.5 3 3"></path><path d="m18.5 14.5-3 3"></path>',
        'chart-column' =>
            '<path d="M3 3v16a2 2 0 0 0 2 2h16"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path>',
        'clipboard-list' =>
            '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"></rect><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><path d="M12 11h4"></path><path d="M12 16h4"></path><path d="M8 11h.01"></path><path d="M8 16h.01"></path>',
        'receipt' =>
            '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2"></path><path d="M8 7h8"></path><path d="M8 11h8"></path><path d="M8 15h5"></path>',
        'printer' =>
            '<path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"></path><rect x="6" y="14" width="12" height="8" rx="1"></rect>',

        // === strumenti sidebar ==========================================
        'database-table' =>
            '<ellipse cx="12" cy="7" rx="9" ry="3"></ellipse><path d="M3 7v10c0 1.66 4 3 9 3s9-1.34 9-3V7"></path><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"></path><rect x="7" y="10" width="10" height="10" rx="1" fill="white" stroke-width="0"></rect><path d="M10 17v-3"></path><path d="M14 17v-6"></path><path d="M7 10h10v10H7z" fill="none" stroke="currentColor" stroke-width="2"></path>',
        'list-ordered' =>
            '<path d="M11 5h10"></path><path d="M11 12h10"></path><path d="M11 19h10"></path><path d="M4 4h1v5"></path><path d="M4 9h2"></path><path d="M6.5 20H3.4c0-1 2.6-1.925 2.6-3.5a1.5 1.5 0 0 0-2.6-1.02"></path>',

        // === topbar / tema ==============================================
        'panel-left' =>
            '<rect width="18" height="18" x="3" y="3" rx="2"></rect><path d="M9 3v18"></path>',
        'maximize' =>
            '<path d="M8 3H5a2 2 0 0 0-2 2v3"></path><path d="M21 8V5a2 2 0 0 0-2-2h-3"></path><path d="M3 16v3a2 2 0 0 0 2 2h3"></path><path d="M16 21h3a2 2 0 0 0 2-2v-3"></path>',
        'minimize' =>
            '<path d="M8 3v3a2 2 0 0 1-2 2H3"></path><path d="M21 8h-3a2 2 0 0 1-2-2V3"></path><path d="M3 16h3a2 2 0 0 1 2 2v3"></path><path d="M16 21v-3a2 2 0 0 1 2-2h3"></path>',
        'moon' =>
            '<path d="M12 3a7 7 0 1 0 9 9 9 9 0 1 1-9-9z"></path>',
        'sun' =>
            '<circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="m4.93 4.93 1.41 1.41"></path><path d="m17.66 17.66 1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="m6.34 17.66-1.41 1.41"></path><path d="m19.07 4.93-1.41 1.41"></path>',

        // === azioni generiche ==========================================
        'x' =>
            '<path d="M18 6 6 18"></path><path d="m6 6 12 12"></path>',
        'plus' =>
            '<path d="M5 12h14"></path><path d="M12 5v14"></path>',
        'pencil' =>
            '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"></path><path d="m15 5 4 4"></path>',
        'pencil-line' =>
            '<path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>',
        'search' =>
            '<circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path>',
        'upload' =>
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="m7 10 5-5 5 5"></path><path d="M12 15V5"></path>',
        'image' =>
            '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect><circle cx="9" cy="9" r="2"></circle><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path>',
        'chevron-left' =>
            '<path d="m15 18-6-6 6-6"></path>',
        'chevron-down' =>
            '<path d="m6 9 6 6 6-6"></path>',
        'trash-2' =>
            '<path d="M3 6h18"></path><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" x2="10" y1="11" y2="17"></line><line x1="14" x2="14" y1="11" y2="17"></line>',
        'trash' =>
            '<path d="M3 6h18"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path>',
        'trash-simple' =>
            '<path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="m19 6-1 14H6L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path>',
        'refresh-cw' =>
            '<path d="M21 2v6h-6"></path><path d="M3 12a9 9 0 0 1 14.5-7.5L21 8"></path><path d="M3 22v-6h6"></path><path d="M21 12a9 9 0 0 1-14.5 7.5L3 16"></path>',
        'refresh-ccw' =>
            '<path d="M21 12a9 9 0 0 1-15.1 6.6L3 16"></path><path d="M3 21v-5h5"></path><path d="M3 12A9 9 0 0 1 18.1 5.4L21 8"></path><path d="M21 3v5h-5"></path>',

        // === pagamenti / scontrino (billing, conf_*) ====================
        'percent' =>
            '<line x1="19" x2="5" y1="5" y2="19"></line><circle cx="6.5" cy="6.5" r="2.5"></circle><circle cx="17.5" cy="17.5" r="2.5"></circle>',
        'cash' =>
            '<rect width="20" height="12" x="2" y="6" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path>',
        'banknote' =>
            '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M7 9h10M7 13h4"></path><circle cx="17" cy="14" r="1"></circle>',
        'credit-card' =>
            '<rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line><line x1="6" y1="15" x2="10" y2="15"></line>',
        'print' =>
            '<polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect>',
        'printer-test' =>
            '<path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path>',
        'print-receipt' => [
            'inner' => '<path d="M6 9V4h12v5"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M18 14H6v7l3-1.5 3 1.5 3-1.5 3 1.5z"></path><line x1="9" y1="17" x2="15" y2="17" stroke-width="1.5"></line><circle cx="18" cy="11" r="0.5" fill="currentColor"></circle>',
        ],
        'receipt-lines' =>
            '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"></path><path d="M16 8h-6"></path><path d="M16 12h-6"></path><path d="M13 16H10"></path>',

        // === statistiche ===============================================
        'trending-up' =>
            '<path d="M3 17l6-6 4 4 8-8"></path><path d="M15 7h6v6"></path>',
        'target' =>
            '<circle cx="12" cy="12" r="9"></circle><circle cx="12" cy="12" r="4"></circle>',
        'filter' =>
            '<path d="M4 5h16l-6 7v5l-4 2v-7z"></path>',
        'card-line' =>
            '<rect x="3" y="6" width="18" height="13" rx="2"></rect><path d="M3 10h18"></path><circle cx="16" cy="14.5" r="1.5" fill="currentColor" stroke="none"></circle>',
        'stat-bars' => [
            'inner' => '<rect x="2.64" y="19.08" width="5.3" height="9.84" rx=".7" ry=".7" stroke-miterlimit="10"></rect><rect x="17.08" y="13.52" width="5.3" height="15.52" rx=".7" ry=".7" stroke-miterlimit="10"></rect><rect x="24.17" y="1.19" width="5.3" height="27.84" rx=".7" ry=".7" stroke-miterlimit="10"></rect><rect x="9.86" y="7.27" width="5.3" height="21.7" rx=".7" ry=".7" stroke-miterlimit="10"></rect><line x1="1.13" y1="31.04" x2="30.87" y2="31.04" stroke-linecap="round" stroke-linejoin="round"></line>',
            'attrs' => ['viewBox' => '0 0 32 32'],
        ],

        // === add_product: icona titolo "Inserisci" (piena) =============
        'add-box' => [
            'inner' => '<path d="M22,13v7a1,1,0,0,1-1,1H3a1,1,0,0,1-1-1V13a1,1,0,0,1,2,0v6H20V13a1,1,0,0,1,2,0ZM12,3a1,1,0,0,0-1,1V8H7a1,1,0,0,0,0,2h4v4a1,1,0,0,0,2,0V10h4a1,1,0,0,0,0-2H13V4A1,1,0,0,0,12,3Z"></path>',
            'attrs' => ['fill' => 'currentColor', 'stroke' => 'none'],
        ],

        // === storni: illustrazioni di sezione (viewBox custom) =========
        'storno-doc' => [
            'inner' => '<path fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M9.23,13.63l3.23,3.33,7.28-7.49M27.02,31.1V9.14c0-2.8,0-4.19-.53-5.26-.47-.94-1.21-1.7-2.12-2.18-1.04-.54-2.4-.54-5.11-.54h-10.35c-2.72,0-4.07,0-5.11.54-.91.48-1.65,1.24-2.12,2.18-.53,1.07-.53,2.47-.53,5.26v21.96l4.45-3.33,4.04,3.33,4.45-3.33,4.45,3.33,4.04-3.33,4.45,3.33Z"></path>',
            'attrs' => ['viewBox' => '0 0 28.17 32.25', 'fill' => 'none', 'stroke' => 'currentColor'],
        ],
        'badge-check-soft' => [
            'inner' => '<circle opacity=".4" cx="204.8" cy="204.8" r="204.8"></circle><path d="M282.6,136.35c15.81-3.31,26.88,13.37,17.11,26.45l-106.24,106.39c-7.48,5.75-14.38,6.04-22.14.43-17.11-20.7-44.71-39.82-60.67-60.53-13.51-17.4,6.61-36.09,22.14-24.15l49.6,48.74,94.02-93.88c1.58-1.29,3.88-2.88,5.89-3.31l.29-.14Z"></path>',
            'attrs' => ['viewBox' => '0 0 409.6 409.6', 'fill' => 'currentColor', 'stroke' => 'none'],
        ],
        'badge-list-soft' => [
            'inner' => '<circle opacity=".4" cx="204.8" cy="204.8" r="204.8"></circle><path d="M362,202.18v6.13c-2.5,9.3-8.83,15.65-18.7,16.53l-216.88.03c-24.91-1.96-25.52-35.93-1.23-39.26l219.35.02c9.21,1.41,15.15,7.72,17.46,16.55Z"></path><path d="M112.87,130.9c-11.82-11.82-3.95-31.86,12.35-33.47h199.09c23.89,3.21,23.9,35.98,0,39.2l-197.9.03c-4.88-.38-10.08-2.28-13.55-5.75Z"></path><path d="M268.05,307.34c-3.74,3.74-8.95,5.35-14.16,5.75l-127.44-.03c-24.42-2.04-25.75-34.44-2.41-39.15l132.27-.04c16.15,1.74,23.38,21.83,11.74,33.47Z"></path><path d="M65.96,97.59c25.91-2.49,29.75,36.64,3.5,39.03s-28.49-36.62-3.5-39.03Z"></path><path d="M65.33,185.8c16.83-2.21,28.77,17,18.8,31.05-12.54,17.67-41.12,4.02-34.77-17.62,1.96-6.66,9.07-12.52,15.97-13.43Z"></path><path d="M54.06,279.59c16.48-16.48,43.74,5.36,30.31,25.11-15.76,23.18-50.04-5.39-30.31-25.11Z"></path>',
            'attrs' => ['viewBox' => '0 0 409.6 409.6', 'fill' => 'currentColor', 'stroke' => 'none'],
        ],
        'receipt-check' => [
            'inner' => '<path d="M9.72,2.22h12.57V.9C22.28.46,22.97.01,23.39,0c.45-.01,1.18.43,1.18.9v1.32c1.02,0,2-.04,3,.18,2.44.54,4.2,2.67,4.43,5.14v19.07c-.19,2.9-2.48,5.2-5.39,5.39H5.25C2.45,31.73.23,29.49,0,26.68V7.61C.2,4.93,2.18,2.73,4.84,2.31c.86-.14,1.72-.08,2.59-.09V.9C7.43.46,8.11.01,8.54,0c.45-.01,1.18.43,1.18.9v1.32ZM7.43,4.5c-1.81-.08-3.67-.02-4.67,1.72-.16.28-.47,1.02-.47,1.32v1.61h27.42v-1.61c0-.3-.31-1.04-.47-1.32-1-1.74-2.86-1.8-4.67-1.72v1.39c0,.06-.3.53-.39.61-.41.35-1.09.35-1.5,0-.09-.07-.39-.55-.39-.61v-1.39h-12.57v1.39c0,.06-.3.53-.39.61-.41.35-1.09.35-1.5,0-.09-.07-.39-.55-.39-.61v-1.39ZM29.71,11.43H2.29v15.32c0,1.34,1.82,2.99,3.18,2.96,7.2-.06,14.43.12,21.62-.09,1.2-.2,2.63-1.72,2.63-2.94v-15.25Z"></path><path d="M21.4,15.8c1.1-.23,1.87.93,1.19,1.84l-7.39,7.4c-.52.4-1,.42-1.54.03-1.19-1.44-3.11-2.77-4.22-4.21-.94-1.21.46-2.51,1.54-1.68l3.45,3.39,6.54-6.53c.11-.09.27-.2.41-.23Z"></path>',
            'attrs' => ['viewBox' => '0 0 32 32', 'fill' => 'currentColor', 'stroke' => 'none'],
        ],
    ];

    /**
     * Ritorna il markup <svg> completo per l'icona $name.
     *
     * @param string              $name  chiave del registry
     * @param array<string,mixed> $attrs attributi extra/override sul tag <svg>.
     *                                   'class' viene accodata; null/false rimuove l'attributo.
     */
    function pos_icon(string $name, array $attrs = []): string
    {
        $icons = $GLOBALS['POS_ICONS'] ?? [];

        if (!isset($icons[$name])) {
            error_log("pos_icon: icona '{$name}' non trovata nel registry (includes/icons.php)");
            return '';
        }

        $entry = $icons[$name];
        $inner = is_array($entry) ? ($entry['inner'] ?? '') : $entry;
        $entryAttrs = is_array($entry) ? ($entry['attrs'] ?? []) : [];

        $defaults = [
            'xmlns'           => 'http://www.w3.org/2000/svg',
            'viewBox'         => '0 0 24 24',
            'width'           => '24',
            'height'          => '24',
            'fill'            => 'none',
            'stroke'          => 'currentColor',
            'stroke-width'    => '2',
            'stroke-linecap'  => 'round',
            'stroke-linejoin' => 'round',
            'aria-hidden'     => 'true',
        ];

        $class = trim((string) ($attrs['class'] ?? ''));
        unset($attrs['class']);

        $merged = array_merge($defaults, $entryAttrs, $attrs);
        if ($class !== '') {
            $merged['class'] = $class;
        }

        $attrString = '';
        foreach ($merged as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {          // attributo senza valore (es. 'v-else' => true)
                $attrString .= ' ' . $key;
                continue;
            }
            $attrString .= ' ' . $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<svg' . $attrString . '>' . $inner . '</svg>';
    }
}
