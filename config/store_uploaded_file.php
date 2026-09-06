<?php

/**
 * Salva un file caricato via HTTP dentro l'app facendo in modo che erediti le ACL
 * della cartella di destinazione.
 *
 * Perche' non usare direttamente move_uploaded_file(): su Windows quella funzione
 * usa MoveFile(), che CONSERVA la ACL del file temporaneo di PHP. Se il processo
 * web gira come LocalSystem (es. il servizio FrankenPHP), il file temporaneo si
 * trova in C:\Windows\Temp, leggibile solo da SYSTEM/Administrators: il file
 * spostato in uploads/ eredita quella ACL restrittiva e diventa illeggibile per
 * l'utente interattivo e per un eventuale worker Apache/XAMPP che serve lo stesso
 * albero.
 *
 * copy() invece CREA un nuovo file a destinazione, che eredita normalmente le ACE
 * ereditabili della cartella (su uploads/: Authenticated Users = Modify).
 *
 * @throws RuntimeException se il file non e' un upload valido o la copia fallisce.
 */
function storeUploadedFile(string $tmpName, string $targetFile): void
{
    if (!is_uploaded_file($tmpName)) {
        throw new RuntimeException('File non valido: non risulta un upload HTTP.');
    }

    if (!copy($tmpName, $targetFile)) {
        throw new RuntimeException('Impossibile salvare il file caricato.');
    }

    @unlink($tmpName);
}
