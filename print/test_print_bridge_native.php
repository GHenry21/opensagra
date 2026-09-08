<?php
// Test di stampa per il tipo cassa BRIDGE_NATIVE (sostituto di QZ Tray).
// Costruisce uno scontrino di prova e lo PUBBLICA sul topic Mercure
// `print/cassa/{cassa-ponte}`. Se il processo bin/opensagra-print-bridge.php
// e' attivo sul PC di quella cassa, lo stampa.

date_default_timezone_set('Europe/Rome');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/mercure.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

function bnInput(): array
{
    $json = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST ?: $_GET;
}

function bnBuildTestRaw(string $cassaId, string $targetCassa): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'bntest_');
    if ($tmp === false) {
        throw new Exception('Impossibile creare file temporaneo.');
    }
    try {
        $printer = new Printer(new FilePrintConnector($tmp));
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("*** TEST BRIDGE NATIVO ***\n");
        $printer->text('Cassa: ' . ($cassaId !== '' ? $cassaId : 'N/D') . "\n");
        $printer->text('Stampa su cassa-ponte: ' . $targetCassa . "\n");
        $printer->text('Ora: ' . date('d/m/Y H:i:s') . "\n");
        $printer->feed(2);
        $printer->cut();
        $printer->close();

        $raw = file_get_contents($tmp);
        if ($raw === false) {
            throw new Exception('Impossibile leggere i dati ESC/POS.');
        }
        return $raw;
    } finally {
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}

try {
    $payload = bnInput();
    $cassaId = trim((string) ($payload['cassa_id'] ?? ''));
    // Per BRIDGE_NATIVE, nome_indirizzo = la cassa-ponte (che ha la stampante).
    $targetCassa = trim((string) ($payload['nome_indirizzo'] ?? '')) ?: $cassaId;

    if ($targetCassa === '') {
        throw new Exception('Manca la cassa-ponte (nome_indirizzo).');
    }

    $raw = bnBuildTestRaw($cassaId, $targetCassa);
    $topic = 'print/cassa/' . $targetCassa;
    $published = publishMercureUpdate($topic, [
        'test' => true,
        'cassa_id' => $cassaId,
        'data_base64' => base64_encode($raw),
    ]);

    if (!$published) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => "Hub Mercure non raggiungibile: il test non e' stato inviato al ponte ($topic).",
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'method' => 'bridge_native',
        'topic' => $topic,
        'message' => "Test inviato al ponte per la cassa '$targetCassa'. Se opensagra-print-bridge e' attivo su quel PC, esce lo scontrino.",
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Test BRIDGE NATIVO fallito: ' . $e->getMessage(),
    ]);
}
