<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/printer_discovery.php';

// Discovery stampanti (locale e proxy verso un altro PC della LAN): nessun DB,
// gestita PRIMA di require get_db_connection.php cosi' gira anche su un PC-ponte
// non ancora in modalita' client (MariaDB non raggiungibile).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && in_array($_GET['action'] ?? '', ['list_win_printers', 'list_linux_printers', 'remote_list_printers'], true)) {
    handlePrinterDiscovery((string) $_GET['action']);
    exit;
}

// Include la connessione al database
require_once __DIR__ . '/../config/get_db_connection.php';

function ensurePaymentMethodColumns($connectionDB) {
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS abilita_contanti TINYINT(1) NOT NULL DEFAULT 1 AFTER qz_host");
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS abilita_carta TINYINT(1) NOT NULL DEFAULT 0 AFTER abilita_contanti");
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS abilita_satispay TINYINT(1) NOT NULL DEFAULT 0 AFTER abilita_carta");
}

function ensureFondoCassaColumn($connectionDB) {
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS fondo_cassa DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER abilita_satispay");
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS ultima_chiusura DATETIME NULL DEFAULT NULL AFTER fondo_cassa");
}

function ensureBridgeNativeColumns($connectionDB) {
    // Modello BRIDGE_NATIVE "a una riga": tipo stampante fisica del ponte +
    // id-topic Mercure. Vuoti = vecchio modello "a due righe".
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS bridge_printer_type VARCHAR(20) NULL AFTER qz_host");
    $connectionDB->query("ALTER TABLE casse_stampanti ADD COLUMN IF NOT EXISTS bridge_topic VARCHAR(50) NULL AFTER bridge_printer_type");
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
    ensureFondoCassaColumn($connectionDB);
    ensureBridgeNativeColumns($connectionDB);

    // ------------------------------------------
    // 1. RICHIESTE GET (Lettura)
    // ------------------------------------------
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';

        switch ($action) {
            // list_win_printers / list_linux_printers / remote_list_printers:
            // gestite prima del require del DB (vedi in cima al file).

            case 'payment_config':
                $cassa_id = trim((string)($_GET['cassa_id'] ?? ''));
                if ($cassa_id === '') {
                    echo json_encode(['error' => 'Cassa ID mancante.']);
                    break;
                }

                $stmt = $connectionDB->prepare("SELECT abilita_contanti, abilita_carta, abilita_satispay, fondo_cassa, ultima_chiusura FROM casse_stampanti WHERE cassa_id = ? LIMIT 1");
                $stmt->bind_param('s', $cassa_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $config = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                echo json_encode([
                    'configured' => (bool)$config,
                    'abilita_contanti' => $config ? (int)$config['abilita_contanti'] : 1,
                    'abilita_carta' => $config ? (int)$config['abilita_carta'] : 0,
                    'abilita_satispay' => $config ? (int)$config['abilita_satispay'] : 0,
                    'fondo_cassa' => $config ? (float)$config['fondo_cassa'] : 0,
                    'ultima_chiusura' => $config ? $config['ultima_chiusura'] : null
                ]);
                break;

            case 'list':
            default:
                $result = $connectionDB->query("SELECT cassa_id, tipo_stampante, nome_indirizzo, porta, qz_host, bridge_printer_type, bridge_topic, abilita_contanti, abilita_carta, abilita_satispay, fondo_cassa, ultima_chiusura FROM casse_stampanti ORDER BY cassa_id ASC");
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
            // Modello BRIDGE_NATIVE "a una riga": stampante fisica del ponte +
            // id-topic Mercure. Solo per BRIDGE_NATIVE, altrimenti azzerati.
            $bridge_printer_type = trim($input['bridge_printer_type'] ?? '');
            $bridge_topic        = trim($input['bridge_topic'] ?? '');
            if ($tipo_stampante !== 'BRIDGE_NATIVE') {
                $bridge_printer_type = '';
                $bridge_topic = '';
            }
            $cassa_id_old   = trim($input['cassa_id_old'] ?? $cassa_id);
            $abilita_contanti = normalizePaymentFlag($input['abilita_contanti'] ?? null, 1);
            $abilita_carta = normalizePaymentFlag($input['abilita_carta'] ?? null, 0);
            $abilita_satispay = normalizePaymentFlag($input['abilita_satispay'] ?? null, 0);
            $fondo_cassa = isset($input['fondo_cassa']) ? (float)str_replace(',', '.', (string)$input['fondo_cassa']) : 0;

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

            if ($tipo_stampante === 'BRIDGE_NATIVE' && $bridge_printer_type !== ''
                && !in_array($bridge_printer_type, ['USB', 'WIN_USB', 'LINUX_USB', 'RETE'], true)) {
                echo json_encode(['error' => 'Tipo stampante del ponte non valido (attesi USB / WIN_USB / LINUX_USB / RETE).']);
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

                $stmt = $connectionDB->prepare("INSERT INTO casse_stampanti (cassa_id, tipo_stampante, nome_indirizzo, porta, qz_host, bridge_printer_type, bridge_topic, abilita_contanti, abilita_carta, abilita_satispay, fondo_cassa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssisssiiid", $cassa_id, $tipo_stampante, $nome_indirizzo, $porta, $qz_host, $bridge_printer_type, $bridge_topic, $abilita_contanti, $abilita_carta, $abilita_satispay, $fondo_cassa);

                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Stampante aggiunta con successo.']);
                } else {
                    echo json_encode(['error' => 'Errore nel salvataggio: ' . $connectionDB->error]);
                }
                $stmt->close();
                break;

            case 'update':
                $stmt = $connectionDB->prepare("UPDATE casse_stampanti SET cassa_id = ?, tipo_stampante = ?, nome_indirizzo = ?, porta = ?, qz_host = ?, bridge_printer_type = ?, bridge_topic = ?, abilita_contanti = ?, abilita_carta = ?, abilita_satispay = ?, fondo_cassa = ? WHERE cassa_id = ?");
                $stmt->bind_param("sssisssiiids", $cassa_id, $tipo_stampante, $nome_indirizzo, $porta, $qz_host, $bridge_printer_type, $bridge_topic, $abilita_contanti, $abilita_carta, $abilita_satispay, $fondo_cassa, $cassa_id_old);

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