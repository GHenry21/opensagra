<?php
require_once __DIR__ . '/../config/get_db_connection.php';
require_once __DIR__ . '/../includes/placeholder-product.php';

header('Content-Type: application/json; charset=utf-8');

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$category = isset($_POST['category']) ? trim($_POST['category']) : '';
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$price = isset($_POST['price']) ? (float) $_POST['price'] : null;
$itemSort = isset($_POST['item_sort']) ? (int) $_POST['item_sort'] : null;
$quantityAvailable = isset($_POST['quantity_available']) && trim((string) $_POST['quantity_available']) !== ''
    ? (int) $_POST['quantity_available']
    : null;
$imagePath = isset($_POST['image_path']) ? trim($_POST['image_path']) : '';

// Gestione Upload Immagine
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $targetDir = __DIR__ . '/../uploads/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $originalFilename = basename($_FILES['image']['name']);
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalFilename);
    $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($extension, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Formato immagine non supportato per aggiornamento.'
        ]);
        $connectionDB->close();
        exit;
    }

    $storedFilename = time() . '_' . $safeFilename;
    $targetFile = $targetDir . $storedFilename;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Errore durante il caricamento della nuova immagine.'
        ]);
        $connectionDB->close();
        exit;
    }

    $imagePath = 'uploads/' . $storedFilename;
} elseif (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Upload immagine non valido.'
    ]);
    $connectionDB->close();
    exit;
}

// Validazione dati
if ($id <= 0 || $category === '' || $name === '' || $price === null || $itemSort === null || $itemSort < 0 || ($quantityAvailable !== null && $quantityAvailable < 0)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Dati non validi per aggiornare il prodotto.'
    ]);
    $connectionDB->close();
    exit;
}

$connectionDB->query('ALTER TABLE stock ADD COLUMN IF NOT EXISTS quantity_available INT NULL DEFAULT NULL AFTER price');
$connectionDB->query('ALTER TABLE stock ADD COLUMN IF NOT EXISTS item_sort INT NULL AFTER image_path');
$connectionDB->query('UPDATE stock SET item_sort = id WHERE item_sort IS NULL');

// Gestione e aggiornamento del placeholder (se non è stata caricata un'immagine reale)
if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
    
    // Se non ha un'immagine O se quella attuale è un placeholder generato in precedenza
    if ($imagePath === '' || str_contains($imagePath, 'uploads/placeholders/')) {
        
        // Elimina il vecchio file SVG per non lasciare file spazzatura sul server
        $oldPlaceholderPath = __DIR__ . '/../' . ltrim($imagePath, '/\\');
        if ($imagePath !== '' && file_exists($oldPlaceholderPath)) {
            @unlink($oldPlaceholderPath);
        }

        // Genera il NUOVO placeholder basato sul nuovo $name
        $imagePath = createPlaceholderFile($name);
        
        if ($imagePath === '') {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Impossibile generare il nuovo placeholder immagine.'
            ]);
            $connectionDB->close();
            exit;
        }
    }
}

// Salvataggio nel Database
$sql = "UPDATE stock SET category = ?, name = ?, price = ?, item_sort = ?, quantity_available = ?, image_path = ? WHERE id = ?";
$stmt = $connectionDB->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Errore interno durante la preparazione query.'
    ]);
    $connectionDB->close();
    exit;
}

$stmt->bind_param('ssdiisi', $category, $name, $price, $itemSort, $quantityAvailable, $imagePath, $id);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Errore durante il salvataggio del prodotto.'
    ]);
    $stmt->close();
    $connectionDB->close();
    exit;
}

if ($stmt->affected_rows === 0) {
    echo json_encode([
        'ok' => true,
        'message' => 'Nessuna modifica rilevata per il prodotto selezionato.'
    ]);
    $stmt->close();
    $connectionDB->close();
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Prodotto aggiornato con successo.'
]);

$stmt->close();
$connectionDB->close();
?>
