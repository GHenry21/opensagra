<?php
// 1. Definiamo la costante per dire a print_receipt.php di caricare solo le funzioni
// senza salvare una nuova vendita nel DB
define('RICEZIONE_INTERNA', true);

// 2. Includiamo il file che hai mostrato (es. api/stampa.php)
require_once __DIR__ . '/../print/print_receipt.php'; 

header('Content-Type: application/json; charset=utf-8');

// 3. Leggiamo l'ID vendita inviato da JavaScript
$input = json_decode(file_get_contents('php://input'), true);
$id_vendita = $input['ordine_id'] ?? null;

if (!$id_vendita) {
    echo json_encode(['success' => false, 'error' => 'ID vendita mancante']);
    exit;
}

try {
    // A. Recuperiamo i dati testata della vendita dal DB
    $stmtV = $connectionDB->prepare("SELECT cassa_id, totale, sconto, importo_pagato, resto, data_ora FROM vendite WHERE id = ? LIMIT 1");
    $stmtV->bind_param('i', $id_vendita);
    $stmtV->execute();
    $vendita = $stmtV->get_result()->fetch_assoc();
    $stmtV->close();

    if (!$vendita) {
        throw new Exception("Vendita #{$id_vendita} non trovata.");
    }

    // B. Recuperiamo le righe dei prodotti della vendita
    $stmtD = $connectionDB->prepare("SELECT prodotto AS name, quantita AS quantity, prezzo_unitario AS price, line_discount_percent, line_discount_value, line_total_before_discount, totale FROM dettagli_vendita WHERE vendita_id = ?");
    $stmtD->bind_param('i', $id_vendita);
    $stmtD->execute();
    $items = $stmtD->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtD->close();

    // C. Leggiamo la configurazione dello scontrino usando la funzione già pronta nel tuo file!
    $receiptConfig = getReceiptConfig($connectionDB);

    // D. Generiamo i byte ESC/POS grezzi usando la funzione già pronta nel tuo file!
    $rawReceipt = buildEscposRawReceipt(
        $items,
        (float)$vendita['totale'],
        (float)$vendita['sconto'],
        (float)$vendita['importo_pagato'],
        (float)$vendita['resto'],
        $vendita['cassa_id'],
        $id_vendita,
        $receiptConfig,
        true, // printLogo
        $vendita['data_ora'] ?? null // ristampa: ora originale della vendita
    );

    // E. Convertiamo in Base64 e lo restituiamo a RawBT
    echo json_encode([
        'success' => true,
        'base64' => base64_encode($rawReceipt)
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$connectionDB->close();