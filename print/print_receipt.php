<?php
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/get_db_connection.php';
require_once __DIR__ . '/../config/get_printer.php';
require_once __DIR__ . '/../config/mercure.php';
require __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\GdEscposImage;

const RECEIPT_CONFIG_GLOBAL_KEY = 'GLOBAL';
const DEFAULT_RECEIPT_HEADER = 'OPENSAGRA - Scontrino di vendita';

// Larghezza massima del logo sullo scontrino, in punti testina (a 203 dpi: 8 punti = 1 mm).
// OpenSagra e' formattato per carta da 80mm, la cui area stampabile e' 72mm = 576 punti.
// 560 = 70mm: riempie quasi tutta la larghezza lasciando un piccolo margine.
// Per un eventuale supporto a 58mm, scendere a 384 (= 48mm).
const RECEIPT_LOGO_MAX_WIDTH = 560;

// Altezza massima del logo, in punti (150 mm): limite di sicurezza contro immagini
// verticali enormi (il dithering e' un ciclo pixel-per-pixel in PHP).
const RECEIPT_LOGO_MAX_HEIGHT = 1200;

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

/**
 * Vero se l'immagine (in scala di grigio) e' gia' sostanzialmente bianco/nero:
 * line art, testo, oppure un logo gia' ditherato a mano dall'utente. In quel caso
 * conviene la soglia netta (bordi nitidi), non un secondo dithering.
 */
function receiptLogoIsNearBilevel($im, $tolerance = 14, $edgeFraction = 0.98) {
    $w = imagesx($im);
    $h = imagesy($im);
    $stepX = max(1, (int)($w / 180));
    $stepY = max(1, (int)($h / 180));
    $edge = 0;
    $sampled = 0;
    for ($y = 0; $y < $h; $y += $stepY) {
        for ($x = 0; $x < $w; $x += $stepX) {
            $v = imagecolorat($im, $x, $y) & 0xFF; // R=G=B dopo IMG_FILTER_GRAYSCALE
            if ($v <= $tolerance || $v >= 255 - $tolerance) {
                $edge++;
            }
            $sampled++;
        }
    }
    return $sampled > 0 && ($edge / $sampled) >= $edgeFraction;
}

/**
 * Dithering Floyd-Steinberg in-place su immagine in scala di grigio -> pixel 0/255.
 * Rende le "sfumature" di un logo con toni continui in un retino di punti che la
 * stampante termica (1 bit) puo' effettivamente rendere, invece di schiacciare
 * tutto a nero/bianco con una soglia dura.
 */
function receiptLogoDither($im) {
    $w = imagesx($im);
    $h = imagesy($im);

    $buf = [];
    for ($y = 0; $y < $h; $y++) {
        $row = [];
        for ($x = 0; $x < $w; $x++) {
            $row[$x] = imagecolorat($im, $x, $y) & 0xFF;
        }
        $buf[$y] = $row;
    }

    $black = imagecolorallocate($im, 0, 0, 0);
    $white = imagecolorallocate($im, 255, 255, 255);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $old = $buf[$y][$x];
            $new = $old < 128 ? 0 : 255;
            $err = $old - $new;
            imagesetpixel($im, $x, $y, $new === 0 ? $black : $white);

            // distribuzione errore Floyd-Steinberg (7/16, 3/16, 5/16, 1/16)
            if ($x + 1 < $w) {
                $buf[$y][$x + 1] += ($err * 7) >> 4;
            }
            if ($y + 1 < $h) {
                if ($x > 0) {
                    $buf[$y + 1][$x - 1] += ($err * 3) >> 4;
                }
                $buf[$y + 1][$x] += ($err * 5) >> 4;
                if ($x + 1 < $w) {
                    $buf[$y + 1][$x + 1] += ($err * 1) >> 4;
                }
            }
        }
    }
}

/**
 * Carica un logo e lo prepara per la stampa termica:
 *  - rileva il formato reale dai byte (non dall'estensione): gestisce anche .jpeg/.webp/.bmp
 *  - appiattisce la trasparenza su sfondo bianco
 *  - RIDIMENSIONA alla larghezza (e altezza) della testina se necessario
 *  - line art / gia' bilevel -> soglia netta; toni continui -> dithering Floyd-Steinberg
 *
 * Senza il ridimensionamento un logo piu' largo della testina viene stampato come
 * rumore: ogni riga raster sfora la testina e "va a capo", spostata di qualche punto
 * rispetto alla precedente -> le classiche striature diagonali.
 */
function loadReceiptLogoImage($absolutePath, $maxWidth = RECEIPT_LOGO_MAX_WIDTH, $maxHeight = RECEIPT_LOGO_MAX_HEIGHT) {
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        throw new Exception("Logo non leggibile: $absolutePath");
    }

    $info = @getimagesize($absolutePath);
    if ($info === false) {
        throw new Exception("Formato logo non riconosciuto: $absolutePath");
    }

    switch ($info[2]) {
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($absolutePath); break;
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($absolutePath); break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($absolutePath); break;
        case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false; break;
        case IMAGETYPE_BMP:  $src = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($absolutePath) : false; break;
        default:             $src = false;
    }
    if (!$src) {
        throw new Exception("Impossibile decodificare il logo: $absolutePath");
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $scale = 1.0;
    if ($maxWidth > 0 && $srcW > $maxWidth) {
        $scale = min($scale, $maxWidth / $srcW);
    }
    if ($maxHeight > 0 && $srcH > $maxHeight) {
        $scale = min($scale, $maxHeight / $srcH);
    }
    $dstW = max(1, (int)round($srcW * $scale));
    $dstH = max(1, (int)round($srcH * $scale));

    $dst = imagecreatetruecolor($dstW, $dstH);
    // Sfondo bianco: sulla carta termica il "non stampato" e' bianco
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

    if (function_exists('imagefilter')) {
        imagefilter($dst, IMG_FILTER_GRAYSCALE);
        if (!receiptLogoIsNearBilevel($dst)) {
            // logo con toni/grigi (o ridimensionato, quindi sfocato): un filo di
            // contrasto e poi dithering per rendere le sfumature
            imagefilter($dst, IMG_FILTER_CONTRAST, -12); // negativo = piu' contrasto in GD
            receiptLogoDither($dst);
        }
        // altrimenti: gia' bilevel -> ci pensa la soglia a 128 della libreria, bordi nitidi
    }

    $escposImage = new GdEscposImage(null, false);
    $escposImage->readImageFromGdResource($dst);
    // $src / $dst liberati dal GC a fine funzione (imagedestroy e' deprecato in PHP 8.5)

    return $escposImage;
}

function renderReceiptLogo($printer, $absolutePath, $maxWidth = RECEIPT_LOGO_MAX_WIDTH) {
    try {
        $logo = loadReceiptLogoImage($absolutePath, $maxWidth);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        try {
            $printer->graphics($logo);
        } catch (Exception $e) {
            $printer->bitImage($logo);
        }
        return true;
    } catch (Exception $e) {
        error_log('Logo scontrino non stampato: ' . $e->getMessage());
        return false;
    }
}

function printReceiptLogo($printer, $configuredLogoPath = '') {
    $trimmedConfigPath = trim((string)$configuredLogoPath);
    if ($trimmedConfigPath === '') {
        return;
    }

    if (str_starts_with($trimmedConfigPath, '/') || preg_match('/^[A-Za-z]:\\\\/', $trimmedConfigPath)) {
        $logoPath = $trimmedConfigPath;
    } else {
        $logoPath = __DIR__ . '/../' . ltrim($trimmedConfigPath, '/\\');
    }

    if (renderReceiptLogo($printer, $logoPath)) {
        $printer->feed(1);
    }
}

function printOpenSagraFooterLogo($printer) {
    renderReceiptLogo($printer, __DIR__ . '/../assets/footer.png');
}

function ensureDettagliVenditaDiscountColumns($connectionDB) {
    $connectionDB->query("ALTER TABLE dettagli_vendita ADD COLUMN IF NOT EXISTS line_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER prezzo_unitario");
    $connectionDB->query("ALTER TABLE dettagli_vendita ADD COLUMN IF NOT EXISTS line_discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER line_discount_percent");
    $connectionDB->query("ALTER TABLE dettagli_vendita ADD COLUMN IF NOT EXISTS line_total_before_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER line_discount_value");
}

/**
 * Scalino 1 (Fase 4) - chiave di idempotenza sul checkout.
 *
 * La colonna e' NULL-abile: le vendite storiche non ce l'hanno e MySQL/MariaDB
 * ammette piu' righe NULL sotto un indice UNIQUE. L'indice UNIQUE fa si' che un
 * secondo POST con la stessa chiave non possa creare un doppione: viene
 * intercettato prima (tryIdempotentReplay) oppure, in caso di corsa, fallisce
 * sull'INSERT e la vendita gia' registrata viene ristampata.
 *
 * Idempotente: la si puo' richiamare a ogni checkout senza costo apprezzabile.
 * Vedi anche config/migrations/003_vendite_idempotency_key.php.
 */
function ensureVenditeIdempotencyColumn($connectionDB) {
    $connectionDB->query("ALTER TABLE vendite ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(36) NULL AFTER stornato");
    $res = $connectionDB->query("SHOW INDEX FROM vendite WHERE Key_name = 'uniq_vendite_idempotency_key'");
    if ($res && $res->num_rows === 0) {
        // Colonna appena creata (tutti NULL) -> nessun duplicato storico da
        // risolvere: l'ALTER non fallisce. Se fallisse comunque non e' fatale.
        $connectionDB->query("ALTER TABLE vendite ADD UNIQUE INDEX uniq_vendite_idempotency_key (idempotency_key)");
    }
}

/**
 * Scalino 1 - se esiste gia' una vendita con questa idempotency_key, la
 * ristampa e restituisce true (richiesta gestita: il chiamante deve solo
 * chiudere la connessione e uscire). Nessuna nuova vendita, nessun decremento
 * di scorte. Copre sia il retry automatico dello Scalino 0 sia il "ripremo il
 * pulsante" manuale dell'operatore.
 */
function tryIdempotentReplay($connectionDB, $idempotencyKey) {
    if ($idempotencyKey === '') {
        return false;
    }

    $stmt = $connectionDB->prepare('SELECT id, totale, importo_pagato, resto, cassa_id, sconto, data_ora FROM vendite WHERE idempotency_key = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $idempotencyKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }

    $id_vendita = (int)$row['id'];
    $items = [];
    $stmtDet = $connectionDB->prepare('SELECT prodotto, quantita, prezzo_unitario, totale, line_discount_percent, line_discount_value, line_total_before_discount FROM dettagli_vendita WHERE vendita_id = ? ORDER BY id ASC');
    if ($stmtDet) {
        $stmtDet->bind_param('i', $id_vendita);
        $stmtDet->execute();
        $resDet = $stmtDet->get_result();
        while ($d = $resDet->fetch_assoc()) {
            $items[] = [
                'name' => (string)($d['prodotto'] ?? ''),
                'quantity' => (int)($d['quantita'] ?? 0),
                'price' => (float)($d['prezzo_unitario'] ?? 0),
                'total' => (float)($d['totale'] ?? 0),
                'line_discount_percent' => (float)($d['line_discount_percent'] ?? 0),
                'line_discount_value' => (float)($d['line_discount_value'] ?? 0),
                'line_total_before_discount' => (float)($d['line_total_before_discount'] ?? 0),
                'line_total_after_discount' => (float)($d['totale'] ?? 0),
            ];
        }
        $stmtDet->close();
    }

    try {
        $res = routingStampa(
            $connectionDB, (string)$row['cassa_id'], $id_vendita, $items,
            (float)$row['totale'], (float)$row['sconto'],
            (float)$row['importo_pagato'], (float)$row['resto'],
            $row['data_ora'] ?? null
        );
        echo json_encode(array_merge(['success' => true, 'idempotent_replay' => true, 'id_vendita' => $id_vendita], $res));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'idempotent_replay' => true]);
    }
    return true;
}

function ensurePaymentMethodColumns($connectionDB) {
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS abilita_contanti TINYINT(1) NOT NULL DEFAULT 1 AFTER porta");
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


// Gestisce l'instradamento della stampa (Bridge nativo, Bluetooth o Diretta)

function routingStampa($connectionDB, $cassa_id, $id_vendita, $items, $totale, $sconto, $pagato, $resto, $dataOra = null) {
    $printerSettings = getPrinterSettings($connectionDB, $cassa_id);
    $receiptConfig = getReceiptConfig($connectionDB);
    $tipoStampante = $printerSettings['tipo_stampante'] ?? '';

        // CASO 1: BRIDGE_NATIVE (bridge di stampa nativo via Mercure, Fase 4 punto 2).
        // Il browser NON stampa: i byte ESC/POS vengono pubblicati su un topic
        // Mercure `print/cassa/{id}` e il processo bin/opensagra-print-bridge.php
        // sul PC col cavo li riceve e stampa.
        //  - modello "a una riga" (bridge_printer_type valorizzato): la stampante
        //    fisica del ponte viaggia nel payload, il ponte non tocca il DB;
        //  - modello legacy "a due righe": nome_indirizzo = cassa-ponte, il ponte
        //    risolve la stampante dal proprio DB.
        if (($printerSettings['tipo_stampante'] ?? '') === 'BRIDGE_NATIVE') {
            $rawReceipt = buildEscposRawReceipt($items, $totale, $sconto, $pagato, $resto, $cassa_id, $id_vendita, $receiptConfig, true, $dataOra);
            $routing = bridgeNativeRouting($printerSettings, $cassa_id);
            $published = publishMercureUpdate($routing['topic'], array_merge([
                'id_vendita' => $id_vendita,
                'cassa_id' => $cassa_id,
                'data_base64' => base64_encode($rawReceipt),
            ], $routing['payload']));

            return [
                'method' => 'bridge_native',
                'topic' => $routing['topic'],
                // La vendita e' gia' registrata: se il ponte non ha ricevuto
                // (hub o ponte giu') il frontend avvisa e offre la ristampa,
                // come per un fallimento QZ - non si perde la vendita.
                'published' => $published,
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

    // Scalino 1 (Fase 4): chiave di idempotenza, generata dal client, una per
    // tentativo di checkout (riusata identica a ogni retry dello Scalino 0).
    // Formato atteso: UUID v4 (crypto.randomUUID) o simile. Una chiave assente o
    // malformata viene ignorata -> comportamento legacy (vendita senza guardia
    // anti-doppione).
    $idempotencyKey = trim((string)($data['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '' && !preg_match('/^[A-Za-z0-9._-]{8,36}$/', $idempotencyKey)) {
        $idempotencyKey = '';
    }

    if (!isPaymentMethodEnabled($connectionDB, $cassa_id, $metodoPag)) {
        http_response_code(403);
        echo json_encode(['error' => 'Metodo di pagamento non abilitato per questa cassa.']);
        $connectionDB->close();
        exit;
    }

    ensureDettagliVenditaDiscountColumns($connectionDB);
    ensureVenditeIdempotencyColumn($connectionDB);

    // Retry (Scalino 0) o doppio clic: la vendita con questa chiave esiste gia'
    // -> ristampa e basta, niente seconda registrazione.
    if (tryIdempotentReplay($connectionDB, $idempotencyKey)) {
        $connectionDB->close();
        exit;
    }

    try {
        if (!is_array($items) || count($items) === 0) {
            throw new RuntimeException('Il carrello è vuoto.');
        }

        $connectionDB->begin_transaction();
        if ($pagato == 0) { $pagato = $totale; $resto = 0; }

        // Diventa true solo se almeno un prodotto a scorta limitata viene
        // scalato: solo allora ha senso avvisare le altre casse (Fase 4,
        // topic 'products'). Una vendita di soli prodotti "illimitati"
        // (quantity_available NULL) non cambia niente di visibile altrove.
        $stockChanged = false;

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
                $stockChanged = true;
            }
        }

        $sqlVendita = "INSERT INTO vendite (cassa_id, totale, importo_pagato, resto, sconto, metodo_pagamento, stornato, idempotency_key) VALUES (?, ?, ?, ?, ?, ?, 0, ?)";
        $stmt = $connectionDB->prepare($sqlVendita);
        if (!$stmt) {
            throw new RuntimeException('Impossibile registrare la vendita.');
        }
        $idempotencyKeyForInsert = $idempotencyKey !== '' ? $idempotencyKey : null;
        $stmt->bind_param("sddddss", $cassa_id, $totale, $pagato, $resto, $sconto, $metodoPag, $idempotencyKeyForInsert);
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

        // Corsa: due invii simultanei con la stessa idempotency_key (doppio clic,
        // o retry dello Scalino 0 che parte mentre il primo e' ancora in volo).
        // Il secondo INSERT ha perso sull'indice UNIQUE: la vendita esiste
        // comunque, quindi ristampala invece di restituire un 409 grezzo. Se non
        // c'e' nessuna riga con quella chiave il fallimento e' un altro (carrello
        // vuoto, scorta esaurita, ...) e si cade nell'errore qui sotto.
        if ($idempotencyKey !== '' && tryIdempotentReplay($connectionDB, $idempotencyKey)) {
            $connectionDB->close();
            exit;
        }

        http_response_code(409);
        echo json_encode(['error' => $e->getMessage()]);
        $connectionDB->close();
        exit;
    }

    // Vendita registrata: se ha scalato scorte limitate, avvisa le altre
    // casse cosi' il pulsante prodotto si disabilita quasi subito invece di
    // aspettare il giro di polling. publishProductsChanged() non lancia mai
    // e torna in fretta se l'hub non c'e': non blocca ne' la stampa qui sotto.
    if ($stockChanged) {
        publishProductsChanged($connectionDB);
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
