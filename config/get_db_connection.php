<?php
require_once __DIR__ . '/env_reader.php';

$envVars = loadPosEnvVars();
$envFile = $envVars['env_file'];

$servername = $envVars['host'];
$username   = $envVars['user'];
$password   = $envVars['pass'];
$database   = $envVars['db'];

// Timeout di connessione esplicito: senza, verso un host spento la connect
// resta appesa ~20s (SYN timeout di Windows) e ogni pagina/endpoint che
// include questo file eredita quell'attesa - in modalita' rete e' proprio il
// caso "il centrale e' caduto", dove serve accorgersene in fretta (Scalino 0
// + countdown fallback). 3s copre una LAN lenta senza incollarsi.
$connectionDB = mysqli_init();
$connectionDB->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
try {
    $ok = @$connectionDB->real_connect($servername, $username, $password, $database);
} catch (mysqli_sql_exception $e) {
    // Da PHP 8.1 mysqli e' in modalita' eccezioni: uniformiamo a "connessione
    // fallita" come il vecchio ramo connect_error.
    $ok = false;
}
if (!$ok) {
    // 503, non il 200 implicito di die(): per il checkout (pages/billing.php,
    // _postCheckoutOnce) un 200 con testo al posto del JSON era un errore
    // "non ritentabile" con il messaggio generico "Errore durante la
    // registrazione della vendita", proprio nel caso tipico da ritentare (DB
    // irraggiungibile per qualche secondo, niente scritto). Probabile causa
    // della prima vendita fallita del giro Linux come centrale (piano 5e).
    $connectError = mysqli_connect_error();
    error_log('get_db_connection: connessione a ' . $servername . ' fallita: ' . $connectError);
    if (!headers_sent()) {
        http_response_code(503);
    }
    die("Connection failed: " . $connectError);
}
