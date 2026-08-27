<?php
require_once 'config/get_db_connection.php';

echo "=== Configurazione stampanti nel DB ===\n\n";

$result = $connectionDB->query('SELECT * FROM casse_stampanti');
if (!$result) {
    echo "Errore query: " . $connectionDB->error . "\n";
    exit(1);
}

if ($result->num_rows === 0) {
    echo "Nessun record trovato in casse_stampanti\n";
} else {
    while ($r = $result->fetch_assoc()) {
        echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
}

// Verifica se colonna qz_host esiste
$colCheck = $connectionDB->query("SHOW COLUMNS FROM casse_stampanti LIKE 'qz_host'");
echo "\n=== Verifica colonna qz_host ===\n";
if ($colCheck->num_rows > 0) {
    $col = $colCheck->fetch_assoc();
    echo "Colonna esiste: " . json_encode($col, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "ATTENZIONE: Colonna qz_host NON ESISTE nel DB!\n";
}
?>
