<?php
// Include il file di connessione centralizzato per recuperare le configurazioni ($vars, $servername, $username, $password, $database)
require_once __DIR__ . '/get_db_connection.php';

// Riassegna/utilizza i valori caricati da get_db_connection.php
$db_host        = $servername;
$nuovo_utente   = $username;
$nuova_password = $password;
$db_nome        = $database;

echo "Inizio configurazione database...\n<br>";

// Connessione iniziale come ROOT (senza password) per creare il database e l'utente definiti nel file variabili.env.
$connRoot = new mysqli($db_host, 'root', '');

if ($connRoot->connect_error) {
    die("Connessione come root fallita: " . $connRoot->connect_error);
}
echo "Connesso con successo come root.<br>\n";

//  Creazione del Database (se non esiste)
$sqlCreateDB = "CREATE DATABASE IF NOT EXISTS `$db_nome` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
if ($connRoot->query($sqlCreateDB) === TRUE) {
    echo "Database '$db_nome' verificato/creato con successo.<br>\n";
} else {
    echo "Errore nella creazione del database: " . $connRoot->error . "<br>\n";
}

// Selezioniamo il database appena creato per la sessione corrente
if (!$connRoot->select_db($db_nome)) {
    die("Impossibile selezionare il database '$db_nome': " . $connRoot->error);
}

// upload dello schema SQL (se esiste)
$schemaFile = __DIR__ . '/pos.sql';
$existingTables = $connRoot->query("SHOW TABLES");

if ($existingTables && $existingTables->num_rows > 0) {
    echo "Schema SQL gia' presente, import saltato.<br>\n";
} elseif (file_exists($schemaFile)) {
    $schemaSql = file_get_contents($schemaFile);
    if ($schemaSql !== false) {
        if ($connRoot->multi_query($schemaSql)) {
            do {
                if ($result = $connRoot->store_result()) {
                    $result->free();
                }
            } while ($connRoot->next_result());
            echo "Schema SQL caricato con successo, database pronto, verificare in pagina Inserisci prodotti o Vendita.<br>\n";
        } else {
            echo "Errore durante il caricamento dello schema SQL: " . $connRoot->error . "<br>\n";
        }
    } else {
        echo "Impossibile leggere il file dello schema SQL.<br>\n";
    }
} else {
    echo "File dello schema SQL non trovato: $schemaFile<br>\n";
}

//  Creazione dell'utente e assegnazione dei privilegi.
// In ambiente XAMPP locale la connessione puo' essere risolta come localhost o 127.0.0.1,
// quindi allineiamo i privilegi agli host effettivamente usati dall'app.
$userHosts = ['localhost', '127.0.0.1', '%'];

foreach ($userHosts as $userHost) {
    $sqlCreateUser = "CREATE USER IF NOT EXISTS '$nuovo_utente'@'$userHost' IDENTIFIED BY '$nuova_password';";
    $sqlAlterUser = "ALTER USER '$nuovo_utente'@'$userHost' IDENTIFIED BY '$nuova_password';";
    $sqlGrant = "GRANT ALL PRIVILEGES ON `$db_nome`.* TO '$nuovo_utente'@'$userHost';";

    if (!$connRoot->query($sqlCreateUser)) {
        die("Errore nella creazione utente per host '$userHost': " . $connRoot->error);
    }

    if (!$connRoot->query($sqlAlterUser)) {
        die("Errore nell'aggiornamento password utente per host '$userHost': " . $connRoot->error);
    }

    if (!$connRoot->query($sqlGrant)) {
        die("Errore nell'assegnazione dei privilegi per host '$userHost': " . $connRoot->error);
    }
}

$sqlFlush = "FLUSH PRIVILEGES;";
if (!$connRoot->query($sqlFlush)) {
    die("Errore nel flush dei privilegi: " . $connRoot->error);
}

echo "Utente '$nuovo_utente' configurato e privilegi assegnati correttamente.<br>\n";

// Chiude la connessione root
$connRoot->close();
echo "Configurazione completata.\n";