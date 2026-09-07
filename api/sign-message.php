<?php
//firma i certificati per i popup di Qz Tray

require __DIR__ . '/../config/qz_key_path.php';

// Percorso centralizzato (vedi config/qz_key_path.php): prima era calcolato
// qui in modo indipendente da config/genera_certificati_qz.php ed era
// finito disallineato con l'installazione a percorso fisso di Fase 3 - bug
// reale trovato testando su VM pulita (2026-09-07).
$KEY = getQzPrivateKeyPath();

$req = $_GET['request'];
$privateKey = openssl_get_privatekey(file_get_contents($KEY) /*, $PASS */);

$signature = null;
openssl_sign($req, $signature, $privateKey, "sha512"); // Use "sha1" for QZ Tray 2.0 and older

if ($signature) {
	header("Content-type: text/plain");
	echo base64_encode($signature);
	exit(0);
}

echo '<h1>Error signing message</h1>';
http_response_code(500);
exit(1);

?>
