<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

// Legge il body grezzo della richiesta HTTP
$req = file_get_contents('php://input');

if (empty($req)) {
    http_response_code(400);
    echo "Richiesta mancante";
    exit;
}

$privateKeyPem = file_get_contents('digital-certicate.key');
$keyId = openssl_get_privatekey($privateKeyPem);

if (!$keyId) {
    http_response_code(500);
    echo "Chiave privata non valida o illeggibile";
    exit;
}

openssl_sign($req, $signature, $keyId, OPENSSL_ALGO_SHA256);
openssl_free_key($keyId);

echo base64_encode($signature);