<?php
/**
 * Cambia DB_POS_HOST in config/variabili.env dopo aver verificato che la
 * connessione funzioni davvero: non scrive mai un host che romperebbe
 * l'app al giro successivo.
 *
 * POST JSON: { "mode": "indipendente" } oppure { "mode": "client", "host": "192.168.x.x" }
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/env_writer.php';

$data = json_decode(file_get_contents('php://input'), true);
$mode = $data['mode'] ?? '';

if (!in_array($mode, ['indipendente', 'client'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Modalità non valida.']);
    exit;
}

$targetHost = $mode === 'indipendente' ? '127.0.0.1' : trim((string) ($data['host'] ?? ''));

if ($mode === 'client' && $targetHost === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Indica l\'indirizzo del server.']);
    exit;
}

$env = loadPosEnvVars();

// Verifica la connessione PRIMA di scrivere: mai salvare un host che non
// risponde, altrimenti l'app resta rotta finché non si torna qui a mano.
try {
    $conn = mysqli_init();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    $ok = @$conn->real_connect($targetHost, $env['user'], $env['pass'], $env['db']);
    if (!$ok) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => "Impossibile collegarsi a '$targetHost' con le credenziali attuali. Verifica indirizzo, rete e che l'altra installazione sia raggiungibile.",
        ]);
        exit;
    }
    $conn->close();
} catch (mysqli_sql_exception $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Connessione fallita: ' . $e->getMessage()]);
    exit;
}

if (!setEnvValue($env['env_file'], 'DB_POS_HOST', $targetHost)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Connessione riuscita ma impossibile salvare la configurazione (permessi file?).']);
    exit;
}

echo json_encode(['success' => true, 'host' => $targetHost, 'mode' => $mode]);
