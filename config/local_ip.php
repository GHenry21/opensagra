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
    // Lanciare PowerShell ha un costo reale (fino a qualche secondo) - questo
    // endpoint viene interrogato ogni 15-20s dalla pillola di stato in ogni
    // scheda aperta, quindi il risultato si mette in cache su file per
    // qualche minuto: l'IP di rete non cambia certo cosi' spesso.
    $cacheFile = sys_get_temp_dir() . '/opensagra_lan_ip.cache';
    $cacheTtlSeconds = 300;
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtlSeconds) {
        $cached = trim((string) file_get_contents($cacheFile));
        if ($cached !== '') {
            return $cached === '-' ? null : $cached;
        }
    }

    $ip = detectLocalLanIpUncached();
    @file_put_contents($cacheFile, $ip ?? '-');
    return $ip;
}

function detectLocalLanIpUncached(): ?string
{
    // Metodo primario, ogni OS: un socket UDP "connesso" verso un indirizzo
    // esterno non invia nulla (UDP non ha handshake), ma fa scegliere al
    // sistema l'interfaccia d'uscita della rotta predefinita - quella con il
    // gateway, esattamente il criterio del metodo PowerShell qui sotto, senza
    // lanciare un processo. Aggiunto 2026-10-07: su Linux/macOS PowerShell non
    // c'e' e il fallback gethostbyname() dava 127.0.1.1 (Debian lo scrive in
    // /etc/hosts per l'hostname), quindi la pagina Rete di un server Linux non
    // mostrava ne' l'IP ne' il QR per i telefoni. 192.0.2.1 = TEST-NET-1
    // (RFC 5737), mai instradato davvero. Senza rotta predefinita fallisce e
    // si passa ai metodi sotto.
    $sock = @stream_socket_client('udp://192.0.2.1:9', $errno, $errstr, 1);
    if ($sock !== false) {
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $ip = is_string($name) ? substr($name, 0, (int) strrpos($name, ':')) : '';
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.') && $ip !== '0.0.0.0') {
            return $ip;
        }
    }

    // Windows: l'adattatore con un gateway predefinito è
    // quello davvero collegato alla LAN — esclude naturalmente gli switch
    // virtuali (Hyper-V, VMware), Tailscale, il PAN Bluetooth, che di norma
    // non ne hanno uno. Scoperto necessario il 2026-09-07: dopo aver
    // abilitato Hyper-V su questa macchina, gethostbyname(gethostname())
    // (metodo precedente, unico fallback sotto) ha iniziato a risolvere
    // sull'IP dello switch virtuale "Default Switch" invece che sulla vera
    // scheda LAN — Windows non garantisce un ordine stabile tra le schede.
    if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        $psCommand = 'Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -ne $null } | Select-Object -First 1 -ExpandProperty IPv4Address | Select-Object -ExpandProperty IPAddress';
        $output = @shell_exec('powershell.exe -NoProfile -Command "' . $psCommand . '" 2>NUL');
        $ip = trim((string) $output);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !str_starts_with($ip, '127.')) {
            return $ip;
        }
    }

    // Fallback se shell_exec è disabilitato o il comando non ha dato nulla di
    // utilizzabile: meno affidabile (vedi sopra), meglio di niente.
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
