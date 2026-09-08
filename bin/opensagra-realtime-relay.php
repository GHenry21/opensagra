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
 * suo hub locale di sempre.
 *
 * Lanciato dal wrapper come processo figlio (non un servizio - vedi piano 3g).
 * Su un server / installazione indipendente esce subito senza fare nulla.
 * Log su STDERR. Avvio manuale per test:  php bin/opensagra-realtime-relay.php
 */

require_once __DIR__ . '/../config/env_reader.php';
require_once __DIR__ . '/../config/mercure.php';
require_once __DIR__ . '/mercure_subscriber.php';

const RELAY_TOPICS = ['products'];
const RELAY_LOCAL_HUB = 'https://localhost/.well-known/mercure';

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
if (($env['mercure_jwt_secret'] ?? '') === '') {
    relayLog("relay: MERCURE_JWT_SECRET non configurato - esco (ripartira' quando il segreto e' sincronizzato).");
    exit(0);
}

runMercureSubscriber([
    'hub_url'  => mercureHubUrl(),   // hub del server (da DB_POS_HOST)
    'topics'   => RELAY_TOPICS,
    'label'    => 'relay',
    'mint_jwt' => static fn() => mintMercureJwt([], RELAY_TOPICS, MERCURE_SUB_JWT_TTL),
    'on_event' => static function ($data) {
        // Difesa contro l'eco: se DB_POS_HOST fosse (per errore di config)
        // l'IP di QUESTA macchina, l'hub del server e quello locale sarebbero
        // lo stesso hub - ripubblicare qui rimanderebbe l'evento a noi stessi,
        // all'infinito. Marchiamo cio' che ripubblichiamo e scartiamo gli
        // eventi gia' marchiati. Il client ignora la chiave `_relay`.
        if (is_array($data) && !empty($data['_relay'])) {
            return;
        }
        $payload = is_array($data) ? $data + ['_relay' => 1] : $data;

        // Ri-pubblica sull'hub LOCALE, cosi' billing.php locale lo riceve.
        if (!publishMercureUpdate('products', $payload, RELAY_LOCAL_HUB)) {
            relayLog("relay: ripubblicazione sull'hub locale fallita (hub locale giu'?).");
        }
    },
]);
