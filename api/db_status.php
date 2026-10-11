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

// Versione del codice di QUESTA macchina (config/.installed_version, vedi
// includes/sidebar.php). Su un client la si confronta con quella che il
// wrapper del server pubblica in app_config.SERVER_APP_VERSION (wrapper/nodes.go,
// piano Fase 6c punto A): la sidebar avvisa se sono diverse.
$versionFile = __DIR__ . '/../config/.installed_version';
$appVersion = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';

$start = microtime(true);
$result = [
    'host' => $env['host'],
    // Per "Indipendente" (127.0.0.1) mostriamo l'IP di rete reale: il
    // loopback non dice nulla di utile a chi deve collegare un'altra
    // cassa a questa macchina come server.
    'display_host' => $isSelf ? (detectLocalLanIp() ?? $env['host']) : $env['host'],
    // Nome host di questo PC, solo informativo (vedi local_ip.php).
    'hostname' => $isSelf ? detectLocalHostname() : null,
    'is_self' => $isSelf,
    'db' => $env['db'],
    'online' => false,
    'app_version' => $appVersion,
    // Solo su un client collegato: '' se ignota (server con wrapper precedente).
    'server_app_version' => '',
];

try {
    $conn = mysqli_init();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    $ok = @$conn->real_connect($env['host'], $env['user'], $env['pass'], $env['db']);
    if ($ok) {
        $result['online'] = true;
        if (!$isSelf) {
            // SELECT diretta, non getAppConfig(): quella fa un CREATE TABLE IF
            // NOT EXISTS a ogni chiamata, e il heartbeat di billing.php passa
            // da qui ogni ~5s. Tabella assente (server vecchio) -> resta ''.
            try {
                $res = $conn->query("SELECT valore FROM app_config WHERE chiave = 'SERVER_APP_VERSION' LIMIT 1");
                if ($res && ($row = $res->fetch_assoc())) {
                    $result['server_app_version'] = trim((string) $row['valore']);
                }
            } catch (mysqli_sql_exception $e) {
                // ignorato: e' solo un avviso informativo
            }
        }
        $conn->close();
    } else {
        $result['error'] = 'Connessione non riuscita.';
    }
} catch (mysqli_sql_exception $e) {
    $result['error'] = $e->getMessage();
}

$result['latency_ms'] = (int) round((microtime(true) - $start) * 1000);

echo json_encode($result);
