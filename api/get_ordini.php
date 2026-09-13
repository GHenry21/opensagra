<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/get_db_connection.php';
require_once __DIR__ . '/../config/env_reader.php';

$cassa_id = trim((string)($_GET['cassa_id'] ?? ''));
$limit    = (int)($_GET['limit'] ?? 20);
$qRaw     = trim((string)($_GET['q'] ?? ''));

if ($cassa_id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parametro cassa_id mancante']);
    $connectionDB->close();
    exit;
}

if ($limit < 1) {
    $limit = 20;
} elseif ($limit > 200) {
    $limit = 200;
}

// La ricerca "q" filtra per numero ordine: se non e' un intero valido si restituisce lista vuota
$searchId = ($qRaw !== '' && ctype_digit($qRaw)) ? (int)$qRaw : null;
if ($qRaw !== '' && $searchId === null) {
    echo json_encode(['ordini' => []]);
    $connectionDB->close();
    exit;
}

/**
 * Ordini da una tabella (`vendite`, quella vera, oppure `vendite_mirror`, la
 * copia calda di sola lettura degli ordini pre-fallback - vedi
 * bin/opensagra-snapshot.php::syncOrdersMirror). `n_articoli` viene da una
 * subquery su `dettagli_vendita` solo per `vendite`: `vendite_mirror` lo porta
 * gia' come colonna piatta, non ha i dettagli riga per riga in locale.
 */
function fetchOrdersFrom(mysqli $db, string $table, string $cassaId, ?int $searchId, int $limit): array
{
    $nArticoliExpr = $table === 'vendite'
        ? '(SELECT COALESCE(SUM(d.quantita), 0) FROM dettagli_vendita d WHERE d.vendita_id = v.id)'
        : 'v.n_articoli';

    $sql = "
        SELECT v.id, v.data_ora, v.totale, v.sconto, v.importo_pagato, v.resto,
               v.metodo_pagamento, v.stornato, $nArticoliExpr AS n_articoli
        FROM `$table` v
        WHERE v.cassa_id = ?
    ";
    if ($table === 'vendite_mirror') {
        // vendite_mirror contiene sempre e solo "gli ordini di oggi" per come
        // viene scritta (bin/opensagra-snapshot.php::syncOrdersMirror) - ma se
        // il nodo e' stato Client un altro giorno e poi e' tornato
        // Indipendente, la tabella puo' ancora avere righe di quel giorno
        // passato (non viene mai ripulita a uno switch). Il filtro qui e' la
        // difesa: non mostrare mai storico non-di-oggi da questa fonte,
        // qualunque cosa ci sia rimasta dentro.
        $sql .= ' AND DATE(v.data_ora) = CURDATE() ';
    }
    if ($searchId !== null) {
        $sql .= " AND v.id = ? ";
    }
    $sql .= " ORDER BY v.id DESC LIMIT ? ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
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
            'id'               => (int)$row['id'],
            'data_ora'         => (string)$row['data_ora'],
            'totale'           => (float)$row['totale'],
            'sconto'           => (float)$row['sconto'],
            'importo_pagato'   => (float)$row['importo_pagato'],
            'resto'            => (float)$row['resto'],
            'metodo_pagamento' => $row['metodo_pagamento'] !== null ? (string)$row['metodo_pagamento'] : '',
            'stornato'         => (int)$row['stornato'],
            'n_articoli'       => (int)$row['n_articoli'],
        ];
    }
    $stmt->close();
    return $rows;
}

$ordini = fetchOrdersFrom($connectionDB, 'vendite', $cassa_id, $searchId, $limit);

// Fase 4 punto 4 (seguito 2026-09-14): durante un fallback il DB attivo e' il
// locale, la cui `vendite` ha SOLO le vendite fatte da quel momento in poi -
// mai quelle precedenti, per design (vedi bin/opensagra-snapshot.php, `vendite`
// non viene mai copiata dal centrale). Si aggiunge qui la copia calda
// `vendite_mirror` (gli ordini di oggi, tenuta fresca dallo snapshot mentre si
// era ancora in rete) cosi' il pannello "Ordini" di billing.php non li mostra
// come spariti durante il fallback.
//
// Va letta SOLO quando serve davvero, non ogni volta che il DB attivo e' il
// locale - un'installazione Indipendente "vera" (mai stata in fallback, o
// tornata Indipendente dopo un giro pulito da Client) ha DB_POS_HOST locale
// SEMPRE, senza che questo significhi nulla di simile a un fallback. Il
// marcatore giusto e' fallback_origin_host (il "debito" del Pezzo 1): non
// vuoto solo se questo nodo sta gestendo, o ha ancora in sospeso, un fallback
// vero. Cosi' il caso "Client ieri, Indipendente pulito oggi, vendite_mirror
// mai ripulita" non arriva nemmeno a guardare il mirror - il filtro per data
// sotto resta comunque come seconda difesa, non l'unica.
$env = loadPosEnvVars();
$isLocalDb = in_array($env['host'], ['', '127.0.0.1', 'localhost', '::1'], true);
$hasFallbackDebt = $env['fallback_origin_host'] !== '';
if ($isLocalDb && $hasFallbackDebt) {
    try {
        $mirrorOrdini = fetchOrdersFrom($connectionDB, 'vendite_mirror', $cassa_id, $searchId, $limit);
    } catch (Throwable $e) {
        // vendite_mirror puo' non esistere ancora (installazione mai stata
        // client, o mai arrivata al primo giro di sincronizzazione): nessun
        // ordine pre-fallback da aggiungere, non e' un errore da segnalare.
        $mirrorOrdini = [];
    }
    if ($mirrorOrdini) {
        // Le vendite vere fatte in fallback e gli ordini pre-fallback vivono
        // in tabelle separate apposta (mai lo stesso id per costruzione): si
        // uniscono e si ordina per data, l'operatore vede la cronologia
        // intera della giornata, non solo quella di dopo il fallback.
        $ordini = array_merge($ordini, $mirrorOrdini);
        usort($ordini, fn($a, $b) => strcmp($b['data_ora'], $a['data_ora']));
        $ordini = array_slice($ordini, 0, $limit);
    }
}

echo json_encode(['ordini' => $ordini]);
$connectionDB->close();
