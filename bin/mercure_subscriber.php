<?php
/**
 * Loop di sottoscrizione a un hub Mercure via il protocollo SSE standard,
 * dal lato PHP-CLI (non un browser: si autentica con l'header Authorization,
 * nessun cookie, nessun CORS).
 *
 * Base condivisa da `bin/opensagra-realtime-relay.php` (Fase 4 punto 1) e
 * `bin/opensagra-print-bridge.php` (Fase 4 punto 2): stessa connessione
 * streaming, stesso parsing riga-per-riga di `text/event-stream`, stessa
 * riconnessione con `Last-Event-ID` + backoff, stesso rilevamento di uno
 * stream morto. Cambia solo cosa si fa alla ricezione di un evento.
 *
 * Non ritorna mai (loop infinito): il chiamante lo lancia come processo
 * persistente (gestito dal wrapper, non da un servizio - vedi piano 3g).
 */

require_once __DIR__ . '/../config/mercure.php';

const MERCURE_SUB_JWT_TTL = 86400;          // 24h: si ri-firma a ogni riconnessione
const MERCURE_SUB_RECONNECT_BASE_MS = 1000;
const MERCURE_SUB_RECONNECT_MAX_MS = 30000;
const MERCURE_SUB_STALE_SECONDS = 45;       // nessun byte (nemmeno un heartbeat ':') per 45s -> riconnetti

/**
 * @param array{
 *   hub_url: string,              URL dell'hub SENZA query (es. https://host/.well-known/mercure)
 *   topics: string[],             topic da sottoscrivere
 *   mint_jwt: callable():string,  restituisce un JWT fresco (chiamata ad ogni riconnessione)
 *   on_event: callable(mixed):void, riceve il payload: array se era JSON valido, altrimenti stringa
 *   label: string,               prefisso per i log
 *   log?: callable(string):void  logger (default: STDERR)
 * } $opts
 */
function runMercureSubscriber(array $opts): void
{
    $hubUrl = $opts['hub_url'];
    $topics = $opts['topics'];
    $mintJwt = $opts['mint_jwt'];
    $onEvent = $opts['on_event'];
    $label = $opts['label'];
    $log = $opts['log'] ?? static function (string $m): void {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n");
    };

    $streamUrl = $hubUrl . '?' . implode('&', array_map(
        static fn($t) => 'topic=' . rawurlencode($t),
        $topics
    ));

    $log("$label: avviato. Hub $hubUrl  topic: " . implode(',', $topics));

    $lastEventId = '';
    $reconnectMs = MERCURE_SUB_RECONNECT_BASE_MS;

    while (true) {
        $connectedAt = time();
        $eventsThisRun = 0;
        $buffer = '';
        $curEvent = ['data' => [], 'id' => null];

        $dispatch = static function () use (&$curEvent, &$lastEventId, &$eventsThisRun, $onEvent, $log, $label) {
            if ($curEvent['id'] !== null) {
                $lastEventId = $curEvent['id'];
            }
            $dataRaw = implode("\n", $curEvent['data']);
            $curEvent = ['data' => [], 'id' => null];
            if ($dataRaw === '') {
                return;
            }
            $eventsThisRun++;
            $decoded = json_decode($dataRaw, true);
            try {
                $onEvent($decoded !== null ? $decoded : $dataRaw);
            } catch (Throwable $e) {
                $log("$label: handler dell'evento ha lanciato: " . $e->getMessage());
            }
        };

        $onData = static function ($ch, string $chunk) use (&$buffer, &$curEvent, $dispatch) {
            $buffer .= $chunk;
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $nl), "\r");
                $buffer = substr($buffer, $nl + 1);

                if ($line === '') {
                    $dispatch();
                    continue;
                }
                if ($line[0] === ':') {
                    continue; // commento / heartbeat
                }
                $colon = strpos($line, ':');
                $field = $colon === false ? $line : substr($line, 0, $colon);
                $value = $colon === false ? '' : ltrim(substr($line, $colon + 1), ' ');

                if ($field === 'data') {
                    $curEvent['data'][] = $value;
                } elseif ($field === 'id') {
                    $curEvent['id'] = $value;
                }
                // 'event'/'retry' ignorati: Mercure usa solo data/id per gli update.
            }
            return strlen($chunk);
        };

        try {
            $jwt = $mintJwt();
        } catch (Throwable $e) {
            $log("$label: impossibile firmare il JWT: " . $e->getMessage() . " - riprovo tra " . round($reconnectMs / 1000, 1) . "s");
            usleep($reconnectMs * 1000);
            $reconnectMs = min($reconnectMs * 2, MERCURE_SUB_RECONNECT_MAX_MS);
            continue;
        }

        $headers = ['Authorization: Bearer ' . $jwt, 'Accept: text/event-stream'];
        if ($lastEventId !== '') {
            $headers[] = 'Last-Event-ID: ' . $lastEventId;
        }

        $ch = curl_init($streamUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => $onData,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => MERCURE_SUB_STALE_SECONDS,
            CURLOPT_SSL_VERIFYPEER => false,   // cert self-signed (tls internal)
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        // curl_close() e' no-op dal PHP 8.0 e deprecata dall'8.5: l'handle si
        // libera quando $ch esce di scope (fine iterazione del while).
        unset($ch);

        $uptime = time() - $connectedAt;

        if ($httpCode === 401 || $httpCode === 403) {
            $log("$label: hub $httpCode (JWT rifiutato). Di solito: MERCURE_JWT_SECRET diverso da quello del server. Riprovo tra 30s.");
            sleep(30);
            continue;
        }

        $log("$label: stream chiuso dopo {$uptime}s (HTTP $httpCode, eventi: $eventsThisRun" . ($err !== '' ? ", curl: $err" : '') . "). Riconnetto tra " . round($reconnectMs / 1000, 1) . "s" . ($lastEventId !== '' ? " da Last-Event-ID $lastEventId" : '') . ".");

        if ($uptime >= 30) {
            $reconnectMs = MERCURE_SUB_RECONNECT_BASE_MS; // era su a lungo: blip, riparti veloce
        }
        usleep($reconnectMs * 1000);
        $reconnectMs = min($reconnectMs * 2, MERCURE_SUB_RECONNECT_MAX_MS);
    }
}
