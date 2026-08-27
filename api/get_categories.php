<?php
require_once __DIR__ . '/../config/get_db_connection.php';

header('Content-Type: application/json; charset=utf-8');

$includeInactiveRaw = isset($_GET['include_inactive']) ? strtolower(trim((string) $_GET['include_inactive'])) : '0';
$includeInactive = in_array($includeInactiveRaw, ['1', 'true', 'yes'], true);
$onlyInactiveRaw = isset($_GET['only_inactive']) ? strtolower(trim((string) $_GET['only_inactive'])) : '0';
$onlyInactive = in_array($onlyInactiveRaw, ['1', 'true', 'yes'], true);

$sql = 'SELECT DISTINCT category FROM stock';
if ($onlyInactive) {
    $sql .= ' WHERE is_active = 0';
} elseif (!$includeInactive) {
    $sql .= ' WHERE is_active = 1';
}
$sql .= ' ORDER BY category';

$result = $connectionDB->query($sql);

if ($result === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore nel caricamento categorie.']);
    $connectionDB->close();
    exit;
}

$categories = [];
while ($row = $result->fetch_assoc()) {
    $categories[] = $row['category'];
}

echo json_encode($categories);
$connectionDB->close();
?>
