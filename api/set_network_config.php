<?php
/**
 * Cambia DB_POS_HOST in config/variabili.env dopo aver verificato che la
 * connessione funzioni davvero: non scrive mai un host che romperebbe
 * l'app al giro successivo.
 *
 * POST JSON: { "mode": "indipendente" } oppure { "mode": "client", "host": "192.168.x.x" }
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/env_writer.php';
require_once __DIR__ . '/../config/app_config.php';

$data = json_decode(file_get_contents('php://input'), true);
$mode = $data['mode'] ?? '';

if (!in_array($mode, ['indipendente', 'client'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Modalità non valida.']);
    exit;
}

$targetHost = $mode === 'indipendente' ? '127.0.0.1' : trim((string) ($data['host'] ?? ''));

if ($mode === 'client' && $targetHost === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Indica l\'indirizzo del server.']);
    exit;
}

$env = loadPosEnvVars();

// Segreto Mercure da propagare in variabili.env (Fase 4, Opzione A):
//  - client       -> lo si LEGGE dal DB del server (tabella app_config) e lo
//                    si salva come MERCURE_JWT_SECRET_REMOTE, cosi' relay e
//                    bridge firmano token che l'hub del server accetta, senza
//                    copia a mano. Vuoto se il server non l'ha (ancora)
//                    pubblicato: si resta sul polling, nessun errore.
//  - indipendente -> si AZZERA il _REMOTE (non c'e' piu' un server) e si
//                    pubblica il proprio segreto locale nel DB, cosi' i
//                    prossimi client lo trovano.
$remoteSecret = '';

// Verifica la connessione PRIMA di scrivere: mai salvare un host che non
// risponde, altrimenti l'app resta rotta finché non si torna qui a mano.
try {
    $conn = mysqli_init();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    $ok = @$conn->real_connect($targetHost, $env['user'], $env['pass'], $env['db']);
    if (!$ok) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => "Impossibile collegarsi a '$targetHost' con le credenziali attuali. Verifica indirizzo, rete e che l'altra installazione sia raggiungibile.",
        ]);
        exit;
    }
    if ($mode === 'client') {
        $remoteSecret = getAppConfig($conn, 'MERCURE_JWT_SECRET', '');
    } elseif ($env['mercure_jwt_secret'] !== '') {
        setAppConfig($conn, 'MERCURE_JWT_SECRET', $env['mercure_jwt_secret']);
    }
    $conn->close();
} catch (mysqli_sql_exception $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Connessione fallita: ' . $e->getMessage()]);
    exit;
}

if (!setEnvValue($env['env_file'], 'DB_POS_HOST', $targetHost)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Connessione riuscita ma impossibile salvare la configurazione (permessi file?).']);
    exit;
}

// Uno switch manuale dall'operatore chiude qualunque fallback locale automatico
// pendente (Fase 4 punto 4): da qui in poi comanda la scelta esplicita. Va
// fatto a servizio chiuso, dopo aver sincronizzato le eventuali vendite locali
// da "Chiudi Cassa".
setEnvValue($env['env_file'], 'FALLBACK_ORIGIN_HOST', '');

// client -> scrive il segreto del server (anche vuoto: azzera un valore
// stantio di una sessione precedente); indipendente -> azzera.
setEnvValue($env['env_file'], 'MERCURE_JWT_SECRET_REMOTE', $mode === 'client' ? $remoteSecret : '');

echo json_encode([
    'success' => true,
    'host' => $targetHost,
    'mode' => $mode,
    // il client sa che il realtime cross-macchina e' pronto solo se ha
    // ricevuto il segreto; se false, resta sul polling finche' il server non
    // lo pubblica (una qualsiasi modifica prodotti lato server basta).
    'mercure_secret_synced' => $mode === 'client' ? ($remoteSecret !== '') : true,
]);
