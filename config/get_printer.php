<?php
require_once __DIR__ . '/get_db_connection.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\CupsPrintConnector;

// I nomi configurati tramite discovery sono code CUPS; solo un path assoluto e' un device raw.
function isLinuxDevicePath($target)
{
    return str_starts_with((string) $target, '/');
}

function normalizeWindowsUsbTarget($target)
{
    $target = trim((string) $target);
    $localHost = strtolower((string) (gethostname() ?: 'localhost'));
    if ($target === '') {
        return 'smb://127.0.0.1/POS-80C';
    }

    // Keep valid smb:// targets as-is, but remap local hostname to loopback.
    if (stripos($target, 'smb://') === 0) {
        $parts = parse_url($target);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === 'localhost' || $host === $localHost) {
            $path = $parts['path'] ?? '';
            return 'smb://127.0.0.1' . $path;
        }
        return $target;
    }

    // Convert UNC format (\\HOST\SHARE) to smb://HOST/SHARE.
    if (preg_match('/^\\\\\\\\([^\\\\]+)\\\\(.+)$/', $target, $matches)) {
        $host = strtolower(trim($matches[1]));
        if ($host === 'localhost' || $host === $localHost) {
            $host = '127.0.0.1';
        }
        return 'smb://' . $host . '/' . $matches[2];
    }

    // Keep local ports untouched.
    if (preg_match('/^(LPT\\d|COM\\d)$/i', $target)) {
        return strtoupper($target);
    }

    // Plain printer names are treated as local shared printers via loopback.
    return 'smb://127.0.0.1/' . $target;
}

function getPrinterSettings($connectionDB, $cassa_id)
{
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS qz_host VARCHAR(255) NULL AFTER porta");

    $query = "SELECT tipo_stampante, nome_indirizzo, porta, qz_host
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
            'qz_host' => trim((string) ($row['qz_host'] ?? ''))
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
            $indirizzoIPStamp = $printerSettings['nome_indirizzo'];
            $portaStamp = (int) ($printerSettings['porta'] ?: 9100);
            return new NetworkPrintConnector($indirizzoIPStamp, $portaStamp); // Network

        case 'LINUX_USB':
            $nomeStamp = $printerSettings['nome_indirizzo'];
            // Device path (es. /dev/usb/lp0): scrittura diretta. Altrimenti e' una coda CUPS (es. da 'lpstat -p').
            return isLinuxDevicePath($nomeStamp)
                ? new FilePrintConnector($nomeStamp)
                : new CupsPrintConnector($nomeStamp);

        case 'WIN_USB':
            $nomeStamp = $printerSettings['nome_indirizzo'];
            return new WindowsPrintConnector(normalizeWindowsUsbTarget($nomeStamp)); // Windows USB

        case 'BRIDGE':
            throw new InvalidArgumentException('Il tipo stampante BRIDGE richiede il flusso di stampa QZ Tray dal browser.');

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