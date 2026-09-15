<?php
require_once __DIR__ . '/../config/get_db_connection.php';
require_once __DIR__ . '/../includes/placeholder-product.php';
require_once __DIR__ . '/../config/mercure.php';
require_once __DIR__ . '/../config/product_name.php';

header('Content-Type: application/json; charset=utf-8');

function importStockFail(mysqli $db, int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message]);
    $db->close();
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    importStockFail($connectionDB, 400, 'Nessun file ricevuto o upload non riuscito.');
}

$originalFilename = basename($_FILES['file']['name']);
$extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
if ($extension !== 'csv') {
    importStockFail($connectionDB, 400, 'Formato file non supportato. Usa un file CSV.');
}

$fh = fopen($_FILES['file']['tmp_name'], 'r');
if ($fh === false) {
    importStockFail($connectionDB, 500, 'Impossibile leggere il file caricato.');
}

$header = fgetcsv($fh, 0, ';');
if ($header === false) {
    fclose($fh);
    importStockFail($connectionDB, 400, 'File CSV vuoto o non leggibile.');
}

$columnIndex = [];
foreach ($header as $index => $label) {
    $columnIndex[mb_strtolower(trim((string) $label), 'UTF-8')] = $index;
}

$requiredColumns = ['categoria', 'nome', 'prezzo'];
foreach ($requiredColumns as $required) {
    if (!isset($columnIndex[$required])) {
        fclose($fh);
        importStockFail($connectionDB, 400, "Colonna obbligatoria mancante nel CSV: {$required}.");
    }
}

$idCol = $columnIndex['id'] ?? null;
$categoryCol = $columnIndex['categoria'];
$nameCol = $columnIndex['nome'];
$priceCol = $columnIndex['prezzo'];
$quantityCol = $columnIndex['disponibilita'] ?? null;

$connectionDB->query('ALTER TABLE stock ADD COLUMN IF NOT EXISTS quantity_available INT NULL DEFAULT NULL AFTER price');
$connectionDB->query('ALTER TABLE stock ADD COLUMN IF NOT EXISTS item_sort INT NULL AFTER image_path');
$connectionDB->query('UPDATE stock SET item_sort = id WHERE item_sort IS NULL');

$nextSortResult = $connectionDB->query('SELECT COALESCE(MAX(item_sort), 0) FROM stock');
$nextSort = $nextSortResult ? (int) $nextSortResult->fetch_row()[0] + 1 : 1;

$created = 0;
$updated = 0;
$skipped = [];
$rowNumber = 1;

while (($row = fgetcsv($fh, 0, ';')) !== false) {
    $rowNumber++;

    if (count($row) === 1 && trim((string) $row[0]) === '') {
        continue;
    }

    $rawId = $idCol !== null ? trim((string) ($row[$idCol] ?? '')) : '';
    $category = trim((string) ($row[$categoryCol] ?? ''));
    $name = normalizeProductNameForStorage((string) ($row[$nameCol] ?? ''));
    $rawPrice = trim((string) ($row[$priceCol] ?? ''));
    $rawQuantity = $quantityCol !== null ? trim((string) ($row[$quantityCol] ?? '')) : '';

    if ($category === '' || $name === '') {
        $skipped[] = ['riga' => $rowNumber, 'motivo' => 'Categoria o nome mancante.'];
        continue;
    }

    $priceNormalized = str_replace(',', '.', $rawPrice);
    if ($priceNormalized === '' || !is_numeric($priceNormalized) || (float) $priceNormalized < 0) {
        $skipped[] = ['riga' => $rowNumber, 'motivo' => 'Prezzo non valido.'];
        continue;
    }
    $price = (float) $priceNormalized;

    $quantityAvailable = null;
    if ($rawQuantity !== '') {
        if (!ctype_digit($rawQuantity)) {
            $skipped[] = ['riga' => $rowNumber, 'motivo' => 'Disponibilità non valida.'];
            continue;
        }
        $quantityAvailable = (int) $rawQuantity;
    }

    $targetId = null;
    if ($rawId !== '' && ctype_digit($rawId)) {
        $candidateId = (int) $rawId;
        $exists = $connectionDB->query('SELECT id FROM stock WHERE id = ' . $candidateId);
        if ($exists && $exists->num_rows > 0) {
            $targetId = $candidateId;
        }
    }
    if ($targetId === null) {
        $targetId = findProductIdByName($connectionDB, $name);
    }

    if ($targetId !== null && productNameIsDuplicate($connectionDB, $name, $targetId)) {
        $skipped[] = ['riga' => $rowNumber, 'motivo' => 'Esiste già un altro prodotto con questo nome.'];
        continue;
    }

    if ($targetId !== null) {
        $stmt = $connectionDB->prepare('UPDATE stock SET category = ?, name = ?, price = ?, quantity_available = ? WHERE id = ?');
        if (!$stmt) {
            $skipped[] = ['riga' => $rowNumber, 'motivo' => 'Errore interno durante l\'aggiornamento.'];
            continue;
        }
        $stmt->bind_param('ssdii', $category, $name, $price, $quantityAvailable, $targetId);
        if ($stmt->execute()) {
            $updated++;
        } else {
            $skipped[] = ['riga' => $rowNumber, 'motivo' => 'Errore durante l\'aggiornamento nel database.'];
        }
        $stmt->close();
        continue;
    }

    $imagePath = createPlaceholderFile($name);
    if ($imagePath === '') {
        $skipped[] = ['riga' => $rowNumber, 'motivo' => 'Impossibile generare l\'immagine placeholder.'];
        continue;
    }

    $itemSort = $nextSort;
    $stmt = $connectionDB->prepare('INSERT INTO stock (category, name, price, image_path, item_sort, quantity_available, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
    if (!$stmt) {
        $skipped[] = ['riga' => $rowNumber, 'motivo' => 'Errore interno durante l\'inserimento.'];
        continue;
    }
    $stmt->bind_param('ssdsii', $category, $name, $price, $imagePath, $itemSort, $quantityAvailable);
    if ($stmt->execute()) {
        $created++;
        $nextSort++;
    } else {
        $skipped[] = ['riga' => $rowNumber, 'motivo' => 'Errore durante l\'inserimento nel database.'];
    }
    $stmt->close();
}

fclose($fh);

if ($created > 0 || $updated > 0) {
    publishProductsChanged($connectionDB);
}

$message = "Importazione completata: {$created} creati, {$updated} aggiornati";
$message .= count($skipped) > 0 ? ', ' . count($skipped) . ' righe saltate.' : '.';

echo json_encode([
    'ok' => true,
    'message' => $message,
    'created' => $created,
    'updated' => $updated,
    'skipped' => $skipped
]);

$connectionDB->close();
