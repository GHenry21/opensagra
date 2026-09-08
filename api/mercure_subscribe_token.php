<?php
/**
 * Consegna al browser un JWT Mercure col claim subscribe sui topic 'products'
 * (catalogo/scorte) e 'cluster/announce' (avviso "il server sta per fermarsi",
 * Fase 4 punto 3). billing.php lo chiama una volta prima di aprire
 * l'EventSource: EventSource non puo' impostare l'header Authorization, quindi
 * il token viaggia nel cookie `mercure_authorization`, che e' esattamente dove
 * l'hub Mercure lo cerca per le connessioni da browser.
 *
 * Nessun gate di autenticazione: il token da' accesso in sola lettura a dati
 * gia' pubblici (il catalogo, e un avviso di stato broadcast), e non puo'
 * pubblicare ne' sottoscrivere altri topic (es. quelli del bridge di stampa).
 *
 * Il cookie e' `Secure`: su una cassa servita in HTTP (postazioni con bridge
 * QZ, vedi Appendice C del piano) non viene impostato e billing.php resta sul
 * polling condizionale - il fallback previsto, nessuna regressione.
 */

require_once __DIR__ . '/../config/mercure.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Durata lunga (12h): una cassa resta aperta per l'intera serata, un TTL corto
// costringerebbe a rinegoziare il token a connessione gia' avviata.
$ttlSeconds = 12 * 3600;
$topics = ['products', 'cluster/announce'];

try {
    $jwt = mintMercureJwt([], $topics, $ttlSeconds);
} catch (Throwable $e) {
    // MERCURE_JWT_SECRET non configurato: l'hub non e' attivo su questa
    // installazione. Non e' un errore per il client - si limita a non aprire
    // l'EventSource e a restare sul polling.
    http_response_code(503);
    echo json_encode(['realtime' => false]);
    exit;
}

setcookie('mercure_authorization', $jwt, [
    'expires' => time() + $ttlSeconds,
    'path' => '/.well-known/mercure',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);

echo json_encode([
    'realtime' => true,
    'topics' => $topics,
    'topic' => 'products', // retrocompat: vecchi client leggevano un singolo topic
    'hub' => '/.well-known/mercure',
    'expires_in' => $ttlSeconds,
]);
