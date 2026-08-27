<?php

require_once __DIR__ . '/../config/get_db_connection.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'message' => 'Metodo non consentito.'
    ]);
    $connectionDB->close();
    exit;
}

$templateFile = __DIR__ . '/../config/template.sql';
$sql = file_get_contents($templateFile);

if ($sql === false) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Impossibile leggere il file template SQL.'
    ]);
    $connectionDB->close();
    exit;
}

if ($connectionDB->multi_query($sql)) {
    do {
        if ($result = $connectionDB->store_result()) {
            $result->free();
        }
    } while ($connectionDB->more_results() && $connectionDB->next_result());

    echo json_encode([
        'ok' => true,
        'message' => 'Template SQL caricato con successo.'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Errore durante il caricamento del template SQL: ' . $connectionDB->error
    ]);
}

$connectionDB->close();
