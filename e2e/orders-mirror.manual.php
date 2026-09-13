<?php
/**
 * Fase 4 punto 4 (seguito 2026-09-14) - harness DB-level per la "copia calda"
 * degli ordini (`vendite_mirror`, bin/opensagra-snapshot.php +
 * api/get_ordini.php). NON automatico:
 *
 *   php e2e/orders-mirror.manual.php
 *
 * Su due schemi usa-e-getta (centrale + locale), non tocca ne'
 * config/variabili.env ne' il DB opensagra_pos - stesso principio degli altri
 * *.manual.php di questa cartella.
 *
 * ATTENZIONE - questo file contiene una copia fedele di tre funzioni da
 * bin/opensagra-snapshot.php (ensureOrdersMirrorTable, ordersMirrorFingerprint,
 * syncOrdersMirror) e della funzione fetchOrdersFrom di api/get_ordini.php:
 * non si possono richiedere direttamente quei file (il primo ha un
 * `while (true)` a livello top, il secondo legge $_GET e apre la connessione
 * "vera" via get_db_connection.php) - se cambi quelle funzioni, aggiorna
 * anche le copie qui sotto.
 *
 * Copre: il fingerprint cattura nuovi ordini E storni su ordini esistenti
 * (non solo aggiunte), non cambia per un giro senza modifiche; syncOrdersMirror
 * copia i campi giusti (incluso n_articoli via dettagli_vendita) e SOSTITUISCE
 * per intero il contenuto locale (una riga sparita dal centrale sparisce anche
 * dal mirror, non resta un residuo); get_ordini unisce vendite locale (le
 * vendite vere fatte in fallback) e vendite_mirror (gli ordini pre-fallback),
 * ordinati per data, senza mai mischiare gli id delle due tabelle.
 */

const CENTRAL_DB = 'opensagra_pos_ordmirror_test_central';
const LOCAL_DB   = 'opensagra_pos_ordmirror_test_local';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$fails = 0;
function check(bool $cond, string $msg): void
{
    global $fails;
    echo ($cond ? "  OK   " : "  FAIL ") . $msg . PHP_EOL;
    if (!$cond) { $fails++; }
}

function rootConn(string $db = ''): mysqli
{
    foreach ([['root', ''], ['root', 'root']] as [$u, $p]) {
        try {
            $c = @new mysqli('127.0.0.1', $u, $p, $db);
            if (!$c->connect_errno) { return $c; }
        } catch (Throwable $e) { /* prova la prossima */ }
    }
    fwrite(STDERR, "Impossibile connettersi a MariaDB come root su 127.0.0.1.\n");
    exit(2);
}

// --- Copie fedeli da bin/opensagra-snapshot.php (vedi ATTENZIONE sopra) ---

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

function ordersMirrorFingerprint(mysqli $server): string
{
    $row = $server->query(
        'SELECT COUNT(*) AS c, COALESCE(MAX(id),0) AS max_id, COALESCE(SUM(stornato),0) AS storni'
        . ' FROM vendite WHERE DATE(data_ora) = CURDATE()'
    )->fetch_assoc();
    return ($row['c'] ?? '0') . ':' . ($row['max_id'] ?? '0') . ':' . ($row['storni'] ?? '0');
}

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
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }

    $local->begin_transaction();
    $local->query('DELETE FROM vendite_mirror');
    if ($rows) {
        $ins = $local->prepare(
            'INSERT INTO vendite_mirror'
            . ' (id, cassa_id, data_ora, totale, sconto, importo_pagato, resto, metodo_pagamento, stornato, n_articoli)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $ins->execute([
                $r['id'], $r['cassa_id'], $r['data_ora'], $r['totale'], $r['sconto'],
                $r['importo_pagato'], $r['resto'], $r['metodo_pagamento'], $r['stornato'], $r['n_articoli'],
            ]);
        }
        $ins->close();
    }
    $local->commit();
    return count($rows);
}

// --- Copia fedele da api/get_ordini.php (vedi ATTENZIONE sopra) ---

function fetchOrdersFrom(mysqli $db, string $table, string $cassaId, ?int $searchId, int $limit): array
{
    $nArticoliExpr = $table === 'vendite'
        ? '(SELECT COALESCE(SUM(d.quantita), 0) FROM dettagli_vendita d WHERE d.vendita_id = v.id)'
        : 'v.n_articoli';
    $sql = "SELECT v.id, v.data_ora, v.totale, v.sconto, v.importo_pagato, v.resto,
                   v.metodo_pagamento, v.stornato, $nArticoliExpr AS n_articoli
              FROM `$table` v WHERE v.cassa_id = ?";
    if ($table === 'vendite_mirror') { $sql .= ' AND DATE(v.data_ora) = CURDATE() '; }
    if ($searchId !== null) { $sql .= ' AND v.id = ? '; }
    $sql .= ' ORDER BY v.id DESC LIMIT ? ';
    $stmt = $db->prepare($sql);
    if ($searchId !== null) {
        $stmt->bind_param('sii', $cassaId, $searchId, $limit);
    } else {
        $stmt->bind_param('si', $cassaId, $limit);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'], 'data_ora' => (string)$row['data_ora'],
            'n_articoli' => (int)$row['n_articoli'],
        ];
    }
    $stmt->close();
    return $rows;
}

// ---------------------------------------------------------------------------
$root = rootConn();

$venditeSchema = "CREATE TABLE vendite (
  id INT AUTO_INCREMENT PRIMARY KEY, data_ora DATETIME, totale DECIMAL(10,2), importo_pagato DECIMAL(10,2),
  resto DECIMAL(10,2), cassa_id VARCHAR(50), sconto DECIMAL(10,2), metodo_pagamento VARCHAR(50),
  stornato INT(1) NOT NULL DEFAULT 0, idempotency_key VARCHAR(36), da_sincronizzare TINYINT(1) NOT NULL DEFAULT 0,
  pushed_at DATETIME NULL)";
$dettagliSchema = "CREATE TABLE dettagli_vendita (
  id INT AUTO_INCREMENT PRIMARY KEY, vendita_id INT, prodotto VARCHAR(100), quantita INT,
  prezzo_unitario DECIMAL(10,2), line_discount_percent DECIMAL(10,2), line_discount_value DECIMAL(10,2),
  line_total_before_discount DECIMAL(10,2), totale DECIMAL(10,2))";

foreach ([CENTRAL_DB, LOCAL_DB] as $db) {
    $root->query("DROP DATABASE IF EXISTS `$db`");
    $root->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
    $root->select_db($db);
    $root->query($venditeSchema);
    $root->query($dettagliSchema);
}

$central = rootConn(CENTRAL_DB);
$local = rootConn(LOCAL_DB);
$central->set_charset('utf8mb4');
$local->set_charset('utf8mb4');

$oggi = date('Y-m-d');

echo "== 1. Sync iniziale: due ordini di oggi sul centrale ==" . PHP_EOL;
$central->query("INSERT INTO vendite (id, data_ora, totale, cassa_id, sconto, metodo_pagamento, stornato) VALUES
  (101, '$oggi 18:00:00', 10.00, 'cassa1', 0, 'contanti', 0),
  (102, '$oggi 18:05:00', 20.00, 'cassa1', 0, 'contanti', 0)");
$central->query("INSERT INTO dettagli_vendita (vendita_id, prodotto, quantita) VALUES (101, 'Panino', 2), (102, 'Bibita', 3)");

$fp1 = ordersMirrorFingerprint($central);
$copied = syncOrdersMirror($central, $local);
check($copied === 2, "syncOrdersMirror copia 2 ordini (copiati: $copied)");

$mirrorRows = [];
$r = $local->query('SELECT * FROM vendite_mirror ORDER BY id');
while ($row = $r->fetch_assoc()) { $mirrorRows[] = $row; }
check(count($mirrorRows) === 2, 'vendite_mirror locale ha 2 righe');
check((int)$mirrorRows[0]['n_articoli'] === 2 && (int)$mirrorRows[1]['n_articoli'] === 3, 'n_articoli calcolato correttamente dai dettagli (2 e 3)');
check($mirrorRows[0]['cassa_id'] === 'cassa1', 'cassa_id preservato');

echo PHP_EOL . "== 2. Il fingerprint cambia per un nuovo ordine ==" . PHP_EOL;
$central->query("INSERT INTO vendite (id, data_ora, totale, cassa_id, sconto, metodo_pagamento, stornato) VALUES (103, '$oggi 18:10:00', 5.00, 'cassa1', 0, 'contanti', 0)");
$fp2 = ordersMirrorFingerprint($central);
check($fp1 !== $fp2, 'fingerprint cambiato dopo un nuovo ordine');

echo "== 3. Il fingerprint cambia ANCHE per uno storno su un ordine gia' esistente ==" . PHP_EOL;
$central->query('UPDATE vendite SET stornato = 1 WHERE id = 101');
$fp3 = ordersMirrorFingerprint($central);
check($fp2 !== $fp3, 'fingerprint cambiato dopo uno storno (SUM(stornato) lo cattura, COUNT/MAX(id) da soli no)');

echo "== 4. Un giro senza modifiche non cambia il fingerprint ==" . PHP_EOL;
$fp4 = ordersMirrorFingerprint($central);
check($fp3 === $fp4, 'fingerprint stabile se nulla e\' cambiato nel frattempo');

echo PHP_EOL . "== 5. Un nuovo sync sostituisce per intero (niente residui) ==" . PHP_EOL;
syncOrdersMirror($central, $local);
$after = (int) $local->query('SELECT COUNT(*) n FROM vendite_mirror')->fetch_assoc()['n'];
check($after === 3, "ora 3 ordini nel mirror (era 2, ne e' arrivato uno nuovo): $after");
$stornatoRow = $local->query('SELECT stornato FROM vendite_mirror WHERE id = 101')->fetch_assoc();
check((int)$stornatoRow['stornato'] === 1, 'lo storno si riflette nel mirror dopo il resync');

echo PHP_EOL . "== 6. get_ordini: unione vendite locale (fallback) + vendite_mirror (pre-fallback) ==" . PHP_EOL;
// Simula una vendita vera fatta DURANTE il fallback, sul DB locale.
$local->query("INSERT INTO vendite (id, data_ora, totale, cassa_id, sconto, metodo_pagamento, stornato, da_sincronizzare) VALUES
  (1, '$oggi 19:00:00', 8.00, 'cassa1', 0, 'contanti', 0, 1)");

$vere = fetchOrdersFrom($local, 'vendite', 'cassa1', null, 20);
$miste = fetchOrdersFrom($local, 'vendite_mirror', 'cassa1', null, 20);
check(count($vere) === 1, 'vendite locale: 1 vendita vera (quella fatta in fallback)');
check(count($miste) === 3, 'vendite_mirror: 3 ordini pre-fallback');

$uniti = array_merge($vere, $miste);
usort($uniti, fn($a, $b) => strcmp($b['data_ora'], $a['data_ora']));
check(count($uniti) === 4, 'unione: 4 ordini totali (1 vera + 3 pre-fallback)');
check($uniti[0]['data_ora'] === "$oggi 19:00:00", 'il piu recente (la vendita in fallback, 19:00) e\' primo dopo l\'ordinamento');
check($uniti[3]['data_ora'] === "$oggi 18:00:00", 'il piu vecchio (18:00, pre-fallback) resta ultimo');
// id=1 in locale e id=101 nel mirror: stessa "forma" di id ma tabelle diverse, mai confusi.
$idsVere = array_column($vere, 'id');
$idsMiste = array_column($miste, 'id');
check(in_array(1, $idsVere, true) && in_array(101, $idsMiste, true), 'gli id delle due tabelle restano distinti, nessuna confusione nel merge');

echo PHP_EOL . "== 7. Cassa senza ordini pre-fallback: solo vendite_mirror vuota, nessun errore ==" . PHP_EOL;
$vuoto = fetchOrdersFrom($local, 'vendite_mirror', 'cassa_mai_esistita', null, 20);
check($vuoto === [], 'nessun ordine per una cassa senza storico nel mirror, array vuoto pulito');

echo PHP_EOL . "== 8. Riga di un giorno diverso nel mirror: mai mostrata, anche se e' ancora li' ==" . PHP_EOL;
// Simula: il nodo e' stato Client IERI, il mirror non e' mai stato ripulito
// dopo essere tornato Indipendente - la riga vecchia resta fisicamente nella
// tabella (nessuno switch la cancella).
$local->query("INSERT INTO vendite_mirror (id, cassa_id, data_ora, totale, sconto, importo_pagato, resto, metodo_pagamento, stornato, n_articoli)
  VALUES (999, 'cassa1', '2020-01-01 10:00:00', 1.00, 0, 1.00, 0, 'contanti', 0, 1)");
$conOrfano = fetchOrdersFrom($local, 'vendite_mirror', 'cassa1', null, 20);
check(!in_array(999, array_column($conOrfano, 'id'), true), 'la riga di un giorno passato NON compare, anche se fisicamente presente nella tabella');
check(count($conOrfano) === 3, 'restano solo i 3 ordini di oggi, la riga orfana di ieri e\' esclusa dal filtro data');

// --- Cleanup ---
$central->close();
$local->close();
foreach ([CENTRAL_DB, LOCAL_DB] as $db) {
    $root->query("DROP DATABASE IF EXISTS `$db`");
}
$root->close();

echo PHP_EOL . ($fails === 0 ? 'TUTTO VERDE' : "$fails ASSERZIONI FALLITE") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
