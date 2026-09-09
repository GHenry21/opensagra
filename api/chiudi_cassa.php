<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/get_db_connection.php';
require_once __DIR__ . '/../config/env_reader.php';

// Stesse colonne auto-migrate anche in api/stampanti.php: qui si ripete la stessa
// ALTER TABLE idempotente per non dipendere dall'ordine in cui le pagine vengono aperte.
function ensureFondoCassaColumn($connectionDB) {
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS fondo_cassa DECIMAL(10,2) NOT NULL DEFAULT 0");
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS ultima_chiusura DATETIME NULL DEFAULT NULL");
}

try {
    ensureFondoCassaColumn($connectionDB);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo di richiesta non supportato.']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $cassa_id = trim((string)($input['cassa_id'] ?? ''));
    if ($cassa_id === '') {
        echo json_encode(['error' => 'Cassa ID mancante.']);
        exit;
    }

    $stmt = $connectionDB->prepare("SELECT fondo_cassa FROM casse_stampanti WHERE cassa_id = ? LIMIT 1");
    $stmt->bind_param('s', $cassa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $config = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $cassaEsistente = $config !== null;
    $fondo_cassa = $config ? (float)$config['fondo_cassa'] : 0;

    $stmt = $connectionDB->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN metodo_pagamento = 'contanti' THEN totale ELSE 0 END), 0) AS totale_contanti,
            COALESCE(SUM(totale), 0) AS totale_vendite
        FROM vendite
        WHERE cassa_id = ? AND stornato = 0 AND DATE(data_ora) = CURDATE()
    ");
    $stmt->bind_param('s', $cassa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $totali = $result ? $result->fetch_assoc() : ['totale_contanti' => 0, 'totale_vendite' => 0];
    $stmt->close();

    $totale_contanti = (float)$totali['totale_contanti'];
    $totale_vendite = (float)$totali['totale_vendite'];

    // Fase 4 punto 4: se la cassa sta girando in fallback locale, la sidebar
    // usa questi campi per lanciare subito il push al centrale (api/push_local_sales.php)
    // e, se il centrale e' ancora giu', mostrare il bottone persistente
    // "N vendite da sincronizzare".
    $env = loadPosEnvVars();
    $fallback_active = $env['fallback_origin_host'] !== '';
    $pending_sync = 0;
    if ($fallback_active) {
        $res = $connectionDB->query("SELECT COUNT(*) AS n FROM vendite WHERE da_sincronizzare = 1 AND pushed_at IS NULL");
        $pending_sync = $res ? (int)($res->fetch_assoc()['n'] ?? 0) : 0;
    }

    // casse_stampanti non ha un vincolo UNIQUE su cassa_id (la deduplica è gestita
    // lato applicativo in api/stampanti.php), quindi qui si sceglie esplicitamente
    // tra UPDATE e INSERT invece di usare "ON DUPLICATE KEY" per evitare righe doppie.
    $ultima_chiusura = date('Y-m-d H:i:s');
    if ($cassaEsistente) {
        $stmt = $connectionDB->prepare("UPDATE casse_stampanti SET ultima_chiusura = ? WHERE cassa_id = ?");
        $stmt->bind_param('ss', $ultima_chiusura, $cassa_id);
    } else {
        // Cassa mai configurata in conf_casse.php (cassa_id è testo libero scelto
        // in billing.php): si crea comunque una riga minima per poter registrare la chiusura.
        $stmt = $connectionDB->prepare("INSERT INTO casse_stampanti (cassa_id, tipo_stampante, ultima_chiusura) VALUES (?, 'NON_CONFIGURATA', ?)");
        $stmt->bind_param('ss', $cassa_id, $ultima_chiusura);
    }
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'success' => true,
        'cassa_id' => $cassa_id,
        'fondo_cassa' => $fondo_cassa,
        'totale_contanti' => $totale_contanti,
        'totale_vendite' => $totale_vendite,
        'totale_atteso' => $fondo_cassa + $totale_contanti,
        'ultima_chiusura' => $ultima_chiusura,
        'fallback_active' => $fallback_active,
        'pending_sync' => $pending_sync
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore Server: ' . $e->getMessage()]);
}
