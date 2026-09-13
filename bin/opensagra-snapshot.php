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
 * COPIA CALDA DEGLI ORDINI (Fase 4 punto 4, seguito 2026-09-14): a ogni giro
 * leggero (~10s, indipendente dal catalogo sopra) copia anche gli ordini di
 * OGGI in `vendite_mirror` - tabella locale separata, di sola lettura, mai
 * guardata dalla logica di push/decremento. Serve solo al pannello "Ordini" di
 * billing.php: durante un fallback, `vendite` locale ha solo le vendite fatte
 * DA quel momento in poi (mai quelle precedenti, per design - vedi sotto),
 * quindi senza questa copia il pannello le mostrerebbe come sparite. Nessuna
 * condizione di "pronto/non pronto": e' solo una comodita' di consultazione,
 * non autorizza nessuno swap (a differenza di snapshot_last_ok).
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
 *
 * DIFESE (dopo l'incidente del 2026-09-10, catalogo azzerato):
 *  - guardia "stessa istanza": se DB_POS_HOST punta allo stesso MariaDB del DB
 *    locale (IP di LAN / hostname invece di 127.0.0.1) non si copia nulla - si
 *    leggerebbe una tabella appena droppata.
 *  - snapshotTable() e' stage-then-swap: costruisce `<t>__snapnew` e fa
 *    `RENAME TABLE` atomico solo a copia completa; su errore la live resta
 *    intatta.
 *  - guardia anti-collasso: non sostituisce una copia locale non vuota con una
 *    vuota (SNAPSHOT_ALLOW_EMPTY=1 per forzare).
 *  - snapshot_last_ok si scrive solo se `stock` locale e' non vuota.
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

function ensureOrdersMirrorTable(mysqli $local): void
{
    $local->query(
        'CREATE TABLE IF NOT EXISTS vendite_mirror ('
        . ' id INT NOT NULL PRIMARY KEY,'
        . ' cassa_id VARCHAR(50) DEFAULT NULL,'
        . ' data_ora DATETIME DEFAULT NULL,'
        . ' totale DECIMAL(10,2) DEFAULT NULL,'
        . ' sconto DECIMAL(10,2) DEFAULT NULL,'
        . ' importo_pagato DECIMAL(10,2) DEFAULT NULL,'
        . ' resto DECIMAL(10,2) DEFAULT NULL,'
        . ' metodo_pagamento VARCHAR(50) DEFAULT NULL,'
        . ' stornato INT(1) NOT NULL DEFAULT 0,'
        . ' n_articoli INT NOT NULL DEFAULT 0'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/**
 * Fingerprint economico degli ordini di OGGI sul server: `COUNT`+`MAX(id)`
 * colgono i nuovi ordini, `SUM(stornato)` coglie anche uno storno fatto su un
 * ordine gia' esistente (che da solo non cambierebbe ne' il conteggio ne' il
 * max id). `vendite` non ha una colonna `updated_at` (a differenza di
 * `stock`), quindi non si puo' riusare lo stesso trucco - da qui l'aggregato
 * a parte invece del confronto di versione visto sopra.
 */
function ordersMirrorFingerprint(mysqli $server): string
{
    $row = $server->query(
        'SELECT COUNT(*) AS c, COALESCE(MAX(id),0) AS max_id, COALESCE(SUM(stornato),0) AS storni'
        . ' FROM vendite WHERE DATE(data_ora) = CURDATE()'
    )->fetch_assoc();
    return ($row['c'] ?? '0') . ':' . ($row['max_id'] ?? '0') . ':' . ($row['storni'] ?? '0');
}

/**
 * Copia calda, di sola lettura, degli ordini di OGGI dal server in locale
 * (`vendite_mirror`). Tabella separata dalla `vendite` locale "vera" (quella
 * delle vendite fatte in fallback): mai un id in comune, zero rischio di
 * confusione per api/push_local_sales.php, che non la guarda mai.
 *
 * Sostituzione integrale (`DELETE` + reinsert in un'unica transazione) e non
 * lo stage-then-swap di snapshotTable(): questa tabella la creiamo e la
 * gestiamo solo noi (nessun drift di schema possibile, a differenza delle
 * tabelle applicative che seguono le migrazioni di opensagra), quindi non
 * serve la protezione contro uno schema locale disallineato.
 *
 * @return int ordini copiati
 */
function syncOrdersMirror(mysqli $server, mysqli $local): int
{
    ensureOrdersMirrorTable($local);

    $rows = [];
    $res = $server->query(
        'SELECT v.id, v.cassa_id, v.data_ora, v.totale, v.sconto, v.importo_pagato, v.resto,'
        . '        v.metodo_pagamento, v.stornato,'
        . '        (SELECT COALESCE(SUM(d.quantita), 0) FROM dettagli_vendita d WHERE d.vendita_id = v.id) AS n_articoli'
        . '   FROM vendite v'
        . '  WHERE DATE(v.data_ora) = CURDATE()'
    );
    if (!$res) {
        throw new RuntimeException('SELECT vendite (mirror ordini) fallita: ' . $server->error);
    }
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }

    $local->begin_transaction();
    try {
        $local->query('DELETE FROM vendite_mirror');
        if ($rows) {
            $ins = $local->prepare(
                'INSERT INTO vendite_mirror'
                . ' (id, cassa_id, data_ora, totale, sconto, importo_pagato, resto, metodo_pagamento, stornato, n_articoli)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$ins) {
                throw new RuntimeException('prepare INSERT vendite_mirror fallita: ' . $local->error);
            }
            foreach ($rows as $r) {
                // mysqli::execute(array) dal PHP 8.1, come snapshotTable().
                $ins->execute([
                    $r['id'], $r['cassa_id'], $r['data_ora'], $r['totale'], $r['sconto'],
                    $r['importo_pagato'], $r['resto'], $r['metodo_pagamento'], $r['stornato'], $r['n_articoli'],
                ]);
            }
            $ins->close();
        }
        $local->commit();
    } catch (Throwable $e) {
        try { $local->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }

    snapLog('copiati ' . count($rows) . ' ordini di oggi nel mirror locale');
    return count($rows);
}

// "Impronta" dell'istanza MariaDB dietro una connessione. Serve a riconoscere
// il caso "il server remoto e' in realta' il DB locale" (stesso MariaDB
// raggiunto via IP di LAN / hostname invece di 127.0.0.1): li' `server` e
// `local` sono la stessa tabella e lo snapshot finirebbe per copiarsela da se'.
//
// MariaDB non ha `@@server_uuid` (e' di MySQL) e `@@server_id` spesso vale 0 di
// default: usiamo hostname + porta + datadir, byte-identici sulle due
// connessioni verso lo stesso mysqld e diversi tra macchine diverse (il
// computer name Windows cambia sempre, il datadir quasi sempre).
function mariadbInstanceFingerprint(mysqli $c): string
{
    try {
        $res = $c->query('SELECT @@hostname AS h, @@port AS p, @@datadir AS d, @@server_id AS s');
        $r = $res ? $res->fetch_assoc() : null;
        if (!$r) {
            return '';
        }
        return implode('|', [$r['h'] ?? '', $r['p'] ?? '', $r['d'] ?? '', $r['s'] ?? '']);
    } catch (mysqli_sql_exception $e) {
        return '';
    }
}

// Conteggio righe di una tabella locale. Tabella inesistente (prima copia in
// assoluto) o errore -> 0, non deve mai far fallire una guardia.
function localRowCount(mysqli $local, string $table): int
{
    try {
        $res = $local->query("SELECT COUNT(*) AS n FROM `$table`");
        return $res ? (int) ($res->fetch_assoc()['n'] ?? 0) : 0;
    } catch (mysqli_sql_exception $e) {
        return 0;
    }
}

// La copia locale e' utilizzabile per il fallback? `stock` vuota = catalogo
// inservibile: non certificarla con snapshot_last_ok (e' l'ancora di fiducia di
// api/enter_local_fallback.php). SNAPSHOT_ALLOW_EMPTY=1 per un catalogo
// legittimamente vuoto.
function localCatalogUsable(mysqli $local): bool
{
    return localRowCount($local, 'stock') > 0 || getenv('SNAPSHOT_ALLOW_EMPTY') === '1';
}

// Scrive snapshot_last_ok solo se la copia locale e' davvero utilizzabile.
// enter_local_fallback.php si fida di questo marker per autorizzare lo swap sul
// DB locale: non deve certificare un `stock` vuoto.
function markSnapshotOk(mysqli $local, int $now): void
{
    if (localCatalogUsable($local)) {
        setAppConfig($local, 'snapshot_last_ok', (string) $now);
        return;
    }
    snapLog('NON aggiorno snapshot_last_ok: `stock` locale vuota dopo la copia - catalogo non utilizzabile per il fallback (SNAPSHOT_ALLOW_EMPTY=1 per accettarlo).');
}

/**
 * Rende la tabella locale una copia esatta di quella del server, SENZA mai
 * lasciarla in uno stato intermedio: costruisce una `<table>__snapnew` a lato
 * (DDL dal `SHOW CREATE TABLE` del server + reinsert delle righe) e solo a
 * copia completa fa lo swap atomico con `RENAME TABLE`. Se qualcosa va storto
 * prima dello swap, la tabella live non e' stata toccata - il "tengo la copia
 * precedente" del loop torna vero.
 *
 * Perche' ricreare da zero e non un DELETE + INSERT: il DB locale di un client
 * non viene usato in esercizio normale, quindi il suo schema puo' essere
 * vecchio / non allineato alle migrazioni (drift reale visto sulla VM:
 * `casse_stampanti` senza `bridge_host`). Queste sono cache di sola lettura
 * lato client, nessuna FK entrante (solo dettagli_vendita->vendite, non toccate).
 *
 * Guardia anti-collasso: se il server ha 0 righe ma la copia locale ne ha,
 * NON sostituisce (sorgente verosimilmente rotta / puntata all'istanza
 * sbagliata). SNAPSHOT_ALLOW_EMPTY=1 per forzare un catalogo davvero vuoto.
 *
 * @return int righe copiate
 */
function snapshotTable(mysqli $server, mysqli $local, string $table): int
{
    $ddlRow = $server->query("SHOW CREATE TABLE `$table`");
    $ddl = $ddlRow ? $ddlRow->fetch_assoc() : null;
    $createSql = $ddl['Create Table'] ?? '';
    if (!str_starts_with($createSql, 'CREATE TABLE')) {
        throw new RuntimeException("SHOW CREATE TABLE `$table` inattesa sul server");
    }

    $cols = [];
    $colsRes = $server->query("SHOW COLUMNS FROM `$table`");
    while ($colsRes && $c = $colsRes->fetch_assoc()) {
        $cols[] = $c['Field'];
    }
    if (!$cols) {
        throw new RuntimeException("tabella `$table` senza colonne?");
    }

    // Righe dal server PRIMA di toccare qualunque cosa in locale.
    $rows = [];
    $dataRes = $server->query("SELECT * FROM `$table`");
    if (!$dataRes) {
        throw new RuntimeException("SELECT `$table` fallita: " . $server->error);
    }
    while ($r = $dataRes->fetch_assoc()) {
        $rows[] = $r;
    }

    // Guardia anti-collasso: mai rimpiazzare una copia locale non vuota con una
    // vuota. Un catalogo che passa da N a 0 in un colpo non e' mai legittimo in
    // esercizio.
    $localBefore = localRowCount($local, $table);
    if (count($rows) === 0 && $localBefore > 0 && getenv('SNAPSHOT_ALLOW_EMPTY') !== '1') {
        throw new RuntimeException(
            "RIFIUTO lo snapshot di `$table`: il server ne ha 0 righe ma la copia locale ne ha "
            . "$localBefore - sorgente inaffidabile, tengo la copia locale "
            . "(SNAPSHOT_ALLOW_EMPTY=1 per forzare)"
        );
    }

    // Tabella sostituta costruita a lato: la live resta intatta fino allo swap.
    $stage = $table . '__snapnew';
    $stageCreate = preg_replace(
        '/^CREATE TABLE `' . preg_quote($table, '/') . '`/',
        "CREATE TABLE `$stage`",
        $createSql,
        1,
        $nSub
    );
    if ($stageCreate === null || $nSub !== 1) {
        throw new RuntimeException("impossibile derivare il DDL di staging per `$table`");
    }

    $local->query('SET FOREIGN_KEY_CHECKS = 0');
    $local->query("DROP TABLE IF EXISTS `$stage`");
    if (!$local->query($stageCreate)) {
        $local->query('SET FOREIGN_KEY_CHECKS = 1');
        throw new RuntimeException("CREATE `$stage` locale fallita: " . $local->error);
    }

    $inTx = false;
    try {
        if ($rows) {
            $colList = '`' . implode('`,`', $cols) . '`';
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $ins = $local->prepare("INSERT INTO `$stage` ($colList) VALUES ($placeholders)");
            if (!$ins) {
                throw new RuntimeException("prepare INSERT `$stage` locale fallita: " . $local->error);
            }
            $local->begin_transaction();
            $inTx = true;
            foreach ($rows as $r) {
                // mysqli::execute(array) - dal PHP 8.1: niente bind_param/tipi,
                // MariaDB coerce ogni valore (string|null) nella colonna giusta.
                $values = [];
                foreach ($cols as $c) {
                    $values[] = $r[$c] ?? null;
                }
                if (!$ins->execute($values)) {
                    throw new RuntimeException("INSERT in `$stage` locale fallita: " . $ins->error);
                }
            }
            $ins->close();
            $local->commit();
            $inTx = false;
        }

        // Swap atomico. La tabella `$table` esiste prima e dopo (o e' la prima
        // copia in assoluto, e allora non c'e' nulla da rimpiazzare).
        $old = $table . '__snapold';
        $local->query("DROP TABLE IF EXISTS `$old`");
        $liveExists = ($lr = $local->query("SHOW TABLES LIKE '$table'")) && $lr->num_rows > 0;
        if ($liveExists) {
            if (!$local->query("RENAME TABLE `$table` TO `$old`, `$stage` TO `$table`")) {
                throw new RuntimeException("RENAME swap `$table` fallita: " . $local->error);
            }
            $local->query("DROP TABLE IF EXISTS `$old`");
        } else {
            if (!$local->query("RENAME TABLE `$stage` TO `$table`")) {
                throw new RuntimeException("RENAME `$stage` -> `$table` fallita: " . $local->error);
            }
        }
    } catch (Throwable $e) {
        if ($inTx) {
            try { $local->rollback(); } catch (Throwable $ignored) {}
        }
        try { $local->query("DROP TABLE IF EXISTS `$stage`"); } catch (Throwable $ignored) {}
        $local->query('SET FOREIGN_KEY_CHECKS = 1');
        throw $e;
    }

    $local->query('SET FOREIGN_KEY_CHECKS = 1');
    snapLog("copiata `$table` (" . count($rows) . " righe)");
    return count($rows);
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
$lastOrdersFingerprint = null;

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

    // Guardia "stessa istanza": se DB_POS_HOST punta (via IP di LAN, hostname,
    // alias...) allo stesso MariaDB del DB locale, `server` e `local` sono la
    // stessa tabella: snapshotTable() la leggerebbe DOPO averla droppata ->
    // catalogo azzerato. Non c'e' comunque niente da copiare da se' a se'.
    $serverId = mariadbInstanceFingerprint($server);
    $localId = mariadbInstanceFingerprint($local);
    if ($serverId !== '' && $serverId === $localId) {
        snapLog("DB_POS_HOST ($host) e' la STESSA istanza MariaDB del DB locale: niente da snapshottare (evito di sovrascrivere le mie stesse tabelle di riferimento). Idle.");
        $server->close();
        $local->close();
        sleep(SNAP_IDLE_SECONDS);
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
            markSnapshotOk($local, $now);
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
                markSnapshotOk($local, $now);
            }
        }

        // Copia calda degli ordini di oggi - indipendente dal catalogo sopra,
        // gira a ogni giro leggero (mai legata a $doFull ne' a
        // snapshot_last_ok: e' solo una comodita' di consultazione per il
        // pannello "Ordini", non una condizione per autorizzare il fallback).
        $ordersFingerprint = ordersMirrorFingerprint($server);
        if ($lastOrdersFingerprint === null || $ordersFingerprint !== $lastOrdersFingerprint) {
            syncOrdersMirror($server, $local);
            $lastOrdersFingerprint = $ordersFingerprint;
        }
    } catch (Throwable $e) {
        snapLog('errore durante lo snapshot (tengo la copia precedente): ' . $e->getMessage());
    } finally {
        $server->close();
        $local->close();
    }

    sleep(SNAP_TICK_SECONDS);
}
