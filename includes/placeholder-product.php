<?php
// includes/template-product.php

if (!function_exists('buildPlaceholderSvg')) {
    function buildPlaceholderSvg($productName)
    {
        $trimmed = trim((string) $productName);
        $source = $trimmed !== '' ? $trimmed : '?';
        $firstChar = function_exists('mb_substr') ? mb_substr($source, 0, 1, 'UTF-8') : substr($source, 0, 1);
        $initial = strtoupper($firstChar);
        $safeInitial = htmlspecialchars($initial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" class="card-placeholder">'
            . '<style>.bg{fill:#f4f4f6}.fg{fill:#1b2028}@media (prefers-color-scheme: dark){.bg{fill:#21252f}.fg{fill:#f4f4f6}}</style>'
            . '<rect class="bg" width="100" height="100" rx="0"/>'
            . '<text class="fg" x="50" y="60" font-family="Segoe UI, sans-serif" font-weight="800" font-size="36" text-anchor="middle">'
            . $safeInitial
            . '</text>'
            . '</svg>';
    }
}

if (!function_exists('createPlaceholderFile')) {
    function createPlaceholderFile($productName)
    {
        $placeholderDir = __DIR__ . '/../uploads/placeholders/';
        if (!is_dir($placeholderDir)) {
            mkdir($placeholderDir, 0777, true);
        }

        $filename = 'ph_' . time() . '_' . bin2hex(random_bytes(4)) . '.svg';
        $target = $placeholderDir . $filename;
        $svg = buildPlaceholderSvg($productName);

        if (file_put_contents($target, $svg) === false) {
            return '';
        }

        return 'uploads/placeholders/' . $filename;
    }
}
