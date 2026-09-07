<?php
/**
 * Rileva l'IP di rete locale della macchina (non 127.0.0.1), per mostrarlo
 * nella pagina "Configurazione Rete" al posto del loopback quando questo PC
 * è "Indipendente" — 127.0.0.1 non dice nulla di utile a chi deve collegare
 * un'altra cassa a questa macchina come server.
 *
 * DB_POS_HOST resta sempre 127.0.0.1 per le connessioni locali (più
 * affidabile, non dipende da quale interfaccia di rete è attiva): questo
 * valore è solo per la visualizzazione.
 */
function detectLocalLanIp(): ?string
{
    $host = gethostname();
    if ($host === false) {
        return null;
    }

    $ip = gethostbyname($host);
    // gethostbyname ritorna l'hostname invariato se la risoluzione fallisce
    if ($ip === $host) {
        return null;
    }
    if ($ip === '127.0.0.1' || str_starts_with($ip, '127.')) {
        return null;
    }

    return $ip;
}

/**
 * Nome host di questo PC (es. "HENRY"), da mostrare accanto all'IP: più
 * facile da riconoscere a colpo d'occhio su più postazioni che un numero.
 * Non è un'alternativa affidabile all'IP per la connessione da un tablet
 * Android (stessa limitazione già documentata per mDNS/.local in
 * Appendice C) — solo un'informazione in più per chi legge la pagina.
 */
function detectLocalHostname(): ?string
{
    $host = gethostname();
    return $host !== false && $host !== '' ? $host : null;
}
