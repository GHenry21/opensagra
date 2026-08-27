<?php
date_default_timezone_set('Europe/Rome');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\CupsPrintConnector;

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

function normalizeWindowsUsbTarget($target) {
    $target = trim((string) $target);
    $localHost = strtolower((string) (gethostname() ?: 'localhost'));
    if ($target === '') {
        return 'smb://127.0.0.1/POS-80C';
    }

    if (stripos($target, 'smb://') === 0) {
        $parts = parse_url($target);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === 'localhost' || $host === $localHost) {
            $path = $parts['path'] ?? '';
            return 'smb://127.0.0.1' . $path;
        }
        return $target;
    }

    if (preg_match('/^\\\\\\\\([^\\\\]+)\\\\(.+)$/', $target, $matches)) {
        $host = strtolower(trim($matches[1]));
        if ($host === 'localhost' || $host === $localHost) {
            $host = '127.0.0.1';
        }
        return 'smb://' . $host . '/' . $matches[2];
    }

    if (preg_match('/^(LPT\\d|COM\\d)$/i', $target)) {
        return strtoupper($target);
    }

    return 'smb://127.0.0.1/' . $target;
}

try {
    $payload = getInputPayload();
    $tipo = trim((string) ($payload['tipo_stampante'] ?? 'WIN_USB'));
    $target = trim((string) ($payload['nome_indirizzo'] ?? $payload['printer'] ?? ''));
    $cassaId = trim((string) ($payload['cassa_id'] ?? ''));

    if ($target === '') {
        throw new Exception('Nome stampante/path mancante.');
    }

    $debug[] = 'Start USB test';
    $debug[] = 'Tipo: ' . $tipo;
    $debug[] = 'Target: ' . $target;

    if ($tipo === 'LINUX_USB') {
        if (str_starts_with($target, '/')) {
            $connector = new FilePrintConnector($target);
            $debug[] = 'Using FilePrintConnector (LINUX_USB device path)';
        } else {
            $connector = new CupsPrintConnector($target);
            $debug[] = 'Using CupsPrintConnector (LINUX_USB CUPS queue)';
        }
    } else {
        $normalizedTarget = normalizeWindowsUsbTarget($target);
        $connector = new WindowsPrintConnector($normalizedTarget);
        $debug[] = 'Using WindowsPrintConnector (WIN_USB)';
        $debug[] = 'Normalized target: ' . $normalizedTarget;
    }

    $printer = new Printer($connector);
    $debug[] = 'Printer object created';

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text("*** TEST STAMPA USB ***\n");
    $printer->text('Cassa: ' . ($cassaId !== '' ? $cassaId : 'N/D') . "\n");
    $printer->text('Target: ' . $target . "\n");
    $printer->text('Ora: ' . date('d/m/Y H:i:s') . "\n");
    $printer->feed(2);
    $printer->cut();

    $printer->close();
    $debug[] = 'Print sent and connector closed';

    echo json_encode([
        'success' => true,
        'message' => 'Test USB inviato a ' . $target,
        'debug' => $debug
    ]);
} catch (Exception $e) {
    http_response_code(500);
    $debug[] = 'ERROR: ' . $e->getMessage();
    echo json_encode([
        'success' => false,
        'error' => 'Test USB fallito: ' . $e->getMessage(),
        'debug' => $debug
    ]);
}
