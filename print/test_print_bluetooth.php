<?php
date_default_timezone_set('Europe/Rome');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

function getInputPayload() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    if (!empty($_POST)) {
        return $_POST;
    }
    return $_GET;
}

try {
    $payload = getInputPayload();
    $cassaId = trim((string) ($payload['cassa_id'] ?? ''));

    // Genera i byte ESC/POS su file temporaneo, poi li converte in Base64 per RawBT
    $tmpFile = tempnam(sys_get_temp_dir(), 'escpos_bt_');
    if ($tmpFile === false) {
        throw new Exception('Impossibile creare il file temporaneo per il test.');
    }

    $connector = new FilePrintConnector($tmpFile);
    $printer = new Printer($connector);

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->text("*** TEST STAMPA BLUETOOTH ***\n");
    $printer->setEmphasis(false);
    $printer->text('Cassa: ' . ($cassaId !== '' ? $cassaId : 'N/D') . "\n");
    $printer->text('Ora: ' . date('d/m/Y H:i:s') . "\n");
    $printer->text("---------------------------------\n");
    $printer->text("Se leggi questo messaggio la\nstampante RawBT e' configurata\ncorrettamente.\n");
    $printer->feed(2);
    $printer->cut();

    $printer->close();

    $rawReceipt = file_get_contents($tmpFile);
    unlink($tmpFile);

    if ($rawReceipt === false) {
        throw new Exception('Impossibile leggere i dati ESC/POS generati.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Test Bluetooth generato, invio a RawBT in corso...',
        'method' => 'bluetooth_rawbt',
        'base64' => base64_encode($rawReceipt)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Test Bluetooth fallito: ' . $e->getMessage()
    ]);
}
