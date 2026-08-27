<?php
//test di stampa bridge qz

date_default_timezone_set('Europe/Rome');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

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

function normalizeQzHost($host) {
    $value = trim((string) $host);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('#^wss?://#i', '', $value);
    $value = preg_replace('#^https?://#i', '', $value);
    $value = preg_replace('#/.*$#', '', $value);

    if (strpos($value, ':') !== false) {
        $parts = explode(':', $value);
        $value = $parts[0];
    }

    return trim($value);
}

function buildEscposTestRaw($cassaId, $printerName, $qzHost, $qzPort) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'qztest_');
    if ($tmpFile === false) {
        throw new Exception('Impossibile creare file temporaneo.');
    }

    try {
        $connector = new FilePrintConnector($tmpFile);
        $printer = new Printer($connector);

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("*** TEST STAMPA BRIDGE (QZ) ***\n");
        $printer->text('Cassa: ' . ($cassaId !== '' ? $cassaId : 'N/D') . "\n");
        $printer->text('Stampante: ' . $printerName . "\n");
        $printer->text('QZ Host: ' . $qzHost . ':' . $qzPort . "\n");
        $printer->text('Ora: ' . date('d/m/Y H:i:s') . "\n");
        $printer->feed(2);
        $printer->cut();
        $printer->close();

        $raw = file_get_contents($tmpFile);
        if ($raw === false) {
            throw new Exception('Impossibile leggere i dati ESC/POS.');
        }

        return $raw;
    } finally {
        if (isset($tmpFile) && file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
    }
}

try {
    $payload = getInputPayload();

    $printerName = trim((string) ($payload['nome_indirizzo'] ?? $payload['printer'] ?? ''));
    $qzHostRaw = trim((string) ($payload['qz_host'] ?? $payload['host'] ?? ''));
    $qzHost = normalizeQzHost($qzHostRaw);
    $qzPort = (int) ($payload['qz_port'] ?? 8182);
    $cassaId = trim((string) ($payload['cassa_id'] ?? ''));

    if ($printerName === '') {
        throw new Exception('Nome stampante QZ mancante.');
    }
    if ($qzHost === '') {
        throw new Exception('Host QZ mancante.');
    }
    if ($qzPort <= 0) {
        $qzPort = 8182;
    }

    $debug[] = 'Start BRIDGE test';
    $debug[] = 'Printer: ' . $printerName;
    $debug[] = 'QZ Host: ' . $qzHost;
    $debug[] = 'QZ Port: ' . $qzPort;

    $raw = buildEscposTestRaw($cassaId, $printerName, $qzHost, $qzPort);
    $debug[] = 'Raw ESC/POS generated';
    $debug[] = 'Raw size: ' . strlen($raw) . ' bytes';

    echo json_encode([
        'success' => true,
        'method' => 'bridge_qz',
        'message' => 'Test BRIDGE pronto: invio a QZ Tray ' . $qzHost . ':' . $qzPort,
        'printer' => $printerName,
        'qz_printer_name' => $printerName,
        'qz_host' => $qzHost,
        'qz_port' => $qzPort,
        'qz_data_base64' => base64_encode($raw),
        'debug' => $debug
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    $debug[] = 'ERROR: ' . $e->getMessage();

    echo json_encode([
        'success' => false,
        'error' => 'Test BRIDGE fallito: ' . $e->getMessage(),
        'debug' => $debug
    ]);
}