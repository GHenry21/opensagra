<?php
header('Content-Type: application/json; charset=utf-8');

// Include la connessione al database
require_once __DIR__ . '/../config/get_db_connection.php'; 

// ==========================================
// FUNZIONI PER STAMPANTI DI SISTEMA
// ==========================================
function getWindowsPrinters() {
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        return [];
    }
    // Esegue PowerShell per ottenere i nomi delle stampanti installate
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Printer | Select-Object -ExpandProperty Name | ConvertTo-Json"';
    $output = shell_exec($cmd);
    if (!$output) return [];

    $data = json_decode($output, true);
    if (is_string($data)) return [$data]; // Se c'è una sola stampante
    if (is_array($data)) return array_values($data);
    return [];
}

function getLinuxPrinters(&$debug = []) {
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
            return array_values(array_unique($lines));
        }
    }

    $debug[] = 'Nessuna stampante CUPS rilevata con nessuno dei comandi provati. Verificare che il pacchetto cups-client sia installato e che l\'utente del web server possa eseguire lpstat.';
    return [];
}

function ensurePaymentMethodColumns($connectionDB) {
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS abilita_contanti TINYINT(1) NOT NULL DEFAULT 1 AFTER qz_host");
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS abilita_carta TINYINT(1) NOT NULL DEFAULT 0 AFTER abilita_contanti");
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS abilita_satispay TINYINT(1) NOT NULL DEFAULT 0 AFTER abilita_carta");
}

function normalizePaymentFlag($value, $default) {
    if ($value === null) {
        return $default;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
}


// ==========================================
// GESTIONE RICHIESTE
// ==========================================
$method = $_SERVER['REQUEST_METHOD'];

try {
    ensurePaymentMethodColumns($connectionDB);

    // ------------------------------------------
    // 1. RICHIESTE GET (Lettura)
    // ------------------------------------------
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';

        switch ($action) {
            case 'list_win_printers':
                echo json_encode(['printers' => getWindowsPrinters()]);
                break;

            case 'list_linux_printers':
                $linuxDebug = [];
                $linuxPrinters = getLinuxPrinters($linuxDebug);
                echo json_encode(['printers' => $linuxPrinters, 'debug' => $linuxDebug]);
                break;

            case 'payment_config':
                $cassa_id = trim((string)($_GET['cassa_id'] ?? ''));
                if ($cassa_id === '') {
                    echo json_encode(['error' => 'Cassa ID mancante.']);
                    break;
                }

                $stmt = $connectionDB->prepare("SELECT abilita_contanti, abilita_carta, abilita_satispay FROM casse_stampanti WHERE cassa_id = ? LIMIT 1");
                $stmt->bind_param('s', $cassa_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $config = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                echo json_encode([
                    'configured' => (bool)$config,
                    'abilita_contanti' => $config ? (int)$config['abilita_contanti'] : 1,
                    'abilita_carta' => $config ? (int)$config['abilita_carta'] : 0,
                    'abilita_satispay' => $config ? (int)$config['abilita_satispay'] : 0
                ]);
                break;

            case 'list':
            default:
                $result = $connectionDB->query("SELECT cassa_id, tipo_stampante, nome_indirizzo, porta, qz_host, abilita_contanti, abilita_carta, abilita_satispay FROM casse_stampanti ORDER BY cassa_id ASC");
                $stampanti = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode($stampanti);
                break;
        }
        exit;
    }

    // ------------------------------------------
    // 2. RICHIESTE POST (Scrittura / Update / Delete)
    // ------------------------------------------
    if ($method === 'POST') {
        // Legge sia payload JSON che form data standard
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $action = $input['action'] ?? '';

        // Estrazione comune per CREATE / UPDATE
        if (in_array($action, ['create', 'update'], true)) {
            $cassa_id       = trim($input['cassa_id'] ?? '');
            $tipo_stampante = trim($input['tipo_stampante'] ?? '');
            $nome_indirizzo = trim($input['nome_indirizzo'] ?? '');
            $porta          = isset($input['porta']) ? (int)$input['porta'] : 0;
            $qz_host        = trim($input['qz_host'] ?? '');
            $cassa_id_old   = trim($input['cassa_id_old'] ?? $cassa_id);
            $abilita_contanti = normalizePaymentFlag($input['abilita_contanti'] ?? null, 1);
            $abilita_carta = normalizePaymentFlag($input['abilita_carta'] ?? null, 0);
            $abilita_satispay = normalizePaymentFlag($input['abilita_satispay'] ?? null, 0);

            if ($tipo_stampante === 'BLUETOOTH' && $nome_indirizzo === '') {
                $nome_indirizzo = '-';
            }

            if (empty($cassa_id) || empty($tipo_stampante)) {
                echo json_encode(['error' => 'I campi Cassa ID e Tipo Stampante sono obbligatori.']);
                exit;
            }

            if ($tipo_stampante !== 'BLUETOOTH' && empty($nome_indirizzo)) {
                echo json_encode(['error' => 'Il campo Nome Stampante / Indirizzo IP è obbligatorio.']);
                exit;
            }

            if (($abilita_contanti + $abilita_carta + $abilita_satispay) === 0) {
                echo json_encode(['error' => 'Definire almeno un metodo di pagamento']);
                exit;
            }
        }

        switch ($action) {
            case 'create':
                $checkStmt = $connectionDB->prepare("SELECT COUNT(*) FROM casse_stampanti WHERE cassa_id = ?");
                $checkStmt->bind_param("s", $cassa_id);
                $checkStmt->execute();
                $checkStmt->bind_result($count);
                $checkStmt->fetch();
                $checkStmt->close();

                if ($count > 0) {
                    echo json_encode(['error' => "La cassa '{$cassa_id}' è già presente."]);
                    exit;
                }

                $stmt = $connectionDB->prepare("INSERT INTO casse_stampanti (cassa_id, tipo_stampante, nome_indirizzo, porta, qz_host, abilita_contanti, abilita_carta, abilita_satispay) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssisiii", $cassa_id, $tipo_stampante, $nome_indirizzo, $porta, $qz_host, $abilita_contanti, $abilita_carta, $abilita_satispay);

                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Stampante aggiunta con successo.']);
                } else {
                    echo json_encode(['error' => 'Errore nel salvataggio: ' . $connectionDB->error]);
                }
                $stmt->close();
                break;

            case 'update':
                $stmt = $connectionDB->prepare("UPDATE casse_stampanti SET cassa_id = ?, tipo_stampante = ?, nome_indirizzo = ?, porta = ?, qz_host = ?, abilita_contanti = ?, abilita_carta = ?, abilita_satispay = ? WHERE cassa_id = ?");
                $stmt->bind_param("sssisiiis", $cassa_id, $tipo_stampante, $nome_indirizzo, $porta, $qz_host, $abilita_contanti, $abilita_carta, $abilita_satispay, $cassa_id_old);

                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Configurazione aggiornata con successo.']);
                } else {
                    echo json_encode(['error' => 'Errore nell\'aggiornamento: ' . $connectionDB->error]);
                }
                $stmt->close();
                break;

            case 'delete':
                $cassa_id = trim($input['cassa_id'] ?? '');
                if (empty($cassa_id)) {
                    echo json_encode(['error' => 'ID cassa non fornito per l\'eliminazione.']);
                    exit;
                }

                $stmt = $connectionDB->prepare("DELETE FROM casse_stampanti WHERE cassa_id = ?");
                $stmt->bind_param("s", $cassa_id);

                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Stampante eliminata con successo.']);
                } else {
                    echo json_encode(['error' => 'Errore nell\'eliminazione: ' . $connectionDB->error]);
                }
                $stmt->close();
                break;

            default:
                echo json_encode(['error' => 'Azione POST non valida.']);
                break;
        }
        exit;
    }

    echo json_encode(['error' => 'Metodo di richiesta non supportato.']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore Server: ' . $e->getMessage()]);
}