<?php
/**
 * Accende o spegne il ponte di stampa su questo PC: scrive PRINT_BRIDGE_CASSE
 * in config/variabili.env. Niente qui tocca i processi: il wrapper rilegge il
 * file da solo entro ~15s (watchBridgeEnabled/syncBridgeChild in
 * wrapper/cluster.go) e mette in pausa o riprende bin/opensagra-print-bridge.php
 * di conseguenza - nessun riavvio richiesto, nessuna installazione senza
 * wrapper (dev locale) ne' resta rotta: il file è comunque corretto.
 *
 * Spento -> scrive "0", MAI stringa vuota: il ponte è acceso di default
 * (bridgeFlagOn in wrapper/config.go, stessa regola qui) proprio perché ogni
 * installazione precedente al 2026-10-16 ha la chiave già scritta vuota - se
 * "spento" scrivesse di nuovo vuoto, un utente che lo disattiva da qui non si
 * distinguerebbe da un'installazione che non l'ha mai toccato, e tornerebbe
 * ad accendersi da solo al prossimo aggiornamento degli installer.
 *
 * POST JSON: { "enabled": true|false }
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/env_writer.php';

$data = json_decode(file_get_contents('php://input'), true);
$enabled = (bool) ($data['enabled'] ?? false);

$env = loadPosEnvVars();

if (!setEnvValue($env['env_file'], 'PRINT_BRIDGE_CASSE', $enabled ? '1' : '0')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Impossibile salvare la configurazione (permessi file?).']);
    exit;
}

echo json_encode(['success' => true, 'enabled' => $enabled]);
