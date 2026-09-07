<?php
/**
 * Percorso della chiave privata usata per firmare le richieste verso QZ Tray
 * (api/sign-message.php) e per generarla (config/genera_certificati_qz.php).
 * Centralizzato in un unico posto apposta: prima erano due percorsi
 * calcolati indipendentemente in due file diversi, ed erano finiti
 * disallineati (uno relativo alla webroot, l'altro relativo alla cartella
 * temporanea dell'installer) - bug reale trovato testando su VM pulita
 * (2026-09-07).
 *
 * Fuori dalla webroot per costruzione (C:\ProgramData, non dentro
 * C:\opensagra), cosi' non e' mai raggiungibile via browser. Stessa
 * convenzione di C:\ProgramData\opensagra\php_upload_tmp gia' usata da
 * install.ps1. LocalSystem (l'account con cui gira il servizio frankenphp)
 * ha comunque accesso in lettura di default, non servono ACL dedicate come
 * per la cartella di upload (che deve essere scrivibile anche da altri
 * account).
 */
function getQzPrivateKeyPath(): string
{
    return 'C:\\ProgramData\\opensagra\\private\\key.pem';
}
