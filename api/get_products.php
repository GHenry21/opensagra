<?php
require_once __DIR__ . '/../config/get_db_connection.php';

header('Content-Type: application/json; charset=utf-8');

$connectionDB->query('ALTER TABLE stock ADD COLUMN IF NOT EXISTS quantity_available INT NULL DEFAULT NULL AFTER price');
$connectionDB->query('ALTER TABLE stock ADD COLUMN IF NOT EXISTS item_sort INT NULL AFTER image_path');
$connectionDB->query('UPDATE stock SET item_sort = id WHERE item_sort IS NULL');

$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$includeInactiveRaw = isset($_GET['include_inactive']) ? strtolower(trim((string) $_GET['include_inactive'])) : '0';
$includeInactive = in_array($includeInactiveRaw, ['1', 'true', 'yes'], true);
$onlyInactiveRaw = isset($_GET['only_inactive']) ? strtolower(trim((string) $_GET['only_inactive'])) : '0';
$onlyInactive = in_array($onlyInactiveRaw, ['1', 'true', 'yes'], true);

$sql = "SELECT id, category, name, price, image_path, item_sort, quantity_available, is_active FROM stock";
$conditions = [];
$types = '';
$params = [];

if ($onlyInactive) {
    $conditions[] = 'is_active = 0';
} elseif (!$includeInactive) {
    $conditions[] = 'is_active = 1';
}

if ($category !== '') {
    $conditions[] = 'category = ?';
    $types .= 's';
    $params[] = $category;
}

if (count($conditions) > 0) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= ' ORDER BY item_sort, id';

$stmt = $connectionDB->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno query prodotti.']);
    $connectionDB->close();
    exit;
}

if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore esecuzione query prodotti.']);
    $stmt->close();
    $connectionDB->close();
    exit;
}

$result = $stmt->get_result();
$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

echo json_encode($products);
$stmt->close();
$connectionDB->close();
?>
