<?php
/**
 * Accesso al database di QUESTO PC dalla rete (piano, Fase 6c: "minimo
 * necessario"). Le casse client entrano nel DB del server con l'account
 * '<DB_POS_USER>'@'%': resta bloccato (ACCOUNT LOCK) finche' questo PC non fa
 * da server per scelta esplicita ("Accetta casse client" in Configurazione
 * Rete). Gli account locali non si toccano mai.
 *
 * Lo sblocco passa dalla procedura opensagra_remote_access, creata
 * dall'installer (config/crea_dbtable_and_user.php) con un definer dedicato:
 * l'utente dell'app non ha il privilegio CREATE USER.
 *
 * Tutte le funzioni lavorano sul MariaDB LOCALE (127.0.0.1), mai su
 * DB_POS_HOST: su un client quello e' il server di un altro.
 */

require_once __DIR__ . '/env_reader.php';
require_once __DIR__ . '/app_config.php';

const REMOTE_ACCESS_PROC = 'opensagra_remote_access';

function connectLocalDb(): ?mysqli
{
    $env = loadPosEnvVars();
    try {
        $db = mysqli_init();
        $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
        if (!@$db->real_connect('127.0.0.1', $env['user'], $env['pass'], $env['db'])) {
            return null;
        }
        return $db;
    } catch (mysqli_sql_exception $e) {
        return null;
    }
}

/**
 * @param string $action 'lock' | 'unlock' | 'status'
 * @return bool|null true = bloccato, false = aperto, null = procedura assente
 *                   (installazione precedente a questa funzionalita': serve
 *                   una reinstallazione) o errore.
 */
function callRemoteAccess(mysqli $local, string $action): ?bool
{
    if (!in_array($action, ['lock', 'unlock', 'status'], true)) {
        return null;
    }
    try {
        $res = $local->query('CALL `' . REMOTE_ACCESS_PROC . "`('$action')");
        $row = $res ? $res->fetch_assoc() : null;
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        while ($local->more_results() && $local->next_result()) {
            // svuota i result set residui della CALL
        }
        if ($row === null) {
            return null; // account '%' inesistente
        }
        $v = strtolower(trim((string) $row['locked']));
        return $v === '1' || $v === 'true';
    } catch (mysqli_sql_exception $e) {
        error_log('callRemoteAccess(' . $action . '): ' . $e->getMessage());
        return null;
    }
}

/**
 * Applica la scelta e la ricorda in app_config.ACCEPT_CLIENTS (la
 * reinstallazione la ripristina). Ritorna lo stato reale dopo il cambio.
 */
function setAcceptClients(mysqli $local, bool $accept): ?bool
{
    $locked = callRemoteAccess($local, $accept ? 'unlock' : 'lock');
    if ($locked !== null) {
        setAppConfig($local, 'ACCEPT_CLIENTS', $accept ? '1' : '0');
    }
    return $locked;
}
