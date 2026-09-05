<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/get_db_connection.php';

// Stessa auto-migrazione idempotente usata in api/stampanti.php e api/chiudi_cassa.php:
// qui serve perché la query per cassa fa JOIN su casse_stampanti.fondo_cassa/ultima_chiusura.
$connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS fondo_cassa DECIMAL(10,2) NOT NULL DEFAULT 0");
$connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS ultima_chiusura DATETIME NULL DEFAULT NULL");

// Ricezione parametri POST
$from = $_POST['from'] ?? '';
$to = $_POST['to'] ?? '';
$cassa = $_POST['cassa'] ?? '';

if (!isset($_POST['from']) || !isset($_POST['to'])) {
    echo json_encode(['error' => 'Parametri non ricevuti']);
    exit;
}

function runFilteredQuery(mysqli $connectionDB, string $sql, string $from, string $to, string $cassa): array {
    $stmt = $connectionDB->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Errore preparazione query: ' . $connectionDB->error);
    }
    $stmt->bind_param('ssss', $from, $to, $cassa, $cassa);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function determineBucketType(string $from, string $to): string {
    try {
        $fromDt = new DateTime($from);
        $toDt = new DateTime($to);
    } catch (Exception $e) {
        return 'day';
    }
    $hours = ($toDt->getTimestamp() - $fromDt->getTimestamp()) / 3600;
    if ($hours <= 0) {
        return 'day';
    }
    if ($hours <= 48) {
        return 'hour';
    }
    if ($hours <= 60 * 24) {
        return 'day';
    }
    if ($hours <= 365 * 24) {
        return 'week';
    }
    return 'month';
}

function formatBucketLabel(string $key, string $bucketType): string {
    $mesiIt = [
        '01' => 'Gen', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'Mag', '06' => 'Giu',
        '07' => 'Lug', '08' => 'Ago', '09' => 'Set', '10' => 'Ott', '11' => 'Nov', '12' => 'Dic'
    ];
    switch ($bucketType) {
        case 'hour':
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $key);
            return $dt ? $dt->format('d/m H:00') : $key;
        case 'week':
            $parts = explode('-W', $key);
            return count($parts) === 2 ? ('Sett. ' . $parts[1] . '/' . $parts[0]) : $key;
        case 'month':
            $parts = explode('-', $key);
            return count($parts) === 2 ? (($mesiIt[$parts[1]] ?? $parts[1]) . ' ' . $parts[0]) : $key;
        case 'day':
        default:
            $dt = DateTime::createFromFormat('Y-m-d', $key);
            return $dt ? $dt->format('d/m') : $key;
    }
}

try {
    // Query 1: dettaglio prodotti
    $queryProdotti = "
        SELECT
            d.prodotto,
            SUM(d.quantita) AS quantita,
            SUM(d.totale) AS totale
        FROM dettagli_vendita d
        JOIN vendite v ON d.vendita_id = v.id
        WHERE v.data_ora BETWEEN ? AND ?
          AND v.stornato = 0
          AND (? = '' OR UPPER(v.cassa_id) = UPPER(?))
        GROUP BY d.prodotto
        ORDER BY SUM(d.totale) DESC";

    $dati = runFilteredQuery($connectionDB, $queryProdotti, $from, $to, $cassa);

    $totale_complessivo = 0;
    foreach ($dati as $row) {
        $totale_complessivo += (float)$row['totale'];
    }

    // Query 2: riepilogo per metodo di pagamento + sconti
    $querySconto = "
        SELECT COALESCE(metodo_pagamento, 'ND') AS metodo_pag, SUM(totale) AS totale, SUM(sconto) AS sconti
        FROM vendite
        WHERE data_ora BETWEEN ? AND ?
          AND stornato = 0
          AND (? = '' OR UPPER(cassa_id) = UPPER(?))
        GROUP BY COALESCE(metodo_pagamento, 'ND')";

    $rigeSconto = runFilteredQuery($connectionDB, $querySconto, $from, $to, $cassa);

    $sconti = 0;
    $tot_contanti = 0;
    $tot_carta = 0;
    $tot_satispay = 0;
    $tot_nd = 0;
    foreach ($rigeSconto as $rowSconto) {
        $sconti += (float)$rowSconto['sconti'];
        $metodoPag = $rowSconto['metodo_pag'];
        if ($metodoPag == "contanti") {
            $tot_contanti += (float)$rowSconto['totale'];
        } else if ($metodoPag == "carta") {
            $tot_carta += (float)$rowSconto['totale'];
        } else if ($metodoPag == "elettronico") {
            $tot_satispay += (float)$rowSconto['totale'];
        } else {
            $tot_nd += (float)$rowSconto['totale'];
        }
    }

    $totale_complessivo = $totale_complessivo - $sconti;

    // Query 3: numero ordini totali + valore medio ordine
    $queryOrdini = "
        SELECT COUNT(*) AS ordini_totali
        FROM vendite
        WHERE data_ora BETWEEN ? AND ?
          AND stornato = 0
          AND (? = '' OR UPPER(cassa_id) = UPPER(?))";

    $ordiniRows = runFilteredQuery($connectionDB, $queryOrdini, $from, $to, $cassa);
    $ordini_totali = (int)($ordiniRows[0]['ordini_totali'] ?? 0);
    $valore_medio_ordine = $ordini_totali > 0 ? $totale_complessivo / $ordini_totali : 0.0;

    // Query 4: riepilogo per cassa (sempre su TUTTE le casse del periodo, a prescindere
    // dal filtro cassa attivo, così selezionare una cassa nella sidebar non fa sparire
    // le altre dall'elenco)
    $queryCasse = "
        SELECT COALESCE(NULLIF(TRIM(v.cassa_id), ''), 'N/D') AS cassa,
               SUM(v.totale) AS totale,
               SUM(CASE WHEN v.metodo_pagamento = 'contanti' THEN v.totale ELSE 0 END) AS contanti,
               COUNT(*) AS ordini,
               MAX(cs.fondo_cassa) AS fondo_cassa,
               MAX(cs.ultima_chiusura) AS ultima_chiusura
        FROM vendite v
        LEFT JOIN casse_stampanti cs ON cs.cassa_id = v.cassa_id
        WHERE v.data_ora BETWEEN ? AND ?
          AND v.stornato = 0
          AND (? = '' OR UPPER(v.cassa_id) = UPPER(?))
        GROUP BY COALESCE(NULLIF(TRIM(v.cassa_id), ''), 'N/D')
        ORDER BY totale DESC";

    $casseRows = runFilteredQuery($connectionDB, $queryCasse, $from, $to, '');
    $casse = array_map(function ($row) {
        $fondoCassa = (float)($row['fondo_cassa'] ?? 0);
        $contanti = (float)$row['contanti'];
        return [
            'cassa' => $row['cassa'],
            'totale' => (float)$row['totale'],
            'ordini' => (int)$row['ordini'],
            'fondo_cassa' => $fondoCassa,
            'ultima_chiusura' => $row['ultima_chiusura'],
            'totale_atteso' => $fondoCassa + $contanti
        ];
    }, $casseRows);

    // Query 5: andamento ricavi nel tempo (bucket automatico in base al range)
    $bucketType = determineBucketType($from, $to);
    switch ($bucketType) {
        case 'hour':
            $bucketExpr = "DATE_FORMAT(data_ora, '%Y-%m-%d %H:00:00')";
            break;
        case 'week':
            $bucketExpr = "DATE_FORMAT(data_ora, '%x-W%v')";
            break;
        case 'month':
            $bucketExpr = "DATE_FORMAT(data_ora, '%Y-%m')";
            break;
        case 'day':
        default:
            $bucketExpr = "DATE(data_ora)";
            break;
    }

    $queryAndamento = "
        SELECT {$bucketExpr} AS bucket_key, MIN(data_ora) AS bucket_sort,
               COALESCE(metodo_pagamento, 'ND') AS metodo_pag,
               SUM(totale) AS totale, COUNT(*) AS ordini
        FROM vendite
        WHERE data_ora BETWEEN ? AND ?
          AND stornato = 0
          AND (? = '' OR UPPER(cassa_id) = UPPER(?))
        GROUP BY bucket_key, metodo_pag
        ORDER BY bucket_sort ASC";

    $andamentoRows = runFilteredQuery($connectionDB, $queryAndamento, $from, $to, $cassa);

    $buckets = [];
    foreach ($andamentoRows as $row) {
        $key = $row['bucket_key'];
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'label' => formatBucketLabel($key, $bucketType),
                'ricavo' => 0.0,
                'ordini' => 0,
                'contanti' => 0.0,
                'carta' => 0.0,
                'elettronico' => 0.0,
                'nd' => 0.0
            ];
        }
        $rowTotale = (float)$row['totale'];
        $buckets[$key]['ricavo'] += $rowTotale;
        $buckets[$key]['ordini'] += (int)$row['ordini'];
        switch ($row['metodo_pag']) {
            case 'contanti':
                $buckets[$key]['contanti'] += $rowTotale;
                break;
            case 'carta':
                $buckets[$key]['carta'] += $rowTotale;
                break;
            case 'elettronico':
                $buckets[$key]['elettronico'] += $rowTotale;
                break;
            default:
                $buckets[$key]['nd'] += $rowTotale;
                break;
        }
    }
    $andamento = array_values($buckets);

    // Risposta JSON
    echo json_encode([
        'vendite' => $dati,
        'totale_complessivo' => $totale_complessivo,
        'sconti' => $sconti,
        'tot_contanti' => $tot_contanti,
        'tot_carta' => $tot_carta,
        'tot_satispay' => $tot_satispay,
        'tot_nd' => $tot_nd,
        'ordini_totali' => $ordini_totali,
        'valore_medio_ordine' => $valore_medio_ordine,
        'casse' => $casse,
        'andamento' => $andamento,
        'bucket_type' => $bucketType
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Errore durante il recupero delle statistiche: ' . $e->getMessage()]);
    exit;
} finally {
    $connectionDB->close();
}
