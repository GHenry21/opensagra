<?php
/**
 * Fase 4 punto 4 - "Fallback locale una-via + push a chiusura cassa".
 *
 * Contropartita di api/enter_local_fallback.php: quando la cassa ha lavorato
 * sul MariaDB locale (FALLBACK_ORIGIN_HOST valorizzato), a "Chiudi Cassa"
 * questo endpoint ricarica sul server centrale le vendite fatte in locale
 * (da_sincronizzare=1 e pushed_at NULL) e ne replaya i decrementi di scorta.
 *
 * Dedup: ogni vendita porta il suo idempotency_key (Scalino 1); l'indice UNIQUE
 * uniq_vendite_idempotency_key sul centrale fa si' che un push interrotto e
 * ripreso non crei doppioni.
 *
 * ID: non si conservano gli id locali. L'INSERT sul centrale prende un nuovo
 * AUTO_INCREMENT e i dettagli vengono rimappati su quello (deciso: solo
 * idempotency_key, nessun node_id da configurare).
 *
 * Stock (punto D): decremento semplice, SENZA FOR UPDATE, negativi ammessi -
 * un valore negativo e' il segnale visibile dell'oversell fra casse durante il
 * buco; lo storno (funzione gia' esistente) lo sistema. Testata + dettagli +
 * decrementi di una vendita stanno in un'unica transazione sul centrale: se la
 * riga vendita c'e', c'e' tutto.
 *
 * A push completo: DB_POS_HOST torna all'IP del server, FALLBACK_ORIGIN_HOST
 * viene azzerato -> la cassa e' di nuovo in modalita' rete e lo snapshot
 * riparte.
 *
 * POST, nessun body. Risposte:
 *   200 {success:true,  pushed:N, skipped:M, server_online:true, back_to_network:true}
 *   200 {success:false, pushed:k, remaining:R, server_online:true, error:"..."}  parziale, ritentare
 *   200 {success:false, server_online:false, pending:N}                          centrale ancora giu'
 *   409 {error:"..."}                                                            non in modalita' locale
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/env_writer.php';
require_once __DIR__ . '/../config/get_db_connection.php'; // $connectionDB -> DB locale (in fallback DB_POS_HOST=127.0.0.1)
require_once __DIR__ . '/../config/mercure.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non supportato.']);
    exit;
}

$env = loadPosEnvVars();
$originHost = $env['fallback_origin_host'];

if ($originHost === '') {
    http_response_code(409);
    echo json_encode(['error' => 'Questa cassa non e\' in modalita\' locale: niente da sincronizzare.']);
    exit;
}

$local = $connectionDB; // alias leggibile (DB locale del nodo)

/** COUNT delle vendite ancora da spingere (per i messaggi di stato). */
function pendingCount(mysqli $db): int
{
    $res = $db->query('SELECT COUNT(*) AS n FROM vendite WHERE da_sincronizzare = 1 AND pushed_at IS NULL');
    return $res ? (int) ($res->fetch_assoc()['n'] ?? 0) : 0;
}

// --- 1. Il centrale risponde? ---
try {
    $server = mysqli_init();
    $server->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    $ok = @$server->real_connect($originHost, $env['user'], $env['pass'], $env['db']);
    if (!$ok) {
        echo json_encode(['success' => false, 'server_online' => false, 'pending' => pendingCount($local)]);
        exit;
    }
} catch (mysqli_sql_exception $e) {
    echo json_encode(['success' => false, 'server_online' => false, 'pending' => pendingCount($local)]);
    exit;
}
$server->set_charset('utf8mb4');

// --- 2. Vendite locali da spingere, in ordine cronologico ---
$vendite = [];
$res = $local->query(
    'SELECT id, data_ora, totale, importo_pagato, resto, cassa_id, sconto, metodo_pagamento, stornato, idempotency_key
       FROM vendite
      WHERE da_sincronizzare = 1 AND pushed_at IS NULL
      ORDER BY data_ora ASC, id ASC'
);
while ($res && $row = $res->fetch_assoc()) {
    $vendite[] = $row;
}

$pushed = 0;
$skipped = 0;      // gia' presenti sul centrale (push precedente interrotto)
$fatalError = null;

$selDettagli  = $local->prepare(
    'SELECT prodotto, quantita, prezzo_unitario, line_discount_percent, line_discount_value, line_total_before_discount, totale
       FROM dettagli_vendita WHERE vendita_id = ? ORDER BY id ASC'
);
$insVendita   = $server->prepare(
    'INSERT INTO vendite (data_ora, totale, importo_pagato, resto, cassa_id, sconto, metodo_pagamento, stornato, idempotency_key, da_sincronizzare, pushed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)'
);
$findVendita  = $server->prepare('SELECT id FROM vendite WHERE idempotency_key = ? LIMIT 1');
$insDettaglio = $server->prepare(
    'INSERT INTO dettagli_vendita (vendita_id, prodotto, quantita, prezzo_unitario, line_discount_percent, line_discount_value, line_total_before_discount, totale)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$decStock     = $server->prepare(
    'UPDATE stock SET quantity_available = quantity_available - ? WHERE name = ? AND quantity_available IS NOT NULL'
);
$markLocale   = $local->prepare('UPDATE vendite SET pushed_at = NOW(), da_sincronizzare = 0 WHERE id = ?');

foreach ($vendite as $v) {
    $localId = (int) $v['id'];
    $idemKey = ($v['idempotency_key'] !== null && $v['idempotency_key'] !== '') ? $v['idempotency_key'] : null;

    try {
        $server->begin_transaction();

        // 2a. Testata vendita sul centrale (nuovo AUTO_INCREMENT id)
        $stornato = (int) $v['stornato'];
        try {
            // tipi: data_ora s | totale d | importo_pagato d | resto d | cassa_id s
            //       | sconto d | metodo_pagamento s | stornato i | idempotency_key s
            $insVendita->bind_param(
                'sdddsdsis',
                $v['data_ora'], $v['totale'], $v['importo_pagato'], $v['resto'],
                $v['cassa_id'], $v['sconto'], $v['metodo_pagamento'], $stornato, $idemKey
            );
            $insVendita->execute();
            $serverVenditaId = (int) $server->insert_id;
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062 && $idemKey !== null) {
                // Gia' arrivata in un push precedente interrotto: l'intera
                // transazione di allora (testata + dettagli + stock) e' andata
                // a buon fine. Qui basta marcare la riga locale.
                $server->rollback();
                $findVendita->bind_param('s', $idemKey);
                $findVendita->execute();
                $found = $findVendita->get_result()->fetch_assoc();
                if ($found) {
                    $markLocale->bind_param('i', $localId);
                    $markLocale->execute();
                    $skipped++;
                    continue;
                }
                throw new RuntimeException("idempotency_key duplicata ma vendita non trovata sul centrale (locale #$localId)");
            }
            throw $e;
        }

        // 2b. Dettagli + replay decrementi scorta (punto D)
        $dettagli = [];
        $selDettagli->bind_param('i', $localId);
        $selDettagli->execute();
        $dettResult = $selDettagli->get_result();
        while ($d = $dettResult->fetch_assoc()) {
            $dettagli[] = $d;
        }

        foreach ($dettagli as $d) {
            $qta = (int) $d['quantita'];
            $prezzo = (float) $d['prezzo_unitario'];
            $ldp = (float) $d['line_discount_percent'];
            $ldv = (float) $d['line_discount_value'];
            $ltbd = (float) $d['line_total_before_discount'];
            $tot = (float) $d['totale'];
            $insDettaglio->bind_param(
                'isiddddd',
                $serverVenditaId, $d['prodotto'], $qta, $prezzo, $ldp, $ldv, $ltbd, $tot
            );
            $insDettaglio->execute();

            // Decremento "cieco": nessun FOR UPDATE, negativi ammessi.
            $decStock->bind_param('is', $qta, $d['prodotto']);
            $decStock->execute();
        }

        $server->commit();

        // 2c. Solo dopo il commit sul centrale: marca la riga locale.
        $markLocale->bind_param('i', $localId);
        $markLocale->execute();
        $pushed++;
    } catch (Throwable $e) {
        @$server->rollback();
        $fatalError = $e->getMessage();
        break; // push parziale: il resto si ritenta dal bottone "Sincronizza ora"
    }
}

$remaining = pendingCount($local);

// --- 3. Esito ---
if ($fatalError === null && $remaining === 0) {
    // Tutto spinto: torna in modalita' rete. Ordine speculare a
    // enter_local_fallback.php: prima ripristina DB_POS_HOST, poi azzera il
    // marker (se muore in mezzo, al giro dopo il marker c'e' ancora e questo
    // endpoint - trovando 0 vendite pendenti - completa comunque).
    setEnvValue($env['env_file'], 'DB_POS_HOST', $originHost);
    setEnvValue($env['env_file'], 'FALLBACK_ORIGIN_HOST', '');

    // Best-effort: avvisa le altre casse che lo stock e' cambiato. Non fatale
    // (publishProductsChanged non lancia e torna subito se l'hub non c'e').
    // A questo punto l'env e' gia' ripristinato -> mercureHubUrl() punta al
    // server e mercureSecretForHub() usa il segreto remoto, entrambi corretti.
    try {
        publishProductsChanged($server);
    } catch (Throwable $e) {
        error_log('push_local_sales: publishProductsChanged fallita (non fatale): ' . $e->getMessage());
    }

    $server->close();
    echo json_encode([
        'success' => true,
        'pushed' => $pushed,
        'skipped' => $skipped,
        'server_online' => true,
        'back_to_network' => true,
    ]);
    exit;
}

$server->close();
echo json_encode([
    'success' => false,
    'pushed' => $pushed,
    'skipped' => $skipped,
    'remaining' => $remaining,
    'server_online' => true,
    'error' => $fatalError ?? 'Alcune vendite non sono state sincronizzate.',
]);
