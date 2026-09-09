<?php
/**
 * Migrazione una tantum: aggiunge vendite.idempotency_key + indice UNIQUE
 * (Fase 4 del piano di migrazione, "Scalino 1" dei sotto-punti di sicurezza).
 *
 * Il client genera un UUID per ogni tentativo di checkout e lo manda nel
 * payload; lo stesso UUID viaggia identico a ogni retry (Scalino 0). L'indice
 * UNIQUE fa si' che un secondo POST con la stessa chiave non registri una
 * seconda vendita: print/print_receipt.php la intercetta (tryIdempotentReplay)
 * e ristampa lo scontrino gia' esistente.
 *
 * La colonna e' NULL-abile: le vendite storiche non hanno la chiave e
 * MySQL/MariaDB ammette piu' righe NULL sotto un indice UNIQUE.
 *
 * print/print_receipt.php fa comunque la stessa cosa a runtime
 * (ensureVenditeIdempotencyColumn) per i DB su cui questa migrazione non gira;
 * questo file la rende esplicita per gli installer/aggiornamenti.
 *
 * Idempotente: si puo' rilanciare quante volte si vuole.
 *
 * Uso:
 *   php config/migrations/003_vendite_idempotency_key.php
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

function indexExists(mysqli $db, string $table, string $index): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function run(mysqli $db, string $label, string $sql): void
{
    echo "-> $label ... ";
    if ($db->query($sql)) {
        echo 'ok' . PHP_EOL;
    } else {
        echo 'ERRORE: ' . $db->error . PHP_EOL;
        exit(1);
    }
}

echo 'Migrazione 003: vendite.idempotency_key + indice UNIQUE' . PHP_EOL;

if (!columnExists($connectionDB, 'vendite', 'idempotency_key')) {
    run($connectionDB, 'aggiungi vendite.idempotency_key',
        'ALTER TABLE vendite ADD COLUMN idempotency_key VARCHAR(36) NULL AFTER stornato');
} else {
    echo '-> colonna idempotency_key gia\' presente' . PHP_EOL;
}

if (!indexExists($connectionDB, 'vendite', 'uniq_vendite_idempotency_key')) {
    run($connectionDB, 'aggiungi indice UNIQUE uniq_vendite_idempotency_key',
        'ALTER TABLE vendite ADD UNIQUE INDEX uniq_vendite_idempotency_key (idempotency_key)');
} else {
    echo '-> indice uniq_vendite_idempotency_key gia\' presente' . PHP_EOL;
}

echo 'Migrazione 003 completata.' . PHP_EOL;
$connectionDB->close();
