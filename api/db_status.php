<?php
/**
 * Ping leggero verso il database attualmente configurato (DB_POS_HOST in
 * variabili.env). Usato dalla pillola di stato in sidebar e dalla pagina
 * Rete per mostrare a colpo d'occhio se il server è raggiungibile.
 *
 * Timeout breve apposta: non deve mai far percepire l'app come "lenta"
 * quando il server è irraggiungibile.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/local_ip.php';

$env = loadPosEnvVars();
$isSelf = in_array($env['host'], ['127.0.0.1', 'localhost'], true);

$start = microtime(true);
$result = [
    'host' => $env['host'],
    // Per "Indipendente" (127.0.0.1) mostriamo l'IP di rete reale: il
    // loopback non dice nulla di utile a chi deve collegare un'altra
    // cassa a questa macchina come server.
    'display_host' => $isSelf ? (detectLocalLanIp() ?? $env['host']) : $env['host'],
    'is_self' => $isSelf,
    'db' => $env['db'],
    'online' => false,
];

try {
    $conn = mysqli_init();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    $ok = @$conn->real_connect($env['host'], $env['user'], $env['pass'], $env['db']);
    if ($ok) {
        $result['online'] = true;
        $conn->close();
    } else {
        $result['error'] = 'Connessione non riuscita.';
    }
} catch (mysqli_sql_exception $e) {
    $result['error'] = $e->getMessage();
}

$result['latency_ms'] = (int) round((microtime(true) - $start) * 1000);

echo json_encode($result);
