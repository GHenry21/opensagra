<?php
//firma i certificati per i popup di Qz Tray

// Sample key.  Replace with one used for CSR generation
$KEY = __DIR__ . '/../../../private/key.pem';

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
