<?php
/**
 * "Accetta casse client" (Configurazione Rete, piano Fase 6c): apre o chiude
 * l'accesso dalla rete al database di QUESTO PC. Vedi config/remote_access.php.
 *
 * GET  -> { available, accepting, is_client }
 * POST JSON { "accept": true|false } -> stesso formato, con lo stato reale.
 *
 * Aprire e' permesso solo in modalita' indipendente: un PC client lavora sul
 * DB di un altro, non fa da server (set_network_config.php lo richiude al
 * passaggio a client).
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/remote_access.php';

$env = loadPosEnvVars();
$isClient = !in_array($env['host'], ['127.0.0.1', 'localhost'], true);

$local = connectLocalDb();
if ($local === null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'available' => false, 'error' => 'Database locale non raggiungibile.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $accept = (bool) ($data['accept'] ?? false);
    if ($accept && $isClient) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Questo PC è collegato a un server come client: per fare da server torna prima a "Indipendente".',
        ]);
        exit;
    }
    $locked = setAcceptClients($local, $accept);
} else {
    $locked = callRemoteAccess($local, 'status');
}
$local->close();

if ($locked === null) {
    // Installazione precedente a questa funzionalita' (procedura assente).
    echo json_encode([
        'success' => false,
        'available' => false,
        'is_client' => $isClient,
        'error' => 'Funzione non disponibile su questa installazione: reinstalla OpenSagra per attivarla.',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'available' => true,
    'accepting' => !$locked,
    'is_client' => $isClient,
]);
