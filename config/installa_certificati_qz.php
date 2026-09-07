<?php
/**
 * Posiziona la chiave privata di firma per QZ Tray, copiandola dal pacchetto
 * di installazione invece di generarne una nuova.
 *
 * Decisione (2026-09-07): si distribuisce la STESSA coppia chiave/certificato
 * con ogni installazione (quella dell'autore), invece di generarne una
 * diversa per macchina. Prima versione di questo script (allora chiamato
 * genera_certificati_qz.php) generava una coppia unica per installazione via
 * openssl_pkey_new() - tecnicamente piu' corretto (nessun segreto condiviso
 * tra installazioni indipendenti), ma emerso un problema pratico appena
 * testato: due installazioni con certificati diversi che devono parlare con
 * lo stesso QZ Tray fisico (es. VM di test + macchina reale nello stesso
 * scenario di prova) non si fidano a vicenda senza un passo di sync manuale.
 * Scelta finale: stessa coppia per tutti, piu' semplice e prevedibile.
 *
 * cert/cert.pem (+ cert/cert.cer) sono gia' nella webroot, committati in git,
 * copiati come qualsiasi altro file da Copy-AppFiles in install.ps1 - non
 * serve occuparsene qui. Questo script si occupa SOLO della chiave privata,
 * che per costruzione non e' (e non deve mai essere) in git: va bundlata a
 * parte nel pacchetto di installazione, in una cartella 'private/' accanto a
 * install.ps1.
 *
 * Idempotente: se la chiave e' gia' al suo posto, non la sovrascrive (non
 * rimpiazza una chiave gia' in uso su un reinstall/riparazione).
 *
 * Uso da CLI (per install.ps1):
 *   php config/installa_certificati_qz.php --source-key=PATH [opzioni]
 *     --source-key=PATH  chiave privata da copiare (bundlata col pacchetto di installazione)
 *     --key-path=PATH    destinazione (default: getQzPrivateKeyPath())
 *     --force            copia anche se una chiave e' gia' presente
 *     -h, --help         mostra questo aiuto
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
$sourceKeyPath = '';
$force = false;

if ($isCli) {
    $opts = getopt('h', ['source-key:', 'key-path:', 'force', 'help']);

    if (isset($opts['h']) || isset($opts['help'])) {
        echo <<<HELP
Uso: php config/installa_certificati_qz.php --source-key=PATH [opzioni]
  --source-key=PATH  chiave privata da copiare (bundlata col pacchetto di installazione)
  --key-path=PATH     destinazione (default: $keyPath)
  --force             copia anche se una chiave e' gia' presente
  -h, --help          mostra questo aiuto

HELP;
        exit(0);
    }

    if (isset($opts['source-key'])) {
        $sourceKeyPath = $opts['source-key'];
    }
    if (isset($opts['key-path'])) {
        $keyPath = $opts['key-path'];
    }
    if (isset($opts['force'])) {
        $force = true;
    }
}

if (!$force && is_file($keyPath)) {
    outLine("Chiave privata QZ Tray gia' presente ('$keyPath'), copia saltata.");
    exit(0);
}

if ($sourceKeyPath === '') {
    fail("Percorso della chiave privata sorgente mancante (--source-key). E' la chiave bundlata col pacchetto di installazione, non se ne genera una nuova.");
}
if (!is_file($sourceKeyPath)) {
    fail("Chiave privata sorgente non trovata: '$sourceKeyPath'.");
}

$keyDir = dirname($keyPath);
if (!is_dir($keyDir) && !mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
    fail("Impossibile creare la cartella '$keyDir'.");
}

if (!copy($sourceKeyPath, $keyPath)) {
    fail("Impossibile copiare la chiave privata in '$keyPath'.");
}

outLine("Chiave privata copiata in '$keyPath'.");
exit(0);
