<?php
/**
 * Migrazione una tantum: rinomina casse_stampanti.qz_host -> bridge_host
 * (Fase 4 del piano di migrazione, rimozione di QZ Tray).
 *
 * Il campo non ha mai avuto a che fare col protocollo QZ vero e proprio: era
 * l'host su cui girava QZ Tray. Da quando QZ e' stato rimosso e sostituito dal
 * bridge di stampa nativo via Mercure, il campo sopravvive con un solo scopo:
 * l'IP del PC-ponte usato dalla discovery stampanti in pages/conf_casse.php
 * (tipo cassa BRIDGE_NATIVE) - NON viene letto in fase di stampa. Il nome
 * "qz_host" era quindi solo fuorviante.
 *
 * Aggiunge anche, per idempotenza su installazioni che le hanno solo come
 * ALTER a runtime, le colonne bridge_printer_type / bridge_topic (modello
 * BRIDGE_NATIVE "a una riga"): ora sono anche in config/pos.sql.
 *
 * Idempotente: si puo' rilanciare quante volte si vuole.
 *
 * Uso:
 *   php config/migrations/002_qz_host_to_bridge_host.php
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

echo 'Migrazione 002: casse_stampanti.qz_host -> bridge_host' . PHP_EOL;

$hasOld = columnExists($connectionDB, 'casse_stampanti', 'qz_host');
$hasNew = columnExists($connectionDB, 'casse_stampanti', 'bridge_host');

if ($hasOld && !$hasNew) {
    // CHANGE COLUMN conserva i dati esistenti (gli IP dei PC-ponte gia' salvati).
    run($connectionDB, 'rinomina qz_host -> bridge_host',
        'ALTER TABLE casse_stampanti CHANGE COLUMN qz_host bridge_host VARCHAR(255) NULL');
} elseif ($hasOld && $hasNew) {
    // Caso raro: entrambe presenti (una ensure ha gia' aggiunto bridge_host
    // vuota prima della migrazione). Travasa i valori e lascia cadere qz_host.
    run($connectionDB, 'travaso qz_host residuo -> bridge_host',
        "UPDATE casse_stampanti SET bridge_host = qz_host
          WHERE (bridge_host IS NULL OR bridge_host = '') AND qz_host IS NOT NULL AND qz_host <> ''");
    run($connectionDB, 'drop qz_host', 'ALTER TABLE casse_stampanti DROP COLUMN qz_host');
} elseif (!$hasNew) {
    run($connectionDB, 'aggiungi bridge_host (nessuna qz_host da rinominare)',
        'ALTER TABLE casse_stampanti ADD COLUMN bridge_host VARCHAR(255) NULL AFTER porta');
} else {
    echo '-> bridge_host gia\' presente, qz_host assente: niente da fare' . PHP_EOL;
}

// Colonne del modello BRIDGE_NATIVE "a una riga" (idempotenti).
run($connectionDB, 'bridge_printer_type',
    'ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS bridge_printer_type VARCHAR(20) NULL AFTER bridge_host');
run($connectionDB, 'bridge_topic',
    'ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS bridge_topic VARCHAR(50) NULL AFTER bridge_printer_type');

echo 'Migrazione 002 completata.' . PHP_EOL;
$connectionDB->close();
