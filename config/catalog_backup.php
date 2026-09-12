<?php
/**
 * Punti di ripristino del catalogo locale (Fase 4 punto 4 - richiesta
 * dell'utente 2026-09-12, seguito del "fallback locale una-via").
 *
 * PROBLEMA: quando un nodo gia' usato come installazione indipendente (con un
 * proprio catalogo prodotti/casse/scontrino, magari accumulato in una stagione
 * intera) passa in modalita' client, bin/opensagra-snapshot.php comincia a
 * sovrascrivere `stock`/`casse_stampanti`/`receipt_config` con la copia del
 * server centrale - ed E' il comportamento giusto in rete (il centrale e' la
 * fonte di verita', serve per il fallback). Ma quel catalogo locale
 * altrimenti sparirebbe senza preavviso al primo giro di snapshot (~10s).
 *
 * SOLUZIONE: un backup automatico, non distruttivo e non bloccante, preso
 * PRIMA dello switch a client (chiamato da api/set_network_config.php) se il
 * locale non e' vuoto. Ogni switch crea un batch NUOVO e distinto - i backup
 * non si mischiano mai tra loro, anche se lo switch avviene piu' volte nella
 * stessa giornata ("puo' essere da un giorno all'altro", non solo da una
 * stagione all'altra). Niente pagina dedicata (rimossa il 2026-09-12, troppo
 * tecnica per un utente medio): il ripristino avviene da solo, in silenzio,
 * dentro api/set_network_config.php a ogni switch pulito verso Indipendente
 * (mostRecentBackupBatch() + restoreCatalogBackupBatch()) - mai query o
 * script a mano. api/restore_catalog_backup.php resta solo per l'azione
 * "Annulla" del toast che segue quel ripristino.
 *
 * Tabelle create on-demand (come app_config, config/app_config.php) SUL DB
 * LOCALE del nodo - i backup sono per-nodo, non si copiano mai da/verso il
 * centrale (niente a che fare con bin/opensagra-snapshot.php ne' con
 * api/push_local_sales.php):
 *   catalog_backup_batches(id, created_at, reason)
 *   catalog_backup_tables(id, batch_id, table_name, row_count, data JSON)
 */

// Tabelle che ha senso salvare: le stesse che bin/opensagra-snapshot.php
// sovrascrive lato client (SNAP_TABLES). MAI vendite/dettagli_vendita: quelle
// non vengono mai toccate da nessun meccanismo automatico, non serve un
// backup dedicato.
const CATALOG_BACKUP_TABLES = ['stock', 'casse_stampanti', 'receipt_config'];

// Quanti punti di ripristino tenere al massimo (il piu' vecchio viene tolto in
// silenzio quando se ne supera il numero). Niente pagina per gestirli - deciso
// il 2026-09-12: solo l'ultimo viene mai offerto in automatico, ma se ne
// tengono un paio in piu' come rete di sicurezza (es. il backup preso appena
// prima di un ripristino).
const CATALOG_BACKUP_KEEP = 3;

function ensureCatalogBackupSchema(mysqli $db): bool
{
    try {
        $db->query(
            'CREATE TABLE IF NOT EXISTS catalog_backup_batches ('
            . ' id INT AUTO_INCREMENT PRIMARY KEY,'
            . ' created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . ' reason VARCHAR(255) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $db->query(
            'CREATE TABLE IF NOT EXISTS catalog_backup_tables ('
            . ' id INT AUTO_INCREMENT PRIMARY KEY,'
            . ' batch_id INT NOT NULL,'
            . ' table_name VARCHAR(64) NOT NULL,'
            . ' row_count INT NOT NULL,'
            . ' data LONGTEXT NOT NULL,'
            . ' INDEX idx_catalog_backup_tables_batch (batch_id),'
            . ' CONSTRAINT fk_catalog_backup_tables_batch FOREIGN KEY (batch_id)'
            . '   REFERENCES catalog_backup_batches(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        return true;
    } catch (Throwable $e) {
        error_log('ensureCatalogBackupSchema: ' . $e->getMessage());
        return false;
    }
}

/**
 * Backup automatico e best-effort: MAI lancia, MAI deve poter bloccare il
 * chiamante (viene usato dentro lo switch di rete, che deve andare a buon
 * fine comunque - "non bloccante" e' un requisito esplicito). Salva solo le
 * tabelle non vuote tra $tables; se nessuna ha righe non crea nessun batch
 * (niente rumore per un catalogo gia' vuoto/mai popolato).
 *
 * @return int|null id del batch creato, null se non c'era nulla da salvare o
 *                   in caso di errore (loggato, mai fatale)
 */
function backupCatalogTables(mysqli $local, array $tables, string $reason): ?int
{
    try {
        if (!ensureCatalogBackupSchema($local)) {
            return null;
        }
        $saved = [];
        foreach ($tables as $table) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                continue; // difesa, anche se la lista chiamante e' una costante interna
            }
            $exists = ($r = $local->query("SHOW TABLES LIKE '$table'")) && $r->num_rows > 0;
            if (!$exists) {
                continue;
            }
            $rows = [];
            $res = $local->query("SELECT * FROM `$table`");
            while ($res && $row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            if (!$rows) {
                continue; // niente da salvare per questa tabella
            }
            $saved[$table] = $rows;
        }
        if (!$saved) {
            return null;
        }

        $stmt = $local->prepare('INSERT INTO catalog_backup_batches (reason) VALUES (?)');
        $stmt->bind_param('s', $reason);
        $stmt->execute();
        $batchId = (int) $local->insert_id;
        $stmt->close();

        $insTable = $local->prepare(
            'INSERT INTO catalog_backup_tables (batch_id, table_name, row_count, data) VALUES (?, ?, ?, ?)'
        );
        foreach ($saved as $table => $rows) {
            $json = json_encode($rows, JSON_UNESCAPED_UNICODE);
            $count = count($rows);
            $insTable->bind_param('isis', $batchId, $table, $count, $json);
            $insTable->execute();
        }
        $insTable->close();

        // Nessuna UI per gestirli: la pulizia dei punti piu' vecchi e' automatica
        // e silenziosa, qui, appena se ne crea uno nuovo.
        pruneCatalogBackups($local);

        return $batchId;
    } catch (Throwable $e) {
        error_log('backupCatalogTables: ' . $e->getMessage());
        return null;
    }
}

/**
 * Tiene solo gli ultimi CATALOG_BACKUP_KEEP batch, cancella i piu' vecchi in
 * silenzio (cascade su catalog_backup_tables). Best-effort: un errore qui non
 * deve mai propagarsi al chiamante (backupCatalogTables gira dentro lo switch
 * di rete, che deve andare a buon fine comunque).
 */
function pruneCatalogBackups(mysqli $local, int $keep = CATALOG_BACKUP_KEEP): void
{
    try {
        $stmt = $local->prepare(
            'DELETE FROM catalog_backup_batches WHERE id NOT IN ('
            . '  SELECT id FROM (SELECT id FROM catalog_backup_batches ORDER BY id DESC LIMIT ?) AS keep_ids'
            . ')'
        );
        $stmt->bind_param('i', $keep);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('pruneCatalogBackups: ' . $e->getMessage());
    }
}

/**
 * L'unico batch che viene mai offerto in automatico (nessuna pagina per
 * sceglierne uno diverso): il piu' recente in assoluto, o null se non esiste
 * ancora nessun salvataggio.
 */
function mostRecentBackupBatch(mysqli $local): ?array
{
    try {
        if (!ensureCatalogBackupSchema($local)) {
            return null;
        }
        $res = $local->query('SELECT id, created_at, reason FROM catalog_backup_batches ORDER BY id DESC LIMIT 1');
        $row = $res ? $res->fetch_assoc() : null;
        return $row ? [
            'id' => (int) $row['id'],
            'created_at' => $row['created_at'],
            'reason' => $row['reason'],
        ] : null;
    } catch (Throwable $e) {
        error_log('mostRecentBackupBatch: ' . $e->getMessage());
        return null;
    }
}

/**
 * Elenco dei batch, piu' recenti prima, con le tabelle salvate in ciascuno
 * (nome + numero di righe - i dati veri e propri restano nel DB, li legge
 * solo restoreCatalogBackupBatch quando serve davvero).
 */
function listCatalogBackupBatches(mysqli $local): array
{
    try {
        if (!ensureCatalogBackupSchema($local)) {
            return [];
        }
        $batches = [];
        $res = $local->query('SELECT id, created_at, reason FROM catalog_backup_batches ORDER BY id DESC');
        while ($res && $row = $res->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['tables'] = [];
            $batches[$row['id']] = $row;
        }
        if (!$batches) {
            return [];
        }
        $ids = implode(',', array_map('intval', array_keys($batches)));
        $tRes = $local->query(
            "SELECT batch_id, table_name, row_count FROM catalog_backup_tables WHERE batch_id IN ($ids) ORDER BY id ASC"
        );
        while ($tRes && $t = $tRes->fetch_assoc()) {
            $batches[(int) $t['batch_id']]['tables'][] = [
                'table_name' => $t['table_name'],
                'row_count' => (int) $t['row_count'],
            ];
        }
        return array_values($batches);
    } catch (Throwable $e) {
        error_log('listCatalogBackupBatches: ' . $e->getMessage());
        return [];
    }
}

/**
 * Sostituisce il contenuto di una tabella locale con $rows, stage-then-swap
 * (stessa tecnica di bin/opensagra-snapshot.php::snapshotTable): costruisce
 * `<t>__restorenew` con lo schema ATTUALE della tabella live (`CREATE TABLE …
 * LIKE`, non il DDL salvato nel backup - se nel frattempo una migrazione ha
 * aggiunto colonne, quelle prendono il default, non e' un errore), inserisce
 * le righe salvate, poi RENAME atomico. Se qualcosa va storto prima dello
 * swap la tabella live non viene toccata.
 */
function restoreTableRows(mysqli $local, string $table, array $rows): void
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
        throw new RuntimeException("nome tabella non valido: $table");
    }
    $stage = $table . '__restorenew';

    $local->query('SET FOREIGN_KEY_CHECKS = 0');
    $local->query("DROP TABLE IF EXISTS `$stage`");
    if (!$local->query("CREATE TABLE `$stage` LIKE `$table`")) {
        $local->query('SET FOREIGN_KEY_CHECKS = 1');
        throw new RuntimeException("CREATE `$stage` fallita: " . $local->error);
    }

    $inTx = false;
    try {
        if ($rows) {
            $cols = array_keys($rows[0]);
            $colList = '`' . implode('`,`', $cols) . '`';
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $ins = $local->prepare("INSERT INTO `$stage` ($colList) VALUES ($placeholders)");
            if (!$ins) {
                throw new RuntimeException("prepare INSERT `$stage` fallita: " . $local->error);
            }
            $local->begin_transaction();
            $inTx = true;
            foreach ($rows as $r) {
                // mysqli::execute(array) dal PHP 8.1: niente bind_param/tipi,
                // MariaDB coerce ogni valore (string|null) nella colonna giusta.
                $values = [];
                foreach ($cols as $c) {
                    $values[] = $r[$c] ?? null;
                }
                if (!$ins->execute($values)) {
                    throw new RuntimeException("INSERT in `$stage` fallita: " . $ins->error);
                }
            }
            $ins->close();
            $local->commit();
            $inTx = false;
        }

        $old = $table . '__restoreold';
        $local->query("DROP TABLE IF EXISTS `$old`");
        if (!$local->query("RENAME TABLE `$table` TO `$old`, `$stage` TO `$table`")) {
            throw new RuntimeException("RENAME swap `$table` fallita: " . $local->error);
        }
        $local->query("DROP TABLE IF EXISTS `$old`");
    } catch (Throwable $e) {
        if ($inTx) {
            try { $local->rollback(); } catch (Throwable $ignored) {}
        }
        try { $local->query("DROP TABLE IF EXISTS `$stage`"); } catch (Throwable $ignored) {}
        $local->query('SET FOREIGN_KEY_CHECKS = 1');
        throw $e;
    }
    $local->query('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Ripristina un batch sul DB locale (127.0.0.1 e' sempre l'unico bersaglio: i
 * backup sono per-nodo, non ha senso ripristinarli su un altro DB). Prima di
 * sovrascrivere qualunque tabella salva un nuovo batch con lo stato ATTUALE
 * ("prima di un ripristino") - un ripristino non puo' mai far perdere per
 * sempre quello che c'era: si annida all'infinito, mai in cancellazione.
 *
 * @return array{restored: array<string,int>, safety_backup_id: int|null, errors: array<string,string>}
 */
function restoreCatalogBackupBatch(mysqli $local, int $batchId): array
{
    $result = ['restored' => [], 'safety_backup_id' => null, 'errors' => []];

    ensureCatalogBackupSchema($local);

    $stmt = $local->prepare('SELECT table_name, data FROM catalog_backup_tables WHERE batch_id = ?');
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $rowsToRestore = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $decoded = json_decode($r['data'], true);
        if (is_array($decoded) && $decoded) {
            $rowsToRestore[$r['table_name']] = $decoded;
        }
    }
    $stmt->close();

    if (!$rowsToRestore) {
        $result['errors']['_'] = 'Backup non trovato o vuoto.';
        return $result;
    }

    // Rete di sicurezza: backup dello stato attuale prima di sovrascrivere.
    $result['safety_backup_id'] = backupCatalogTables(
        $local,
        array_keys($rowsToRestore),
        "prima di un ripristino (backup #$batchId)"
    );

    foreach ($rowsToRestore as $table => $rows) {
        try {
            restoreTableRows($local, $table, $rows);
            $result['restored'][$table] = count($rows);
        } catch (Throwable $e) {
            $result['errors'][$table] = $e->getMessage();
        }
    }

    return $result;
}

function deleteCatalogBackupBatch(mysqli $local, int $batchId): bool
{
    try {
        ensureCatalogBackupSchema($local);
        $stmt = $local->prepare('DELETE FROM catalog_backup_batches WHERE id = ?');
        $stmt->bind_param('i', $batchId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    } catch (Throwable $e) {
        error_log('deleteCatalogBackupBatch: ' . $e->getMessage());
        return false;
    }
}
