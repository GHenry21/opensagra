<?php
/**
 * Enumerazione delle stampanti di sistema (locale) e proxy verso un altro PC
 * della LAN (discovery remota, modello BRIDGE_NATIVE "a una riga").
 *
 * Nessuna dipendenza dal database: api/stampanti.php serve le action
 * list_win_printers / list_linux_printers / remote_list_printers PRIMA di
 * require get_db_connection.php, cosi' la discovery gira anche su un PC-ponte
 * non ancora in modalita' client (MariaDB non raggiungibile).
 */

require_once __DIR__ . '/env_reader.php';

/**
 * Stampanti Windows reali (filtra PDF / XPS / OneNote / Fax).
 * @return string[]
 */
function getWindowsPrinters(): array
{
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        return [];
    }

    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Printer | Select-Object Name,DriverName,PortName | ConvertTo-Json -Compress"';
    $output = shell_exec($cmd);
    if (!$output) {
        return [];
    }

    $data = json_decode($output, true);
    if (!is_array($data)) {
        return [];
    }
    // Un solo risultato: ConvertTo-Json restituisce un oggetto, non un array.
    if (array_key_exists('Name', $data)) {
        $data = [$data];
    }

    $virtualDrivers = [
        'microsoft print to pdf',
        'microsoft xps document writer',
        'microsoft shared fax driver',
    ];
    $virtualPorts = ['portprompt:', 'nul:', 'shrfax:'];

    $printers = [];
    foreach ($data as $p) {
        if (!is_array($p) || empty($p['Name'])) {
            continue;
        }
        $name = (string) $p['Name'];
        $driver = strtolower(trim((string) ($p['DriverName'] ?? '')));
        $port = strtolower(trim((string) ($p['PortName'] ?? '')));

        if (in_array($driver, $virtualDrivers, true) || strpos($driver, 'onenote') !== false) {
            continue;
        }
        if (in_array($port, $virtualPorts, true)) {
            continue;
        }
        if (preg_match('/onenote|xps|microsoft print to pdf|(^|\s)fax(\s|$)/i', $name)) {
            continue;
        }

        $printers[] = $name;
    }

    return array_values(array_unique($printers));
}

/**
 * Code CUPS / device raw Linux.
 * @return string[]
 */
function getLinuxPrinters(array &$debug = []): array
{
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return [];
    }

    if (!function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        $debug[] = 'shell_exec non disponibile (disabilitata in php.ini disable_functions).';
        return [];
    }

    // Prova prima il binario risolto via PATH, poi i percorsi assoluti tipici (il processo PHP/Apache spesso ha un PATH ridotto).
    $candidates = ['lpstat', '/usr/bin/lpstat', '/usr/sbin/lpstat', '/usr/bin/lpinfo'];
    foreach ($candidates as $bin) {
        $cmd = escapeshellarg($bin) . ' -p 2>&1';
        $output = shell_exec($cmd);
        $debug[] = "Comando: $cmd => " . trim((string) $output);

        if ($output === null || $output === false || stripos($output, 'not found') !== false || stripos($output, 'no such file') !== false) {
            continue;
        }

        $lines = [];
        foreach (explode("\n", trim($output)) as $line) {
            // Formato atteso: "printer NOME is idle..."
            if (preg_match('/^printer\s+(\S+)/i', $line, $m)) {
                $lines[] = $m[1];
            }
        }

        if (!empty($lines)) {
            // Scarta la coda PDF virtuale di cups-pdf, se presente.
            $lines = array_values(array_filter($lines, static fn($n) => !preg_match('/(^|[-_])pdf$/i', $n)));
            return array_values(array_unique($lines));
        }
    }

    $debug[] = 'Nessuna stampante CUPS rilevata con nessuno dei comandi provati. Verificare che il pacchetto cups-client sia installato e che l\'utente del web server possa eseguire lpstat.';
    return [];
}

/**
 * MERCURE_JWT_SECRET locale di QUESTO PC, riportato nella discovery cosi' che
 * conf_casse.php possa salvarlo in casse_stampanti.bridge_jwt_secret (bridge
 * punto-punto: la cassa pubblica direttamente sull'hub del ponte, invece che
 * su quello derivato da DB_POS_HOST - vedi routingStampa() in
 * print/print_receipt.php). Chiamata solo da un altro PC della LAN, filtrata a
 * IP privati da remoteListPrinters() prima di arrivare qui.
 */
function bridgeDiscoverySecret(): string
{
    return trim((string) (loadPosEnvVars()['mercure_jwt_secret'] ?? ''));
}

/**
 * True solo per IPv4 di rete privata / loopback o hostname *.local.
 * Guardia anti-SSRF per remoteListPrinters().
 */
function isPrivateLanHost(string $host): bool
{
    $host = trim($host);
    if ($host === '') {
        return false;
    }
    if (preg_match('/^[a-z0-9][a-z0-9\-]*\.local$/i', $host)) {
        return true;
    }
    if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $ip = ip2long($host);
    $ranges = [
        ['10.0.0.0', '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
        ['127.0.0.0', '127.255.255.255'],
    ];
    foreach ($ranges as [$lo, $hi]) {
        if ($ip >= ip2long($lo) && $ip <= ip2long($hi)) {
            return true;
        }
    }
    return false;
}

/**
 * Chiede a un'altra installazione opensagra della LAN il suo elenco stampanti
 * (server-to-server, niente browser). Assume lo stesso layout di path dell'app.
 *
 * @return array{printers?:string[],bridge_jwt_secret?:string,source?:string,host?:string,error?:string}
 */
function remoteListPrinters(string $host, string $os): array
{
    $host = trim($host);
    $os = ($os === 'linux') ? 'linux' : 'win';

    if (!isPrivateLanHost($host)) {
        return ['error' => 'Host non valido: ammessi solo indirizzi IPv4 di rete locale o nomi .local.'];
    }

    // Stesso path dell'app di questa richiesta, assunto identico sul ponte.
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/stampanti.php'));
    $basePath = preg_replace('#/api/[^/]*$#', '', $script);
    $path = $basePath . '/api/stampanti.php?action=list_' . $os . '_printers';

    $lastErr = '';
    foreach (['https', 'http'] as $scheme) {
        $url = $scheme . '://' . $host . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastErr = curl_error($ch) ?: ('HTTP ' . $code);
        // curl_close(): no-op dal PHP 8.0, deprecata dall'8.5.
        unset($ch);

        if ($body === false || $code !== 200) {
            continue;
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !isset($decoded['printers']) || !is_array($decoded['printers'])) {
            continue;
        }
        return [
            'printers' => array_values($decoded['printers']),
            'bridge_jwt_secret' => (string) ($decoded['bridge_jwt_secret'] ?? ''),
            'source' => 'remote',
            'host' => $host,
        ];
    }

    return ['error' => "Nessuna risposta valida da {$host} ({$lastErr}). opensagra e' avviato e raggiungibile su quel PC?"];
}

/**
 * Dispatcher per le action di discovery gestite prima del require del DB.
 * Emette JSON ed esce.
 */
function handlePrinterDiscovery(string $action): void
{
    if ($action === 'remote_list_printers') {
        echo json_encode(remoteListPrinters((string) ($_GET['host'] ?? ''), (string) ($_GET['os'] ?? 'win')));
        return;
    }

    if ($action === 'list_win_printers') {
        echo json_encode([
            'printers' => getWindowsPrinters(),
            'bridge_jwt_secret' => bridgeDiscoverySecret(),
        ]);
        return;
    }

    // list_linux_printers
    $debug = [];
    $printers = getLinuxPrinters($debug);
    echo json_encode([
        'printers' => $printers,
        'bridge_jwt_secret' => bridgeDiscoverySecret(),
        'debug' => $debug,
    ]);
}
