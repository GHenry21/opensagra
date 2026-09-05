<?php
/**
 * Migrazione una tantum: metadati tabella `stock` (Fase 0 del piano di
 * migrazione, vedi docs/PIANO-MIGRAZIONE-FRANKENPHP.md).
 *
 * Sposta fuori da api/get_products.php le operazioni DDL che oggi girano
 * a ogni richiesta (una per ogni poll di ogni cassa):
 *   - colonne quantity_available / item_sort (gia' presenti oggi, qui solo
 *     per idempotenza su un DB nuovo)
 *   - backfill di item_sort
 *   - nuova colonna updated_at, aggiornata da sola da MariaDB a ogni
 *     modifica riga (serve al polling condizionale della Fase 1)
 *   - indice su updated_at (letto ad ogni poll da api/products_version.php)
 *
 * Idempotente: si puo' rilanciare quante volte si vuole (installer, reinstall,
 * ripristino) — ogni passo verifica prima se serve davvero fare qualcosa,
 * cosi' non sovrascrive updated_at con backfill vecchi su un DB gia' in uso.
 *
 * Uso:
 *   php config/migrations/001_stock_updated_at.php
 */

require_once __DIR__ . '/../get_db_connection.php';

function columnExists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function indexExists(mysqli $db, string $table, string $indexName): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $indexName);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function run(mysqli $db, string $label, string $sql): void
{
    echo "-> $label ... ";
    if ($db->query($sql)) {
        echo "ok" . PHP_EOL;
    } else {
        echo "ERRORE: " . $db->error . PHP_EOL;
        exit(1);
    }
}

echo 'Migrazione 001: metadati stock (quantity_available, item_sort, updated_at)' . PHP_EOL;

// 1-2: colonne storiche (idempotenti grazie a IF NOT EXISTS, estensione MariaDB)
run($connectionDB, 'quantity_available', 'ALTER TABLE stock ADD COLUMN IF NOT EXISTS quantity_available INT NULL DEFAULT NULL AFTER price');
run($connectionDB, 'item_sort', 'ALTER TABLE stock ADD COLUMN IF NOT EXISTS item_sort INT NULL AFTER image_path');

// 3: backfill item_sort (sicuro da rilanciare: tocca solo le righe ancora NULL)
run($connectionDB, 'backfill item_sort', 'UPDATE stock SET item_sort = id WHERE item_sort IS NULL');

// 4-5: updated_at + backfill SOLO alla prima esecuzione, per non sovrascrivere
// storico reale con created_at se la colonna esiste gia'.
if (columnExists($connectionDB, 'stock', 'updated_at')) {
    echo '-> updated_at ... gia\' presente, salto colonna e backfill' . PHP_EOL;
} else {
    run($connectionDB, 'updated_at (colonna)', 'ALTER TABLE stock ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');
    run($connectionDB, 'updated_at (backfill da created_at)', 'UPDATE stock SET updated_at = COALESCE(created_at, NOW())');
}

// 6: indice, verificato via information_schema (piu' portabile di
// "ADD INDEX IF NOT EXISTS", non garantita su tutte le versioni di MariaDB)
if (indexExists($connectionDB, 'stock', 'idx_stock_updated_at')) {
    echo '-> idx_stock_updated_at ... gia\' presente, salto' . PHP_EOL;
} else {
    run($connectionDB, 'indice idx_stock_updated_at', 'ALTER TABLE stock ADD INDEX idx_stock_updated_at (updated_at)');
}

echo 'Migrazione 001 completata.' . PHP_EOL;
$connectionDB->close();
