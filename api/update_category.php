<?php
require_once __DIR__ . '/../config/get_db_connection.php';

header('Content-Type: application/json; charset=utf-8');

$oldCategory = isset($_POST['old_category']) ? trim($_POST['old_category']) : '';
$newCategory = isset($_POST['new_category']) ? trim($_POST['new_category']) : '';

if ($oldCategory === '' || $newCategory === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Categoria attuale e nuovo nome sono obbligatori.'
    ]);
    $connectionDB->close();
    exit;
}

if ($oldCategory === $newCategory) {
    echo json_encode([
        'ok' => true,
        'message' => 'Il nome categoria e\' gia uguale al valore corrente.'
    ]);
    $connectionDB->close();
    exit;
}

$sql = "UPDATE stock SET category = ? WHERE category = ?";
$stmt = $connectionDB->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Errore interno durante la preparazione query categoria.'
    ]);
    $connectionDB->close();
    exit;
}

$stmt->bind_param('ss', $newCategory, $oldCategory);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Errore durante aggiornamento categoria.'
    ]);
    $stmt->close();
    $connectionDB->close();
    exit;
}

if ($stmt->affected_rows === 0) {
    echo json_encode([
        'ok' => true,
        'message' => 'Nessun prodotto aggiornato: verifica la categoria selezionata.'
    ]);
    $stmt->close();
    $connectionDB->close();
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Categoria aggiornata con successo su ' . $stmt->affected_rows . ' prodotti.'
]);

$stmt->close();
$connectionDB->close();
?>