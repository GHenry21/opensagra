<?php
/**
 * Lettura di config/variabili.env, condivisa da get_db_connection.php e da
 * chi ha bisogno delle credenziali senza forzare una connessione (es.
 * api/db_status.php, pages/conf_rete.php).
 */

/**
 * @return array{host:string,user:string,pass:string,db:string,env_file:string}
 */
function loadPosEnvVars(): array
{
    $envFile = __DIR__ . '/variabili.env';
    $vars = [];

    if (is_file($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if (($hashPos = strpos($value, '#')) !== false) {
                $value = substr($value, 0, $hashPos);
            }

            $value = trim($value, "\"' ");
            $vars[$key] = $value;
        }
    }

    return [
        'host' => $vars['DB_POS_HOST'] ?? '127.0.0.1',
        'user' => $vars['DB_POS_USER'] ?? 'nuovo_utente_pos',
        'pass' => $vars['DB_POS_PASS'] ?? 'PasswordSicura2026!',
        'db' => 'opensagra_pos', // Nome del database, non ancora parametrizzato (vedi piano Fase 3)
        'env_file' => $envFile,
        // Segreto condiviso con l'hub Mercure (Caddyfile, direttive
        // publisher_jwt/subscriber_jwt) per firmare i JWT lato PHP - vedi
        // config/mercure.php. Vuoto se l'hub non e' configurato su questa
        // installazione (Fase 4, opzionale).
        'mercure_jwt_secret' => $vars['MERCURE_JWT_SECRET'] ?? '',
    ];
}
