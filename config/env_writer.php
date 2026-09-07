<?php
/**
 * Scrittura mirata di una singola chiave in config/variabili.env, preservando
 * tutte le altre righe (commenti inclusi) cosi' com'erano.
 *
 * Usato dalla pagina "Rete" (pages/conf_rete.php) per cambiare DB_POS_HOST al
 * volo: get_db_connection.php rilegge il file ad ogni richiesta senza cache,
 * quindi il cambio ha effetto immediato, senza riavviare nulla.
 */

/**
 * @return bool true se scritto con successo
 */
function setEnvValue(string $envFile, string $key, string $value): bool
{
    $lines = [];
    if (is_file($envFile)) {
        $raw = file($envFile, FILE_IGNORE_NEW_LINES);
        if ($raw !== false) {
            $lines = $raw;
        }
    }

    $found = false;
    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $pos = strpos($trimmed, '=');
        if ($pos === false) {
            continue;
        }
        $lineKey = trim(substr($trimmed, 0, $pos));
        if ($lineKey === $key) {
            $lines[$i] = "$key=$value";
            $found = true;
            break;
        }
    }

    if (!$found) {
        $lines[] = "$key=$value";
    }

    $content = implode("\n", $lines) . "\n";
    return file_put_contents($envFile, $content, LOCK_EX) !== false;
}
