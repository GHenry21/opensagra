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

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Firma un JWT HS256 con i claim mercure richiesti dall'hub.
 *
 * @param string[] $publish   topic che questo token puo' pubblicare (es. ['*'] o ['stock/1'])
 * @param string[] $subscribe topic che questo token puo' sottoscrivere
 * @param int      $ttlSeconds validita' del token, in secondi (default 1h)
 */
function mintMercureJwt(array $publish = [], array $subscribe = [], int $ttlSeconds = 3600): string
{
    $secret = loadPosEnvVars()['mercure_jwt_secret'];
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
 * @param string $hubUrl  URL dell'hub. Default 'localhost', NON '127.0.0.1':
 *                        bug reale trovato testando - il Caddyfile emette
 *                        certificati solo per gli hostname elencati nel site
 *                        address (localhost/IP-LAN/opensagra.local), non per
 *                        '127.0.0.1' - connettersi con quell'IP causa un
 *                        mismatch SNI e Caddy chiude il TLS con un alert
 *                        generico ("internal error"), non un errore HTTP.
 * @return bool true se l'hub ha accettato la pubblicazione (HTTP 200)
 */
function publishMercureUpdate(string $topic, $data, string $hubUrl = 'https://localhost/.well-known/mercure'): bool
{
    try {
        $jwt = mintMercureJwt([$topic]);
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
    curl_close($ch);

    if ($result === false || $httpCode !== 200) {
        error_log("publishMercureUpdate: pubblicazione su topic '$topic' fallita (HTTP $httpCode) $curlError");
        return false;
    }
    return true;
}
