<?php
/**
 * Accesso al database di QUESTO PC dalla rete (piano, Fase 6c). Regola
 * (decisione utente 2026-10-07): OpenSagra aperto -> accesso aperto,
 * OpenSagra chiuso -> chiuso; su una cassa client sempre chiuso (lavora sul
 * DB del centrale, il suo non serve a nessuno). Lo applica il wrapper
 * (wrapper/remote_access.go) all'avvio, al cambio di ruolo e all'uscita;
 * api/set_network_config.php lo applica subito al cambio di modalita'.
 *
 * Le casse client entrano nel DB del centrale con l'account
 * '<DB_POS_USER>'@'%': "chiuso" = ACCOUNT LOCK su quell'account. Gli account
 * locali non si toccano mai. Passa dalla procedura opensagra_remote_access,
 * creata dall'installer (config/crea_dbtable_and_user.php) con un definer
 * dedicato: l'utente dell'app non ha il privilegio CREATE USER.
 *
 * Tutte le funzioni lavorano sul MariaDB LOCALE (127.0.0.1), mai su
 * DB_POS_HOST: su un client quello e' il centrale di un altro.
 */

require_once __DIR__ . '/env_reader.php';

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
 * @return bool|null true = chiuso, false = aperto, null = procedura assente
 *                   (installazione precedente a questa funzionalita') o errore.
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
