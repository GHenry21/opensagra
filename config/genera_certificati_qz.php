<?php
/**
 * Genera (una tantum, idempotente) la coppia chiave privata + certificato
 * self-signed usata per firmare le richieste verso QZ Tray e sopprimerne il
 * popup di conferma connessione (assets/js/qz-helper.js, api/sign-message.php).
 *
 * Prima di questo script la coppia era un file cert/cert.pem fisso,
 * committato in git e condiviso da ogni installazione, con la chiave privata
 * corrispondente da posizionare a mano - passo manuale mai davvero
 * documentato ne' automatizzato, e comunque un segreto condiviso da tutte le
 * installazioni. Generarla qui, una per installazione, e' sia piu' semplice
 * (zero passi manuali) sia piu' corretto (nessun segreto condiviso tra
 * installazioni indipendenti) - vedi piano, Fase 3i.
 *
 * Idempotente: se chiave privata e certificato esistono gia' entrambi, non
 * fa nulla (non rigenera una coppia gia' funzionante, es. su un reinstall o
 * riparazione). Se manca anche solo uno dei due, rigenera l'intera coppia
 * (una chiave senza il suo certificato o viceversa e' comunque inutile).
 *
 * Uso da CLI (per install.ps1):
 *   php config/genera_certificati_qz.php [opzioni]
 *     --key-path=PATH       default: getQzPrivateKeyPath()
 *     --cert-pem-path=PATH  default: cert/cert.pem nella webroot
 *     --cert-cer-path=PATH  default: cert/cert.cer nella webroot
 *     --force               rigenera anche se gia' presenti
 *     -h, --help
 *
 * Exit code: 0 = successo (compreso "gia' presente, saltato"), 1 = errore.
 */

require_once __DIR__ . '/qz_key_path.php';

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

$keyPath = getQzPrivateKeyPath();
$certPemPath = __DIR__ . '/../cert/cert.pem';
$certCerPath = __DIR__ . '/../cert/cert.cer';
$force = false;

if ($isCli) {
    $opts = getopt('h', ['key-path:', 'cert-pem-path:', 'cert-cer-path:', 'force', 'help']);

    if (isset($opts['h']) || isset($opts['help'])) {
        echo <<<HELP
Uso: php config/genera_certificati_qz.php [opzioni]
  --key-path=PATH       percorso della chiave privata (default: $keyPath)
  --cert-pem-path=PATH  percorso del certificato PEM (default: $certPemPath)
  --cert-cer-path=PATH  percorso del certificato CER/DER (default: $certCerPath)
  --force               rigenera anche se gia' presenti
  -h, --help            mostra questo aiuto

HELP;
        exit(0);
    }

    if (isset($opts['key-path'])) {
        $keyPath = $opts['key-path'];
    }
    if (isset($opts['cert-pem-path'])) {
        $certPemPath = $opts['cert-pem-path'];
    }
    if (isset($opts['cert-cer-path'])) {
        $certCerPath = $opts['cert-cer-path'];
    }
    if (isset($opts['force'])) {
        $force = true;
    }
}

if (!$force && is_file($keyPath) && is_file($certPemPath)) {
    outLine("Certificati QZ Tray gia' presenti ($keyPath), generazione saltata.");
    exit(0);
}

outLine('Generazione coppia chiave/certificato per QZ Tray...');

$privateKeyResource = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
if ($privateKeyResource === false) {
    fail('Generazione della chiave privata fallita: ' . openssl_error_string());
}

if (!openssl_pkey_export($privateKeyResource, $privateKeyPem)) {
    fail('Esportazione della chiave privata fallita: ' . openssl_error_string());
}

// Campi specificati tutti esplicitamente: altrimenti openssl_csr_new eredita
// i placeholder di default del template openssl.cnf locale (es. "C=AU,
// O=Internet Widgits Pty Ltd") - innocuo ma sciatto se qualcuno ispeziona il
// certificato.
$dn = [
    'countryName' => 'IT',
    'stateOrProvinceName' => 'IT',
    'organizationName' => 'opensagra',
    'commonName' => 'opensagra QZ Tray',
];
$csr = openssl_csr_new($dn, $privateKeyResource);
if ($csr === false) {
    fail('Generazione della richiesta di certificato fallita: ' . openssl_error_string());
}

// Self-signed, 10 anni: usata solo per sopprimere il popup locale di QZ Tray,
// non per un vero canale cifrato - una validita' lunga evita di dover
// rigenerare la coppia (e ridistribuire l'override su ogni QZ Tray) a
// scadenza.
$cert = openssl_csr_sign($csr, null, $privateKeyResource, 3650);
if ($cert === false) {
    fail('Generazione del certificato fallita: ' . openssl_error_string());
}

if (!openssl_x509_export($cert, $certPem)) {
    fail('Esportazione del certificato fallita: ' . openssl_error_string());
}

$keyDir = dirname($keyPath);
if (!is_dir($keyDir) && !mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
    fail("Impossibile creare la cartella '$keyDir'.");
}
if (file_put_contents($keyPath, $privateKeyPem) === false) {
    fail("Impossibile scrivere la chiave privata in '$keyPath'.");
}
outLine("Chiave privata scritta in '$keyPath'.");

$certDir = dirname($certPemPath);
if (!is_dir($certDir) && !mkdir($certDir, 0700, true) && !is_dir($certDir)) {
    fail("Impossibile creare la cartella '$certDir'.");
}
if (file_put_contents($certPemPath, $certPem) === false) {
    fail("Impossibile scrivere il certificato PEM in '$certPemPath'.");
}
outLine("Certificato PEM scritto in '$certPemPath'.");

// Formato .cer (DER), solo per comodita' di import manuale (nessun codice lo
// legge a runtime) - stesso certificato, solo binario invece che base64.
$pemBody = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|[\r\n]+/', '', $certPem);
$der = base64_decode((string) $pemBody, true);
if ($der === false) {
    fail('Conversione del certificato in formato DER fallita.');
}
if (file_put_contents($certCerPath, $der) === false) {
    fail("Impossibile scrivere il certificato CER in '$certCerPath'.");
}
outLine("Certificato CER scritto in '$certCerPath'.");

outLine('Generazione completata.');
exit(0);
