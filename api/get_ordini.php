<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/get_db_connection.php';

$cassa_id = trim((string)($_GET['cassa_id'] ?? ''));
$limit    = (int)($_GET['limit'] ?? 20);
$qRaw     = trim((string)($_GET['q'] ?? ''));

if ($cassa_id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parametro cassa_id mancante']);
    $connectionDB->close();
    exit;
}

if ($limit < 1) {
    $limit = 20;
} elseif ($limit > 200) {
    $limit = 200;
}

// La ricerca "q" filtra per numero ordine: se non e' un intero valido si restituisce lista vuota
$searchId = ($qRaw !== '' && ctype_digit($qRaw)) ? (int)$qRaw : null;
if ($qRaw !== '' && $searchId === null) {
    echo json_encode(['ordini' => []]);
    $connectionDB->close();
    exit;
}

$sql = "
    SELECT v.id, v.data_ora, v.totale, v.sconto, v.importo_pagato, v.resto,
           v.metodo_pagamento, v.stornato,
           (SELECT COALESCE(SUM(d.quantita), 0) FROM dettagli_vendita d WHERE d.vendita_id = v.id) AS n_articoli
    FROM vendite v
    WHERE v.cassa_id = ?
";
if ($searchId !== null) {
    $sql .= " AND v.id = ? ";
}
$sql .= " ORDER BY v.id DESC LIMIT ? ";

$stmt = $connectionDB->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore prepare: ' . $connectionDB->error]);
    $connectionDB->close();
    exit;
}

if ($searchId !== null) {
    $stmt->bind_param('sii', $cassa_id, $searchId, $limit);
} else {
    $stmt->bind_param('si', $cassa_id, $limit);
}
$stmt->execute();
$result = $stmt->get_result();

$ordini = [];
while ($row = $result->fetch_assoc()) {
    $ordini[] = [
        'id'               => (int)$row['id'],
        'data_ora'         => (string)$row['data_ora'],
        'totale'           => (float)$row['totale'],
        'sconto'           => (float)$row['sconto'],
        'importo_pagato'   => (float)$row['importo_pagato'],
        'resto'            => (float)$row['resto'],
        'metodo_pagamento' => $row['metodo_pagamento'] !== null ? (string)$row['metodo_pagamento'] : '',
        'stornato'         => (int)$row['stornato'],
        'n_articoli'       => (int)$row['n_articoli'],
    ];
}
$stmt->close();

echo json_encode(['ordini' => $ordini]);
$connectionDB->close();
