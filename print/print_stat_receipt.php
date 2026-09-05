<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/get_printer.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

function printStatReceiptContent($printer, $from, $to, $cassa, $vendite, $totale, $sconti, $dataOraEstr, $ultimaChiusura = null, $fondoCassa = null, $totaleAtteso = null) {
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text("La gent di Cavalè e Fumè\n\n");
    $printer->setEmphasis(true);
    $printer->setTextSize(2, 2);
    $printer->text("STATISTICHE VENDITE\n\n");
    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $printer->setTextSize(1, 1);
    $printer->text(sprintf("Dalla data/ora: %s\n", $from));
    $printer->text(sprintf("Alla data/ora: %s\n", $to));
    $printer->text(sprintf("Cassa: %s\n", $cassa));
    if (!empty($ultimaChiusura)) {
        $printer->text(sprintf("Chiusura cassa %s ore: %s\n", $cassa, $ultimaChiusura));
    }
    if ($fondoCassa !== null && $totaleAtteso !== null) {
        $printer->text(str_replace('.', ',', sprintf("Fondo cassa: EUR %.2f - Totale atteso: EUR %.2f\n", $fondoCassa, $totaleAtteso)));
    }
    $printer->setEmphasis(false);
    $printer->text(sprintf("\nData/ora estrazione: %s\n", $dataOraEstr));
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->text(sprintf("\n QTA     PRDOTTO                TOTALE\n"));
    $printer->setEmphasis(false);
    $printer->text("-----------------------------------------\n");

    foreach ($vendite as $vendita) {
        $line = str_replace('.', ',', sprintf("%5d %-25s  %7.2f\n", $vendita['quantita'], $vendita['prodotto'], $vendita['totale']));
        $printer->text($line);
    }

    $line = str_replace('.', ',', sprintf("\n      %-25s  %7.2f\n", "Sconti applicati", -$sconti));
    $printer->text($line);

    $printer->setEmphasis(true);
    $printer->text(str_replace('.', ',', sprintf("\n%25s   EUR %7.2f\n", "TOTALE COMPLESSIVO", $totale)));
    $printer->setEmphasis(false);
    $printer->feed(2);
    $printer->cut();
}

function buildEscposRawStatReceipt($from, $to, $cassa, $vendite, $totale, $sconti, $dataOraEstr, $ultimaChiusura = null, $fondoCassa = null, $totaleAtteso = null) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'escpos_');
    if ($tmpFile === false) {
        throw new Exception('Impossibile creare il file temporaneo per le statistiche.');
    }

    $connector = new FilePrintConnector($tmpFile);
    $printer = new Printer($connector);
    printStatReceiptContent($printer, $from, $to, $cassa, $vendite, $totale, $sconti, $dataOraEstr, $ultimaChiusura, $fondoCassa, $totaleAtteso);
    $printer->close();

    $raw = file_get_contents($tmpFile);
    unlink($tmpFile);

    if ($raw === false) {
        throw new Exception('Impossibile leggere i dati ESC/POS delle statistiche.');
    }

    return $raw;
}

function getPrintrzPrinterName($host, $port) {
    $url = sprintf('http://%s:%s/printers', $host, $port);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);


    if ($response === false || $httpCode !== 200) {
        throw new Exception('Richiesta elenco stampanti Printrz fallita: ' . ($error ?: 'HTTP ' . $httpCode));
    }

    $printers = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($printers)) {
        throw new Exception('Risposta non valida dall\'endpoint Printrz /printers');
    }

    foreach ($printers as $printer) {
        if (!empty($printer['isDefault']) && isset($printer['name'])) {
            return $printer['name'];
        }
    }

    if (isset($printers[0]['name'])) {
        return $printers[0]['name'];
    }

    throw new Exception('Nessuna stampante disponibile su Printrz.');
}

function buildPrintrzRawJsonPayload($printerName, $rawData) {
    $printerJson = json_encode($printerName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($printerJson === false) {
        throw new Exception('Creazione payload Printrz fallita: nome stampante non valido.');
    }

    $dataJson = '"';
    $length = strlen($rawData);

    for ($index = 0; $index < $length; $index++) {
        $byte = ord($rawData[$index]);

        if ($byte >= 0x20 && $byte <= 0x7E && $byte !== 0x22 && $byte !== 0x5C) {
            $dataJson .= chr($byte);
            continue;
        }

        $dataJson .= sprintf('\\u%04x', $byte);
    }

    $dataJson .= '"';

    return '{"printer":' . $printerJson . ',"type":"RAW","data":' . $dataJson . '}';
}

function postToPrintrzJob($host, $port, $printerName, $rawData) {
    $url = sprintf('http://%s:%s/job', $host, $port);
    // Use RAW format (BASE64 caused HTTP 500 from Printrz)
    $payload = buildPrintrzRawJsonPayload($printerName, $rawData);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    error_log("Printrz /job - HTTP " . $httpCode . ", Payload: " . strlen($payload) . " bytes");

    if ($response === false) {
        throw new Exception('Richiesta job Printrz fallita: ' . $error);
    }

    if ($httpCode !== 200) {
        throw new Exception('Richiesta job Printrz fallita: HTTP ' . $httpCode . ' - ' . $response);
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Risposta JSON non valida da Printrz: ' . $response);
    }

    return $result;
}

// Ricevi i dati dal POST
$data = json_decode(file_get_contents("php://input"), true);

$from = $data['from'] ?? '1990-01-01 00:00:00';
$to = $data['to'] ?? '3000-12-31 23:59:59';
$cassa = $data['cassa'] ?? ''; // cassa per le statistiche
$vendite = $data['vendite'] ?? [];
$totale = $data['totale'] ?? 0;
$sconti = $data['sconti'] ?? 0;
$dataOraEstr = $data['dataEstr'] ?? '';
$cassa_id = $data['cassaId'] ?? ''; // cassa per stampante
$ultimaChiusura = $data['ultimaChiusura'] ?? null;
$fondoCassa = isset($data['fondoCassa']) ? (float)$data['fondoCassa'] : null;
$totaleAtteso = isset($data['totaleAtteso']) ? (float)$data['totaleAtteso'] : null;

if ($cassa == null || $cassa == '') {
    $cassa = '(tutte)';
}

try {
    $printerSettings = getPrinterSettings($connectionDB, $cassa_id);

    if (($printerSettings['tipo_stampante'] ?? '') === 'BRIDGE') {
        $printerName = trim((string) ($printerSettings['nome_indirizzo'] ?? ''));
        $qzHost = trim((string) ($printerSettings['qz_host'] ?? ''));

        if ($printerName === '') {
            throw new Exception('Configurazione bridge non valida: nome stampante QZ mancante.');
        }
        if ($qzHost === '') {
            throw new Exception('Configurazione bridge non valida: host QZ mancante.');
        }

        $rawReceipt = buildEscposRawStatReceipt($from, $to, $cassa, $vendite, $totale, $sconti, $dataOraEstr, $ultimaChiusura, $fondoCassa, $totaleAtteso);

        echo json_encode([
            'success' => true,
            'method' => 'bridge_qz',
            'printer' => $printerName,
            'qz_host' => $qzHost,
            'qz_port' => 8182,
            'qz_data_base64' => base64_encode($rawReceipt)
        ]);
        exit;
    }

    $printer = getPrinter($connectionDB, $cassa_id);
    printStatReceiptContent($printer, $from, $to, $cassa, $vendite, $totale, $sconti, $dataOraEstr, $ultimaChiusura, $fondoCassa, $totaleAtteso);
    $printer->close();

    echo json_encode(['success' => true, 'method' => 'direct']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore di stampa: ' . $e->getMessage()]);
}
?>
