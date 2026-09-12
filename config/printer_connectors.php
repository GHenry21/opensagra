<?php
/**
 * Costruzione dei PrintConnector escpos-php a partire da (tipo, target, porta),
 * SENZA alcuna dipendenza dal database.
 *
 * Condiviso da:
 *  - config/get_printer.php  -> risolve il tipo/target dalla riga casse_stampanti
 *  - bin/opensagra-print-bridge.php -> riceve tipo/target nel payload Mercure
 *    (modello BRIDGE_NATIVE "a una riga"): cosi' il ponte stampa anche con
 *    MariaDB spento e senza il resto dello stack.
 */

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

/**
 * USB / stampante locale in modello wrapper: il tipo salvato e' 'USB' (OS-agnostico).
 * L'host che stampa davvero (dove gira il wrapper / opensagra-print-bridge) sa se e'
 * Windows o Linux: qui si risolve al volo il connector giusto, cosi' la stessa riga di
 * config e' portabile fra i due sistemi.
 */
function usbConnectorForTarget($nomeStamp)
{
    if (PHP_OS_FAMILY === 'Windows') {
        return new WindowsPrintConnector(normalizeWindowsUsbTarget($nomeStamp));
    }

    // Linux / macOS: un path assoluto (es. /dev/usb/lp0) e' scrittura raw, tutto il
    // resto e' una coda CUPS (nome da 'lpstat -p').
    return isLinuxDevicePath($nomeStamp)
        ? new FilePrintConnector($nomeStamp)
        : new CupsPrintConnector($nomeStamp);
}

/**
 * Topic fisso per il bridge nativo: bin/opensagra-print-bridge.php ascolta
 * SEMPRE sul proprio hub Mercure locale (mai su uno condiviso derivato da
 * DB_POS_HOST - vedi la sua intestazione), quindi non c'e' mai piu' di un
 * ponte in ascolto sullo stesso hub - un id-topic per distinguere le casse
 * non serve: una sola stampante o cento sullo stesso ponte, non fa differenza
 * (la stampante fisica la sceglie il payload `printer`/`printer_type`, mai
 * il topic).
 */
const BRIDGE_P2P_TOPIC = 'print/bridge';

/**
 * Per una riga BRIDGE_NATIVE calcola il topic Mercure (sempre BRIDGE_P2P_TOPIC)
 * e i campi stampante da allegare al payload pubblicato. Nessuna dipendenza
 * dal DB: identico tra print/print_receipt.php, print/print_stat_receipt.php
 * e il bottone di test.
 *
 * - modello "a una riga" (bridge_printer_type valorizzato): la stampante
 *   fisica del ponte viaggia nel payload (`printer`/`printer_type`/
 *   `printer_port`), il ponte non tocca il DB;
 * - modello legacy "a due righe" (bridge_printer_type vuoto): nessuna
 *   stampante nel payload, il ponte la risolve dalla riga della cassa che ha
 *   pubblicato (cassa_id, sempre nel payload).
 *
 * @return array{topic:string,payload:array<string,mixed>}
 */
function bridgeNativeRouting(array $printerSettings, string $cassa_id): array
{
    $printerType = trim((string) ($printerSettings['bridge_printer_type'] ?? ''));

    if ($printerType !== '') {
        $payload = [
            'printer' => trim((string) ($printerSettings['nome_indirizzo'] ?? '')),
            'printer_type' => $printerType,
        ];
        if ($printerType === 'RETE') {
            $payload['printer_port'] = (int) ($printerSettings['porta'] ?: 9100);
        }
        return ['topic' => BRIDGE_P2P_TOPIC, 'payload' => $payload];
    }

    return ['topic' => BRIDGE_P2P_TOPIC, 'payload' => []];
}

/**
 * @param string          $type   USB | WIN_USB | LINUX_USB | RETE
 * @param string          $target nome stampante / device path / IP
 * @param int|string|null $port   porta TCP (solo RETE, default 9100)
 */
function getPrinterConnectorFromSpec(string $type, string $target, $port = null)
{
    switch ($type) {
        case 'RETE':
            return new NetworkPrintConnector($target, (int) ($port ?: 9100));

        case 'USB':
            return usbConnectorForTarget($target);

        case 'LINUX_USB':
            return isLinuxDevicePath($target)
                ? new FilePrintConnector($target)
                : new CupsPrintConnector($target);

        case 'WIN_USB':
            return new WindowsPrintConnector(normalizeWindowsUsbTarget($target));

        default:
            throw new InvalidArgumentException(
                "Tipo stampante '{$type}' non valido per il connettore diretto (attesi USB / WIN_USB / LINUX_USB / RETE)."
            );
    }
}
