<?php
require_once __DIR__ . '/../config/get_db_connection.php';
require_once __DIR__ . '/../config/store_uploaded_file.php';
require_once __DIR__ . '/../includes/placeholder-product.php';

$category = isset($_POST['category']) ? trim((string) $_POST['category']) : '';
$name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
$price = isset($_POST['price']) ? (float) $_POST['price'] : null;
$quantityAvailable = isset($_POST['quantity_available']) && trim((string) $_POST['quantity_available']) !== ''
    ? (int) $_POST['quantity_available']
    : null;

// Validazione dati
if ($category === '' || $name === '' || $price === null || ($quantityAvailable !== null && $quantityAvailable < 0)) {
    echo 'Dati prodotto non validi.';
    $connectionDB->close();
    exit;
}

$connectionDB->query('ALTER TABLE stock ADD COLUMN IF NOT EXISTS quantity_available INT NULL DEFAULT NULL AFTER price');
$connectionDB->query('ALTER TABLE stock ADD COLUMN IF NOT EXISTS item_sort INT NULL AFTER image_path');
$connectionDB->query('UPDATE stock SET item_sort = id WHERE item_sort IS NULL');

$nextSortResult = $connectionDB->query('SELECT COALESCE(MAX(item_sort), 0) + 1 AS next_sort FROM stock');
$nextSortRow = $nextSortResult ? $nextSortResult->fetch_assoc() : null;
$itemSort = (int)($nextSortRow['next_sort'] ?? 1);

$imagePath = '';
$hasUpload = isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK;

// Gestione Upload Immagine
if ($hasUpload) {
    $targetDir = __DIR__ . '/../uploads/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $originalFilename = basename($_FILES['image']['name']);
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalFilename);
    $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($extension, $allowedTypes, true)) {
        echo 'Formato immagine non supportato. Usa JPG, PNG, GIF o WEBP.';
        $connectionDB->close();
        exit;
    }

    $storedFilename = time() . '_' . $safeFilename;
    $targetFile = $targetDir . $storedFilename;
    try {
        storeUploadedFile($_FILES['image']['tmp_name'], $targetFile);
    } catch (RuntimeException $e) {
        echo 'Errore durante il caricamento immagine.';
        $connectionDB->close();
        exit;
    }

    $imagePath = 'uploads/' . $storedFilename;
} elseif (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
    echo 'Upload immagine non valido.';
    $connectionDB->close();
    exit;
}

// Generazione Placeholder se manca immagine
if ($imagePath === '') {
    $imagePath = createPlaceholderFile($name);
    if ($imagePath === '') {
        echo 'Impossibile generare il placeholder immagine.';
        $connectionDB->close();
        exit;
    }
}

// Inserimento nel Database tramite Prepared Statement
$sql = "INSERT INTO stock (category, name, price, image_path, item_sort, quantity_available, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)";
$stmt = $connectionDB->prepare($sql);

if (!$stmt) {
    echo 'Errore interno durante la preparazione della query.';
    $connectionDB->close();
    exit;
}

$stmt->bind_param('ssdsii', $category, $name, $price, $imagePath, $itemSort, $quantityAvailable);

if ($stmt->execute()) {
    echo 'Prodotto inserito con successo!';
} else {
    echo 'Errore durante l\'inserimento nel database.';
}

$stmt->close();
$connectionDB->close();
?>
