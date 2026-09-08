<?php
/**
 * opensagra - relay realtime per la modalita' rete (Fase 4, "Roadmap collegata"
 * punto 1 nel piano di migrazione).
 *
 * PROBLEMA: ogni installazione ha il SUO hub Mercure. In modalita' rete
 * (1 server + N client, ognuno col suo FrankenPHP) un evento pubblicato
 * sull'hub del server non arriva alle casse di un client, che ascoltano
 * l'hub locale del client. Il cross-macchina resta sul polling 6s.
 *
 * QUESTO PROCESSO: gira SOLO sui client (DB_POS_HOST remoto). Si iscrive in
 * streaming all'hub del SERVER e ri-pubblica ogni evento sull'hub LOCALE.
 * Cosi' billing.php del client - invariato - riceve gli eventi del server dal
 * suo hub locale di sempre. Topic inoltrati: 'products' (catalogo/scorte) e
 * 'cluster/announce' (avviso "il server sta per fermarsi", Fase 4 punto 3).
 * Mercure non trasporta il topic al subscriber: il topic locale su cui
 * ripubblicare si ricava dal campo 'type' del payload.
 *
 * Lanciato dal wrapper come processo figlio (non un servizio - vedi piano 3g).
 * Su un server / installazione indipendente esce subito senza fare nulla.
 * Log su STDERR. Avvio manuale per test:  php bin/opensagra-realtime-relay.php
 */

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/mercure.php';
require_once __DIR__ . '/mercure_subscriber.php';

const RELAY_TOPICS = ['products', 'cluster/announce'];
const RELAY_LOCAL_HUB = 'https://localhost/.well-known/mercure';

// type del payload -> topic locale su cui ri-pubblicare. Un type sconosciuto
// non viene inoltrato (non sappiamo dove metterlo).
const RELAY_TYPE_TO_TOPIC = [
    'products' => 'products',
    'announce' => 'cluster/announce',
];

function relayLog(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

$env = loadPosEnvVars();
$isClient = !in_array($env['host'], ['', '127.0.0.1', 'localhost', '::1'], true);

if (!$isClient) {
    relayLog("relay: DB_POS_HOST e' locale ('{$env['host']}'): non e' una postazione client, niente da fare. Esco.");
    exit(0);
}

// L'hub a cui ci si iscrive e' quello del SERVER: serve il suo segreto
// (MERCURE_JWT_SECRET_REMOTE, sincronizzato da conf_rete). Fallback a quello
// locale se non ancora sincronizzato - probabile 401, ma runMercureSubscriber
// lo gestisce (attesa e retry).
$serverHub = mercureHubUrl();
$serverSecret = mercureSecretForHub($serverHub);
if ($serverSecret === '') {
    relayLog("relay: segreto Mercure del server non disponibile - esco (ripartira' quando conf_rete l'ha sincronizzato).");
    exit(0);
}

runMercureSubscriber([
    'hub_url'  => $serverHub,        // hub del server (da DB_POS_HOST)
    'topics'   => RELAY_TOPICS,
    'label'    => 'relay',
    'mint_jwt' => static fn() => mintMercureJwt([], RELAY_TOPICS, MERCURE_SUB_JWT_TTL, $serverSecret),
    'on_event' => static function ($data) {
        // Un evento senza struttura JSON non e' inoltrabile: non sappiamo su
        // che topic locale metterlo.
        if (!is_array($data)) {
            return;
        }
        // Difesa contro l'eco: se DB_POS_HOST fosse (per errore di config)
        // l'IP di QUESTA macchina, l'hub del server e quello locale sarebbero
        // lo stesso hub - ripubblicare qui rimanderebbe l'evento a noi stessi,
        // all'infinito. Marchiamo cio' che ripubblichiamo e scartiamo gli
        // eventi gia' marchiati. Il client ignora la chiave `_relay`.
        if (!empty($data['_relay'])) {
            return;
        }

        $localTopic = RELAY_TYPE_TO_TOPIC[$data['type'] ?? 'products'] ?? null;
        if ($localTopic === null) {
            relayLog("relay: type sconosciuto ('" . ($data['type'] ?? '') . "'), evento non inoltrato.");
            return;
        }

        // Ri-pubblica sull'hub LOCALE, cosi' billing.php locale lo riceve.
        if (!publishMercureUpdate($localTopic, $data + ['_relay' => 1], RELAY_LOCAL_HUB)) {
            relayLog("relay: ripubblicazione di '$localTopic' sull'hub locale fallita (hub locale giu'?).");
        }
    },
]);
