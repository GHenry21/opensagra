<?php
/**
 * Migrazione una tantum: aggiunge vendite.da_sincronizzare + vendite.pushed_at
 * + indice (Fase 4 del piano di migrazione, punto 4 "Fallback locale una-via +
 * push a chiusura cassa").
 *
 * Quando una cassa lavora sul proprio MariaDB locale perche' il centrale e'
 * irraggiungibile (FALLBACK_ORIGIN_HOST valorizzato in variabili.env),
 * print/print_receipt.php marca ogni vendita con da_sincronizzare=1. A
 * "Chiudi Cassa" api/push_local_sales.php ricarica quelle righe sul centrale
 * (deduplicando sull'indice UNIQUE gia' esistente uniq_vendite_idempotency_key)
 * e, riga per riga, valorizza pushed_at + rimette da_sincronizzare=0 in locale.
 *
 * Entrambe le colonne hanno un default: le vendite storiche e quelle fatte in
 * esercizio normale restano da_sincronizzare=0 / pushed_at NULL.
 *
 * print/print_receipt.php fa comunque la stessa ALTER a runtime
 * (ensureVenditeIdempotencyColumn) per i DB su cui questa migrazione non gira;
 * questo file la rende esplicita per gli installer/aggiornamenti.
 *
 * Idempotente: si puo' rilanciare quante volte si vuole.
 *
 * Uso:
 *   php config/migrations/004_vendite_fallback_sync.php
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

echo 'Migrazione 004: vendite.da_sincronizzare + vendite.pushed_at + indice' . PHP_EOL;

if (!columnExists($connectionDB, 'vendite', 'da_sincronizzare')) {
    run($connectionDB, 'aggiungi vendite.da_sincronizzare',
        'ALTER TABLE vendite ADD COLUMN da_sincronizzare TINYINT(1) NOT NULL DEFAULT 0 AFTER idempotency_key');
} else {
    echo '-> colonna da_sincronizzare gia\' presente' . PHP_EOL;
}

if (!columnExists($connectionDB, 'vendite', 'pushed_at')) {
    run($connectionDB, 'aggiungi vendite.pushed_at',
        'ALTER TABLE vendite ADD COLUMN pushed_at DATETIME NULL DEFAULT NULL AFTER da_sincronizzare');
} else {
    echo '-> colonna pushed_at gia\' presente' . PHP_EOL;
}

if (!indexExists($connectionDB, 'vendite', 'idx_vendite_da_sincronizzare')) {
    run($connectionDB, 'aggiungi indice idx_vendite_da_sincronizzare',
        'ALTER TABLE vendite ADD INDEX idx_vendite_da_sincronizzare (da_sincronizzare)');
} else {
    echo '-> indice idx_vendite_da_sincronizzare gia\' presente' . PHP_EOL;
}

echo 'Migrazione 004 completata.' . PHP_EOL;
$connectionDB->close();
