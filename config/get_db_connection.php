<?php
$envFile = __DIR__ . '/variabili.env';
$vars = [];

// Carica il file .env SOLO se esiste, altrimenti prosegue usando le variabili di default
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // Rimuove eventuali commenti sulla stessa riga (#)
        if (($hashPos = strpos($value, '#')) !== false) {
            $value = substr($value, 0, $hashPos);
        }

        $value = trim($value, "\"' ");
        $vars[$key] = $value;
    }
}

$servername = $vars['DB_POS_HOST'] ?? '127.0.0.1';
$username   = $vars['DB_POS_USER'] ?? 'nuovo_utente_pos';
$password   = $vars['DB_POS_PASS'] ?? 'PasswordSicura2026!';
$database   = 'opensagra_pos'; // Nome del database da selezionare

$connectionDB = new mysqli($servername, $username, $password, $database);
if ($connectionDB->connect_error) { 
    die("Connection failed: " . $connectionDB->connect_error); 
}