<?php
/**
 * Pacchetto dell'aggiornamento leggero della versione di QUESTO PC, per le
 * casse client della rete (piano, Fase 6c punto C): si aggiornano alla
 * versione del server scaricandola da qui, senza bisogno di Internet.
 *
 * GET ?f=manifest|zip|sha. I file li tiene il wrapper (wrapper/dist.go) nella
 * cartella OPENSAGRA_DIST_DIR, fuori dal webroot; questo endpoint li legge e
 * basta, con un elenco chiuso di nomi (nessun percorso dall'esterno).
 *
 * Nessuna autenticazione: e' il codice dell'app, lo stesso pubblicato su
 * GitHub. L'integrita' la verifica la cassa con l'impronta (?f=sha).
 */

$files = [
    'manifest' => ['opensagra-app-update.manifest.json', 'application/json'],
    'zip' => ['opensagra-app-update.zip', 'application/zip'],
    'sha' => ['opensagra-app-update.zip.sha256', 'text/plain; charset=utf-8'],
];

$f = (string) ($_GET['f'] ?? '');
$dir = (string) getenv('OPENSAGRA_DIST_DIR');

if (!isset($files[$f])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'parametro f non valido';
    exit;
}

// VERSION la scrive il wrapper per ultima, a file completi: senza, il
// pacchetto non e' pronto (o non c'e' affatto, es. copia di sviluppo).
if ($dir === '' || !is_file($dir . '/VERSION')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'nessun pacchetto disponibile su questo PC';
    exit;
}

[$name, $type] = $files[$f];
$path = $dir . '/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'file non disponibile';
    exit;
}

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
header('X-OpenSagra-Version: ' . trim((string) file_get_contents($dir . '/VERSION')));
readfile($path);
