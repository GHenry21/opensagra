<?php
// 1. Configura i dettagli del certificato
$dn = [
    "commonName" => "OpenSagra Digital Certificate",
];

// 2. Genera la chiave privata e pubblica
$config = [
    "private_key_bits" => 4096,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
];

$privateKeyResource = openssl_pkey_new($config);

// 3. Estrai la chiave privata
openssl_pkey_export($privateKeyResource, $privateKeyPem);

// 4. Genera il certificato Self-Signed (valido per 10 anni)
$csrResource = openssl_csr_new($dn, $privateKeyResource, array('digest_alg' => 'sha256'));
$certResource = openssl_csr_sign($csrResource, null, $privateKeyResource, 3650, array('digest_alg' => 'sha256'));

openssl_x509_export($certResource, $certPem);

// 5. Salva i file sul disco
file_put_contents('digital-certificate.key', $privateKeyPem);
file_put_contents('digital-certificate.txt', $certPem);

echo "Chiavi generate con successo!\n";
echo "Creati i file: digital-certificate.key e digital-certificate.txt\n";