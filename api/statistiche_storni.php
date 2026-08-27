<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/get_db_connection.php';

// Ricezione parametri POST
$from = $_POST['from'];
$to = $_POST['to'];
$cassa = $_POST['cassa'] ?? '';

if (!isset($_POST['from']) || !isset($_POST['to'])) {
    echo json_encode(['error' => 'Parametri non ricevuti']);
    exit;
}

// Preparazione query
if (isset($cassa) && $cassa != '') {
	$query = "
	    SELECT 
	        v.id, 
	        v.data_ora, 
	        v.totale,
			v.metodo_pagamento 
	    FROM vendite v 
	    WHERE v.data_ora BETWEEN ? AND ?
		  AND UPPER(v.cassa_id) = UPPER('$cassa')  
		  AND v.stornato = 1
	    ORDER BY v.id
	";
} else {
$query = "
    SELECT 
        v.id, 
        v.data_ora, 
        v.totale,
		v.metodo_pagamento 
    FROM vendite v 
    WHERE v.data_ora BETWEEN ? AND ? 
	  AND v.stornato = 1 
    ORDER BY v.id
";
}

$stmt = $connectionDB->prepare($query);
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$result = $stmt->get_result();

$dati = [];

while ($row = $result->fetch_assoc()) {
    $dati[] = $row;
}

// Risposta JSON
echo json_encode([
    'storni' => $dati
]);

$stmt->close();
$connectionDB->close();
?>
