<?php
/**
 * Lettura di config/variabili.env, condivisa da get_db_connection.php e da
 * chi ha bisogno delle credenziali senza forzare una connessione (es.
 * api/db_status.php, pages/conf_rete.php).
 */

/**
 * @return array{host:string,user:string,pass:string,db:string,env_file:string,mercure_jwt_secret:string,mercure_jwt_secret_remote:string,print_bridge_casse:string}
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
        // Segreto dell'hub Mercure del SERVER, in modalita' rete. Lo scrive
        // api/set_network_config.php leggendolo dal DB del server (tabella
        // app_config) al passaggio a client - non si tocca a mano. Vuoto su
        // un'installazione indipendente / server. Serve per firmare i JWT
        // diretti all'hub del server (relay, bridge, checkout) mentre l'hub
        // LOCALE del client resta sul suo MERCURE_JWT_SECRET. Vedi
        // config/mercure.php::mercureSecretForHub().
        'mercure_jwt_secret_remote' => $vars['MERCURE_JWT_SECRET_REMOTE'] ?? '',
        // PC-ponte (modalita' client): id topic di stampa serviti da questo PC,
        // lista separata da virgole. Letto da bin/opensagra-print-bridge.php
        // (--cassa= lo sovrascrive) e da api/stampanti.php per riportare il
        // bridge_id nella discovery.
        'print_bridge_casse' => $vars['PRINT_BRIDGE_CASSE'] ?? '',
    ];
}
