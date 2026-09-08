<?php
/**
 * opensagra - bridge di stampa nativo (Fase 4, "Roadmap collegata" punto 2).
 * Sostituto di QZ Tray.
 *
 * Gira sul PC a cui e' collegata (USB / rete / ...) la stampante di una cassa.
 * Si iscrive al topic Mercure `print/cassa/{id}` sull'hub del server e, alla
 * ricezione, scrive i byte ESC/POS gia' pronti direttamente sulla stampante
 * locale (stesso codice escpos-php di WIN_USB oggi). Il browser non partecipa
 * piu' allo step di stampa: niente WebSocket, niente mixed-content, niente
 * popup/certificati di override.
 *
 * Lanciato dal wrapper come processo figlio (vedi piano 3g), uno per cassa che
 * questo PC serve. La cassa e' data da:  --cassa=<id>  oppure  PRINT_BRIDGE_CASSE
 * in variabili.env (primo valore di una lista separata da virgole).
 *
 * Test senza stampante reale: PRINT_BRIDGE_SINK_FILE=<path> scrive i byte su
 * file invece che sulla stampante.
 *
 * Log su STDERR. Avvio manuale:  php bin/opensagra-print-bridge.php --cassa=henry
 */

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/mercure.php';
require_once __DIR__ . '/../config/get_db_connection.php';   // $connectionDB (verso DB_POS_HOST)
require_once __DIR__ . '/../config/get_printer.php';
require_once __DIR__ . '/mercure_subscriber.php';
require __DIR__ . '/../vendor/autoload.php';

function bridgeLog(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

// --- quale cassa serve questo bridge ---
$cassa = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--cassa=')) {
        $cassa = trim(substr($a, 8));
    }
}
if ($cassa === '') {
    $fromEnv = getenv('PRINT_BRIDGE_CASSE');
    if ($fromEnv !== false && trim($fromEnv) !== '') {
        $cassa = trim(explode(',', $fromEnv)[0]);
    }
}
if ($cassa === '') {
    bridgeLog("print-bridge: nessuna cassa configurata (--cassa=<id> o PRINT_BRIDGE_CASSE). Esco.");
    exit(0);
}

$env = loadPosEnvVars();
if (($env['mercure_jwt_secret'] ?? '') === '') {
    bridgeLog("print-bridge: MERCURE_JWT_SECRET non configurato - esco (ripartira' quando il segreto e' sincronizzato).");
    exit(0);
}

$sinkFile = getenv('PRINT_BRIDGE_SINK_FILE') ?: '';
$topic = 'print/cassa/' . $cassa;

/**
 * Stampa i byte ESC/POS ricevuti. Riapre il connettore ad ogni scontrino:
 * le condivisioni Windows / i device USB non amano gli handle tenuti aperti a
 * lungo, e uno scontrino ogni tanto non ha problemi di costo.
 */
function printRaw(string $bytes, string $cassa, string $sinkFile): void
{
    global $connectionDB;

    if ($sinkFile !== '') {
        file_put_contents($sinkFile, $bytes, FILE_APPEND | LOCK_EX);
        bridgeLog("print-bridge: " . strlen($bytes) . " byte scritti su $sinkFile (modalita' test).");
        return;
    }

    $connector = getPrinterConnector($connectionDB, $cassa);
    $connector->write($bytes);
    $connector->finalize();
    bridgeLog("print-bridge: scontrino stampato (" . strlen($bytes) . " byte) su cassa '$cassa'.");
}

bridgeLog("print-bridge: cassa '$cassa', topic '$topic'" . ($sinkFile !== '' ? ", SINK=$sinkFile" : '') . ".");

runMercureSubscriber([
    'hub_url'  => mercureHubUrl(),          // hub del server (o locale se questa e' il server)
    'topics'   => [$topic],
    'label'    => "print-bridge[$cassa]",
    'mint_jwt' => static fn() => mintMercureJwt([], [$topic], MERCURE_SUB_JWT_TTL),  // scoped: solo questa cassa
    'on_event' => static function ($data) use ($cassa, $sinkFile) {
        if (!is_array($data) || !isset($data['data_base64'])) {
            bridgeLog("print-bridge: evento senza 'data_base64', ignorato.");
            return;
        }
        $bytes = base64_decode($data['data_base64'], true);
        if ($bytes === false || $bytes === '') {
            bridgeLog("print-bridge: 'data_base64' non valido, ignorato.");
            return;
        }
        try {
            printRaw($bytes, $cassa, $sinkFile);
        } catch (Throwable $e) {
            bridgeLog("print-bridge: STAMPA FALLITA (vendita " . ($data['id_vendita'] ?? '?') . "): " . $e->getMessage());
        }
    },
]);
