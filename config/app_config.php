<?php
/**
 * Coppie chiave/valore condivise nel DB (tabella `app_config`).
 *
 * Nata per la Fase 4 "Opzione A": il SERVER pubblica qui il proprio
 * MERCURE_JWT_SECRET, cosi' che un client, al passaggio a modalita' rete
 * (api/set_network_config.php), lo legga dal DB del server e lo salvi nel
 * proprio variabili.env come MERCURE_JWT_SECRET_REMOTE - senza copia a mano.
 *
 * La tabella si crea da sola alla prima chiamata. Tutte le funzioni sono
 * "non fatali": qualunque errore SQL torna il default / false, non lancia
 * (girano anche nel percorso di checkout).
 */

function ensureAppConfigTable(mysqli $db): bool
{
    try {
        return $db->query(
            "CREATE TABLE IF NOT EXISTS app_config ("
            . " chiave VARCHAR(64) NOT NULL PRIMARY KEY,"
            . " valore TEXT NOT NULL,"
            . " updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ) !== false;
    } catch (Throwable $e) {
        error_log('ensureAppConfigTable: ' . $e->getMessage());
        return false;
    }
}

function getAppConfig(mysqli $db, string $key, string $default = ''): string
{
    try {
        if (!ensureAppConfigTable($db)) {
            return $default;
        }
        $stmt = $db->prepare("SELECT valore FROM app_config WHERE chiave = ? LIMIT 1");
        if ($stmt === false) {
            return $default;
        }
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string) $row['valore'] : $default;
    } catch (Throwable $e) {
        error_log("getAppConfig('$key'): " . $e->getMessage());
        return $default;
    }
}

function setAppConfig(mysqli $db, string $key, string $value): bool
{
    try {
        if (!ensureAppConfigTable($db)) {
            return false;
        }
        $stmt = $db->prepare(
            "INSERT INTO app_config (chiave, valore) VALUES (?, ?)"
            . " ON DUPLICATE KEY UPDATE valore = VALUES(valore)"
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ss', $key, $value);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    } catch (Throwable $e) {
        error_log("setAppConfig('$key'): " . $e->getMessage());
        return false;
    }
}
