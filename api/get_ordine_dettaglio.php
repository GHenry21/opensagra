<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/get_db_connection.php';

$id       = (int)($_GET['id'] ?? 0);
$cassa_id = trim((string)($_GET['cassa_id'] ?? ''));

if ($id <= 0 || $cassa_id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parametri id / cassa_id mancanti']);
    $connectionDB->close();
    exit;
}

$stmt = $connectionDB->prepare("
    SELECT id, data_ora, totale, sconto, importo_pagato, resto, metodo_pagamento, stornato, cassa_id
    FROM vendite
    WHERE id = ? AND cassa_id = ?
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore prepare vendite: ' . $connectionDB->error]);
    $connectionDB->close();
    exit;
}
$stmt->bind_param('is', $id, $cassa_id);
$stmt->execute();
$res = $stmt->get_result();
$ordineRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

if ($ordineRow === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Ordine non trovato']);
    $connectionDB->close();
    exit;
}

$ordine = [
    'id'               => (int)$ordineRow['id'],
    'data_ora'         => (string)$ordineRow['data_ora'],
    'totale'           => (float)$ordineRow['totale'],
    'sconto'           => (float)$ordineRow['sconto'],
    'importo_pagato'   => (float)$ordineRow['importo_pagato'],
    'resto'            => (float)$ordineRow['resto'],
    'metodo_pagamento' => $ordineRow['metodo_pagamento'] !== null ? (string)$ordineRow['metodo_pagamento'] : '',
    'stornato'         => (int)$ordineRow['stornato'],
    'cassa_id'         => (string)$ordineRow['cassa_id'],
];

$stmtD = $connectionDB->prepare("
    SELECT prodotto, quantita, prezzo_unitario, line_discount_percent, line_discount_value,
           line_total_before_discount, totale
    FROM dettagli_vendita
    WHERE vendita_id = ?
    ORDER BY id ASC
");
if (!$stmtD) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore prepare dettagli_vendita: ' . $connectionDB->error]);
    $connectionDB->close();
    exit;
}
$stmtD->bind_param('i', $id);
$stmtD->execute();
$resD = $stmtD->get_result();

$righe = [];
while ($row = $resD->fetch_assoc()) {
    $righe[] = [
        'prodotto'                   => (string)($row['prodotto'] ?? ''),
        'quantita'                   => (int)($row['quantita'] ?? 0),
        'prezzo_unitario'            => (float)($row['prezzo_unitario'] ?? 0),
        'line_discount_percent'      => (float)($row['line_discount_percent'] ?? 0),
        'line_discount_value'        => (float)($row['line_discount_value'] ?? 0),
        'line_total_before_discount' => (float)($row['line_total_before_discount'] ?? 0),
        'totale'                     => (float)($row['totale'] ?? 0),
    ];
}
$stmtD->close();

echo json_encode(['ordine' => $ordine, 'righe' => $righe]);
$connectionDB->close();
