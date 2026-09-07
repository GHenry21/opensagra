<?php
/**
 * Conta le connessioni ATTIVE E ESTERNE al database MariaDB LOCALE di questo
 * PC (sempre 127.0.0.1, indipendentemente da come questa installazione è
 * configurata in questo momento) — cioè: altre postazioni che stanno usando
 * *questo* PC come server condiviso, adesso.
 *
 * Usato da pages/conf_rete.php per rafforzare il dialogo di conferma con un
 * dato reale invece di un avviso generico sempre uguale.
 *
 * Richiede il privilegio PROCESS sull'utente applicativo (concesso da
 * config/crea_dbtable_and_user.php): senza, SHOW PROCESSLIST mostra solo la
 * propria connessione e questo endpoint riporterebbe sempre 0 — non è un
 * errore bloccante, l'avviso semplicemente non compare.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';

$env = loadPosEnvVars();
$result = ['external_count' => 0, 'hosts' => []];

try {
    $conn = mysqli_init();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    // Sempre il DB locale: e' il proprio ruolo di server ad essere in
    // questione, non quello a cui questa installazione punta ora.
    $ok = @$conn->real_connect('127.0.0.1', $env['user'], $env['pass'], $env['db']);
    if ($ok) {
        $res = $conn->query('SHOW PROCESSLIST');
        if ($res) {
            $hosts = [];
            while ($row = $res->fetch_assoc()) {
                $host = (string) ($row['Host'] ?? '');
                $isLocal = $host === '' ||
                    str_starts_with($host, '127.0.0.1') ||
                    str_starts_with($host, 'localhost') ||
                    str_starts_with($host, '::1');
                if (!$isLocal) {
                    // "192.168.1.10:54321" -> teniamo solo l'indirizzo
                    $hosts[] = explode(':', $host)[0];
                }
            }
            $result['hosts'] = array_values(array_unique($hosts));
            $result['external_count'] = count($result['hosts']);
        }
        $conn->close();
    }
} catch (mysqli_sql_exception $e) {
    // Nessun privilegio PROCESS, o connessione locale non disponibile:
    // riportiamo 0 invece di un errore, il dialogo resta comunque utilizzabile.
}

echo json_encode($result);
