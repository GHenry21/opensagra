<?php
/**
 * Ripristina un backup del catalogo locale (config/catalog_backup.php) sul DB
 * locale (127.0.0.1 - i backup sono per-nodo, non si ripristinano altrove).
 * Prima di sovrascrivere qualunque tabella salva un backup dello stato
 * ATTUALE ("prima di un ripristino"): un ripristino non fa mai perdere per
 * sempre quello che c'era.
 *
 * Non c'e' piu' una pagina che elenca i backup e lascia scegliere quale
 * ripristinare (rimossa il 2026-09-12, giudicata troppo macchinosa per un
 * utente medio): il ripristino "vero" avviene da solo, in automatico, dentro
 * api/set_network_config.php a ogni switch pulito verso Indipendente. Questo
 * endpoint resta solo per l'azione "Annulla" del toast che segue quel
 * ripristino - richiama lo stesso batch_id di sicurezza che e' stato appena
 * creato, per tornare indietro di un passo.
 *
 * POST JSON: { "batch_id": <int> }
 *
 * Risposte:
 *   200 { success, restored:{table:righe}, safety_backup_id, errors:{}, is_client }
 *   400/404/503/500 { error }
 *
 * `is_client`: se true, la cassa e' in modalita' rete - bin/opensagra-snapshot.php
 * puo' sovrascrivere di nuovo le tabelle ripristinate entro il prossimo giro
 * (fino a ~10s). E' solo un avviso: il ripristino resta comunque recuperabile
 * (e' stato appena salvato anche lui in un nuovo backup).
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/catalog_backup.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non supportato.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$batchId = (int) ($data['batch_id'] ?? 0);
if ($batchId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'batch_id mancante o non valido.']);
    exit;
}

$env = loadPosEnvVars();

try {
    $local = mysqli_init();
    $local->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    if (!@$local->real_connect('127.0.0.1', $env['user'], $env['pass'], $env['db'])) {
        http_response_code(503);
        echo json_encode(['error' => 'DB locale non raggiungibile.']);
        exit;
    }
    $local->set_charset('utf8mb4');

    $result = restoreCatalogBackupBatch($local, $batchId);
    $local->close();

    if (!$result['restored'] && $result['errors']) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => reset($result['errors'])]);
        exit;
    }

    echo json_encode([
        'success' => empty($result['errors']),
        'restored' => $result['restored'],
        'safety_backup_id' => $result['safety_backup_id'],
        'errors' => $result['errors'],
        'is_client' => $env['host'] !== '' && !in_array($env['host'], ['127.0.0.1', 'localhost', '::1'], true),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore durante il ripristino: ' . $e->getMessage()]);
}
