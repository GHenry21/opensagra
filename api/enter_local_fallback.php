<?php
/**
 * Fase 4 punto 4 - "Fallback locale una-via + push a chiusura cassa".
 *
 * Chiamato dal client (pages/billing.php) quando il server centrale resta
 * irraggiungibile oltre la soglia (~2-3 min): sposta DB_POS_HOST su 127.0.0.1
 * e salva il vecchio host in FALLBACK_ORIGIN_HOST. Da quel momento la cassa
 * lavora sul proprio MariaDB locale (alimentato dallo snapshot periodico,
 * bin/opensagra-snapshot.php) e ci resta finche' non si preme "Chiudi Cassa",
 * che ricarica le vendite sul centrale (api/push_local_sales.php).
 *
 * Una sola direzione: nessun ritorno automatico alla rete durante la serata.
 *
 * POST, nessun body. Risposte:
 *   200 {success:true, origin:"<ip server>"}        swap eseguito
 *   200 {success:true, already:true, origin:"..."}  gia' in fallback (idempotente)
 *   409 {error:"..."}                               non e' in modalita' rete
 *   422 {error:"..."}                               DB locale non pronto (snapshot mai fatto)
 *   500 {error:"..."}                               scrittura variabili.env fallita
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/env_writer.php';
require_once __DIR__ . '/../config/app_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non supportato.']);
    exit;
}

$env = loadPosEnvVars();

// Gia' in fallback: non fare nulla, rispondi ok (il client puo' richiamare
// questo endpoint piu' volte prima che il reload faccia effetto).
if ($env['fallback_origin_host'] !== '') {
    echo json_encode(['success' => true, 'already' => true, 'origin' => $env['fallback_origin_host']]);
    exit;
}

$currentHost = $env['host'];
$isLocal = in_array($currentHost, ['', '127.0.0.1', 'localhost', '::1'], true);
if ($isLocal) {
    http_response_code(409);
    echo json_encode(['error' => 'Questa installazione non e\' in modalita\' rete: non c\'e\' un server da cui ripiegare.']);
    exit;
}

// Guardia anti-DB-morto: passare al locale ha senso solo se lo snapshot lo ha
// gia' popolato almeno una volta. Un locale vuoto/irraggiungibile e' peggio che
// continuare a ritentare il server.
try {
    $local = mysqli_init();
    $local->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    $ok = @$local->real_connect('127.0.0.1', $env['user'], $env['pass'], $env['db']);
    if (!$ok) {
        http_response_code(422);
        echo json_encode(['error' => 'DB locale non raggiungibile: resto in attesa del server centrale.']);
        exit;
    }

    $snapshotOk = getAppConfig($local, 'snapshot_last_ok', '') !== '';
    $stockRows = 0;
    if ($res = $local->query('SELECT COUNT(*) AS n FROM stock')) {
        $stockRows = (int) ($res->fetch_assoc()['n'] ?? 0);
    }
    $local->close();

    if (!$snapshotOk || $stockRows === 0) {
        http_response_code(422);
        echo json_encode(['error' => 'DB locale non pronto (snapshot mai eseguito): resto in attesa del server centrale.']);
        exit;
    }
} catch (mysqli_sql_exception $e) {
    http_response_code(422);
    echo json_encode(['error' => 'DB locale non pronto: ' . $e->getMessage()]);
    exit;
}

// Ordine: prima salva l'origine (il debito) e marca la sessione come decisa
// dal sistema, poi sposta DB_POS_HOST. Se il processo muore a meta', al giro
// dopo i marker sono gia' scritti e questo endpoint (ramo "already") completa
// il quadro senza danni. FALLBACK_SESSION_ACTIVE=1 e' cio' che permette a
// push_local_sales.php di distinguere questo caso (automatico, si aspetta di
// tornare in rete da solo) da un domani switch manuale (l'operatore decide
// lui, vedi env_reader.php).
if (!setEnvValue($env['env_file'], 'FALLBACK_ORIGIN_HOST', $currentHost)
    || !setEnvValue($env['env_file'], 'FALLBACK_SESSION_ACTIVE', '1')
    || !setEnvValue($env['env_file'], 'DB_POS_HOST', '127.0.0.1')) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossibile scrivere config/variabili.env (permessi file?).']);
    exit;
}

echo json_encode(['success' => true, 'origin' => $currentHost]);
