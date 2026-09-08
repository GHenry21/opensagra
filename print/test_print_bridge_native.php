<?php
// Test di stampa per il tipo cassa BRIDGE_NATIVE (sostituto di QZ Tray).
// Costruisce uno scontrino di prova e lo PUBBLICA sul topic Mercure
// `print/cassa/{cassa-ponte}`. Se il processo bin/opensagra-print-bridge.php
// e' attivo sul PC di quella cassa, lo stampa.

date_default_timezone_set('Europe/Rome');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/mercure.php';
require_once __DIR__ . '/../config/printer_connectors.php';   // bridgeNativeRouting()

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

    // Stesso instradamento del checkout: modello "a una riga" (bridge_printer_type
    // valorizzato -> stampante nel payload) o legacy "a due righe" (nome_indirizzo
    // = cassa-ponte).
    $routing = bridgeNativeRouting([
        'nome_indirizzo' => (string) ($payload['nome_indirizzo'] ?? ''),
        'porta' => $payload['porta'] ?? null,
        'bridge_printer_type' => (string) ($payload['bridge_printer_type'] ?? ''),
        'bridge_topic' => (string) ($payload['bridge_topic'] ?? ''),
    ], $cassaId);

    $topic = $routing['topic'];
    $topicId = substr($topic, strlen('print/cassa/'));
    if ($topicId === '') {
        throw new Exception('Manca l\'id-topic del ponte (bridge_topic / cassa_id).');
    }

    $raw = bnBuildTestRaw($cassaId, $topicId);
    $published = publishMercureUpdate($topic, array_merge([
        'test' => true,
        'cassa_id' => $cassaId,
        'data_base64' => base64_encode($raw),
    ], $routing['payload']));

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
        'message' => "Test inviato al ponte ($topic). Se opensagra-print-bridge e' attivo su quel PC, esce lo scontrino.",
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Test BRIDGE NATIVO fallito: ' . $e->getMessage(),
    ]);
}
