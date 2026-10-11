<?php
/**
 * Una cassa sta passando a Client con l'indirizzo di QUESTO PC (piano, Fase
 * 6c, regola dell'utente 2026-10-07): questo PC diventa cassa centrale e apre
 * l'accesso dalla rete al proprio database. Lo chiama
 * api/set_network_config.php della cassa, prima di collegarsi.
 *
 * Il database di un'indipendente lo usa solo lei; si apre solo quando una
 * cassa la sceglie come centrale. Da li' in poi resta aperto mentre OpenSagra
 * gira (anche dopo un riavvio: app_config.IS_CENTRAL, letto dal wrapper in
 * wrapper/remote_access.go), chiuso quando OpenSagra si chiude. Smette di
 * essere centrale quando passa a sua volta a Client.
 *
 * Nessun consenso richiesto (decisione utente).
 *
 * POST -> { success } | 409 se questo PC e' a sua volta una cassa client.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/remote_access.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST']);
    exit;
}

$env = loadPosEnvVars();
if (!in_array($env['host'], ['127.0.0.1', 'localhost'], true)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => 'Questo PC è a sua volta collegato a un\'altra cassa centrale: non può fare da centrale.',
    ]);
    exit;
}

$local = connectLocalDb();
if ($local === null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Database locale non raggiungibile.']);
    exit;
}
setAppConfig($local, 'IS_CENTRAL', '1');
$locked = callRemoteAccess($local, 'unlock');
$local->close();

echo json_encode(['success' => $locked === false]);
