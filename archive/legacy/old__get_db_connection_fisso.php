<?php
//$servername = "127.0.0.1";
//$username = "root";
//$password = "";

$servername = '127.0.0.1';
$username = "pos_own";
$password = "pos_own1";
$database = "pos";

$connectionDB = new mysqli($servername, $username, $password, $database);
if ($connectionDB->connect_error) { die("Connection failed: " . $connectionDB->connect_error); }
?>
