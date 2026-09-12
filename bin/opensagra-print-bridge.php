<?php
/**
 * opensagra - bridge di stampa nativo (Fase 4, "Roadmap collegata" punto 2).
 * Sostituto di QZ Tray.
 *
 * Gira sul PC a cui e' collegata (USB / rete / ...) la stampante. Si iscrive
 * SEMPRE al topic Mercure fisso `print/bridge` (BRIDGE_P2P_TOPIC) sul proprio
 * hub LOCALE e, alla ricezione, scrive i byte ESC/POS gia' pronti direttamente
 * sulla stampante. Il browser non partecipa piu' allo step di stampa: niente
 * WebSocket, niente mixed-content, niente popup/certificati di override.
 *
 * Topic fisso perche' punto-punto: ogni cassa che ha bridge_host configurato
 * pubblica DIRETTAMENTE sull'hub di questo PC (esclusivo, mai condiviso), non
 * su un hub centrale spartito con altri ponti - quindi non serve un id-topic
 * per distinguere le casse, ne' un DB_POS_HOST "giusto": questo processo non
 * dipende ne' dall'uno ne' dall'altro. Una o cento stampanti su questo stesso
 * PC, un solo processo basta: la stampante fisica la sceglie il payload
 * (`printer`/`printer_type`), mai il topic.
 *
 * Stampante:
 *  - modello "a una riga" (consigliato): la cassa e' configurata BRIDGE_NATIVE
 *    con la stampante del ponte (tipo + nome/IP). Il server la mette nel payload
 *    Mercure (`printer` / `printer_type` / `printer_port`) e questo processo
 *    stampa SENZA toccare il DB -> funziona con MariaDB spento.
 *  - modello legacy "a due righe": il payload non porta la stampante; questo
 *    processo la risolve dalla riga `casse_stampanti` della cassa che ha
 *    pubblicato l'evento (`cassa_id` nel payload; connessione al DB non
 *    fatale: se il DB non c'e' la singola stampa fallisce e viene loggata, il
 *    processo resta vivo).
 *
 * Lanciato dal wrapper come processo figlio (vedi piano 3g), un solo processo
 * per PC. Abilitato da:  PRINT_BRIDGE_CASSE  non vuoto in variabili.env (il
 * valore stesso non conta piu', solo se e' vuoto o no - compatibilita' con
 * installazioni che avevano gia' un id li' scritto).
 *
 * Test senza stampante reale: PRINT_BRIDGE_SINK_FILE=<path> scrive i byte su
 * file invece che sulla stampante.
 *
 * Log su STDERR. Avvio manuale:  php bin/opensagra-print-bridge.php
 */

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/mercure.php';
require_once __DIR__ . '/../config/printer_connectors.php';   // getPrinterConnectorFromSpec() - nessun DB
require_once __DIR__ . '/mercure_subscriber.php';
require __DIR__ . '/../vendor/autoload.php';

function bridgeLog(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

/**
 * Lock single-instance: un solo processo bridge per PC (topic fisso, vedi
 * intestazione del file). Due processi iscritti allo stesso topic = ogni
 * scontrino stampato due volte, in silenzio (osservato 2026-09-09 - allora
 * era per cassa, il rischio resta identico con un topic condiviso). Il
 * wrapper dovrebbe avviarne uno solo, ma un avvio manuale sovrapposto o un
 * riavvio del wrapper prima che il vecchio figlio sia morto lo fanno
 * succedere lo stesso.
 *
 * `flock(LOCK_EX|LOCK_NB)` funziona anche su Windows con PHP. L'handle va
 * tenuto vivo per tutta la durata del processo (variabile in scope nel corpo
 * dello script): il lock si rilascia da solo alla chiusura del processo.
 *
 * @return resource|null l'handle del file di lock (null se non apribile) -
 *                       NON farlo uscire di scope finche' il processo vive
 */
function bridgeAcquireLock()
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opensagra-print-bridge.lock';

    $fh = fopen($path, 'c');
    if ($fh === false) {
        bridgeLog("print-bridge: impossibile aprire il lock file '$path' - proseguo senza guardia.");
        return null;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        bridgeLog("print-bridge: un altro processo e' gia' in ascolto (lock '$path'). Esco.");
        fclose($fh);
        exit(0); // uscita pulita: il supervisore non deve trattarlo come crash
    }
    ftruncate($fh, 0);
    fwrite($fh, (string) getmypid());
    fflush($fh);
    return $fh;
}

// --- il bridge e' abilitato su questo PC? ---
$fromEnv = getenv('PRINT_BRIDGE_CASSE');
if ($fromEnv === false || trim((string) $fromEnv) === '') {
    $fromEnv = loadPosEnvVars()['print_bridge_casse'] ?? '';
}
if (trim((string) $fromEnv) === '') {
    bridgeLog("print-bridge: PRINT_BRIDGE_CASSE non impostato in variabili.env. Esco.");
    exit(0);
}

// Tenuto in scope per tutta la vita del processo: rilascia il lock all'uscita.
$bridgeLock = bridgeAcquireLock();

$env = loadPosEnvVars();

// Punto-punto (bridge di stampa indipendente dal fallback DB): questo processo
// ascolta SEMPRE sul proprio hub Mercure locale, mai su quello derivato da
// DB_POS_HOST. La cassa lo raggiunge direttamente per IP (bridge_host +
// bridge_jwt_secret in casse_stampanti, letti una tantum dalla discovery in
// conf_casse.php) - vedi routingStampa() in print/print_receipt.php. Cosi' la
// stampa non dipende piu' dallo stato di rete/fallback della cassa, ne' da
// conf_rete per sincronizzare un segreto "remoto".
$hubUrl = 'https://localhost/.well-known/mercure';
$hubSecret = trim((string) ($env['mercure_jwt_secret'] ?? ''));
if ($hubSecret === '') {
    bridgeLog("print-bridge: MERCURE_JWT_SECRET non configurato in variabili.env - esco.");
    exit(0);
}

$sinkFile = getenv('PRINT_BRIDGE_SINK_FILE') ?: '';
$topic = BRIDGE_P2P_TOPIC;

/**
 * Connessione DB non fatale, solo per il fallback "modello a due righe": la
 * cassa che pubblica non allega la stampante nel payload e va risolta dalla
 * sua riga in casse_stampanti (cassa_id nel payload). Nel modello "a una
 * riga" non viene mai chiamata.
 */
function bridgeConnectorFromDb(string $cassa)
{
    static $conn = false;

    if ($conn === false) {
        $env = loadPosEnvVars();
        mysqli_report(MYSQLI_REPORT_OFF);
        $c = @new mysqli($env['host'], $env['user'], $env['pass'], $env['db']);
        $conn = $c->connect_error ? null : $c;
        if ($conn === null) {
            bridgeLog("print-bridge: DB non raggiungibile ({$c->connect_error}).");
        }
    }

    if ($conn === null) {
        throw new RuntimeException("stampante non nel payload e DB non raggiungibile per la cassa '$cassa'");
    }

    $stmt = $conn->prepare("SELECT tipo_stampante, nome_indirizzo, porta FROM casse_stampanti WHERE cassa_id = ?");
    $stmt->bind_param('s', $cassa);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException("nessuna riga casse_stampanti per '$cassa'");
    }
    return getPrinterConnectorFromSpec((string) $row['tipo_stampante'], (string) $row['nome_indirizzo'], $row['porta'] ?? null);
}

/**
 * Stampa i byte ESC/POS ricevuti. Riapre il connettore ad ogni scontrino: le
 * condivisioni Windows / i device USB non amano gli handle tenuti aperti a
 * lungo, e uno scontrino ogni tanto non ha problemi di costo.
 */
function printRaw(string $bytes, string $sinkFile, array $data): void
{
    $cassa = trim((string) ($data['cassa_id'] ?? ''));

    if ($sinkFile !== '') {
        file_put_contents($sinkFile, $bytes, FILE_APPEND | LOCK_EX);
        bridgeLog("print-bridge: " . strlen($bytes) . " byte scritti su $sinkFile (modalita' test).");
        return;
    }

    $printerType = trim((string) ($data['printer_type'] ?? ''));
    if ($printerType !== '') {
        // Modello "a una riga": stampante dal payload, nessun accesso al DB.
        $connector = getPrinterConnectorFromSpec(
            $printerType,
            (string) ($data['printer'] ?? ''),
            $data['printer_port'] ?? null
        );
    } else {
        // Modello legacy "a due righe": risolvi dalla riga della cassa che ha pubblicato.
        $connector = bridgeConnectorFromDb($cassa);
    }

    $connector->write($bytes);
    $connector->finalize();
    bridgeLog("print-bridge: scontrino stampato (" . strlen($bytes) . " byte), cassa '$cassa'.");
}

bridgeLog("print-bridge: in ascolto, topic '$topic'" . ($sinkFile !== '' ? ", SINK=$sinkFile" : '') . ".");

runMercureSubscriber([
    'hub_url'  => $hubUrl,                  // sempre il proprio hub locale (punto-punto)
    'topics'   => [$topic],
    'label'    => 'print-bridge',
    'mint_jwt' => static fn() => mintMercureJwt([], [$topic], MERCURE_SUB_JWT_TTL, $hubSecret),
    'on_event' => static function ($data) use ($sinkFile) {
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
            printRaw($bytes, $sinkFile, $data);
        } catch (Throwable $e) {
            bridgeLog("print-bridge: STAMPA FALLITA (vendita " . ($data['id_vendita'] ?? '?') . "): " . $e->getMessage());
        }
    },
]);
