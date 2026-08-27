<?php
/**
 * Registry centralizzato delle icone UI (stile Lucide, griglia 24x24, stroke).
 *
 * Ogni voce contiene SOLO il contenuto interno dell'<svg>; il tag <svg> con gli
 * attributi comuni viene costruito da pos_icon(). Nessuna dipendenza esterna,
 * nessun file da scaricare: l'SVG finisce inline nell'HTML come prima.
 *
 * Uso:   <?= pos_icon('printer') ?>
 *        <?= pos_icon('house', ['class' => 'pos-sidebar__icon']) ?>
 *        <?= pos_icon('x', ['stroke-width' => '2.5', 'width' => '16', 'height' => '16']) ?>
 *
 * Per aggiungere un'icona: copiare il markup da https://lucide.dev ("Copy SVG"),
 * tenere solo il contenuto interno (path/rect/circle...) e aggiungere una voce
 * all'array qui sotto. Icone custom/Flaticon: stessa cosa, ma sistemare prima
 * fill/viewBox a mano perche' non seguono lo stile stroke di Lucide.
 */

if (!function_exists('pos_icon')) {

    /** @var array<string,string> contenuto interno degli <svg>, per nome icona */
    $GLOBALS['POS_ICONS'] = [
        // --- navigazione / pagine -------------------------------------------
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

        // --- azioni / strumenti -------------------------------------------
        'database-table' =>
            '<ellipse cx="12" cy="7" rx="9" ry="3"></ellipse><path d="M3 7v10c0 1.66 4 3 9 3s9-1.34 9-3V7"></path><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"></path><rect x="7" y="10" width="10" height="10" rx="1" fill="white" stroke-width="0"></rect><path d="M10 17v-3"></path><path d="M14 17v-6"></path><path d="M7 10h10v10H7z" fill="none" stroke="currentColor" stroke-width="2"></path>',
        'list-ordered' =>
            '<path d="M11 5h10"></path><path d="M11 12h10"></path><path d="M11 19h10"></path><path d="M4 4h1v5"></path><path d="M4 9h2"></path><path d="M6.5 20H3.4c0-1 2.6-1.925 2.6-3.5a1.5 1.5 0 0 0-2.6-1.02"></path>',

        // --- topbar / tema ----------------------------------------------
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

        // --- generiche (usate nelle altre pagine) ----------------------
        'x' =>
            '<path d="M18 6 6 18"></path><path d="m6 6 12 12"></path>',
        'plus' =>
            '<path d="M5 12h14"></path><path d="M12 5v14"></path>',
        'pencil' =>
            '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"></path><path d="m15 5 4 4"></path>',
        'search' =>
            '<circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path>',
        'upload' =>
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="m7 10 5-5 5 5"></path><path d="M12 15V5"></path>',
        'image' =>
            '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect><circle cx="9" cy="9" r="2"></circle><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path>',
        'rotate-ccw' =>
            '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path>',
        'refresh-cw' =>
            '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path><path d="M8 16H3v5"></path>',
        'chevron-left' =>
            '<path d="m15 18-6-6 6-6"></path>',
        'chevron-down' =>
            '<path d="m6 9 6 6 6-6"></path>',
        'trash-2' =>
            '<path d="M3 6h18"></path><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" x2="10" y1="11" y2="17"></line><line x1="14" x2="14" y1="11" y2="17"></line>',
    ];

    /**
     * Ritorna il markup <svg> completo per l'icona $name.
     *
     * @param string               $name  chiave del registry
     * @param array<string,mixed>  $attrs attributi extra o override sul tag <svg>.
     *                                    'class' viene accodata a quella di default;
     *                                    un valore null/false rimuove l'attributo.
     */
    function pos_icon(string $name, array $attrs = []): string
    {
        $icons = $GLOBALS['POS_ICONS'] ?? [];

        if (!isset($icons[$name])) {
            error_log("pos_icon: icona '{$name}' non trovata nel registry (includes/icons.php)");
            return '';
        }

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

        $merged = array_merge($defaults, $attrs);
        if ($class !== '') {
            $merged['class'] = $class;
        }

        $attrString = '';
        foreach ($merged as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $attrString .= ' ' . $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<svg' . $attrString . '>' . $icons[$name] . '</svg>';
    }
}
