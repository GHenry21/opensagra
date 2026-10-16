<?php
/**
 * Pacchetti della versione di QUESTO PC, per le casse client della rete
 * (piano, Fase 6c): si aggiornano alla versione del server scaricandola da
 * qui, senza bisogno di Internet.
 *
 * GET ?f=manifest|zip|sha      aggiornamento leggero (punto C)
 * GET ?f=installers            elenco JSON degli installer completi (punto C3)
 * GET ?f=installer&os=&arch=   un installer completo, da scaricare
 *
 * I file li tiene il wrapper (wrapper/dist.go, wrapper/installers.go) nella
 * cartella OPENSAGRA_DIST_DIR, fuori dal webroot; questo endpoint li legge e
 * basta, con un elenco chiuso di nomi (nessun percorso dall'esterno). Gli
 * installer li conserva solo una cassa centrale, per la propria piattaforma e
 * per quelle delle casse viste nel battito.
 *
 * Nessuna autenticazione: e' il codice dell'app, lo stesso pubblicato su
 * GitHub. L'integrita' la verifica la cassa con l'impronta (?f=sha, e sha256
 * nell'elenco degli installer).
 */

$f = (string) ($_GET['f'] ?? '');
$dir = (string) getenv('OPENSAGRA_DIST_DIR');

function notFound(string $msg): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

/** Nome leggibile della piattaforma, per la pagina Rete. */
function platformLabel(string $os, string $arch): string
{
    $labels = [
        'windows/amd64' => 'Windows',
        'linux/amd64' => 'Linux (PC)',
        'linux/arm64' => 'Linux (Raspberry Pi)',
        'darwin/arm64' => 'Mac (Apple Silicon)',
        'darwin/amd64' => 'Mac (Intel)',
    ];
    return $labels["$os/$arch"] ?? "$os/$arch";
}

/** Indice scritto dal wrapper: {version, installers:[{os,arch,name,size,sha256}]}. */
function readInstallers(string $dir): array
{
    if ($dir === '') {
        return ['version' => '', 'installers' => []];
    }
    $raw = @file_get_contents($dir . '/installers/index.json');
    $idx = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($idx) || !is_array($idx['installers'] ?? null)) {
        return ['version' => '', 'installers' => []];
    }
    $out = [];
    foreach ($idx['installers'] as $e) {
        $name = basename((string) ($e['name'] ?? ''));
        if ($name === '' || !is_file($dir . '/installers/' . $name)) {
            continue;
        }
        $out[] = [
            'os' => (string) $e['os'],
            'arch' => (string) $e['arch'],
            'label' => platformLabel((string) $e['os'], (string) $e['arch']),
            'name' => $name,
            'size' => (int) ($e['size'] ?? 0),
            'sha256' => (string) ($e['sha256'] ?? ''),
        ];
    }
    return ['version' => (string) ($idx['version'] ?? ''), 'installers' => $out];
}

if ($f === 'installers') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(readInstallers($dir));
    exit;
}

if ($f === 'installer') {
    $os = (string) ($_GET['os'] ?? '');
    $arch = (string) ($_GET['arch'] ?? '');
    $idx = readInstallers($dir);
    foreach ($idx['installers'] as $e) {
        if ($e['os'] === $os && $e['arch'] === $arch) {
            $path = $dir . '/installers/' . $e['name'];
            set_time_limit(0); // ~80 MB: non deve scadere a meta'
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $e['name'] . '"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: no-store');
            header('X-OpenSagra-Version: ' . $idx['version']);
            header('X-OpenSagra-SHA256: ' . $e['sha256']);
            readfile($path);
            exit;
        }
    }
    notFound('nessun installer per questa piattaforma su questo PC');
}

$files = [
    'manifest' => ['opensagra-app-update.manifest.json', 'application/json'],
    'zip' => ['opensagra-app-update.zip', 'application/zip'],
    'sha' => ['opensagra-app-update.zip.sha256', 'text/plain; charset=utf-8'],
];

if (!isset($files[$f])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'parametro f non valido';
    exit;
}

// VERSION la scrive il wrapper per ultima, a file completi: senza, il
// pacchetto non e' pronto (o non c'e' affatto, es. copia di sviluppo).
if ($dir === '' || !is_file($dir . '/VERSION')) {
    notFound('nessun pacchetto disponibile su questo PC');
}

[$name, $type] = $files[$f];
$path = $dir . '/' . $name;
if (!is_file($path)) {
    notFound('file non disponibile');
}

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
header('X-OpenSagra-Version: ' . trim((string) file_get_contents($dir . '/VERSION')));
readfile($path);
