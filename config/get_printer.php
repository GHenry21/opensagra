<?php
require_once __DIR__ . '/get_db_connection.php';
require_once __DIR__ . '/printer_connectors.php';

use Mike42\Escpos\Printer;

function getPrinterSettings($connectionDB, $cassa_id)
{
    // qz_host -> bridge_host: da Fase 4 (QZ Tray rimosso) e' solo l'IP del
    // PC-ponte per la discovery BRIDGE_NATIVE. Rinomina idempotente (vedi anche
    // config/migrations/002_qz_host_to_bridge_host.php).
    $hasOld = $connectionDB->query("SHOW COLUMNS FROM casse_stampanti LIKE 'qz_host'");
    $hasNew = $connectionDB->query("SHOW COLUMNS FROM casse_stampanti LIKE 'bridge_host'");
    if ($hasOld && $hasOld->num_rows > 0 && $hasNew && $hasNew->num_rows === 0) {
        $connectionDB->query("ALTER TABLE casse_stampanti CHANGE COLUMN qz_host bridge_host VARCHAR(255) NULL");
    } else {
        $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS bridge_host VARCHAR(255) NULL AFTER porta");
    }
    // Modello BRIDGE_NATIVE "a una riga": la stampante fisica del ponte e'
    // descritta qui (bridge_printer_type + nome_indirizzo/porta) e il topic
    // Mercure e' bridge_topic (default cassa_id). bridge_printer_type vuoto =
    // vecchio modello "a due righe" (nome_indirizzo = cassa-ponte).
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS bridge_printer_type VARCHAR(20) NULL AFTER bridge_host");
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS bridge_topic VARCHAR(50) NULL AFTER bridge_printer_type");
    // Segreto Mercure locale del PC-ponte (letto una tantum dalla discovery in
    // conf_casse.php), per pubblicare punto-punto sul suo hub invece che su
    // quello derivato da DB_POS_HOST - vedi routingStampa() in print_receipt.php.
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS bridge_jwt_secret VARCHAR(255) NULL AFTER bridge_topic");

    $query = "SELECT tipo_stampante, nome_indirizzo, porta, bridge_host, bridge_printer_type, bridge_topic, bridge_jwt_secret
                FROM casse_stampanti
               WHERE cassa_id = ? ";
    $stmt = $connectionDB->prepare($query);
    $stmt->bind_param("s", $cassa_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return [
            // Use the value from the database if available
            'tipo_stampante' => $row['tipo_stampante'], /* ?: $defaultConfig['tipo_stampante'], */
            'nome_indirizzo' => $row['nome_indirizzo'],
            'porta' => $row['porta'],
            'bridge_host' => trim((string) ($row['bridge_host'] ?? '')),
            'bridge_printer_type' => trim((string) ($row['bridge_printer_type'] ?? '')),
            'bridge_topic' => trim((string) ($row['bridge_topic'] ?? '')),
            'bridge_jwt_secret' => trim((string) ($row['bridge_jwt_secret'] ?? '')),
        ];
    }

    $stmt->close();

    // Error se nessuna stampante trovata per la cassa_id specificata
    throw new Exception("Nessuna stampante trovata per {$cassa_id}, associare la stampante nella pagina Configurazione Stampanti");
}

/**
 * Costruisce il PrintConnector escpos-php per la stampante fisica della cassa.
 * Estratto da getPrinter() per essere riusato da bin/opensagra-print-bridge.php,
 * che deve scrivere byte ESC/POS gia' pronti (write()/finalize()) senza passare
 * dall'API Printer.
 */
function getPrinterConnector($connectionDB, $cassa_id)
{
    // Se la cassa non ha una riga nel DB, getPrinterSettings lancia un'eccezione e blocca il flusso
    $printerSettings = getPrinterSettings($connectionDB, $cassa_id);
    $tipoStamp = $printerSettings['tipo_stampante'];

    switch ($tipoStamp) {
        case 'RETE':
        case 'USB':
        case 'LINUX_USB':
        case 'WIN_USB':
            return getPrinterConnectorFromSpec(
                $tipoStamp,
                (string) $printerSettings['nome_indirizzo'],
                $printerSettings['porta'] ?? null
            );

        case 'BRIDGE_NATIVE':
            // Il PC che stampa davvero (dove gira opensagra-print-bridge.php) ha
            // la sua stampante configurata come tipo diretto (WIN_USB/RETE/...),
            // non BRIDGE_NATIVE. Se si arriva qui, la cassa che pubblica e quella
            // che stampa sono state configurate uguali per errore.
            throw new InvalidArgumentException('BRIDGE_NATIVE non e\' una stampante fisica: e\' la cassa che pubblica su un topic di stampa. La cassa-ponte deve avere un tipo diretto.');

        default: // errore in caso di valore non tra quelli previsti sopra
            throw new Exception("Tipo stampante '{$tipoStamp}' sconosciuto.");
    }
}

function getPrinter($connectionDB, $cassa_id)
{
    return new Printer(getPrinterConnector($connectionDB, $cassa_id));
}
