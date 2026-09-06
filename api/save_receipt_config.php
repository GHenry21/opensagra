<?php
require_once __DIR__ . '/../config/get_db_connection.php';
require_once __DIR__ . '/../config/store_uploaded_file.php';

header('Content-Type: application/json; charset=utf-8');

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

    if (!$connectionDB->query($sql)) {
        throw new RuntimeException('Impossibile inizializzare la tabella receipt_config: ' . $connectionDB->error);
    }

    $connectionDB->query("ALTER TABLE receipt_config ADD COLUMN IF NOT EXISTS enable_logo_print TINYINT(1) NOT NULL DEFAULT 1 AFTER cut_each_item");
    $connectionDB->query("ALTER TABLE receipt_config ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) NULL AFTER cut_each_item");
}

function getCurrentLogoPath($connectionDB, $cassa_id) {
    $query = 'SELECT logo_path FROM receipt_config WHERE cassa_id = ? LIMIT 1';
    $stmt = $connectionDB->prepare($query);
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('s', $cassa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return trim((string)($row['logo_path'] ?? ''));
}

try {
    ensureReceiptConfigTable($connectionDB);

    $cassa_id = RECEIPT_CONFIG_GLOBAL_KEY;
    $customHeaderText = isset($_POST['custom_header_text']) ? trim((string)$_POST['custom_header_text']) : '';
    $cutEachItem = isset($_POST['cut_each_item']) ? (int)$_POST['cut_each_item'] : 1;
    $enableLogoPrint = isset($_POST['enable_logo_print']) ? (int)$_POST['enable_logo_print'] : 1;
    $removeLogo = isset($_POST['remove_logo']) && (string)$_POST['remove_logo'] === '1';

    if ($customHeaderText === '') {
        $customHeaderText = DEFAULT_RECEIPT_HEADER;
    }

    if (strlen($customHeaderText) > 500) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Testo intestazione troppo lungo (max 500 caratteri).'
        ]);
        $connectionDB->close();
        exit;
    }

    $cutEachItem = $cutEachItem === 1 ? 1 : 0;
    $enableLogoPrint = $enableLogoPrint === 1 ? 1 : 0;
    $logoPath = getCurrentLogoPath($connectionDB, $cassa_id);

    if ($removeLogo && $logoPath !== '') {
        $absoluteLogoPath = __DIR__ . '/' . ltrim($logoPath, '/\\');
        if (is_file($absoluteLogoPath) && str_contains(str_replace('\\', '/', $absoluteLogoPath), '/uploads/')) {
            @unlink($absoluteLogoPath);
        }
        $logoPath = '';
    }

    if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Errore upload logo (codice: ' . $_FILES['logo']['error'] . ')');
        }

        $targetDir = __DIR__ . '/../uploads';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true)) {
            throw new RuntimeException('Impossibile creare directory uploads.');
        }

        $originalFilename = basename($_FILES['logo']['name']);
        $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalFilename);
        $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($extension, $allowedTypes, true)) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Formato logo non supportato. Usa JPG, PNG, GIF o WEBP.'
            ]);
            $connectionDB->close();
            exit;
        }

        $fileName = 'receipt_logo_global_' . time() . '.' . $extension;
        $targetFile = $targetDir . '/' . $fileName;

        storeUploadedFile($_FILES['logo']['tmp_name'], $targetFile);

        if ($logoPath !== '') {
            $oldAbsPath = __DIR__ . '/../' . ltrim($logoPath, '/\\');
            if (is_file($oldAbsPath) && str_contains(str_replace('\\', '/', $oldAbsPath), '/uploads/')) {
                @unlink($oldAbsPath);
            }
        }

        $logoPath = 'uploads/' . $fileName;
    }

    $sql = 'INSERT INTO receipt_config (cassa_id, custom_header_text, cut_each_item, enable_logo_print, logo_path)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                custom_header_text = VALUES(custom_header_text),
                cut_each_item = VALUES(cut_each_item),
                enable_logo_print = VALUES(enable_logo_print),
                logo_path = VALUES(logo_path)';

    $stmt = $connectionDB->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Errore prepare save config: ' . $connectionDB->error);
    }

    $stmt->bind_param('ssiis', $cassa_id, $customHeaderText, $cutEachItem, $enableLogoPrint, $logoPath);
    if (!$stmt->execute()) {
        throw new RuntimeException('Errore salvataggio configurazione: ' . $stmt->error);
    }
    $stmt->close();

    echo json_encode([
        'ok' => true,
        'message' => 'Configurazione scontrino salvata con successo.',
        'config' => [
            'custom_header_text' => $customHeaderText,
            'cut_each_item' => $cutEachItem,
            'enable_logo_print' => $enableLogoPrint,
            'logo_path' => $logoPath
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Errore salvataggio configurazione scontrino: ' . $e->getMessage()
    ]);
}

$connectionDB->close();
?>