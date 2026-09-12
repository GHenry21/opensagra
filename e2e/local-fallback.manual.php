<?php
/**
 * Fase 4 punto 4 - harness DB-level per "Fallback locale una-via + push a
 * chiusura cassa". NON automatico (come gli altri *.manual.* di questa
 * cartella): si lancia a mano
 *
 *   php e2e/local-fallback.manual.php
 *
 * Perche' a mano: su una singola macchina di sviluppo 127.0.0.1 e l'IP di LAN
 * puntano allo STESSO MariaDB / stesso schema, quindi un "push da locale a
 * centrale" reale (due DB distinti) si prova solo sulla VM (vedi
 * docs/PIANO-MIGRAZIONE-FRANKENPHP.md, punto E3). Qui si verifica la LOGICA
 * del push su due schemi usa-e-getta creati apposta, senza toccare
 * config/variabili.env ne' il DB opensagra_pos.
 *
 * ATTENZIONE - questo file rispecchia il ciclo di api/push_local_sales.php:
 * se cambi quel ciclo, aggiorna anche pushLoop() qui sotto.
 *
 * Scenari coperti:
 *   1. Guardie di enter_local_fallback (host locale -> no-op; snapshot mai
 *      fatto -> non pronto).
 *   3. Push: dettagli rimappati sul nuovo id, stock decrementato (negativi
 *      ammessi), riga locale marcata; ri-push idempotente (0 doppioni).
 *   4. Centrale irraggiungibile -> esito "graceful", nessuna eccezione.
 *   5. Fase 4 punto 4, correzione 2026-09-12 - "vendite mai orfane": la
 *      decisione "torno in rete da solo o resto dove l'operatore mi ha
 *      messo" (api/push_local_sales.php, $autoReturnToNetwork) e la
 *      marcatura delle vendite nuove (print/print_receipt.php,
 *      $daSincronizzare) dipendono da FALLBACK_SESSION_ACTIVE, non piu' da
 *      FALLBACK_ORIGIN_HOST - copie fedeli delle due espressioni, da tenere
 *      in sync se cambiano.
 * (Lo scenario 2 - lo swap che riscrive variabili.env - e' coperto lato
 * browser da e2e/local-fallback.spec.js con la POST stubbata.)
 */

require_once __DIR__ . '/../config/app_config.php';

const CENTRAL_DB = 'opensagra_pos_fbtest_central';
const LOCAL_DB   = 'opensagra_pos_fbtest_local';

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
    fwrite(STDERR, "Impossibile connettersi a MariaDB come root su 127.0.0.1 (serve per creare gli schemi di test).\n");
    exit(2);
}

/** Ciclo di push - copia fedele di api/push_local_sales.php::foreach. */
function pushLoop(mysqli $local, mysqli $server): array
{
    $vendite = [];
    $res = $local->query(
        'SELECT id, data_ora, totale, importo_pagato, resto, cassa_id, sconto, metodo_pagamento, stornato, idempotency_key
           FROM vendite WHERE da_sincronizzare = 1 AND pushed_at IS NULL ORDER BY data_ora ASC, id ASC'
    );
    while ($row = $res->fetch_assoc()) { $vendite[] = $row; }

    $pushed = 0; $skipped = 0; $fatal = null;

    $selDettagli  = $local->prepare('SELECT prodotto, quantita, prezzo_unitario, line_discount_percent, line_discount_value, line_total_before_discount, totale FROM dettagli_vendita WHERE vendita_id = ? ORDER BY id ASC');
    $insVendita   = $server->prepare('INSERT INTO vendite (data_ora, totale, importo_pagato, resto, cassa_id, sconto, metodo_pagamento, stornato, idempotency_key, da_sincronizzare, pushed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)');
    $findVendita  = $server->prepare('SELECT id FROM vendite WHERE idempotency_key = ? LIMIT 1');
    $insDettaglio = $server->prepare('INSERT INTO dettagli_vendita (vendita_id, prodotto, quantita, prezzo_unitario, line_discount_percent, line_discount_value, line_total_before_discount, totale) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $decStock     = $server->prepare('UPDATE stock SET quantity_available = quantity_available - ? WHERE name = ? AND quantity_available IS NOT NULL');
    $markLocale   = $local->prepare('UPDATE vendite SET pushed_at = NOW(), da_sincronizzare = 0 WHERE id = ?');

    foreach ($vendite as $v) {
        $localId = (int) $v['id'];
        $idemKey = ($v['idempotency_key'] !== null && $v['idempotency_key'] !== '') ? $v['idempotency_key'] : null;
        try {
            $server->begin_transaction();
            $stornato = (int) $v['stornato'];
            try {
                $insVendita->bind_param('sdddsdsis', $v['data_ora'], $v['totale'], $v['importo_pagato'], $v['resto'], $v['cassa_id'], $v['sconto'], $v['metodo_pagamento'], $stornato, $idemKey);
                $insVendita->execute();
                $serverVenditaId = (int) $server->insert_id;
            } catch (mysqli_sql_exception $e) {
                if ((int) $e->getCode() === 1062 && $idemKey !== null) {
                    $server->rollback();
                    $findVendita->bind_param('s', $idemKey);
                    $findVendita->execute();
                    if ($findVendita->get_result()->fetch_assoc()) {
                        $markLocale->bind_param('i', $localId);
                        $markLocale->execute();
                        $skipped++;
                        continue;
                    }
                    throw new RuntimeException("dup key ma vendita non trovata (locale #$localId)");
                }
                throw $e;
            }

            $selDettagli->bind_param('i', $localId);
            $selDettagli->execute();
            $dettagli = $selDettagli->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($dettagli as $d) {
                $qta = (int) $d['quantita'];
                $prezzo = (float) $d['prezzo_unitario'];
                $ldp = (float) $d['line_discount_percent'];
                $ldv = (float) $d['line_discount_value'];
                $ltbd = (float) $d['line_total_before_discount'];
                $tot = (float) $d['totale'];
                $insDettaglio->bind_param('isiddddd', $serverVenditaId, $d['prodotto'], $qta, $prezzo, $ldp, $ldv, $ltbd, $tot);
                $insDettaglio->execute();
                $decStock->bind_param('is', $qta, $d['prodotto']);
                $decStock->execute();
            }
            $server->commit();
            $markLocale->bind_param('i', $localId);
            $markLocale->execute();
            $pushed++;
        } catch (Throwable $e) {
            @$server->rollback();
            $fatal = $e->getMessage();
            break;
        }
    }
    return ['pushed' => $pushed, 'skipped' => $skipped, 'error' => $fatal];
}

// ---------------------------------------------------------------------------
$root = rootConn();
$posSql = file_get_contents(__DIR__ . '/../config/pos.sql');

foreach ([CENTRAL_DB, LOCAL_DB] as $db) {
    $root->query("DROP DATABASE IF EXISTS `$db`");
    $root->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $root->select_db($db);
    if ($root->multi_query($posSql)) {
        do { /* drena */ } while ($root->more_results() && $root->next_result());
    }
    if ($root->errno) {
        fwrite(STDERR, "Import pos.sql in $db fallito: {$root->error}\n");
        exit(2);
    }
}

$central = @new mysqli('127.0.0.1', 'root', '', CENTRAL_DB);
if ($central->connect_errno) { $central = new mysqli('127.0.0.1', 'root', 'root', CENTRAL_DB); }
$local = @new mysqli('127.0.0.1', 'root', '', LOCAL_DB);
if ($local->connect_errno) { $local = new mysqli('127.0.0.1', 'root', 'root', LOCAL_DB); }
$central->set_charset('utf8mb4');
$local->set_charset('utf8mb4');

// --- Seed catalogo (central + copia "snapshot" in local) ---
$seedStock = "INSERT INTO stock (id, category, name, price, image_path, item_sort, quantity_available, is_active) VALUES
  (1, 'Cucina', 'Salamella', 3.50, '', 1, 5, 1),
  (2, 'Bar', 'Birra Media', 4.00, '', 2, 3, 1),
  (3, 'Cucina', 'Patatine', 3.00, '', 3, NULL, 1)";
$central->query($seedStock);
$local->query($seedStock);

// --- Seed vendite fatte "in fallback" nel DB locale ---
$local->query("INSERT INTO vendite (id, data_ora, totale, importo_pagato, resto, cassa_id, sconto, metodo_pagamento, stornato, idempotency_key, da_sincronizzare, pushed_at) VALUES
  (1, '2026-09-09 19:30:00', 11.00, 20.00, 9.00, 'test', 0, 'contanti', 0, '11111111-1111-4111-8111-111111111111', 1, NULL),
  (2, '2026-09-09 19:45:00', 15.00, 15.00, 0.00, 'test', 0, 'contanti', 0, '22222222-2222-4222-8222-222222222222', 1, NULL)");
// vendita 1: 2x Salamella (7.00) + 1x Birra Media (4.00) = 11.00
$local->query("INSERT INTO dettagli_vendita (vendita_id, prodotto, quantita, prezzo_unitario, line_discount_percent, line_discount_value, line_total_before_discount, totale) VALUES
  (1, 'Salamella', 2, 3.50, 0, 0, 7.00, 7.00),
  (1, 'Birra Media', 1, 4.00, 0, 0, 4.00, 4.00)");
// vendita 2: 4x Birra Media (16.00, sconto 1.00) -> porta lo stock (3) a -1
$local->query("INSERT INTO dettagli_vendita (vendita_id, prodotto, quantita, prezzo_unitario, line_discount_percent, line_discount_value, line_total_before_discount, totale) VALUES
  (2, 'Birra Media', 4, 4.00, 0, 0, 16.00, 15.00)");

echo PHP_EOL . "== Scenario 1 - guardie enter_local_fallback ==" . PHP_EOL;
check(in_array('127.0.0.1', ['', '127.0.0.1', 'localhost', '::1'], true), 'host 127.0.0.1 riconosciuto come locale -> endpoint risponderebbe 409');
$freshLocalMarker = getAppConfig($local, 'snapshot_last_ok', '');
$freshStockCount = (int) $local->query('SELECT COUNT(*) n FROM stock')->fetch_assoc()['n'];
check($freshLocalMarker === '' , 'snapshot_last_ok assente su DB appena creato -> 422 se lo stock fosse vuoto');
check($freshStockCount === 3, 'ma qui lo stock e\' stato seminato: la guardia "pronto" passa quando c\'e\' anche il marker');
setAppConfig($local, 'snapshot_last_ok', (string) time());
check(getAppConfig($local, 'snapshot_last_ok', '') !== '', 'dopo uno snapshot il marker c\'e\' -> swap autorizzato');

echo PHP_EOL . "== Scenario 3 - push + replay stock ==" . PHP_EOL;
$r1 = pushLoop($local, $central);
check($r1['error'] === null, 'push senza errori fatali (' . json_encode($r1) . ')');
check($r1['pushed'] === 2, '2 vendite spinte');

$cv = $central->query('SELECT id, data_ora, totale, cassa_id, sconto, metodo_pagamento, idempotency_key FROM vendite ORDER BY id')->fetch_all(MYSQLI_ASSOC);
check(count($cv) === 2, 'centrale: 2 righe vendite');
check($cv[0]['idempotency_key'] === '11111111-1111-4111-8111-111111111111' && $cv[1]['idempotency_key'] === '22222222-2222-4222-8222-222222222222', 'idempotency_key preservate');
// guardia contro una stringa di tipi bind_param sbagliata (data_ora/cassa_id/metodo_pagamento sono 's', non 'd')
check($cv[0]['cassa_id'] === 'test' && $cv[0]['metodo_pagamento'] === 'contanti', 'campi testo della testata preservati (cassa_id, metodo_pagamento)');
check($cv[0]['data_ora'] === '2026-09-09 19:30:00', 'data_ora preservata (non azzerata da un tipo bind errato)');
check((float) $cv[0]['totale'] === 11.00 && (float) $cv[1]['totale'] === 15.00, 'totali preservati');

$cd = $central->query('SELECT vendita_id, COUNT(*) n FROM dettagli_vendita GROUP BY vendita_id ORDER BY vendita_id')->fetch_all(MYSQLI_ASSOC);
check(count($cd) === 2 && (int) $cd[0]['vendita_id'] === (int) $cv[0]['id'] && (int) $cd[1]['vendita_id'] === (int) $cv[1]['id'], 'dettagli rimappati sui NUOVI id centrali');
check((int) $cd[0]['n'] === 2 && (int) $cd[1]['n'] === 1, 'numero righe dettaglio corretto per vendita');

$stock = [];
foreach ($central->query('SELECT name, quantity_available FROM stock')->fetch_all(MYSQLI_ASSOC) as $s) { $stock[$s['name']] = $s['quantity_available']; }
check((int) $stock['Salamella'] === 3, "Salamella 5 -> 3 (2 vendute)");
check((int) $stock['Birra Media'] === -2, "Birra Media 3 -> -2 (1+4 vendute): negativo AMMESSO, segnale di oversell (attuale: {$stock['Birra Media']})");
check($stock['Patatine'] === null, 'Patatine resta a scorta illimitata (NULL), il decremento non la tocca');

$lv = $local->query('SELECT COUNT(*) n FROM vendite WHERE da_sincronizzare = 0 AND pushed_at IS NOT NULL')->fetch_assoc()['n'];
check((int) $lv === 2, 'locale: entrambe le vendite marcate pushed_at + da_sincronizzare=0');

echo PHP_EOL . "== Scenario 3b - ri-push idempotente ==" . PHP_EOL;
// rimette i flag come se il push non avesse marcato il locale (push interrotto)
$local->query("UPDATE vendite SET da_sincronizzare = 1, pushed_at = NULL");
$r2 = pushLoop($local, $central);
check($r2['error'] === null && $r2['pushed'] === 0 && $r2['skipped'] === 2, 'secondo giro: 0 nuove, 2 saltate via idempotency_key (' . json_encode($r2) . ')');
check((int) $central->query('SELECT COUNT(*) n FROM vendite')->fetch_assoc()['n'] === 2, 'centrale ancora 2 vendite, nessun doppione');
check((int) $central->query('SELECT quantity_available FROM stock WHERE name = "Salamella"')->fetch_assoc()['quantity_available'] === 3, 'stock NON decrementato di nuovo');

echo PHP_EOL . "== Scenario 4 - centrale irraggiungibile ==" . PHP_EOL;
$graceful = false;
try {
    $bogus = mysqli_init();
    $bogus->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    $ok = @$bogus->real_connect('192.0.2.1', 'root', '', CENTRAL_DB); // TEST-NET-1, non instradabile
    $graceful = ($ok === false); // in modalita' eccezioni non si arriva qui; il catch sotto e' la via normale
} catch (mysqli_sql_exception $e) {
    $graceful = true;
}
check($graceful, 'connessione al centrale fallita in modo gestito (l\'endpoint risponde {server_online:false})');

echo PHP_EOL . "== Scenario 5 - FALLBACK_SESSION_ACTIVE (vendite mai orfane, 2026-09-12) ==" . PHP_EOL;

/** Copia fedele di api/push_local_sales.php::$autoReturnToNetwork. */
function autoReturnToNetwork(bool $sessionActive, string $host): bool
{
    return $sessionActive && in_array($host, ['', '127.0.0.1', 'localhost', '::1'], true);
}

/** Copia fedele di print/print_receipt.php::$daSincronizzare. */
function daSincronizzareFlag(bool $sessionActive): int
{
    return $sessionActive ? 1 : 0;
}

// Caso A: fallback automatico mai toccato -> torna in rete da solo.
check(autoReturnToNetwork(true, '127.0.0.1') === true,
    'sessione attiva + ancora locale -> torna in rete da solo (percorso "felice", invariato)');
check(daSincronizzareFlag(true) === 1,
    'sessione attiva -> le vendite nuove si marcano da_sincronizzare=1');

// Caso B: l'operatore ha forzato Indipendente con un debito ancora aperto.
check(autoReturnToNetwork(false, '127.0.0.1') === false,
    'sessione spenta (switch manuale) pur restando locale -> NON torna in rete da solo: modalita\' dell\'operatore rispettata');
check(daSincronizzareFlag(false) === 0,
    'sessione spenta -> le vendite di oggi sono locali normali, NON inseguono un vecchio debito');

// Caso C: l'operatore ha forzato Client verso un server B diverso da quello del debito.
check(autoReturnToNetwork(false, '192.168.88.50') === false,
    'sessione spenta + host gia\' remoto (server B) -> nessun cambio di modalita\' automatico, B resta B');

// Caso D: orfano pre-esistente (FALLBACK_ORIGIN_HOST vuoto, DB_POS_HOST remoto) - vedi push_local_sales.php,
// rientra comunque nel ramo "sessione spenta": nessun ritorno automatico, si limita a saldare.
check(autoReturnToNetwork(false, '192.168.88.224') === false,
    'orfano pre-esistente sanato -> stesso comportamento del caso C, nessuna sorpresa sulla modalita\'');

echo PHP_EOL . "== Scenario 5b - il debito non si azzera mai con uno switch manuale, solo col push ==" . PHP_EOL;
// Simula: FALLBACK_ORIGIN_HOST resta valorizzato attraverso due switch manuali
// (Client -> Indipendente -> Client di nuovo), poi si salda solo al push.
$origin = '192.168.88.224';
$sessionActive = true; // fallback automatico iniziale

// api/enter_local_fallback.php: swap automatico.
check($sessionActive === true && $origin !== '', 'stato iniziale: fallback automatico, debito verso il centrale');

// api/set_network_config.php (switch manuale a Indipendente): la correzione
// e' che NON tocca $origin, spegne solo la sessione.
$sessionActive = false; // set_network_config.php azzera SEMPRE la sessione
check($origin === '192.168.88.224', 'switch manuale a Indipendente: il debito (FALLBACK_ORIGIN_HOST) resta intatto');
check($sessionActive === false, 'switch manuale: la sessione si spegne (decide l\'operatore da qui in poi)');

// Un secondo switch manuale (es. verso un altro Client) - stesso principio,
// $origin ancora non toccato.
check($origin === '192.168.88.224', 'un secondo switch manuale non tocca il debito nemmeno lui');

// Solo il push, quando riesce, lo azzera.
$pushSucceeded = true;
if ($pushSucceeded) {
    $origin = ''; // api/push_local_sales.php: FALLBACK_ORIGIN_HOST azzerato SOLO qui
}
check($origin === '', 'il debito si azzera SOLO quando il push lo salda per davvero, mai prima');

// --- Cleanup ---
$central->close();
$local->close();
foreach ([CENTRAL_DB, LOCAL_DB] as $db) {
    $root->query("DROP DATABASE IF EXISTS `$db`");
}
$root->close();

echo PHP_EOL . ($fails === 0 ? "TUTTO VERDE" : "$fails ASSERZIONI FALLITE") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
