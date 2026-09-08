<?php
/**
 * opensagra - CLI per pubblicare un avviso di stato del cluster (Fase 4,
 * punto 3). Pubblica sul topic Mercure 'cluster/announce'; il relay di ogni
 * client lo ripesca sull'hub locale e billing.php mostra un banner.
 *
 * USO:
 *   php bin/opensagra-announce.php --kind=shutdown [--eta=30] [--message="..."]
 *   php bin/opensagra-announce.php --kind=back
 *
 * --kind      shutdown = il server sta per fermarsi/riavviarsi (le vendite
 *             potrebbero non salvarsi finche' non torna su);
 *             back     = di nuovo operativo, i client puliscono il banner.
 * --eta       secondi stimati prima del fermo (solo informativo, opzionale).
 * --message   testo extra opzionale mostrato nel banner.
 *
 * Da lanciare SUL SERVER (dove l'hub "buono" e' locale). Il wrapper (punto 5)
 * lo chiamera' con --kind=shutdown prima di terminare FrankenPHP e con
 * --kind=back alla ripartenza. Exit 0 se l'hub ha accettato, 1 altrimenti.
 */

require_once __DIR__ . '/../config/mercure.php';

$opts = getopt('', ['kind:', 'eta::', 'message::']);
$kind = $opts['kind'] ?? '';

if (!in_array($kind, ['shutdown', 'back'], true)) {
    fwrite(STDERR, "opensagra-announce: --kind mancante o non valido (attesi: shutdown | back).\n");
    exit(2);
}

$eta = isset($opts['eta']) ? (int) $opts['eta'] : 0;
$message = isset($opts['message']) ? (string) $opts['message'] : '';

$ok = publishClusterAnnounce($kind, $eta, $message);

fwrite(
    $ok ? STDOUT : STDERR,
    '[' . date('Y-m-d H:i:s') . '] announce: ' . $kind
        . ($eta > 0 ? " (eta {$eta}s)" : '')
        . ($ok ? ' pubblicato.' : ' NON pubblicato (hub giu\' o MERCURE_JWT_SECRET assente).')
        . "\n"
);

exit($ok ? 0 : 1);
