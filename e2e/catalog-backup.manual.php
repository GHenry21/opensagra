<?php
/**
 * Fase 4 punto 4 - harness DB-level per "Backup Catalogo"
 * (config/catalog_backup.php). NON automatico: si lancia a mano
 *
 *   php e2e/catalog-backup.manual.php
 *
 * Su uno schema usa-e-getta, non tocca ne' config/variabili.env ne' il DB
 * opensagra_pos (stesso principio di e2e/local-fallback.manual.php).
 *
 * Copre: backup di tabelle non vuote (e skip di quelle vuote/assenti), elenco
 * batch, ripristino con rete di sicurezza (backup pre-ripristino), verifica
 * dati ripristinati, cascade della cancellazione, backup di un catalogo
 * completamente vuoto (nessun batch creato), l'ultimo batch disponibile
 * (mostRecentBackupBatch, usato da api/set_network_config.php per il
 * ripristino automatico allo switch a Indipendente) e il pruning automatico
 * (CATALOG_BACKUP_KEEP - nessuna pagina per gestirli, si tengono solo gli
 * ultimi N).
 */

require_once __DIR__ . '/../config/catalog_backup.php';

const TEST_DB = 'opensagra_pos_catbackup_test';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$fails = 0;
function check(bool $cond, string $msg): void
{
    global $fails;
    echo ($cond ? "  OK   " : "  FAIL ") . $msg . PHP_EOL;
    if (!$cond) { $fails++; }
}

function rootConn(): mysqli
{
    foreach ([['root', ''], ['root', 'root']] as [$u, $p]) {
        try {
            $c = @new mysqli('127.0.0.1', $u, $p);
            if (!$c->connect_errno) { return $c; }
        } catch (Throwable $e) { /* prova la prossima */ }
    }
    fwrite(STDERR, "Impossibile connettersi a MariaDB come root su 127.0.0.1.\n");
    exit(2);
}

function rowsOf(mysqli $db, string $table): array
{
    $rows = [];
    $res = $db->query("SELECT * FROM `$table` ORDER BY id ASC");
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    return $rows;
}

$root = rootConn();
$root->query('DROP DATABASE IF EXISTS ' . TEST_DB);
$root->query('CREATE DATABASE ' . TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->select_db(TEST_DB);

// Schema minimo, sufficiente a esercitare il meccanismo generico (stage-then-
// swap via SELECT * / INSERT dinamico) - non serve replicare lo schema reale.
$root->query('CREATE TABLE stock (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), quantity_available INT)');
$root->query('CREATE TABLE casse_stampanti (id INT AUTO_INCREMENT PRIMARY KEY, cassa_id VARCHAR(50))');
// receipt_config NON viene creata: verifica che una tabella assente sia solo
// saltata, non un errore.

echo "== Setup ==" . PHP_EOL;
$root->query("INSERT INTO stock (name, quantity_available) VALUES ('Panino', 30), ('Bibita', 50)");
$root->query("INSERT INTO casse_stampanti (cassa_id) VALUES ('cassa1')");
check(count(rowsOf($root, 'stock')) === 2, 'setup: 2 righe in stock');

echo "== 1. Backup di un catalogo non vuoto ==" . PHP_EOL;
$batch1 = backupCatalogTables($root, CATALOG_BACKUP_TABLES, 'test: passaggio a client');
check($batch1 !== null, 'backupCatalogTables ritorna un batch id (' . var_export($batch1, true) . ')');

$batches = listCatalogBackupBatches($root);
check(count($batches) === 1, 'listCatalogBackupBatches: 1 batch');
if ($batches) {
    $tableNames = array_column($batches[0]['tables'], 'table_name');
    check(in_array('stock', $tableNames, true), 'batch include stock');
    check(in_array('casse_stampanti', $tableNames, true), 'batch include casse_stampanti');
    check(!in_array('receipt_config', $tableNames, true), 'batch NON include receipt_config (tabella assente, saltata senza errori)');
    $stockRow = current(array_filter($batches[0]['tables'], fn($t) => $t['table_name'] === 'stock'));
    check($stockRow && $stockRow['row_count'] === 2, 'row_count di stock nel batch = 2');
}

echo "== 2. Modifica il \"presente\" e ripristina ==" . PHP_EOL;
$root->query("UPDATE stock SET quantity_available = 999 WHERE name = 'Panino'");
$root->query("DELETE FROM stock WHERE name = 'Bibita'");
check(count(rowsOf($root, 'stock')) === 1, 'stock ora ha 1 riga (modificata)');

$restore = restoreCatalogBackupBatch($root, $batch1);
check(empty($restore['errors']), 'restoreCatalogBackupBatch senza errori (' . json_encode($restore['errors']) . ')');
check(($restore['restored']['stock'] ?? 0) === 2, 'ripristinate 2 righe di stock');
check(($restore['restored']['casse_stampanti'] ?? 0) === 1, 'ripristinata 1 riga di casse_stampanti');
check($restore['safety_backup_id'] !== null, 'creato un backup di sicurezza dello stato pre-ripristino');

$after = rowsOf($root, 'stock');
check(count($after) === 2, 'stock dopo il ripristino ha di nuovo 2 righe');
$panino = current(array_filter($after, fn($r) => $r['name'] === 'Panino'));
check($panino && (int) $panino['quantity_available'] === 30, 'Panino: quantity_available tornato a 30 (non 999)');

echo "== 3. Il backup di sicurezza e' un batch distinto, non mischiato ==" . PHP_EOL;
$batches = listCatalogBackupBatches($root);
check(count($batches) === 2, 'ora ci sono 2 batch distinti (originale + sicurezza)');
check($restore['safety_backup_id'] !== $batch1, 'id del batch di sicurezza diverso da quello ripristinato');

echo "== 4. Ripristino di un batch inesistente ==" . PHP_EOL;
$bad = restoreCatalogBackupBatch($root, 999999);
check(empty($bad['restored']), 'nessuna tabella ripristinata per un id inesistente');
check(!empty($bad['errors']), 'errore presente per un id inesistente');

echo "== 5. Eliminazione con cascade ==" . PHP_EOL;
$deleted = deleteCatalogBackupBatch($root, $batch1);
check($deleted, 'deleteCatalogBackupBatch riporta successo');
$batches = listCatalogBackupBatches($root);
check(count($batches) === 1, 'dopo la cancellazione resta 1 solo batch (quello di sicurezza)');
$orphanRows = $root->query('SELECT COUNT(*) AS n FROM catalog_backup_tables WHERE batch_id = ' . (int) $batch1)->fetch_assoc();
check((int) $orphanRows['n'] === 0, 'cascade: nessuna riga orfana in catalog_backup_tables per il batch cancellato');

echo "== 6. Backup di un catalogo vuoto: nessun batch creato ==" . PHP_EOL;
$root->query('TRUNCATE TABLE stock');
$root->query('TRUNCATE TABLE casse_stampanti');
$emptyBatch = backupCatalogTables($root, CATALOG_BACKUP_TABLES, 'test: catalogo vuoto');
check($emptyBatch === null, 'backupCatalogTables su tabelle tutte vuote ritorna null (nessun batch creato)');

echo "== 7. mostRecentBackupBatch() - quello offerto in automatico allo switchback ==" . PHP_EOL;
// Pulizia esplicita: dallo scenario 5 resta ancora il batch di sicurezza.
$root->query('DELETE FROM catalog_backup_batches');
check(mostRecentBackupBatch($root) === null, 'nessun batch dopo la pulizia -> null');
$root->query("INSERT INTO stock (name, quantity_available) VALUES ('Cotoletta', 12)");
$bOld = backupCatalogTables($root, CATALOG_BACKUP_TABLES, 'test: vecchio');
$root->query("UPDATE stock SET quantity_available = 7");
$bNew = backupCatalogTables($root, CATALOG_BACKUP_TABLES, 'test: piu recente');
$mostRecent = mostRecentBackupBatch($root);
check($mostRecent !== null && $mostRecent['id'] === $bNew, 'mostRecentBackupBatch ritorna il piu recente (id ' . $bNew . '), non il primo (' . $bOld . ')');
check($mostRecent['reason'] === 'test: piu recente', 'porta anche il motivo del batch piu recente');

echo "== 8. Pruning automatico: solo gli ultimi CATALOG_BACKUP_KEEP restano ==" . PHP_EOL;
$root->query('DELETE FROM catalog_backup_batches'); // riparte pulito per contare con precisione
$ids = [];
for ($i = 0; $i < CATALOG_BACKUP_KEEP + 2; $i++) {
    $root->query('UPDATE stock SET quantity_available = ' . (100 + $i));
    $ids[] = backupCatalogTables($root, CATALOG_BACKUP_TABLES, "test: giro $i");
}
$remaining = array_column(listCatalogBackupBatches($root), 'id');
check(count($remaining) === CATALOG_BACKUP_KEEP, 'restano esattamente CATALOG_BACKUP_KEEP=' . CATALOG_BACKUP_KEEP . ' batch (creati ' . count($ids) . ')');
$expectedKept = array_slice($ids, -CATALOG_BACKUP_KEEP);
sort($expectedKept);
$actualKept = $remaining;
sort($actualKept);
check($expectedKept === $actualKept, 'sono rimasti proprio gli ultimi ' . CATALOG_BACKUP_KEEP . ' creati, non una selezione arbitraria');
check(!in_array($ids[0], $remaining, true), 'il primo batch creato (il piu vecchio) e stato tolto in silenzio');

echo PHP_EOL;
$root->query('DROP DATABASE IF EXISTS ' . TEST_DB);
$root->close();

if ($fails > 0) {
    echo "$fails controlli falliti." . PHP_EOL;
    exit(1);
}
echo 'Tutti i controlli superati.' . PHP_EOL;
