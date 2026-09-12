<?php
/**
 * Fase 4 punto 4 - stato "vendite locali da sincronizzare col centrale".
 *
 * Contropartita leggera di api/push_local_sales.php: dice a colpo d'occhio,
 * da qualunque pagina (badge in sidebar) e dalla pagina Rete, se questa
 * postazione ha vendite fatte in fallback locale ancora da ricaricare sul
 * server centrale.
 *
 * Le vendite in attesa stanno SEMPRE nel MariaDB locale (127.0.0.1): in
 * fallback e' il DB in uso, tornati in rete e' la copia che le conserva
 * finche' il push non le carica sul centrale. Per questo si interroga sempre
 * 127.0.0.1, non l'host configurato.
 *
 * GET, nessun parametro. Risposta:
 *   {
 *     "fallback_active": bool,   // FALLBACK_ORIGIN_HOST valorizzato
 *     "origin_host":     string, // IP del centrale da cui si e' ripiegato ("" se non in fallback)
 *     "pending_sync":    int,    // vendite da_sincronizzare=1 AND pushed_at IS NULL nel DB locale
 *     "pending_known":   bool,   // false = DB locale/tabella non interrogabili (client mai andato in fallback)
 *     "armed":           bool    // "Chiudi Cassa" gia' premuto con vendite pendenti -> affordance persistente
 *   }
 *
 * Timeout brevi apposta: e' in polling, non deve mai far percepire l'app come lenta.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/app_config.php';

$env = loadPosEnvVars();

$result = [
    'fallback_active' => $env['fallback_origin_host'] !== '',
    'origin_host'     => $env['fallback_origin_host'],
    'pending_sync'    => 0,
    'pending_known'   => false,
    'armed'           => false,
];

try {
    $local = mysqli_init();
    $local->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    if (@$local->real_connect('127.0.0.1', $env['user'], $env['pass'], $env['db'])) {
        try {
            $res = $local->query(
                'SELECT COUNT(*) AS n FROM vendite WHERE da_sincronizzare = 1 AND pushed_at IS NULL'
            );
            if ($res) {
                $result['pending_sync'] = (int) ($res->fetch_assoc()['n'] ?? 0);
                $result['pending_known'] = true;
            }
        } catch (mysqli_sql_exception $e) {
            // La tabella `vendite` puo' non esistere sul cache locale di un
            // client che non e' mai andato in fallback: non e' un errore,
            // semplicemente non c'e' niente da sincronizzare.
        }

        // Marker scritto da api/chiudi_cassa.php quando si chiude con vendite
        // pendenti, azzerato da api/push_local_sales.php a push completo. Tiene
        // il badge silenzioso durante il servizio (compare solo a fine serata).
        $result['armed'] = getAppConfig($local, 'close_sync_pending', '') !== '';
        $local->close();
    }
} catch (mysqli_sql_exception $e) {
    // DB locale non raggiungibile: si risponde comunque con i valori di default
    // (pending_known=false), il chiamante non mostra nulla.
}

echo json_encode($result);
