<?php
/**
 * Fondamenta per il realtime via Mercure (Fase 4, opzionale - vedi piano,
 * sezione "Fase 4 — Realtime via Mercure"). Due funzioni:
 *
 * - mintMercureJwt(): firma un JWT HS256 con i claim mercure.publish/
 *   mercure.subscribe richiesti dall'hub (direttive publisher_jwt/
 *   subscriber_jwt nel Caddyfile). Scritto a mano (nessuna libreria JWT
 *   aggiunta a composer.json) perche' serve solo firmare HS256, non
 *   verificare/decodificare ne' altri algoritmi - una libreria completa
 *   sarebbe sovradimensionata per questo scopo.
 * - publishMercureUpdate(): pubblica un aggiornamento su un topic, via il
 *   protocollo HTTP standard di Mercure (POST a /.well-known/mercure) - non
 *   la funzione nativa mercure_publish() di FrankenPHP, che risulta
 *   disponibile solo dentro il processo del server web (Caddy), non da
 *   `frankenphp php-cli` (verificato: restituisce false lì). Il POST HTTP
 *   funziona invece identico da qualunque contesto (richiesta web, CLI,
 *   migrazione), quindi e' la via scelta qui.
 */

require_once __DIR__ . '/env_reader.php';
require_once __DIR__ . '/app_config.php';

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Segreto giusto per firmare un JWT diretto all'hub `$hubUrl`.
 *
 * In modalita' rete ci sono DUE hub: quello LOCALE del client (che verifica
 * col MERCURE_JWT_SECRET del Caddyfile del client) e quello del SERVER (che
 * verifica col segreto del server). Il client conosce il primo come
 * `mercure_jwt_secret` e il secondo, sincronizzato dal DB, come
 * `mercure_jwt_secret_remote`.
 *
 * - hub localhost/127.0.0.1  -> segreto LOCALE
 * - qualunque altro host      -> segreto REMOTO se impostato, altrimenti
 *   quello locale (installazione indipendente: unico hub, unico segreto).
 */
function mercureSecretForHub(string $hubUrl): string
{
    $env = loadPosEnvVars();
    $isLocalHub = str_contains($hubUrl, '//localhost/')
        || str_contains($hubUrl, '//localhost:')
        || str_contains($hubUrl, '//127.0.0.1/')
        || str_contains($hubUrl, '//127.0.0.1:');

    if ($isLocalHub) {
        return $env['mercure_jwt_secret'];
    }
    return $env['mercure_jwt_secret_remote'] !== ''
        ? $env['mercure_jwt_secret_remote']
        : $env['mercure_jwt_secret'];
}

/**
 * URL dell'hub Mercure da usare per PUBBLICARE. Si ricava da DB_POS_HOST:
 * l'hub "buono" e' quello del server che tiene il DB, cosi' in modalita' rete
 * il checkout di un client pubblica dritto sull'hub del server (dove sono
 * iscritte le casse del server e da cui il relay di ogni client ripesca).
 *
 * - host locale (127.0.0.1 / localhost / vuoto) -> 'https://localhost' (questa
 *   macchina e' il server, o e' indipendente): l'hub e' qui.
 * - host remoto (IP del server) -> 'https://<IP>': l'hub del server. Il
 *   Caddyfile del server elenca il suo IP di LAN nel site address (Insidia #6),
 *   quindi il certificato self-signed copre quel nome; publishMercureUpdate()
 *   disabilita comunque la verifica peer/host (chiamata server-to-server).
 */
function mercureHubUrl(): string
{
    $host = loadPosEnvVars()['host'];
    if ($host === '' || $host === '127.0.0.1' || $host === 'localhost' || $host === '::1') {
        return 'https://localhost/.well-known/mercure';
    }
    return 'https://' . $host . '/.well-known/mercure';
}

/**
 * Firma un JWT HS256 con i claim mercure richiesti dall'hub.
 *
 * @param string[] $publish   topic che questo token puo' pubblicare (es. ['*'] o ['stock/1'])
 * @param string[] $subscribe topic che questo token puo' sottoscrivere
 * @param int      $ttlSeconds validita' del token, in secondi (default 1h)
 * @param string   $secret    segreto di firma; vuoto (default) = quello locale
 *                            (MERCURE_JWT_SECRET). Passare quello del server
 *                            per i token diretti al suo hub - vedi
 *                            mercureSecretForHub().
 */
function mintMercureJwt(array $publish = [], array $subscribe = [], int $ttlSeconds = 3600, string $secret = ''): string
{
    if ($secret === '') {
        $secret = loadPosEnvVars()['mercure_jwt_secret'];
    }
    if ($secret === '') {
        throw new RuntimeException('MERCURE_JWT_SECRET non configurato in variabili.env - hub Mercure non attivo su questa installazione.');
    }

    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $payload = [
        'mercure' => array_filter([
            'publish' => $publish ?: null,
            'subscribe' => $subscribe ?: null,
        ]),
        'exp' => time() + $ttlSeconds,
    ];

    $segments = base64UrlEncode(json_encode($header)) . '.' . base64UrlEncode(json_encode($payload));
    $signature = hash_hmac('sha256', $segments, $secret, true);

    return $segments . '.' . base64UrlEncode($signature);
}

/**
 * Pubblica un aggiornamento sul topic dato. Non lancia mai eccezioni verso
 * il chiamante: il realtime e' un extra, un hub irraggiungibile/non
 * configurato non deve mai far fallire l'operazione applicativa che lo
 * innesca (es. un checkout non deve fallire se Mercure e' giu').
 *
 * @param string $topic  identificatore del topic (es. 'stock', 'orders/henry')
 * @param mixed  $data    dati da serializzare in JSON e inviare come payload dell'evento
 * @param string $hubUrl  URL dell'hub. Vuoto (default) -> mercureHubUrl(), che
 *                        lo ricava da DB_POS_HOST. Passare un URL esplicito solo
 *                        per casi speciali (es. il relay che ripubblica
 *                        sull'hub LOCALE, 'https://localhost/.well-known/mercure').
 *                        Mai '127.0.0.1': il Caddyfile emette certificati solo
 *                        per gli hostname elencati nel site address
 *                        (localhost/IP-LAN/opensagra.local), non per il loopback
 *                        - connettersi con quell'IP causa un mismatch SNI e Caddy
 *                        chiude il TLS con un alert generico ("internal error").
 * @return bool true se l'hub ha accettato la pubblicazione (HTTP 200)
 */
function publishMercureUpdate(string $topic, $data, string $hubUrl = ''): bool
{
    if ($hubUrl === '') {
        $hubUrl = mercureHubUrl();
    }

    try {
        $jwt = mintMercureJwt([$topic], [], 3600, mercureSecretForHub($hubUrl));
    } catch (Throwable $e) {
        return false;
    }

    $ch = curl_init($hubUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'topic' => $topic,
            'data' => json_encode($data),
            // Bug reale trovato testando: senza questo campo, Mercure
            // consegna l'update a QUALSIASI subscriber con un JWT valido,
            // indipendentemente dal suo claim "subscribe" - lo scoping per
            // topic (essenziale per il futuro isolamento per-cassa del bridge
            // di stampa) conta SOLO per gli update marcati "privati". La
            // presenza del campo basta, il valore e' ignorato dal protocollo.
            'private' => 'on',
        ]),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $jwt],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2, // non deve mai rallentare percettibilmente chi pubblica
        // Certificato locale self-signed (tls internal) - stesso self-signed
        // gia' accettato dall'app stessa per le chiamate interne server-to-server.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // Niente curl_close(): no-op dal PHP 8.0, deprecata (warning nei log) dall'8.5.
    // L'handle si libera da solo quando $ch esce di scope.

    if ($result === false || $httpCode !== 200) {
        error_log("publishMercureUpdate: pubblicazione su topic '$topic' fallita (HTTP $httpCode) $curlError");
        return false;
    }
    return true;
}

/**
 * Notifica alle casse che il catalogo prodotti e' cambiato (Fase 4, topic
 * 'products'). Il payload porta solo version+count - la stessa forma di
 * api/products_version.php - cosi' il client di billing.php aggiorna i suoi
 * contatori e richiama loadProducts() (strategia "full reload", nessun delta).
 *
 * Da chiamare dopo il successo di ogni mutazione che tocca la tabella stock
 * (aggiornamento/inserimento/nascondi/ripristino prodotto, rinomina categoria,
 * decremento scorte al checkout, ripristino scorte allo storno). Non lancia
 * mai: se l'hub e' giu' o non configurato, publishMercureUpdate() torna false
 * in silenzio e la mutazione applicativa resta valida comunque.
 */
/**
 * Solo lato SERVER: pubblica il proprio MERCURE_JWT_SECRET nella tabella
 * app_config, cosi' che un client lo legga al passaggio a modalita' rete
 * (api/set_network_config.php) invece di copiarlo a mano (Fase 4, Opzione A).
 *
 * Guardato in tre modi: (1) solo se questa installazione e' il server / e'
 * indipendente (host locale) - su un client `$db` e' il DB del server e il
 * segreto locale sarebbe quello sbagliato; (2) una volta per processo PHP;
 * (3) scrive solo se manca o e' diverso. install.ps1 scrivera' la riga
 * direttamente (punto 5): questo copre gli install esistenti e lo sviluppo.
 */
function seedServerMercureSecret(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $env = loadPosEnvVars();
    $isLocal = in_array($env['host'], ['', '127.0.0.1', 'localhost', '::1'], true);
    if (!$isLocal || $env['mercure_jwt_secret'] === '') {
        return;
    }
    if (getAppConfig($db, 'MERCURE_JWT_SECRET') !== $env['mercure_jwt_secret']) {
        setAppConfig($db, 'MERCURE_JWT_SECRET', $env['mercure_jwt_secret']);
    }
}

function publishProductsChanged(mysqli $db): void
{
    seedServerMercureSecret($db);

    $res = $db->query(
        'SELECT COALESCE(UNIX_TIMESTAMP(MAX(updated_at)), 0) AS version, COUNT(*) AS count FROM stock WHERE is_active = 1'
    );
    $row = $res ? $res->fetch_assoc() : null;

    publishMercureUpdate('products', [
        // 'type' distingue gli eventi sull'EventSource condiviso del client:
        // il protocollo Mercure NON trasporta il topic al subscriber, quindi
        // il payload deve auto-descriversi (vedi anche publishClusterAnnounce).
        'type' => 'products',
        'version' => (int) ($row['version'] ?? 0),
        'count' => (int) ($row['count'] ?? 0),
    ]);
}

/**
 * Avviso di stato del cluster verso le casse (Fase 4, punto 3 - topic
 * 'cluster/announce', una-via server -> client). Lo pubblica un processo
 * lato server: oggi il CLI bin/opensagra-announce.php a mano, domani il
 * wrapper prima di fermare FrankenPHP ('shutdown') e alla ripartenza ('back').
 *
 * $kind: 'shutdown' (il server sta per fermarsi/riavviarsi, le vendite
 * potrebbero non salvarsi) | 'back' (di nuovo su, il client pulisce il banner).
 * Non lancia mai, come publishMercureUpdate.
 */
function publishClusterAnnounce(string $kind, int $etaSeconds = 0, string $message = ''): bool
{
    return publishMercureUpdate('cluster/announce', [
        'type' => 'announce',
        'kind' => $kind,
        'eta_seconds' => max(0, $etaSeconds),
        'message' => $message,
    ]);
}
