<?php
require_once __DIR__ . '/../config/get_db_connection.php';

$result = $connectionDB->query('SELECT id, category, name, price, quantity_available FROM stock WHERE is_active = 1 ORDER BY item_sort, id');

$filename = 'stock_' . date('Ymd_Hi') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['id', 'categoria', 'nome', 'prezzo', 'disponibilita'], ';');

if ($result) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [
            $row['id'],
            $row['category'],
            $row['name'],
            $row['price'],
            $row['quantity_available'] ?? ''
        ], ';');
    }
}

fclose($out);
$connectionDB->close();
