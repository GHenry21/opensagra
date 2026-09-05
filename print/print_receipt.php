<?php
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/get_db_connection.php';
require_once __DIR__ . '/../config/get_printer.php';
require __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\EscposImage;

const RECEIPT_CONFIG_GLOBAL_KEY = 'GLOBAL';
const DEFAULT_RECEIPT_HEADER = 'OPENSAGRA - Scontrino di vendita';

function ensureReceiptConfigTable($connectionDB) {
    $sql = "CREATE TABLE IF NOT EXISTS receipt_config (
        cassa_id VARCHAR(50) NOT NULL,
        custom_header_text TEXT NULL,
        cut_each_item TINYINT(1) NOT NULL DEFAULT 1,
        enable_logo_print TINYINT(1) NOT NULL DEFAULT 1,
        logo_path VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (cassa_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $connectionDB->query($sql);
    $connectionDB->query("ALTER TABLE receipt_config ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) NULL AFTER cut_each_item");
    $connectionDB->query("ALTER TABLE receipt_config ADD COLUMN IF NOT EXISTS enable_logo_print TINYINT(1) NOT NULL DEFAULT 1 AFTER cut_each_item");
}

function getReceiptConfig($connectionDB) {
    $defaults = [
        'custom_header_text' => DEFAULT_RECEIPT_HEADER,
        'cut_each_item' => 1,
        'enable_logo_print' => 1,
        'logo_path' => ''
    ];

    ensureReceiptConfigTable($connectionDB);

    $query = "SELECT custom_header_text, cut_each_item, enable_logo_print, logo_path FROM receipt_config WHERE cassa_id = ? LIMIT 1";
    $stmt = $connectionDB->prepare($query);
    if (!$stmt) {
        return $defaults;
    }

    $globalKey = RECEIPT_CONFIG_GLOBAL_KEY;
    $stmt->bind_param('s', $globalKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return $defaults;
    }

    return [
        'custom_header_text' => trim((string)($row['custom_header_text'] ?? '')) !== ''
            ? (string)$row['custom_header_text']
            : $defaults['custom_header_text'],
        'cut_each_item' => ((int)($row['cut_each_item'] ?? 1)) === 1 ? 1 : 0,
        'enable_logo_print' => ((int)($row['enable_logo_print'] ?? 1)) === 1 ? 1 : 0,
        'logo_path' => trim((string)($row['logo_path'] ?? ''))
    ];
}

function printReceiptLogo($printer, $configuredLogoPath = '') {
    $logoCandidates = [];

    $trimmedConfigPath = trim((string)$configuredLogoPath);
    if ($trimmedConfigPath !== '') {
        if (str_starts_with($trimmedConfigPath, '/') || preg_match('/^[A-Za-z]:\\\\/', $trimmedConfigPath)) {
            $logoCandidates[] = $trimmedConfigPath;
        } else {
            $logoCandidates[] = __DIR__ . '/../' . ltrim($trimmedConfigPath, '/\\');
        }
    }

    foreach ($logoCandidates as $logoPath) {
        if (!is_file($logoPath)) {
            continue;
        }

        try {
            $logo = EscposImage::load($logoPath, false);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            try {
                $printer->graphics($logo);
            } catch (Exception $e) {
                $printer->bitImage($logo);
            }
            $printer->feed(1);
            return;
        } catch (Exception $e) {
            error_log('Impossibile stampare logo scontrino: ' . $e->getMessage());
        }
    }
}

function printOpenSagraFooterLogo($printer) {
    $logoPath = __DIR__ . '/../assets/footer.png';
    if (!is_file($logoPath)) {
        return;
    }

    try {
        $logo = EscposImage::load($logoPath, false);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        try {
            $printer->graphics($logo);
        } catch (Exception $e) {
            $printer->bitImage($logo);
        }
        
       
    } catch (Exception $e) {
        error_log('Impossibile stampare firma OpenSagra: ' . $e->getMessage());
    }
}

function ensureDettagliVenditaDiscountColumns($connectionDB) {
    $connectionDB->query("ALTER TABLE dettagli_vendita ADD COLUMN IF NOT EXISTS line_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER prezzo_unitario");
    $connectionDB->query("ALTER TABLE dettagli_vendita ADD COLUMN IF NOT EXISTS line_discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER line_discount_percent");
    $connectionDB->query("ALTER TABLE dettagli_vendita ADD COLUMN IF NOT EXISTS line_total_before_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER line_discount_value");
}

function ensurePaymentMethodColumns($connectionDB) {
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS abilita_contanti TINYINT(1) NOT NULL DEFAULT 1 AFTER qz_host");
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS abilita_carta TINYINT(1) NOT NULL DEFAULT 0 AFTER abilita_contanti");
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS abilita_satispay TINYINT(1) NOT NULL DEFAULT 0 AFTER abilita_carta");
}

function isPaymentMethodEnabled($connectionDB, $cassa_id, $metodoPag) {
    $methodColumn = [
        'contanti' => 'abilita_contanti',
        'carta' => 'abilita_carta',
        'elettronico' => 'abilita_satispay'
    ][$metodoPag] ?? null;

    if ($methodColumn === null) {
        return false;
    }

    ensurePaymentMethodColumns($connectionDB);
    $stmt = $connectionDB->prepare("SELECT {$methodColumn} FROM casse_stampanti WHERE cassa_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $cassa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int)$row[$methodColumn] === 1 : $metodoPag === 'contanti';
}

function getReceiptItemQuantity($item) {
    return (int)($item['quantity'] ?? 0);
}

function getReceiptItemUnitPrice($item) {
    return (float)($item['price'] ?? 0);
}

function getReceiptItemLineTotalBeforeDiscount($item) {
    if (isset($item['line_total_before_discount'])) {
        return (float)$item['line_total_before_discount'];
    }

    return getReceiptItemQuantity($item) * getReceiptItemUnitPrice($item);
}

function getReceiptItemLineDiscountValue($item) {
    if (isset($item['line_discount_unit_value'])) {
        $unitDiscount = (float)$item['line_discount_unit_value'];
        return $unitDiscount * getReceiptItemQuantity($item);
    }

    if (isset($item['line_discount_value'])) {
        return (float)$item['line_discount_value'];
    }

    if (isset($item['line_discount_percent'])) {
        return getReceiptItemLineTotalBeforeDiscount($item) * (((float)$item['line_discount_percent']) / 100);
    }

    return 0.0;
}

function getReceiptItemLineTotalAfterDiscount($item) {
    if (isset($item['line_unit_price_after_discount'])) {
        return max(((float)$item['line_unit_price_after_discount']) * getReceiptItemQuantity($item), 0);
    }

    if (isset($item['line_total_after_discount'])) {
        return (float)$item['line_total_after_discount'];
    }

    if (isset($item['total'])) {
        return (float)$item['total'];
    }

    return max(getReceiptItemLineTotalBeforeDiscount($item) - getReceiptItemLineDiscountValue($item), 0);
}

function getReceiptItemsDiscountTotal($items) {
    $total = 0.0;
    foreach ($items as $item) {
        $total += getReceiptItemLineDiscountValue($item);
    }
    return $total;
}

function formatReceiptPercent($value) {
    return (string)max(0, (int)round((float)$value)) . '%';
}

function formatReceiptItemLine($item, $nameColumnWidth = 18, $quantityColumnWidth = 3, $priceColumnWidth = 7, $totalColumnWidth = 8) {
    $name = (string)($item['name'] ?? '');
    $quantityText = getReceiptItemQuantity($item) . 'x';
    $unitPriceText = number_format(getReceiptItemUnitPrice($item), 2, ',', '.');
    $lineTotalText = number_format(getReceiptItemLineTotalBeforeDiscount($item), 2, ',', '.');

    $line = str_pad($name, $nameColumnWidth, ' ');
    $line .= ' ' . str_pad($quantityText, $quantityColumnWidth, ' ', STR_PAD_LEFT);
    $line .= ' ' . str_pad($unitPriceText, $priceColumnWidth, ' ', STR_PAD_LEFT);
    $line .= ' EUR ' . str_pad($lineTotalText, $totalColumnWidth, ' ', STR_PAD_LEFT);

    return $line . "\n";
}

function formatReceiptDiscountLine($label, $discountText, $value, $labelColumnWidth = 20, $discountColumnWidth = 7, $valueColumnWidth = 9) {
    $line = str_pad($label, $labelColumnWidth, ' ');
    $line .= ' ' . str_pad($discountText, $discountColumnWidth, ' ', STR_PAD_LEFT);
    $line .= ' EUR ' . str_pad(number_format($value, 2, ',', '.'), $valueColumnWidth, ' ', STR_PAD_LEFT);

    return $line . "\n";
}

function printReceiptContent($printer, $items, $totale, $sconto, $pagato, $resto, $cassa_id, $id_vendita, $receiptConfig = [], $printLogo = true, $dataOra = null) {
    $customHeaderText = trim((string)($receiptConfig['custom_header_text'] ?? ''));
    if ($customHeaderText === '') {
        $customHeaderText = DEFAULT_RECEIPT_HEADER; // Default header if not set
    }

    // In ristampa si passa la data_ora originale della vendita: senza, si usa l'ora corrente.
    $timestamp = $dataOra !== null ? strtotime((string)$dataOra) : false;
    $dateText = $timestamp !== false ? date('d/m/Y H:i', $timestamp) : date('d/m/Y H:i');

    $cutEachItem = ((int)($receiptConfig['cut_each_item'] ?? 1)) === 1;
    if ($cutEachItem) {
        foreach ($items as $item) {
            for ($i = 0; $i < $item['quantity']; $i++) {
                $printer->setJustification(Printer::JUSTIFY_CENTER);
                $printer->text($customHeaderText . "\n");
                $printer->text($dateText . "\n");
                $printer->text("---------------------------------\n");
                $printer->feed(1);
                $printer->setTextSize(2, 2);
                $printer->text(sprintf("%s", $item['name'] . "\n"));
                $printer->setTextSize(1, 1);
                $printer->feed(2);
                $printer->cut();
            }
        }
    }
    
    /*Scontrino finale con tutti gli articoli*/

    $enableLogoPrint = ((int)($receiptConfig['enable_logo_print'] ?? 1)) === 1;
    if ($printLogo && $enableLogoPrint) {
        $logoPath = trim((string)($receiptConfig['logo_path'] ?? ''));
        printReceiptLogo($printer, $logoPath);
    }

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text($customHeaderText . "\n");
    $printer->text("CASSA #$cassa_id\n");
    $printer->text($dateText . "  --  #$id_vendita \n");
    $printer->text("---------------------------------\n");

    $printer->setJustification(Printer::JUSTIFY_LEFT);
    foreach ($items as $item) {
        $printer->text(formatReceiptItemLine($item));

        $lineDiscountValue = getReceiptItemLineDiscountValue($item);
        if ($lineDiscountValue > 0) {
            $lineDiscountPercent = isset($item['line_discount_percent']) ? (float)$item['line_discount_percent'] : 0.0;
            $discountPercentText = formatReceiptPercent($lineDiscountPercent);
            $printer->text(formatReceiptDiscountLine('Sconto articolo', $discountPercentText, -$lineDiscountValue));
        }
    }

    if ($sconto > 0) {
        $totalDiscountBase = 0.0;
        foreach ($items as $item) {
            $totalDiscountBase += getReceiptItemLineTotalAfterDiscount($item);
        }
        $totalDiscountPercent = $totalDiscountBase > 0
            ? formatReceiptPercent(($sconto / $totalDiscountBase) * 100)
            : '0%';
        $printer->text(formatReceiptDiscountLine('Sconto su totale', $totalDiscountPercent, -$sconto));
    }

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text("---------------------------------\n");
    $printer->setJustification(Printer::JUSTIFY_RIGHT);
    $printer->setEmphasis(true);
    $printer->setTextSize(2, 2);
    $printer->text(str_replace('.', ',', sprintf("%s   EUR %7.2f\n", "TOTALE ", $totale)));
    $printer->setTextSize(1, 1);
    $printer->setEmphasis(false);

    $printer->feed(1);
    printOpenSagraFooterLogo($printer);
    $printer->feed(2);
    $printer->cut();
    $printer->pulse();
}

function buildEscposRawReceipt($items, $totale, $sconto, $pagato, $resto, $cassa_id, $id_vendita, $receiptConfig = [], $printLogo = true, $dataOra = null) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'escpos_');
    if ($tmpFile === false) {
        throw new Exception('Impossibile creare il file temporaneo per lo scontrino.');
    }
    $connector = new FilePrintConnector($tmpFile);
    $printer = new Printer($connector);
    printReceiptContent($printer, $items, $totale, $sconto, $pagato, $resto, $cassa_id, $id_vendita, $receiptConfig, $printLogo, $dataOra);
    $printer->close();
    $raw = file_get_contents($tmpFile);
    unlink($tmpFile);
    if ($raw === false) {
        throw new Exception('Impossibile leggere i dati ESC/POS generati.');
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


// Gestisce l'instradamento della stampa (Bridge, Bluetooth o Diretta)

function routingStampa($connectionDB, $cassa_id, $id_vendita, $items, $totale, $sconto, $pagato, $resto, $dataOra = null) {
    $printerSettings = getPrinterSettings($connectionDB, $cassa_id);
    $receiptConfig = getReceiptConfig($connectionDB);
    $tipoStampante = $printerSettings['tipo_stampante'] ?? '';
    
    // CASO 1: BRIDGE (QZ Tray)
    if (($printerSettings['tipo_stampante'] ?? '') === 'BRIDGE') {
        $printerName = trim((string)($printerSettings['nome_indirizzo'] ?? ''));
        $qzHost = trim((string)($printerSettings['qz_host'] ?? ''));

        if ($printerName === '') {
            http_response_code(400);
            echo json_encode([
                'error' => 'Configurazione bridge non valida: nome stampante QZ mancante. Verificare conf_casse.php',
                'cassa_id' => $cassa_id,
                'printer_settings' => $printerSettings
            ]);
            exit;
        }
        if ($qzHost === '') {
            http_response_code(400);
            echo json_encode([
                'error' => 'Configurazione bridge non valida: host QZ mancante. Inserire l\'indirizzo IP o hostname del bridge QZ Tray in conf_casse.php',
                'cassa_id' => $cassa_id,
                'nome_stampante' => $printerName,
                'printer_settings' => $printerSettings
            ]);
            exit;
        }

        // QZ Tray: il browser stampa localmente usando i byte ESC/POS restituiti dal backend.
        $rawReceipt = buildEscposRawReceipt($items, $totale, $sconto, $pagato, $resto, $cassa_id, $id_vendita, $receiptConfig, true, $dataOra);
        
        return [
            'method' => 'bridge_qz',
            'printer' => $printerName,
            'qz_host' => $qzHost,
            'qz_port' => 8182,
            'qz_data_base64' => base64_encode($rawReceipt)
        ];
    }
        // CASO 2: BLUETOOTH (RawBT via Intent Android)
        if (($printerSettings['tipo_stampante'] ?? '') === 'BLUETOOTH') {
            $rawReceipt = buildEscposRawReceipt($items, $totale, $sconto, $pagato, $resto, $cassa_id, $id_vendita, $receiptConfig, true, $dataOra);

        return [
            'method' => 'bluetooth_rawbt',
            'base64' => base64_encode($rawReceipt)
        ];
    }
         // CASO 3: DIRECT (USB Windows/Linux o Network)
        $printer = getPrinter($connectionDB, $cassa_id);
        printReceiptContent($printer, $items, $totale, $sconto, $pagato, $resto, $cassa_id, $id_vendita, $receiptConfig, true, $dataOra);
        $printer->close();
    
        return [
            'method' => 'direct'
        ];
    }


/* =========================================================================
   BLOCCO DI ESECUZIONE (Solo per nuove vendite via POST diretto)
   ========================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !defined('RICEZIONE_INTERNA')) {

    $data = json_decode(file_get_contents("php://input"), true);
    $items = $data['items'] ?? [];
    $totale = $data['totale'] ?? 0;
    $sconto = $data['sconto'] ?? 0;
    $pagato = $data['pagato'] ?? 0;
    $resto = $data['resto'] ?? 0;
    $cassa_id = $data['cassa_id'] ?? 'ND';
    $metodoPag = $data['metodoPag'] ?? 'ND';

    if (!isPaymentMethodEnabled($connectionDB, $cassa_id, $metodoPag)) {
        http_response_code(403);
        echo json_encode(['error' => 'Metodo di pagamento non abilitato per questa cassa.']);
        $connectionDB->close();
        exit;
    }

    ensureDettagliVenditaDiscountColumns($connectionDB);

    try {
        if (!is_array($items) || count($items) === 0) {
            throw new RuntimeException('Il carrello è vuoto.');
        }

        $connectionDB->begin_transaction();
        if ($pagato == 0) { $pagato = $totale; $resto = 0; }

        foreach ($items as $item) {
            $itemId = isset($item['id']) ? (int)$item['id'] : 0;
            $itemQty = (int)($item['quantity'] ?? 0);
            if ($itemId <= 0 || $itemQty <= 0) {
                throw new RuntimeException('Dati prodotto non validi nel carrello.');
            }

            $stmtStock = $connectionDB->prepare('SELECT quantity_available FROM stock WHERE id = ? AND is_active = 1 FOR UPDATE');
            if (!$stmtStock) {
                throw new RuntimeException('Impossibile verificare la disponibilità del prodotto.');
            }
            $stmtStock->bind_param('i', $itemId);
            $stmtStock->execute();
            $stockResult = $stmtStock->get_result();
            $stockRow = $stockResult ? $stockResult->fetch_assoc() : null;
            $stmtStock->close();

            if (!$stockRow) {
                throw new RuntimeException('Prodotto esaurito o non più disponibile. Aggiorna il catalogo e riprova.');
            }

            if ($stockRow['quantity_available'] !== null) {
                if ((int)$stockRow['quantity_available'] < $itemQty) {
                    throw new RuntimeException('Prodotto esaurito o non più disponibile. Aggiorna il catalogo e riprova.');
                }

                $stmtStock = $connectionDB->prepare('UPDATE stock SET quantity_available = quantity_available - ? WHERE id = ?');
                if (!$stmtStock) {
                    throw new RuntimeException('Impossibile aggiornare la disponibilità del prodotto.');
                }
                $stmtStock->bind_param('ii', $itemQty, $itemId);
                if (!$stmtStock->execute()) {
                    $stmtStock->close();
                    throw new RuntimeException('Impossibile aggiornare la disponibilità del prodotto.');
                }
                $stmtStock->close();
            }
        }

        $sqlVendita = "INSERT INTO vendite (cassa_id, totale, importo_pagato, resto, sconto, metodo_pagamento, stornato) VALUES (?, ?, ?, ?, ?, ?, 0)";
        $stmt = $connectionDB->prepare($sqlVendita);
        if (!$stmt) {
            throw new RuntimeException('Impossibile registrare la vendita.');
        }
        $stmt->bind_param("sdddds", $cassa_id, $totale, $pagato, $resto, $sconto, $metodoPag);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Impossibile registrare la vendita.');
        }
        $id_vendita = $connectionDB->insert_id;
        $stmt->close();

        foreach ($items as $item) {
            $sqlDettVendita = "INSERT INTO dettagli_vendita (vendita_id, prodotto, quantita, prezzo_unitario, line_discount_percent, line_discount_value, line_total_before_discount, totale) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtDettVendita = $connectionDB->prepare($sqlDettVendita);
            if (!$stmtDettVendita) {
                throw new RuntimeException('Impossibile registrare il dettaglio della vendita.');
            }
            $lineDiscountPercent = isset($item['line_discount_percent']) ? (float)$item['line_discount_percent'] : 0.0;
            $lineDiscountValue = isset($item['line_discount_unit_value'])
                ? ((float)$item['line_discount_unit_value'] * (float)$item['quantity'])
                : (isset($item['line_discount_value']) ? (float)$item['line_discount_value'] : 0.0);
            $lineTotalBeforeDiscount = isset($item['line_total_before_discount']) ? (float)$item['line_total_before_discount'] : ((float)$item['quantity'] * (float)$item['price']);
            $lineTotalAfterDiscount = isset($item['line_unit_price_after_discount'])
                ? ((float)$item['line_unit_price_after_discount'] * (float)$item['quantity'])
                : (isset($item['line_total_after_discount']) ? (float)$item['line_total_after_discount'] : max($lineTotalBeforeDiscount - $lineDiscountValue, 0));
            $stmtDettVendita->bind_param("isiddddd", $id_vendita, $item['name'], $item['quantity'], $item['price'], $lineDiscountPercent, $lineDiscountValue, $lineTotalBeforeDiscount, $lineTotalAfterDiscount);
            if (!$stmtDettVendita->execute()) {
                $stmtDettVendita->close();
                throw new RuntimeException('Impossibile registrare il dettaglio della vendita.');
            }
            $stmtDettVendita->close();
        }

        $connectionDB->commit();
    } catch (Throwable $e) {
        $connectionDB->rollback();
        http_response_code(409);
        echo json_encode(['error' => $e->getMessage()]);
        $connectionDB->close();
        exit;
    }

    try {
        // Richiamiamo funzione di routing della stampa, che gestisce BRIDGE, BLUETOOTH e DIRECT
        $res = routingStampa($connectionDB, $cassa_id, $id_vendita, $items, $totale, $sconto, $pagato, $resto);
        echo json_encode(array_merge(['success' => true], $res));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>
