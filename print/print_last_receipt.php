<?php
// Diciamo a print_receipt che stiamo solo leggendo le sue funzioni
define('RICEZIONE_INTERNA', true);
require_once __DIR__ . '/print_receipt.php';
require_once __DIR__ . '/../config/get_db_connection.php';
require_once __DIR__ . '/../config/get_printer.php';
header('Content-Type: application/json; charset=utf-8');

// Recuperiamo il cassa_id inviato dal browser
$cassa_id_richiesto = trim((string)($_GET['cassa_id'] ?? ''));

// Ristampa di un ordine specifico: se arriva ?id= si ristampa quello (anche se stornato),
// altrimenti si mantiene il comportamento storico (ultimo scontrino non stornato della cassa).
$id_richiesto = (int)($_GET['id'] ?? 0);

if ($cassa_id_richiesto === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Parametro cassa_id mancante o non valido.'
    ]);
    $connectionDB->close();
    exit;
}


try {
    ensureDettagliVenditaDiscountColumns($connectionDB);

    // 1. Recupera la vendita da ristampare: quella con id richiesto (qualsiasi stato) oppure
    //    l'ultima vendita non stornata per la cassa specificata.
    if ($id_richiesto > 0) {
        $queryVendita = 'SELECT id, totale, importo_pagato, resto, cassa_id, sconto, data_ora FROM vendite WHERE id = ? AND cassa_id = ? LIMIT 1';
    } else {
        $queryVendita = 'SELECT id, totale, importo_pagato, resto, cassa_id, sconto, data_ora FROM vendite WHERE stornato = 0 AND cassa_id = ? ORDER BY id DESC LIMIT 1';
    }

    $stmtVendita = $connectionDB->prepare($queryVendita);
    if (!$stmtVendita) {
        throw new RuntimeException('Errore prepare vendite: ' . $connectionDB->error);
    }

    if ($id_richiesto > 0) {
        $stmtVendita->bind_param('is', $id_richiesto, $cassa_id_richiesto);
    } else {
        $stmtVendita->bind_param('s', $cassa_id_richiesto);
    }
    $stmtVendita->execute();
    $resultVendita = $stmtVendita->get_result();

    if (!$resultVendita || $resultVendita->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Nessuna vendita disponibile da ristampare.'
        ]);
        $connectionDB->close();
        exit;
    }
    
    $lastReceipt = $resultVendita->fetch_assoc();
    $id_vendita = (int)($lastReceipt['id'] ?? 0);
    
    // 2. Recuperiamo i dettagli dei prodotti (i piatti ordinati)
    $stmtDettagli = $connectionDB->prepare('SELECT prodotto, quantita, prezzo_unitario, totale, line_discount_percent, line_discount_value, line_total_before_discount FROM dettagli_vendita WHERE vendita_id = ? ORDER BY id ASC');
    if (!$stmtDettagli) {
        throw new RuntimeException('Errore prepare dettagli_vendita: ' . $connectionDB->error);
    }

    $stmtDettagli->bind_param('i', $id_vendita);
    $stmtDettagli->execute();
    $resultDettagli = $stmtDettagli->get_result();

    $items = [];
    while ($row = $resultDettagli->fetch_assoc()) {
        $items[] = [
            'name' => (string)($row['prodotto'] ?? ''),
            'quantity' => (int)($row['quantita'] ?? 0),
            'price' => (float)($row['prezzo_unitario'] ?? 0),
            'total' => (float)($row['totale'] ?? 0),
            'line_discount_percent' => (float)($row['line_discount_percent'] ?? 0),
            'line_discount_value' => (float)($row['line_discount_value'] ?? 0),
            'line_total_before_discount' => (float)($row['line_total_before_discount'] ?? 0),
            'line_total_after_discount' => (float)($row['totale'] ?? 0)
        ];
    }
    $stmtDettagli->close();

    if (count($items) === 0) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => 'La vendita selezionata non contiene righe in dettagli_vendita.'
        ]);
        $connectionDB->close();
        exit;
    }
    
    // Estrarre i dati per la stampa
    $totale = (float)($lastReceipt['totale'] ?? 0);
    $sconto = (float)($lastReceipt['sconto'] ?? 0);
    $pagato = (float)($lastReceipt['importo_pagato'] ?? 0);
    $resto = (float)($lastReceipt['resto'] ?? 0);
    $cassa_id = (string)($lastReceipt['cassa_id'] ?? 'ND');
    $data_ora = $lastReceipt['data_ora'] ?? null; // ristampa: si stampa l'ora originale della vendita

    // 3. MANDIAMO IN STAMPA con la nuova funzione centralizzata!
    $printerSettings = getPrinterSettings($connectionDB, $cassa_id);
    $receiptConfig = getReceiptConfig($connectionDB);

    $printResult = routingStampa($connectionDB, $cassa_id, $id_vendita, $items, $totale, $sconto, $pagato, $resto, $data_ora);

    echo json_encode(array_merge([
        'success' => true,
        'message' => 'Ultimo scontrino recuperato con successo.',
        'id_vendita' => $id_vendita,
    ], $printResult));

} catch (Throwable $e) {
error_log('Errore durante la ristampa ultimo scontrino: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Errore durante la ristampa: ' . $e->getMessage()
    ]);
}

$connectionDB->close();