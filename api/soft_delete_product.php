<?php
require_once __DIR__ . '/../config/get_db_connection.php';

header('Content-Type: application/json; charset=utf-8');

$action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
$rawIds = isset($_POST['ids']) ? $_POST['ids'] : (isset($_POST['id']) ? [$_POST['id']] : []);
$rawIds = is_array($rawIds) ? $rawIds : [$rawIds];
$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static function ($id) {
    return $id > 0;
})));

if (count($ids) === 0 || !in_array($action, ['delete', 'restore'], true)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Parametri non validi. Usa id o ids e action=delete|restore.'
    ]);
    $connectionDB->close();
    exit;
}

$newState = $action === 'delete' ? 0 : 1;
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = "UPDATE stock SET is_active = ? WHERE id IN ($placeholders)";
$stmt = $connectionDB->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Errore interno durante la preparazione query.'
    ]);
    $connectionDB->close();
    exit;
}

$types = 'i' . str_repeat('i', count($ids));
$params = array_merge([$newState], $ids);
$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Errore durante aggiornamento stato prodotto.'
    ]);
    $stmt->close();
    $connectionDB->close();
    exit;
}

if ($stmt->affected_rows === 0) {
    echo json_encode([
        'ok' => true,
        'message' => 'Nessuna modifica applicata al prodotto selezionato.'
    ]);
    $stmt->close();
    $connectionDB->close();
    exit;
}

$isMultiple = count($ids) > 1;
$message = $action === 'delete'
    ? ($isMultiple ? 'Prodotti nascosti con successo.' : 'Prodotto nascosto con successo.')
    : ($isMultiple ? 'Prodotti ripristinati con successo.' : 'Prodotto ripristinato con successo.');

echo json_encode([
    'ok' => true,
    'message' => $message
]);

$stmt->close();
$connectionDB->close();
?>
