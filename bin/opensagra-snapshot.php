<?php
/**
 * opensagra - snapshot locale per il "Fallback locale una-via" (Fase 4 punto 4
 * del piano di migrazione).
 *
 * PROBLEMA: in modalita' rete un client non usa il proprio MariaDB. Se il
 * centrale cade a lungo durante il servizio, api/enter_local_fallback.php
 * sposta la cassa sul DB locale - ma quel DB dev'essere gia' popolato con le
 * tabelle di riferimento (catalogo, casse, config), altrimenti la cassa passa
 * a un database inutile.
 *
 * QUESTO PROCESSO: gira SOLO sui client (DB_POS_HOST remoto) e NON in fallback.
 * Ogni ~10s controlla se sul server sono cambiati (a) il catalogo `stock`
 * (MAX(updated_at)) o (b) `casse_stampanti`/`receipt_config` (CHECKSUM TABLE -
 * `casse_stampanti` non ha updated_at) e ricopia SOLO le tabelle cambiate;
 * ogni ~3 min ricopia comunque tutto come rete di sicurezza. Cosi' se
 * riconfiguri la stampante di una cassa sul centrale, la copia locale del
 * client si allinea in ~10s invece di aspettare fino a 3 min - importante
 * perche' quella copia e' quella che si usa al fallback locale.
 * Ogni copia riuscita scrive app_config['snapshot_last_ok'] in locale: e' la
 * prova che enter_local_fallback.php pretende prima di autorizzare lo swap.
 *
 * MUTUA ESCLUSIONE (critico): appena la cassa entra in fallback
 * (FALLBACK_ORIGIN_HOST valorizzato) questo processo si mette in pausa. Se
 * continuasse, al ritorno del centrale sovrascriverebbe i decrementi di scorta
 * delle vendite fatte in locale prima che api/push_local_sales.php le carichi.
 * Dopo il fallback col centrale parla solo il push.
 *
 * Lanciato dal wrapper come processo figlio (non un servizio - vedi piano 3g).
 * Su un server / installazione indipendente resta idle. Log su STDERR.
 * Avvio manuale per test:  php bin/opensagra-snapshot.php
 */

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/app_config.php';

const SNAP_TICK_SECONDS = 10;       // cadenza del controllo "catalogo cambiato?"
const SNAP_FULL_SECONDS = 180;      // ogni quanto ricopiare TUTTE le tabelle di riferimento
const SNAP_IDLE_SECONDS = 30;       // pausa quando non c'e' niente da fare (non client)
const SNAP_PARKED_SECONDS = 15;     // pausa quando la cassa e' in fallback
const SNAP_RETRY_SECONDS = 15;      // pausa dopo un errore di connessione al server

// Tabelle di riferimento che servono al nodo per operare in fallback. MAI
// vendite/dettagli_vendita (sono append-only per-cassa, non vanno replicate).
// `app_config` non si copia: al client non serve la k/v del server, gli basta
// scrivere in locale il proprio `snapshot_last_ok` (vedi ensureAppConfigTable
// piu' sotto).
const SNAP_TABLES = ['stock', 'casse_stampanti', 'receipt_config'];

// Tabelle di riferimento diverse da `stock`: non hanno una colonna di versione
// affidabile (`casse_stampanti` non ha `updated_at`), quindi per capire se
// sono cambiate sul server usiamo CHECKSUM TABLE - costo trascurabile, sono
// tabelle da poche righe.
const SNAP_REF_TABLES = ['casse_stampanti', 'receipt_config'];

function snapLog(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] snapshot: ' . $msg . "\n");
}

function refTablesFingerprint(mysqli $server): string
{
    $parts = [];
    foreach (SNAP_REF_TABLES as $t) {
        $row = $server->query("CHECKSUM TABLE `$t`");
        $parts[] = $row ? (string) (($row->fetch_assoc()['Checksum'] ?? '')) : '';
    }
    return implode(':', $parts);
}

/**
 * Rende la tabella locale una copia esatta di quella del server: DROP + CREATE
 * dal DDL del server (`SHOW CREATE TABLE`) + reinsert delle righe.
 *
 * Il DROP+CREATE (non un semplice DELETE) e' deliberato: il DB locale di un
 * client non viene mai usato in esercizio normale, quindi il suo schema puo'
 * essere vecchio / non allineato alle migrazioni (drift reale visto sulla VM:
 * `casse_stampanti` senza la colonna `bridge_host`). Queste sono cache di sola
 * lettura lato client, ricrearle e' sicuro (nessuna FK entrante: solo
 * dettagli_vendita->vendite, tabelle che non tocchiamo).
 */
function snapshotTable(mysqli $server, mysqli $local, string $table): void
{
    $ddlRow = $server->query("SHOW CREATE TABLE `$table`");
    $ddl = $ddlRow ? $ddlRow->fetch_assoc() : null;
    $createSql = $ddl['Create Table'] ?? '';
    if (!str_starts_with($createSql, 'CREATE TABLE')) {
        throw new RuntimeException("SHOW CREATE TABLE `$table` inattesa sul server");
    }

    // DDL = commit implicito: fuori da qualsiasi transazione.
    $local->query('SET FOREIGN_KEY_CHECKS = 0');
    $local->query("DROP TABLE IF EXISTS `$table`");
    if (!$local->query($createSql)) {
        $local->query('SET FOREIGN_KEY_CHECKS = 1');
        throw new RuntimeException("CREATE `$table` locale fallita: " . $local->error);
    }
    $local->query('SET FOREIGN_KEY_CHECKS = 1');

    $cols = [];
    $colsRes = $server->query("SHOW COLUMNS FROM `$table`");
    while ($colsRes && $c = $colsRes->fetch_assoc()) {
        $cols[] = $c['Field'];
    }
    if (!$cols) {
        throw new RuntimeException("tabella `$table` senza colonne?");
    }

    $rows = [];
    $dataRes = $server->query("SELECT * FROM `$table`");
    if (!$dataRes) {
        throw new RuntimeException("SELECT `$table` fallita: " . $server->error);
    }
    while ($r = $dataRes->fetch_assoc()) {
        $rows[] = $r;
    }

    $colList = '`' . implode('`,`', $cols) . '`';
    $placeholders = implode(',', array_fill(0, count($cols), '?'));

    $local->begin_transaction();
    try {
        if ($rows) {
            $ins = $local->prepare("INSERT INTO `$table` ($colList) VALUES ($placeholders)");
            if (!$ins) {
                throw new RuntimeException("prepare INSERT `$table` locale fallita: " . $local->error);
            }
            foreach ($rows as $r) {
                // mysqli::execute(array) - dal PHP 8.1: niente bind_param/tipi,
                // MariaDB coerce ogni valore (string|null) nella colonna giusta.
                $values = [];
                foreach ($cols as $c) {
                    $values[] = $r[$c] ?? null;
                }
                if (!$ins->execute($values)) {
                    throw new RuntimeException("INSERT in `$table` locale fallita: " . $ins->error);
                }
            }
            $ins->close();
        }
        $local->commit();
    } catch (Throwable $e) {
        $local->rollback();
        throw $e;
    }

    snapLog("copiata `$table` (" . count($rows) . " righe)");
}

// --- Connessioni (ricreate a ogni giro di lavoro: il processo e' longevo, le
//     connessioni no) ---
function connectDb(string $host, array $env, int $timeout = 3): ?mysqli
{
    try {
        $c = mysqli_init();
        $c->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);
        if (!@$c->real_connect($host, $env['user'], $env['pass'], $env['db'])) {
            return null;
        }
        $c->set_charset('utf8mb4');
        return $c;
    } catch (mysqli_sql_exception $e) {
        return null;
    }
}

snapLog('avviato.');

$lastFullAt = 0;
$lastStockVersion = null;
$lastRefFingerprint = null;

while (true) {
    $env = loadPosEnvVars();
    $host = $env['host'];
    $isClient = !in_array($host, ['', '127.0.0.1', 'localhost', '::1'], true);

    if (!$isClient) {
        // Server o installazione indipendente: niente da fare.
        sleep(SNAP_IDLE_SECONDS);
        continue;
    }
    if ($env['fallback_origin_host'] !== '') {
        // La cassa e' in fallback locale: lo snapshot deve stare fermo, comanda
        // il push (vedi commento in testa). Si riattiva da solo quando
        // api/push_local_sales.php azzera FALLBACK_ORIGIN_HOST.
        sleep(SNAP_PARKED_SECONDS);
        continue;
    }

    $server = connectDb($host, $env);
    $local = connectDb('127.0.0.1', $env, 2);
    if (!$server || !$local) {
        if (!$server) { snapLog("server $host irraggiungibile, riprovo tra " . SNAP_RETRY_SECONDS . "s."); }
        if (!$local) { snapLog('DB locale irraggiungibile, riprovo tra ' . SNAP_RETRY_SECONDS . 's.'); }
        if ($server) { $server->close(); }
        if ($local) { $local->close(); }
        sleep(SNAP_RETRY_SECONDS);
        continue;
    }

    try {
        // Il catalogo del server e' cambiato dall'ultimo giro?
        $verRow = $server->query(
            'SELECT COALESCE(UNIX_TIMESTAMP(MAX(updated_at)), 0) AS v, COUNT(*) AS c FROM stock WHERE is_active = 1'
        )->fetch_assoc();
        $stockVersion = ($verRow['v'] ?? '0') . ':' . ($verRow['c'] ?? '0');

        $now = time();
        $doFull = ($now - $lastFullAt) >= SNAP_FULL_SECONDS;
        $stockChanged = $lastStockVersion === null || $stockVersion !== $lastStockVersion;

        $refFingerprint = refTablesFingerprint($server);
        $refChanged = $lastRefFingerprint === null || $refFingerprint !== $lastRefFingerprint;

        if ($doFull) {
            foreach (SNAP_TABLES as $t) {
                snapshotTable($server, $local, $t);
            }
            $lastFullAt = $now;
            $lastStockVersion = $stockVersion;
            $lastRefFingerprint = $refFingerprint;
            setAppConfig($local, 'snapshot_last_ok', (string) $now);
        } else {
            $didAny = false;
            if ($stockChanged) {
                snapshotTable($server, $local, 'stock');
                $lastStockVersion = $stockVersion;
                $didAny = true;
            }
            if ($refChanged) {
                // Config stampanti / scontrino cambiata sul centrale: riallinea
                // subito la copia locale (e' quella usata al fallback).
                foreach (SNAP_REF_TABLES as $t) {
                    snapshotTable($server, $local, $t);
                }
                $lastRefFingerprint = $refFingerprint;
                $didAny = true;
            }
            if ($didAny) {
                setAppConfig($local, 'snapshot_last_ok', (string) $now);
            }
        }
    } catch (Throwable $e) {
        snapLog('errore durante lo snapshot (tengo la copia precedente): ' . $e->getMessage());
    } finally {
        $server->close();
        $local->close();
    }

    sleep(SNAP_TICK_SECONDS);
}
