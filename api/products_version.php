<?php
// Endpoint leggero per il polling condizionale di billing.php (vedi
// docs/PIANO-MIGRAZIONE-FRANKENPHP.md, Fase 1). Non restituisce i prodotti:
// solo un "numero di versione" (l'istante dell'ultima modifica, come unix
// timestamp) e il conteggio, cosi' il client scarica la lista completa da
// api/get_products.php solo quando uno dei due cambia davvero.
require_once __DIR__ . '/../config/get_db_connection.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$result = $connectionDB->query(
    'SELECT COALESCE(UNIX_TIMESTAMP(MAX(updated_at)), 0) AS version, COUNT(*) AS count FROM stock WHERE is_active = 1'
);

if ($result === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore nel controllo versione prodotti.']);
    $connectionDB->close();
    exit;
}

$row = $result->fetch_assoc();

echo json_encode([
    'version' => (int) $row['version'],
    'count' => (int) $row['count']
]);

$connectionDB->close();
