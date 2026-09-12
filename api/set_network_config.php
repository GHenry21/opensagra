<?php
/**
 * Cambia DB_POS_HOST in config/variabili.env dopo aver verificato che la
 * connessione funzioni davvero: non scrive mai un host che romperebbe
 * l'app al giro successivo.
 *
 * POST JSON: { "mode": "indipendente" } oppure { "mode": "client", "host": "192.168.x.x" }
 *
 * Fase 4 punto 4 (correzione 2026-09-12): uno switch manuale qui NON tocca mai
 * FALLBACK_ORIGIN_HOST (il debito verso il centrale per le vendite fatte in
 * fallback) - si azzera solo quando api/push_local_sales.php lo salda per
 * davvero. Tocca invece sempre FALLBACK_SESSION_ACTIVE (lo spegne): da qui in
 * poi decide l'operatore, non il sistema. Vedi config/env_reader.php per il
 * dettaglio dei due marcatori.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/env_writer.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/catalog_backup.php';

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

// Backup non distruttivo e non bloccante del catalogo locale (Fase 4 punto 4,
// richiesta esplicita 2026-09-12): un nodo usato come indipendente puo' avere
// un catalogo proprio (stock/casse_stampanti/receipt_config), magari di una
// stagione intera, che bin/opensagra-snapshot.php sovrascriverebbe col
// catalogo del centrale al primo giro utile (~10s) - comportamento corretto
// in rete, ma non deve sparire senza lasciare traccia. Si salva QUI, prima
// dello switch, non dentro lo snapshot: se il locale e' vuoto non crea nulla.
// Un errore qui non deve MAI impedire lo switch (try/catch dedicato).
$catalogBackupId = null;
if ($mode === 'client') {
    try {
        $localForBackup = mysqli_init();
        $localForBackup->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
        if (@$localForBackup->real_connect('127.0.0.1', $env['user'], $env['pass'], $env['db'])) {
            $localForBackup->set_charset('utf8mb4');
            $catalogBackupId = backupCatalogTables(
                $localForBackup,
                CATALOG_BACKUP_TABLES,
                'passaggio a modalità client (verso ' . $targetHost . ')'
            );
            $localForBackup->close();
        }
    } catch (Throwable $e) {
        error_log('set_network_config: backup catalogo locale fallito (non bloccante): ' . $e->getMessage());
    }
}

if (!setEnvValue($env['env_file'], 'DB_POS_HOST', $targetHost)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Connessione riuscita ma impossibile salvare la configurazione (permessi file?).']);
    exit;
}

// Ripristino automatico e silenzioso del catalogo (Fase 4 punto 4, 2026-09-12):
// tornando a Indipendente, se esiste un salvataggio precedente (preso al
// passaggio a Client, sopra), lo si rimette a posto subito - righe intere,
// quantita' comprese - SENZA chiedere nulla e a PRESCINDERE dal debito
// vendite (sono due faccende indipendenti: questo tocca solo stock/casse/
// scontrino, mai vendite). Se il catalogo era vuoto in origine non c'e' nulla
// da ripristinare: resta cosi', deciso esplicitamente (meglio un catalogo
// pieno che uno svuotato di sorpresa - pulizia manuale da Gestione Prodotti
// se serve). Anche qui: un errore non deve mai impedire lo switch.
$catalogRestored = null;
if ($mode === 'indipendente') {
    try {
        $localForRestore = mysqli_init();
        $localForRestore->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
        if (@$localForRestore->real_connect('127.0.0.1', $env['user'], $env['pass'], $env['db'])) {
            $localForRestore->set_charset('utf8mb4');
            $lastBatch = mostRecentBackupBatch($localForRestore);
            if ($lastBatch !== null) {
                $result = restoreCatalogBackupBatch($localForRestore, $lastBatch['id']);
                if ($result['restored']) {
                    $catalogRestored = [
                        'created_at' => $lastBatch['created_at'],
                        'tables' => $result['restored'],
                        'safety_backup_id' => $result['safety_backup_id'],
                    ];
                }
            }
            $localForRestore->close();
        }
    } catch (Throwable $e) {
        error_log('set_network_config: ripristino catalogo locale fallito (non bloccante): ' . $e->getMessage());
    }
}

// FALLBACK_ORIGIN_HOST (il debito) NON si tocca qui - vedi il docblock in
// testa al file: si azzera solo a push completato. FALLBACK_SESSION_ACTIVE
// invece si spegne sempre: da questo istante la modalita' e' una scelta
// esplicita dell'operatore, il sistema non deve piu' agire di testa sua ne'
// marcare le vendite nuove per l'invio ne' cambiare la modalita' da solo
// quando un vecchio debito verra' saldato (vedi print_receipt.php e
// push_local_sales.php).
setEnvValue($env['env_file'], 'FALLBACK_SESSION_ACTIVE', '');

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
    // non null solo se il locale aveva un catalogo non vuoto: il frontend lo
    // usa per un avviso informativo, mai per bloccare nulla.
    'catalog_backup_id' => $catalogBackupId,
    // non null solo se e' stato davvero ripristinato qualcosa: il frontend
    // mostra un avviso informativo con l'azione "Annulla" (safety_backup_id).
    'catalog_restored' => $catalogRestored,
]);
