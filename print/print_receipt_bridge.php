<?php
date_default_timezone_set('Europe/Rome');
require __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\EscposImage;

function printReceiptLogo($printer) {
    $logoCandidates = [
        __DIR__ . '/../uploads/logo_gcf_print3.png',
        __DIR__ . '/../uploads/logo_gcf_print3.jpg',
        __DIR__ . '/../uploads/logo_gcf_print.png',
        __DIR__ . '/../uploads/logo_gcf_print2.jpg'
    ];

    foreach ($logoCandidates as $logoPath) {
        if (!file_exists($logoPath)) {
            continue;
        }

        $logo = EscposImage::load($logoPath, false);
        $printer->setJustification(Printer::JUSTIFY_CENTER);

        try {
            $printer->graphics($logo);
        } catch (Exception $e) {
            $printer->bitImage($logo);
        }

        return;
    }
}

function getPrinterConfig($cassa_id) {
    $configFile = __DIR__ . '/printers.json';
    
    if (!file_exists($configFile)) {
        return [
            'success' => false,
            'error' => 'printers.json not found. Please configure printers first.'
        ];
    }
    
    $json = file_get_contents($configFile);
    $config = json_decode($json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'Invalid printers.json format'
        ];
    }
    
    $printbridge = $config['printer']['printbridge'] ?? null;
    
    if (!$printbridge) {
        return [
            'success' => false,
            'error' => 'Printer configuration invalid'
        ];
    }
    
    $clients = $printbridge['clients'] ?? [];
    
    if (!isset($clients[$cassa_id])) {
        return [
            'success' => false,
            'error' => 'Cassa ID not found in printer configuration: ' . $cassa_id
        ];
    }
    
    return [
        'success' => true,
        'config' => $clients[$cassa_id]
    ];
}

function buildReceiptData($items, $totale, $sconto, $pagato, $resto, $cassa_id, $metodoPag, $id_vendita) {
    return [
        'items' => $items,
        'totale' => $totale,
        'sconto' => $sconto,
        'pagato' => $pagato,
        'resto' => $resto,
        'cassa_id' => $cassa_id,
        'metodo_pagamento' => $metodoPag,
        'id_vendita' => $id_vendita,
        'timestamp' => date('d/m/Y H:i'),
        'restaurant' => 'La gent di Cavalè e Fumè'
    ];
}

function buildEscposRawReceipt($items, $totale, $sconto, $pagato, $resto, $cassa_id, $metodoPag, $id_vendita) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'escpos_');
    if ($tmpFile === false) {
        throw new Exception('Unable to create temporary file for receipt data.');
    }
    // Crea un printer che scrive su file temporaneo
    $connector = new FilePrintConnector($tmpFile);
    $printer = new Printer($connector);

    // RICHIAMA la funzione generica di stampa
    printReceiptContent($printer, $items, $totale, $sconto, $pagato, $resto, $cassa_id, $metodoPag, $id_vendita);

    $printer->close();

    $raw = file_get_contents($tmpFile);
    unlink($tmpFile);

    if ($raw === false) {
        throw new Exception('Unable to read receipt data from temporary file.');
    }
    
    return $raw;
}

function getPrintrzPrinterName($host, $port, $preferredPrinter = null) {
    $url = sprintf('http://%s:%s/printers', $host, $port);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
   

    if ($response === false || $httpCode !== 200) {
        throw new Exception('Printrz printer list request failed: ' . ($error ?: 'HTTP ' . $httpCode));
    }

    $printers = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($printers)) {
        throw new Exception('Invalid response from Printrz /printers endpoint');
    }

    if ($preferredPrinter) {
        foreach ($printers as $printer) {
            if (isset($printer['name']) && $printer['name'] === $preferredPrinter) {
                return $preferredPrinter;
            }
        }
    }

    foreach ($printers as $printer) {
        if (!empty($printer['isDefault']) && isset($printer['name'])) {
            return $printer['name'];
        }
    }

    if (isset($printers[0]['name'])) {
        return $printers[0]['name'];
    }

    throw new Exception('No Printrz printer found.');
}

function buildPrintrzRawJsonPayload($printerName, $rawData, $useBase64 = false) {
    $printerJson = json_encode($printerName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($printerJson === false) {
        throw new Exception('Failed to encode Printrz printer name: ' . json_last_error_msg());
    }

    if ($useBase64) {
        // Use Base64 as encoding - simpler and more reliable for binary data
        $encodedData = base64_encode($rawData);
        $dataJson = json_encode($encodedData, JSON_UNESCAPED_UNICODE);
        return '{"printer":' . $printerJson . ',"type":"BASE64","data":' . $dataJson . '}';
    }

    // Raw mode with \uXXXX escapes
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

    error_log("=== PRINTRZ DEBUG ===");
    error_log("URL: " . $url);
    error_log("Printer: " . $printerName);
    error_log("Raw data size: " . strlen($rawData) . " bytes");

    // Use RAW format (BASE64 caused HTTP 500 from Printrz)
    $payload = buildPrintrzRawJsonPayload($printerName, $rawData);
    
    error_log("Payload size (RAW): " . strlen($payload) . " bytes");
    error_log("Payload (first 300 chars): " . substr($payload, 0, 300));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);


    error_log("HTTP Code: " . $httpCode);
    error_log("Response: " . substr($response, 0, 500));
    error_log("cURL Error: " . $error);
    error_log("=== END DEBUG ===");

    if ($response === false) {
        throw new Exception('Printrz job request failed: ' . $error);
    }

    if ($httpCode !== 200) {
        // Log debug info
        error_log("Printrz /job failed: HTTP $httpCode");
        error_log("Payload size: " . strlen($payload) . " bytes");
        error_log("Raw data size: " . strlen($rawData) . " bytes");
        error_log("Response: " . $response);
        throw new Exception('Printrz job request failed: HTTP ' . $httpCode . ' - ' . $response);
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from Printrz: ' . $response);
    }

    return $result;
}

$data = json_decode(file_get_contents("php://input"), true);

$items = $data['items'] ?? [];
$totale = $data['totale'] ?? 0;
$sconto = $data['sconto'] ?? 0;
$pagato = $data['pagato'] ?? 0;
$resto = $data['resto'] ?? 0;
$cassa_id = $data['cassa_id'] ?? 'ND';
$metodoPag = $data['metodoPag'] ?? 'ND';

$servername = "127.0.0.1";
$username = "root";
$password = "";
$database = "pos";

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { 
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

$sqlVendita = "INSERT INTO vendite (cassa_id, totale, importo_pagato, resto, sconto, metodo_pagamento, stornato) VALUES (?, ?, ?, ?, ?, ?, 0)";
$stmt = $conn->prepare($sqlVendita);
if ($pagato == 0) {
    $pagato = $totale;
    $resto = 0;
}
$stmt->bind_param("sdddds", $cassa_id, $totale, $pagato, $resto, $sconto, $metodoPag);
$stmt->execute();
$stmt->close();

$sqlIdVendita = "SELECT MAX(id) AS id FROM vendite";
$stmt2 = $conn->prepare($sqlIdVendita);
$stmt2->execute();
$result = $stmt2->get_result();
$result = $result->fetch_assoc();
$id_vendita = $result['id'];
$stmt2->close();

if ($id_vendita != null) {
    foreach ($items as $item) {
        $sqlDettVendita = "INSERT INTO dettagli_vendita (vendita_id, prodotto, quantita, prezzo_unitario, totale) VALUES (?, ?, ?, ?, ?)";
        $stmtDettVendita = $conn->prepare($sqlDettVendita);
        $tot = $item['quantity'] * $item['price'];
        $stmtDettVendita->bind_param("isddd", $id_vendita, $item['name'], $item['quantity'], $item['price'], $tot);
        $stmtDettVendita->execute();
        $stmtDettVendita->close();
    }
}

$conn->close();

function formatReceiptItemLine($item, $nameColumnWidth = 18, $quantityColumnWidth = 3, $priceColumnWidth = 7, $totalColumnWidth = 8) {
    $name = (string)($item['name'] ?? '');
    $quantityText = (int)($item['quantity'] ?? 0) . 'x';
    $unitPriceText = number_format((float)($item['price'] ?? 0), 2, ',', '.');
    $lineTotalText = number_format(((int)($item['quantity'] ?? 0)) * (float)($item['price'] ?? 0), 2, ',', '.');

    $line = str_pad($name, $nameColumnWidth, ' ');
    $line .= ' ' . str_pad($quantityText, $quantityColumnWidth, ' ', STR_PAD_LEFT);
    $line .= ' ' . str_pad($unitPriceText, $priceColumnWidth, ' ', STR_PAD_LEFT);
    $line .= ' EUR ' . str_pad($lineTotalText, $totalColumnWidth, ' ', STR_PAD_LEFT);

    return $line . "\n";
}

// Stampa il contenuto dello scontrino (singoli item + riepilogo totale)
function printReceiptContent($printer, $items, $totale, $sconto, $pagato, $resto, $cassa_id, $metodoPag, $id_vendita) {
    foreach ($items as $item) {                                      
        for ($i = 0; $i < $item['quantity']; $i++) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("La gent di Cavalè e Fumè" . "\n");
            $printer->text(date('d/m/Y H:i') . "\n");
            $printer->text("---------------------------------\n");
            $printer->feed(1);
            $printer->setTextSize(2,2);
            $printer->text(sprintf("%s", $item['name']. "\n"));
            $printer->setTextSize(1,1);
            $printer->feed(2);
            $printer->cut();
        }
    }

    //  ===== STAMPA RIEPILOGO TOTALE =====
    // Logo removed for bridge (reduce payload size)

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text("La gent di Cavalè e Fumè\n");
    $printer->text("CASSA #" . $cassa_id . "\n");
    $printer->text(date('d/m/Y H:i') . "  --  #" . $id_vendita . "\n");
    $printer->text("---------------------------------\n");
    $printer->feed(1);

    $printer->setJustification(Printer::JUSTIFY_LEFT);
    foreach ($items as $item) {
        $printer->text(formatReceiptItemLine($item));
    }

    if ($sconto > 0) {
        $printer->text(str_replace('.', ',', sprintf("%-20s               EUR %7.2f\n", 'Sconto', -$sconto)));
    }

    $printer->setJustification(Printer::JUSTIFY_RIGHT);
    $printer->setEmphasis(true);
    $printer->setTextSize(2,2);
    $printer->text(str_replace('.', ',', sprintf("%s   EUR %7.2f\n", "TOTALE ", $totale)));
    $printer->setTextSize(1,1);
    $printer->setEmphasis(false);

    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $printer->text("Metodo pagamento: " . $metodoPag . "\n");
    $printer->text("Pagato: EUR" . number_format($pagato, 2, ',', '.') . "\n");
    $printer->text("Resto: EUR " . number_format($resto, 2, ',', '.') . "\n");

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text("\nGrazie e arrivederci!\n");
    $printer->feed(2);
    $printer->cut();
    $printer->pulse();
}

try {
    $configResult = getPrinterConfig($cassa_id);
    
    if (!$configResult['success']) {
        http_response_code(400);
        echo json_encode(['error' => $configResult['error']]);
        exit;
    }
    
    $printerConfig = $configResult['config'];
    $printerType = $printerConfig['type'] ?? 'usb';
    $host = $printerConfig['host'] ?? 'localhost';
    $port = $printerConfig['port'] ?? 3000;
    
    if ($printerType === 'usb') {
         // USB: genera raw ESC/POS e invia a Printrz
        $printerName = getPrintrzPrinterName($host, $port, $printerConfig['printer_name'] ?? null);
        $rawReceipt = buildEscposRawReceipt($items, $totale, $sconto, $pagato, $resto, $cassa_id, $metodoPag, $id_vendita ?? 0);
        $printrzResult = postToPrintrzJob($host, $port, $printerName, $rawReceipt);

        echo json_encode([
            'success' => true,
            'method' => 'printrz',
            'printer' => $printerName,
            'job_id' => $printrzResult['jobID'] ?? $printrzResult['jobId'] ?? null,
            'printer_type' => 'usb',
            'message' => 'Printed via Printrz on ' . $host . ':' . $port
        ]);
        exit;
    }
    
    elseif ($printerType === 'network') {
        $connector = new NetworkPrintConnector($host, $port);
        $printer = new Printer($connector);
     // RICHIAMA la funzione generica di stampa 
        printReceiptContent($printer, $items, $totale, $sconto, $pagato, $resto, $cassa_id, $metodoPag, $id_vendita ?? 0);

        $printer->close();
 
        echo json_encode([
            'success' => true,
            'printer_type' => 'network',
            'message' => 'Printed directly to network printer at ' . $host . ':' . $port
        ]);
        exit;
    }
    
    else {
        throw new Exception('Unknown printer type: ' . $printerType);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Print error: ' . $e->getMessage()]);
}
?>