<?php
require_once __DIR__ . '/../config/get_db_connection.php';

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
    $connectionDB->query("ALTER TABLE receipt_config ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) NULL AFTER enable_logo_print");
}

function defaultReceiptConfig() {
    return [
        'custom_header_text' => DEFAULT_RECEIPT_HEADER,
        'cut_each_item' => 1,
        'enable_logo_print' => 1,
        'logo_path' => ''
    ];
}

try {
    ensureReceiptConfigTable($connectionDB);

    $cassa_id = RECEIPT_CONFIG_GLOBAL_KEY;

    $defaults = defaultReceiptConfig();

    $query = 'SELECT custom_header_text, cut_each_item, enable_logo_print, logo_path FROM receipt_config WHERE cassa_id = ? LIMIT 1';
    $stmt = $connectionDB->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('Errore prepare: ' . $connectionDB->error);
    }

    $stmt->bind_param('s', $cassa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $config = $defaults;
    if ($row) {
        $config['custom_header_text'] = trim((string)($row['custom_header_text'] ?? '')) !== ''
            ? (string)$row['custom_header_text']
            : $defaults['custom_header_text'];
        $config['cut_each_item'] = ((int)($row['cut_each_item'] ?? 1)) === 1 ? 1 : 0;
        $config['enable_logo_print'] = ((int)($row['enable_logo_print'] ?? 1)) === 1 ? 1 : 0;
        $config['logo_path'] = trim((string)($row['logo_path'] ?? ''));
    }

    echo json_encode([
        'ok' => true,
        'scope' => 'global',
        'config' => $config
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Errore caricamento configurazione scontrino: ' . $e->getMessage()
    ]);
}

$connectionDB->close();
