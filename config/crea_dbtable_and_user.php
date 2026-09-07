<?php
/**
 * Crea il database, importa lo schema (se manca) e crea/aggiorna l'utente
 * applicativo. Idempotente: si puo' rilanciare senza danni su un'istanza
 * gia' provisionata (schema import saltato se le tabelle esistono gia',
 * utente/permessi riallineati comunque).
 *
 * Uso da browser (comportamento storico, invariato): apri il file dalla
 * webroot. Usa le credenziali root di default XAMPP (root, password vuota)
 * sull'host letto da config/variabili.env.
 *
 * Uso da CLI (per lo script d'installazione di Fase 3):
 *   php config/crea_dbtable_and_user.php [opzioni]
 *     --root-host=HOST   host del server MariaDB come root (default: quello di variabili.env)
 *     --root-user=USER   utente root (default: root)
 *     --root-pass=PASS   password root (default: vuota)
 *     -h, --help         mostra questo aiuto
 *
 * Exit code: 0 = successo, 1 = errore (a differenza della versione
 * precedente, che usava die() con una stringa e usciva sempre con
 * codice 0 anche in caso di errore — inutilizzabile per l'automazione).
 */

require_once __DIR__ . '/get_db_connection.php';

$isCli = (PHP_SAPI === 'cli');

function outLine(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
}

function fail(string $msg): void
{
    global $isCli;
    if ($isCli) {
        fwrite(STDERR, "ERRORE: $msg\n");
    } else {
        echo "ERRORE: $msg<br>\n";
    }
    exit(1);
}

// --- Parsing argomenti CLI (nessun effetto se lanciato da browser) ---
$rootHost = $servername; // da get_db_connection.php: stesso host dell'app
$rootUser = 'root';
$rootPass = '';

if ($isCli) {
    $shortOpts = 'h';
    $longOpts = ['root-host:', 'root-user:', 'root-pass:', 'help'];
    $opts = getopt($shortOpts, $longOpts);

    if (isset($opts['h']) || isset($opts['help'])) {
        echo <<<HELP
Uso: php config/crea_dbtable_and_user.php [opzioni]
  --root-host=HOST   host del server MariaDB come root (default: $rootHost)
  --root-user=USER   utente root (default: root)
  --root-pass=PASS   password root (default: vuota)
  -h, --help         mostra questo aiuto

HELP;
        exit(0);
    }

    if (isset($opts['root-host'])) {
        $rootHost = $opts['root-host'];
    }
    if (isset($opts['root-user'])) {
        $rootUser = $opts['root-user'];
    }
    if (isset($opts['root-pass'])) {
        $rootPass = $opts['root-pass'];
    }
}

// Riassegna/utilizza i valori caricati da get_db_connection.php per l'utente applicativo
$db_host        = $servername;
$nuovo_utente   = $username;
$nuova_password = $password;
$db_nome        = $database;

outLine('Inizio configurazione database...');

// Connessione iniziale come ROOT per creare il database e l'utente definiti nel file variabili.env.
// Nota: da PHP 8.1 mysqli e' in modalita' eccezioni di default (mysqli_report),
// quindi le credenziali sbagliate lanciano mysqli_sql_exception invece di
// valorizzare connect_error — va intercettata esplicitamente, altrimenti lo
// script termina con uno stack trace invece di un messaggio comprensibile.
try {
    $connRoot = new mysqli($rootHost, $rootUser, $rootPass);
} catch (mysqli_sql_exception $e) {
    fail("Connessione come root a '$rootHost' fallita: " . $e->getMessage());
    exit(1); // irraggiungibile (fail() esce gia'), solo per chiarezza statica
}
outLine('Connesso con successo come root.');

// Creazione del Database (se non esiste)
$sqlCreateDB = "CREATE DATABASE IF NOT EXISTS `$db_nome` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
if ($connRoot->query($sqlCreateDB) === true) {
    outLine("Database '$db_nome' verificato/creato con successo.");
} else {
    fail('Errore nella creazione del database: ' . $connRoot->error);
}

// Selezioniamo il database appena creato per la sessione corrente
if (!$connRoot->select_db($db_nome)) {
    fail("Impossibile selezionare il database '$db_nome': " . $connRoot->error);
}

// Import dello schema SQL (solo se il database e' vuoto: idempotente)
$schemaFile = __DIR__ . '/pos.sql';
$existingTables = $connRoot->query('SHOW TABLES');

if ($existingTables && $existingTables->num_rows > 0) {
    outLine('Schema SQL gia\' presente, import saltato.');
} elseif (file_exists($schemaFile)) {
    $schemaSql = file_get_contents($schemaFile);
    if ($schemaSql !== false) {
        if ($connRoot->multi_query($schemaSql)) {
            do {
                if ($result = $connRoot->store_result()) {
                    $result->free();
                }
            } while ($connRoot->next_result());
            outLine('Schema SQL caricato con successo, database pronto.');
        } else {
            fail('Errore durante il caricamento dello schema SQL: ' . $connRoot->error);
        }
    } else {
        fail('Impossibile leggere il file dello schema SQL.');
    }
} else {
    fail("File dello schema SQL non trovato: $schemaFile");
}

// Creazione dell'utente e assegnazione dei privilegi.
// In ambiente XAMPP locale la connessione puo' essere risolta come localhost o 127.0.0.1,
// quindi allineiamo i privilegi agli host effettivamente usati dall'app.
$userHosts = ['localhost', '127.0.0.1', '%'];

foreach ($userHosts as $userHost) {
    $sqlCreateUser = "CREATE USER IF NOT EXISTS '$nuovo_utente'@'$userHost' IDENTIFIED BY '$nuova_password';";
    $sqlAlterUser = "ALTER USER '$nuovo_utente'@'$userHost' IDENTIFIED BY '$nuova_password';";
    $sqlGrant = "GRANT ALL PRIVILEGES ON `$db_nome`.* TO '$nuovo_utente'@'$userHost';";
    // Privilegio globale (non per-database): serve solo a SHOW PROCESSLIST, per
    // poter avvisare "N altre casse sono connesse in questo momento" nella
    // pagina Configurazione Rete prima di staccarsi da un ruolo di server.
    // Innocuo su un'istanza MariaDB dedicata solo a opensagra (nessun altro
    // database/utente le cui connessioni sarebbe indiscreto vedere).
    $sqlGrantProcess = "GRANT PROCESS ON *.* TO '$nuovo_utente'@'$userHost';";

    if (!$connRoot->query($sqlCreateUser)) {
        fail("Errore nella creazione utente per host '$userHost': " . $connRoot->error);
    }
    if (!$connRoot->query($sqlAlterUser)) {
        fail("Errore nell'aggiornamento password utente per host '$userHost': " . $connRoot->error);
    }
    if (!$connRoot->query($sqlGrant)) {
        fail("Errore nell'assegnazione dei privilegi per host '$userHost': " . $connRoot->error);
    }
    if (!$connRoot->query($sqlGrantProcess)) {
        fail("Errore nell'assegnazione del privilegio PROCESS per host '$userHost': " . $connRoot->error);
    }
}

if (!$connRoot->query('FLUSH PRIVILEGES;')) {
    fail('Errore nel flush dei privilegi: ' . $connRoot->error);
}

outLine("Utente '$nuovo_utente' configurato e privilegi assegnati correttamente.");

$connRoot->close();
outLine('Configurazione completata.');
exit(0);
