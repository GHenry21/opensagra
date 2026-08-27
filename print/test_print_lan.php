<?php
date_default_timezone_set('Europe/Rome');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

$debug = [];

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
    $ip = trim((string) ($payload['nome_indirizzo'] ?? $payload['ip'] ?? ''));
    $port = (int) ($payload['porta'] ?? 9100);
    $cassaId = trim((string) ($payload['cassa_id'] ?? ''));

    if ($ip === '') {
        throw new Exception('Nome/Indirizzo IP mancante.');
    }

    if ($port <= 0) {
        $port = 9100;
    }

    $debug[] = 'Start LAN test';
    $debug[] = 'Target IP: ' . $ip;
    $debug[] = 'Target Port: ' . $port;

    $connector = new NetworkPrintConnector($ip, $port);
    $debug[] = 'Connector created';

    $printer = new Printer($connector);
    $debug[] = 'Printer object created';

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text("*** TEST STAMPA LAN ***\n");
    $printer->text('Cassa: ' . ($cassaId !== '' ? $cassaId : 'N/D') . "\n");
    $printer->text('Target: ' . $ip . ':' . $port . "\n");
    $printer->text('Ora: ' . date('d/m/Y H:i:s') . "\n");
    $printer->feed(2);
    $printer->cut();

    $printer->close();
    $debug[] = 'Print sent and connector closed';

    echo json_encode([
        'success' => true,
        'message' => 'Test LAN inviato a ' . $ip . ':' . $port,
        'debug' => $debug
    ]);
} catch (Exception $e) {
    http_response_code(500);
    $debug[] = 'ERROR: ' . $e->getMessage();
    echo json_encode([
        'success' => false,
        'error' => 'Test LAN fallito: ' . $e->getMessage(),
        'debug' => $debug
    ]);
}
