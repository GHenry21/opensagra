<?php
require_once __DIR__ . '/env_reader.php';

$envVars = loadPosEnvVars();
$envFile = $envVars['env_file'];

$servername = $envVars['host'];
$username   = $envVars['user'];
$password   = $envVars['pass'];
$database   = $envVars['db'];

$connectionDB = new mysqli($servername, $username, $password, $database);
if ($connectionDB->connect_error) {
    die("Connection failed: " . $connectionDB->connect_error);
}
