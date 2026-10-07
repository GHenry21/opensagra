#!/bin/bash
# Installer OpenSagra per Linux (Debian/Raspberry Pi OS) - equivalente di
# install.ps1 per Windows. Porta una macchina con solo Debian/Raspberry Pi OS
# a "app funzionante" (FrankenPHP + MariaDB + wrapper come supervisore dei
# processi), stessa configurazione "indipendente" di default - il passaggio a
# client si fa DOPO, dalla pagina Configurazione Rete dentro l'app (vedi
# install.ps1 per lo stesso ragionamento, identico qui).
#
# A differenza di install.ps1 (un solo eseguibile elevato, con un task
# pianificato INTERACTIVE per de-elevare l'avvio del wrapper) qui non serve
# nessun trucco di de-elevazione: lo script gira come utente normale e usa
# `sudo` solo sui singoli comandi che lo richiedono davvero (apt, setcap,
# systemctl di sistema per MariaDB, loginctl). Il wrapper e la sua unit
# systemd --user restano SEMPRE nel contesto dell'utente normale.
#
# Idempotente per design, come install.ps1: rilanciarlo su una macchina gia'
# configurata non deve rompere nulla.
#
# Uso:
#   ./install.sh
#
# Presuppone: uno "unprivileged user" con sudo NOPASSWD o pronto a inserire la
# password quando richiesta (systemd-ask-password style, via i comandi sudo
# qui sotto - niente automazione della password).

set -euo pipefail

# ============================================================================
# Configurazione
# ============================================================================

INSTALL_DIR="$HOME/opensagra"
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRANKEN_DIR="$HOME/.frankenphp"
LOG_FILE="/tmp/opensagra-install.log"
: > "$LOG_FILE"

log() { echo "[$(date -Iseconds)] $*" | tee -a "$LOG_FILE"; }
ok()  { echo "  OK: $*" | tee -a "$LOG_FILE"; }
die() { echo "ERRORE: $*" | tee -a "$LOG_FILE" >&2; exit 1; }

# Cartelle/file del repo da NON copiare in $INSTALL_DIR - materiale di
# sviluppo, non serve a chi usa l'app. Stessa lista logica di
# $Script:ExcludeFromCopy in install.ps1, adattata (niente .ps1/.bat qui,
# niente 'archive' perche' gia' rimossa dal repo).
EXCLUDE_FROM_COPY=(
    .git .github .claude .vscode e2e docs node_modules
    install.ps1 install.sh install-macos.sh
    uninstall.ps1 uninstall.sh uninstall-macos.sh
    .gitignore .gitattributes
    playwright-report test-results package.json package-lock.json playwright.config.js
    "bt.html" "navbar example.html"
    private packaging
    # Sorgente Go del wrapper: nella webroot non serve, solo l'eseguibile
    # gia' compilato (wrapper/opensagra-wrapper, copiato a parte piu' sotto).
    wrapper
    VERSION # metadato del pacchetto (letto da app_version), non un file dell'app
    frankenphp # binario incluso nel pacchetto, lo installa install_frankenphp
)

# app_version: stessa fonte di install.ps1 - il file VERSION che il pacchetto
# di release porta con se' (generato dal tag git in CI), poi il tag git esatto
# su HEAD se si installa da una copia del repo (come Get-ReleaseVersion.ps1),
# infine '0.0.0-dev'. Mai piu' un numero scritto a mano qui.
app_version() {
    if [ -f "$SOURCE_DIR/VERSION" ]; then
        tr -d '[:space:]' < "$SOURCE_DIR/VERSION"
        return
    fi
    local tag
    tag="$(git -C "$SOURCE_DIR" describe --tags --exact-match HEAD 2>/dev/null || true)"
    if [ -n "$tag" ]; then echo "${tag#v}"; else echo "0.0.0-dev"; fi
}

# ============================================================================
# Passi dell'installazione
# ============================================================================

test_prerequisites() {
    [ "$(uname -s)" != "Darwin" ] || die "Su macOS usa install-macos.sh."
    [ "$(uname -s)" = "Linux" ] || die "Questo installer supporta solo Linux."
    [ "$EUID" -ne 0 ] || die "Non lanciare come root: lo script chiede sudo da solo dove serve (systemd --user va registrato come utente normale, non come root)."
    command -v sudo >/dev/null || die "sudo non trovato."
    ok "prerequisiti di base"
}

# install_frankenphp: binario statico ufficiale (musl, "portable" - nessuna
# dipendenza di sistema), non un pacchetto apt (Debian/Raspbian non lo
# impacchetta). Posizionato in ~/.frankenphp/ come su Windows
# ($env:USERPROFILE\.frankenphp) - detectFrankenphp() nel wrapper (util.go)
# cerca PRIMA li', poi nel $PATH: mettercelo qui evita di dover passare
# -frankenphp a mano.
#
# UNA versione fissata per tutti gli OS (FRANKENPHP_VERSION), mai "latest":
# gli asset di "latest" vengono ricompilati ogni notte e quelli della v1.12.7
# hanno Mercure 1.0 su Linux/macOS ma 0.24 su Windows (php/frankenphp#2685) -
# un rilancio di questo script con "latest" aveva gia' rotto il Pi una volta.
# Vedi packaging/frankenphp.sha256. Il pacchetto di release include gia' il
# binario (frankenphp/<asset>, impronta verificata quando si costruisce il
# pacchetto): nessun download all'installazione. Il download diretto di QUESTA
# versione resta solo per chi lancia lo script da una copia del repo.
# Una versione diversa gia' presente (es. una "latest" di un'installazione
# precedente) viene sostituita: rm+mv e' sicuro anche con FrankenPHP in
# esecuzione (il vecchio inode resta valido finche' il processo non esce; il
# wrapper viene comunque riavviato a fine installazione). Il binario nuovo
# perde la capability di setcap: la riapplica grant_bind_service_capability.
FRANKENPHP_VERSION="1.12.6"

frankenphp_installed_version() {
    [ -x "$FRANKEN_DIR/frankenphp" ] || return 0
    "$FRANKEN_DIR/frankenphp" version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true
}

install_frankenphp() {
    local current; current="$(frankenphp_installed_version)"
    if [ "$current" = "$FRANKENPHP_VERSION" ]; then
        ok "FrankenPHP $current gia' presente"
        return
    fi
    local arch asset
    arch="$(uname -m)"
    case "$arch" in
        aarch64|arm64) asset="frankenphp-linux-aarch64" ;;
        x86_64|amd64)  asset="frankenphp-linux-x86_64" ;;
        *) die "Architettura non supportata: $arch" ;;
    esac
    mkdir -p "$FRANKEN_DIR"
    local tmp="$FRANKEN_DIR/frankenphp.new"
    if [ -f "$SOURCE_DIR/frankenphp/$asset" ]; then
        cp "$SOURCE_DIR/frankenphp/$asset" "$tmp"
    else
        local url="https://github.com/php/frankenphp/releases/download/v$FRANKENPHP_VERSION/$asset"
        log "Scarico FrankenPHP $FRANKENPHP_VERSION ($asset)..."
        curl -fL --retry 3 -o "$tmp" "$url" || die "Download di FrankenPHP fallito ($url)"
    fi
    chmod +x "$tmp"
    "$tmp" version >/dev/null 2>&1 || { rm -f "$tmp"; die "FrankenPHP non si avvia (binario incompatibile con questa CPU/libc?)"; }
    rm -f "$FRANKEN_DIR/frankenphp"
    mv "$tmp" "$FRANKEN_DIR/frankenphp"
    if [ -n "$current" ]; then
        ok "FrankenPHP $current sostituito con $FRANKENPHP_VERSION ($asset)"
    else
        ok "FrankenPHP $FRANKENPHP_VERSION installato ($asset)"
    fi
}

# grant_bind_service_capability: il wrapper (e quindi FrankenPHP, suo figlio)
# gira SEMPRE come utente normale, mai come root (stesso principio del
# modello Windows: gira de-elevato per non ereditare permessi larghi sui
# file). Su Linux pero' bindare le porte 80/443 senza essere root richiede
# esplicitamente la capability CAP_NET_BIND_SERVICE sul binario - senza,
# frankenphp fallisce all'avvio con "permission denied" sulla porta 80. Il
# vecchio setup manuale (rimosso, vedi pulizia) la otteneva da
# AmbientCapabilities= nella unit systemd; qui non c'e' piu' una unit
# per FrankenPHP (lo lancia il wrapper come processo figlio), quindi la
# capability va sul file stesso - sopravvive ai riavvii, va riapplicata solo
# se il binario viene rimpiazzato (nuova versione di FrankenPHP).
# setcap/getcap vivono in /usr/sbin, che una shell utente normale spesso non
# ha nel $PATH (a differenza di `sudo`, il cui secure_path lo include quasi
# sempre) - un `command -v setcap` senza sudo puo' dare falso-negativo anche
# a pacchetto gia' installato, facendo ritentare l'apt install ad ogni
# rilancio (innocuo ma inutile). dpkg -s e' l'unico controllo affidabile
# indipendente dal $PATH di chi lancia lo script.
grant_bind_service_capability() {
    if ! dpkg -s libcap2-bin >/dev/null 2>&1; then
        log "installo libcap2-bin (setcap/getcap)..."
        sudo apt-get update -qq && sudo apt-get install -y -qq libcap2-bin || die "Installazione di libcap2-bin fallita"
    fi
    if sudo getcap "$FRANKEN_DIR/frankenphp" 2>/dev/null | grep -q cap_net_bind_service; then
        ok "FrankenPHP puo' gia' legarsi alle porte 80/443 senza root"
        return
    fi
    sudo setcap 'cap_net_bind_service=+ep' "$FRANKEN_DIR/frankenphp" || die "setcap fallito su FrankenPHP"
    ok "Permesso di legarsi alle porte 80/443 concesso a FrankenPHP (setcap)"
}

# set_php_ini: a differenza di Windows, la build statica ufficiale di
# FrankenPHP per Linux ha GIA' incorporate tutte le estensioni che servono
# (mysqli, mbstring, gd, zip, intl, curl, openssl, fileinfo, ...) - verificato
# dal vivo sul Pi di test, nessuna riga extension= necessaria. php.ini qui
# serve solo per le impostazioni runtime (limiti upload, timezone, ecc.).
# Posizionato ACCANTO al binario (~/.frankenphp/php.ini): FrankenPHP legge
# di default il php.ini nella propria cartella, stessa convenzione gia'
# usata su Windows - nessuna variabile d'ambiente PHP_INI_SCAN_DIR da
# impostare nella unit systemd (a differenza del vecchio setup manuale).
set_php_ini() {
    local ini="$FRANKEN_DIR/php.ini"
    local upload_tmp="/tmp/opensagra-php-upload"
    mkdir -p "$upload_tmp"
    cat > "$ini" <<EOF
memory_limit = 512M
upload_max_filesize = 40M
post_max_size = 40M
max_execution_time = 120
date.timezone = Europe/Rome
upload_tmp_dir = "$upload_tmp"
display_errors = Off
log_errors = On
EOF

    local required=(mysqli mbstring gd zip intl curl openssl iconv dom fileinfo)
    local loaded missing=()
    loaded="$("$FRANKEN_DIR/frankenphp" php-cli -r 'echo implode(",", get_loaded_extensions());' 2>>"$LOG_FILE")"
    for ext in "${required[@]}"; do
        [[ ",$loaded," == *",$ext,"* ]] || missing+=("$ext")
    done
    [ ${#missing[@]} -eq 0 ] || die "Estensioni PHP mancanti: ${missing[*]} (dettagli in $LOG_FILE)"
    ok "php.ini scritto, estensioni PHP verificate"
}

# install_mariadb: dal repository di sistema (Debian/Raspbian la
# impacchettano gia', a differenza di Windows dove va scaricata a mano) -
# niente download diretto come per FrankenPHP.
install_mariadb() {
    # dpkg -s, non `command -v mariadbd`: mariadbd sta in /usr/sbin, che una
    # shell utente normale spesso non ha nel $PATH - il controllo dava sempre
    # "assente" e ripeteva l'apt install a ogni rilancio (~2 minuti sul Pi,
    # visto 2026-10-02). Stesso motivo di grant_bind_service_capability.
    if dpkg -s mariadb-server >/dev/null 2>&1; then
        ok "MariaDB gia' presente"
    else
        log "Installo mariadb-server..."
        sudo apt-get update -qq && sudo apt-get install -y -qq mariadb-server || die "Installazione di MariaDB fallita"
        ok "MariaDB installato"
    fi
    sudo systemctl enable --now mariadb || die "Impossibile avviare il servizio mariadb"
    allow_lan_mariadb
    ok "Servizio MariaDB attivo"
}

# allow_lan_mariadb: il mariadb-server di Debian ascolta solo su 127.0.0.1
# (50-server.cnf), quindi questa macchina non poteva fare da SERVER: le casse
# client scrivono direttamente sulla 3306 del server (trovato 2026-10-07,
# piano Fase 6). Su Windows l'MSI ascolta gia' su tutte le interfacce. I file
# di mariadb.conf.d/ si leggono in ordine alfabetico: 99-* prevale su 50-*.
# L'utente app esiste gia' anche su '%' (crea_dbtable_and_user.php). Il
# gestore DB grafico (/db) resta solo-localhost: e' una regola del Caddyfile.
MARIADB_LAN_CNF=/etc/mysql/mariadb.conf.d/99-opensagra.cnf
allow_lan_mariadb() {
    local want
    want="$(printf '%s\n' \
        '# Generato da OpenSagra (install.sh): MariaDB raggiungibile dalle casse in LAN.' \
        '[mysqld]' \
        'bind-address = 0.0.0.0')"
    if [ -f "$MARIADB_LAN_CNF" ] && [ "$(cat "$MARIADB_LAN_CNF")" = "$want" ]; then
        return
    fi
    printf '%s\n' "$want" | sudo tee "$MARIADB_LAN_CNF" >/dev/null || die "Impossibile scrivere $MARIADB_LAN_CNF"
    sudo systemctl restart mariadb || die "Riavvio di MariaDB fallito dopo aver abilitato l'accesso dalla LAN"
    ok "MariaDB raggiungibile dalla LAN (porta 3306)"
}

# copy_app_files: sovrappone (merge), NON rispecchia - un rm -rf della
# cartella di destinazione prima di ricopiare (fatto in una prima versione di
# questo script, bug reale trovato testando sul Pi il 2026-09-16) cancella
# anche i file generati SOLO a destinazione, come config/variabili.env
# (gitignored: contiene il segreto Mercure persistente e le credenziali DB) -
# un rilancio perdeva il segreto ad ogni volta, silenziosamente. Stessa
# semantica di merge di Copy-Item -Recurse -Force in install.ps1 (che su una
# directory gia' esistente sovrascrive i file ma non tocca quelli extra).
copy_app_files() {
    mkdir -p "$INSTALL_DIR"
    shopt -s dotglob
    for entry in "$SOURCE_DIR"/*; do
        local name; name="$(basename "$entry")"
        local skip=0
        for ex in "${EXCLUDE_FROM_COPY[@]}"; do
            [ "$name" = "$ex" ] && { skip=1; break; }
        done
        [ "$skip" -eq 1 ] && continue
        if [ -d "$entry" ]; then
            mkdir -p "$INSTALL_DIR/$name"
            cp -a "$entry/." "$INSTALL_DIR/$name/"
        else
            cp -a "$entry" "$INSTALL_DIR/$name"
        fi
    done
    shopt -u dotglob
    mkdir -p "$INSTALL_DIR/config"
    printf '%s' "$(app_version)" > "$INSTALL_DIR/config/.installed_version"
    ok "File dell'app copiati in $INSTALL_DIR"
}

# get_or_new_mercure_secret: riusa il segreto gia' scritto se variabili.env
# c'e' gia' (rilancio idempotente), altrimenti ne genera uno nuovo - stesso
# ragionamento di Get-OrNewMercureSecret in install.ps1.
get_or_new_mercure_secret() {
    local env_path="$INSTALL_DIR/config/variabili.env"
    if [ -f "$env_path" ]; then
        local existing
        existing="$(grep -oP '^\s*MERCURE_JWT_SECRET\s*=\s*\K\S+' "$env_path" 2>/dev/null || true)"
        if [ -n "$existing" ]; then
            echo "$existing"
            return
        fi
    fi
    openssl rand -hex 32
}

new_env_file() {
    local mercure_secret="$1"
    local env_path="$INSTALL_DIR/config/variabili.env"

    if [ ! -f "$env_path" ]; then
        cat > "$env_path" <<EOF
DB_POS_HOST=127.0.0.1
DB_POS_USER=pos_own
DB_POS_PASS=pos_own1

# Realtime Mercure (Fase 4). Lo stesso valore va nel blocco mercure{} del
# Caddyfile e nella riga app_config del DB - li allinea tutti l'installer.
MERCURE_JWT_SECRET=$mercure_secret
# Lo scrive la pagina Rete al passaggio a client (segreto dell'hub del
# server). Vuoto su server / installazione indipendente.
MERCURE_JWT_SECRET_REMOTE=
# PC-ponte: id-topic di stampa serviti da questo PC, separati da virgola.
PRINT_BRIDGE_CASSE=
EOF
        ok "config/variabili.env creato"
        return
    fi

    local added=()
    grep -qP '^\s*MERCURE_JWT_SECRET\s*=\s*\S' "$env_path" || { sed -i '/^\s*MERCURE_JWT_SECRET\s*=/d' "$env_path"; echo "MERCURE_JWT_SECRET=$mercure_secret" >> "$env_path"; added+=(MERCURE_JWT_SECRET); }
    grep -qP '^\s*MERCURE_JWT_SECRET_REMOTE\s*=' "$env_path" || { echo "MERCURE_JWT_SECRET_REMOTE=" >> "$env_path"; added+=(MERCURE_JWT_SECRET_REMOTE); }
    grep -qP '^\s*PRINT_BRIDGE_CASSE\s*=' "$env_path" || { echo "PRINT_BRIDGE_CASSE=" >> "$env_path"; added+=(PRINT_BRIDGE_CASSE); }

    if [ ${#added[@]} -gt 0 ]; then
        ok "config/variabili.env aggiornato (${added[*]})"
    else
        ok "config/variabili.env gia' completo, non toccato"
    fi
}

# detect_lan_ip: stesso ragionamento di New-CaddyConfig in install.ps1 (Get-
# NetIPConfiguration con IPv4DefaultGateway) - l'IP dell'interfaccia usata per
# uscire verso Internet e' quello "vero" della LAN, non un IP di una vnet/
# container/loopback. `ip route get` lo da' senza dover elencare e filtrare
# tutte le interfacce a mano.
detect_lan_ip() {
    ip -4 route get 1.1.1.1 2>/dev/null | grep -oP 'src \K\S+' | head -1 || true
}

# Solo HTTPS - niente piu' un blocco http://:80 che serve l'app in chiaro.
# Motivo storico dell'HTTP (vedi docs/PIANO-MIGRAZIONE-FRANKENPHP.md,
# "Appendice C"): QZ Tray, il vecchio metodo di stampa condivisa via
# WebSocket dal browser, andava in mixed-content su Firefox/WebKit se la
# pagina era in HTTPS. QZ Tray e' stato rimosso (Fase 4, bridge nativo via
# Mercure - stampa lato server/PHP, mai lato browser), quindi quel motivo
# non c'e' piu': nessun blocco su HTTPS oggi. Rimuovendo `auto_https
# disable_redirects`, Caddy aggiunge da solo un redirect 308 da :80 a
# https:// per ogni host elencato sotto - non va scritto a mano. Beneficio
# collaterale: un solo hub Mercure invece di due, quindi via anche `name`/
# `transport bolt` per-hub (necessari solo quando ce n'e' più di uno nella
# stessa configurazione - vedi commit precedente).
# Mercure: sintassi 0.x (publisher_jwt/subscriber_jwt), quella del FrankenPHP
# fissato in FRANKENPHP_VERSION - vedi packaging/frankenphp.sha256.
new_caddy_config() {
    local mercure_secret="$1"
    local lan_ip; lan_ip="$(detect_lan_ip)"
    local hosts=(localhost)
    [ -n "$lan_ip" ] && hosts+=("$lan_ip")

    local https_hosts https_cors
    https_hosts="$(printf 'https://%s, ' "${hosts[@]}")"; https_hosts="${https_hosts%, }"
    https_cors="$(printf 'https://%s ' "${hosts[@]}")"; https_cors="${https_cors% }"

    local caddy_path="$INSTALL_DIR/Caddyfile"
    cat > "$caddy_path" <<EOF
{
	frankenphp
}

$https_hosts {
	root * $INSTALL_DIR
	encode zstd gzip
	php_server
	tls internal

	@dbpath path /db /db/*
	@dblocal {
		path /db /db/*
		remote_ip 127.0.0.1 ::1
	}
	handle @dblocal {
		rewrite * /adminer.php
		root * $INSTALL_DIR/tools
		php_server
	}
	handle @dbpath {
		respond "Il gestore DB e' raggiungibile solo dal PC server." 403
	}

	# File interni dell'app mai serviti via HTTP: il webroot e' l'intera
	# cartella (variabili.env, Caddyfile, log, php-cli di bin/ e config/) -
	# come in install.ps1, vedi li' il perche'. Non toccare /.well-known/ (hub).
	@private path /config/* /bin/* /logs/* /wrapper/* /private/* /tools/* /vendor/* /includes/* /frankenphp/* /packaging/* /docs/* /e2e/* /node_modules/* /Caddyfile* /composer.json /composer.lock /package.json /package-lock.json /*.ps1 /*.sh /*.exe /*.zip /*.md /*.log /*.sql /*.env
	@dotfiles {
		path_regexp /\.
		not path /.well-known/*
	}
	@uploadsphp path_regexp (?i)^/uploads/.*\.(php|phtml|phar)
	respond @private 404
	respond @dotfiles 404
	respond @uploadsphp 404

	mercure {
		publisher_jwt $mercure_secret
		subscriber_jwt $mercure_secret
		cookie_name mercure_authorization
		cors_origins $https_cors
		heartbeat 20s
	}
}
EOF
    ok "Caddyfile generato (host: ${hosts[*]})"
}

# OPENSAGRA_DB_HOST=127.0.0.1 (letto da config/env_reader.php): provisioning,
# seed del segreto Mercure e migrazioni toccano SEMPRE il MariaDB di questa
# macchina, anche su un client con DB_POS_HOST verso il server (piano, Fase 6c
# punto B). Solo per questo figlio, mai esportata: FrankenPHP non deve vederla.
invoke_php_cli() {
    OPENSAGRA_DB_HOST=127.0.0.1 "$FRANKEN_DIR/frankenphp" php-cli "$@"
}

# invoke_database_provisioning: crea_dbtable_and_user.php si aspetta
# credenziali ROOT via TCP (mysqli($host,$user,$pass)) - va bene su XAMPP
# (root senza password su Windows, stesso schema di install.ps1), ma
# l'account root@localhost di un mariadb-server apt su Debian usa
# l'autenticazione unix_socket, che RIFIUTA una connessione TCP con
# password. Anziche' toccare l'account root vero (rischioso, e persistente
# oltre l'installazione), si crea un utente di bootstrap TEMPORANEO con
# privilegi da root, si passa quello allo script via --root-*, e lo si
# elimina subito dopo - stessa idea di un token usa-e-getta. `sudo mariadb`
# funziona sempre senza password (root di sistema -> unix_socket), qualunque
# sia lo stato dell'account root SQL.
invoke_database_provisioning() {
    local tmp_user="opensagra_setup_tmp"
    local tmp_pass; tmp_pass="$(openssl rand -hex 16)"

    sudo mariadb -e "
        DROP USER IF EXISTS '$tmp_user'@'127.0.0.1';
        CREATE USER '$tmp_user'@'127.0.0.1' IDENTIFIED BY '$tmp_pass';
        GRANT ALL PRIVILEGES ON *.* TO '$tmp_user'@'127.0.0.1' WITH GRANT OPTION;
        FLUSH PRIVILEGES;
    " || die "Creazione dell'utente di bootstrap DB fallita"

    local rc=0
    invoke_php_cli "$INSTALL_DIR/config/crea_dbtable_and_user.php" \
        --root-host=127.0.0.1 --root-user="$tmp_user" --root-pass="$tmp_pass" \
        >>"$LOG_FILE" 2>&1 || rc=$?

    sudo mariadb -e "DROP USER IF EXISTS '$tmp_user'@'127.0.0.1';" || true

    [ "$rc" -eq 0 ] || die "Provisioning del database fallito (dettagli in $LOG_FILE)"
    ok "Database creato/verificato"
}

set_mercure_secret_in_db() {
    local mercure_secret="$1"
    local seed_script="/tmp/opensagra-seed-mercure.php"
    cat > "$seed_script" <<PHPEOF
<?php
require '$INSTALL_DIR/config/get_db_connection.php';
require '$INSTALL_DIR/config/app_config.php';
\$secret = getenv('OPENSAGRA_MERCURE_SECRET');
if (!is_string(\$secret) || \$secret === '') { fwrite(STDERR, "segreto mancante\n"); exit(1); }
\$ok = setAppConfig(\$connectionDB, 'MERCURE_JWT_SECRET', \$secret);
fwrite(\$ok ? STDOUT : STDERR, (\$ok ? 'scritto' : 'fallito') . "\n");
exit(\$ok ? 0 : 1);
PHPEOF
    local rc=0
    OPENSAGRA_MERCURE_SECRET="$mercure_secret" invoke_php_cli "$seed_script" >>"$LOG_FILE" 2>&1 || rc=$?
    rm -f "$seed_script"
    [ "$rc" -eq 0 ] || die "Scrittura di MERCURE_JWT_SECRET in app_config fallita (dettagli in $LOG_FILE)"
    ok "Segreto Mercure salvato in app_config"
}

invoke_migrations() {
    local dir="$INSTALL_DIR/config/migrations"
    [ -d "$dir" ] || return 0
    local f
    for f in "$dir"/*.php; do
        [ -e "$f" ] || continue
        invoke_php_cli "$f" >>"$LOG_FILE" 2>&1 || die "Migrazione fallita: $(basename "$f") (dettagli in $LOG_FILE)"
    done
    ok "Migrazioni database applicate"
}

install_wrapper() {
    local src="$SOURCE_DIR/wrapper/opensagra-wrapper"
    [ -f "$src" ] || die "Wrapper non trovato ($src). Compilalo prima: cd wrapper && GOOS=linux GOARCH=\$(go env GOARCH) go build -o opensagra-wrapper ."
    # rm prima di cp, non sovrascrittura in-place: un rilancio con il wrapper
    # GIA' in esecuzione altrimenti fallisce con "Text file busy" (Linux non
    # permette di riscrivere l'inode di un eseguibile mappato in memoria).
    # rm+create e' sicuro anche a processo vivo: il vecchio inode (ormai senza
    # nome) resta valido per chi lo sta gia' eseguendo finche' non esce -
    # stesso meccanismo con cui un package manager aggiorna un binario attivo.
    rm -f "$INSTALL_DIR/opensagra-wrapper"
    cp "$src" "$INSTALL_DIR/opensagra-wrapper"
    chmod +x "$INSTALL_DIR/opensagra-wrapper"
    ok "Wrapper copiato"
}

# install_uninstaller: copia uninstall.sh accanto all'app - a differenza di
# Windows (una voce vera in Impostazioni > App, Register-UninstallEntry in
# install.ps1), Linux non ha un registro centrale equivalente per uno script;
# lo si lascia semplicemente dentro $INSTALL_DIR cosi' chi vuole disinstallare
# mesi dopo lo trova li', senza doversi procurare di nuovo il pacchetto
# originale.
install_uninstaller() {
    local src="$SOURCE_DIR/uninstall.sh"
    [ -f "$src" ] || { log "uninstall.sh non trovato in $SOURCE_DIR, salto la copia (non blocca l'installazione)."; return; }
    cp "$src" "$INSTALL_DIR/uninstall.sh"
    chmod +x "$INSTALL_DIR/uninstall.sh"
    ok "Disinstaller copiato"
}

# new_desktop_launcher: equivalente di New-WrapperShortcut (collegamento sul
# desktop di install.ps1). A differenza del "server headless" (nessuna
# sessione grafica: qui l'icona non serve a nessuno, solo l'unit systemd
# --user conta), questo passo e' per un desktop Linux con un ambiente
# grafico vero (GNOME/KDE/XFCE) - se manca ~/Desktop lo si salta senza
# fallire l'installazione, non tutte le macchine target ce l'hanno.
new_desktop_launcher() {
    local desktop_dir="$HOME/Desktop"
    [ -d "$desktop_dir" ] || { log "Nessuna cartella Desktop, salto il launcher grafico (systemd --user resta comunque attivo)."; return; }
    local launcher="$desktop_dir/OpenSagra.desktop"
    cat > "$launcher" <<EOF
[Desktop Entry]
Type=Application
Name=OpenSagra
Comment=OpenSagra - pannello di controllo
Exec=$INSTALL_DIR/opensagra-wrapper
Path=$INSTALL_DIR
Terminal=false
Categories=Office;
EOF
    chmod +x "$launcher"
    # Nautilus (GNOME Files) tratta un .desktop scaricato/copiato come non
    # fidato finche' non lo si marca esplicitamente (altrimenti mostra "Fidati
    # ed esegui" invece di lanciarlo al doppio click) - best-effort, altri
    # desktop environment (XFCE, KDE) non ne hanno bisogno e gio potrebbe non
    # esserci affatto.
    command -v gio >/dev/null 2>&1 && gio set "$launcher" metadata::trusted true 2>/dev/null || true
    ok "Collegamento sul desktop creato"
}

# start_wrapper_and_autostart: -register-autostart fa scrivere al wrapper
# stesso la sua unit systemd --user (setAutostart in platform_linux.go) e la
# fa partire con `systemctl --user enable --now` - MA l'invocazione qui sotto
# CONTINUA anche lei nel proprio avvio normale (non e' un'operazione "solo
# registra ed esci" come su Windows, dove la chiave HKCU Run scritta da
# -register-autostart non fa partire nessun secondo processo). Risultato:
# DUE processi si contendono il lock a file (acquireSingleInstance, flock)
# quasi nello stesso istante - chi perde rileva l'altro vivo e esce da solo
# (main.go), ma non e' garantito che a vincere sia la copia lanciata da
# systemd (verificato dal vivo: spesso vince questa invocazione diretta,
# lasciando l'istanza REALMENTE in esecuzione fuori dalla supervisione di
# systemd - "systemctl status" la mostrerebbe inactive anche se OpenSagra
# funziona). Si forza il risultato: si ferma con SIGTERM (stessa uscita
# pulita di un vero stop systemd - la conferma a video e' solo sul percorso
# -quit/requestQuit, il segnale la salta) qualunque istanza sia rimasta viva,
# letta dal PID nel lock file, e si fa ripartire esplicitamente SOTTO
# systemd - da qui in poi e' davvero supervisionata (restart automatico).
#
# Serve anche `loginctl enable-linger`: senza, la sessione utente (e quindi
# la unit --user) sparisce alla disconnessione, e su un PC server senza login
# grafico interattivo la unit non riparte mai al boot - esattamente il caso
# "ruolo client/server headless" gia' annotato in platform_linux.go.
start_wrapper_and_autostart() {
    sudo loginctl enable-linger "$USER" || die "loginctl enable-linger fallito"
    ok "Linger abilitato per $USER (l'unit systemd --user sopravvive al logout/riparte al boot)"

    # Ferma qualunque istanza GIA' viva (systemd o un lancio diretto
    # precedente) PRIMA di toccare il lock file. Il file <logdir>/wrapper.lock
    # e' solo informativo (PID+URL) - il vero mutex e' un flock del kernel
    # (acquireSingleInstance, platform_unix.go), che si rilascia da solo se il
    # processo muore. Cancellare qui sotto il file informativo mentre il
    # flock e' ANCORA tenuto da un processo vivo lo renderebbe illeggibile
    # per la prossima istanza, che a quel punto non distingue piu' "nessuno
    # vivo" da "vivo ma non riesco a saperlo" - main.go tratta quel caso come
    # mutex stantio e PROCEDE comunque (pensato per una stranezza reale dei
    # mutex nominali di Windows, non per un flock Linux genuinamente tenuto),
    # risultato: due wrapper vivi in contemporanea (bug reale trovato
    # testando sul Pi, 2026-09-16). Si evita lo scenario alla radice: nessuna
    # istanza viva -> il file, quando lo si cancella, non mente a nessuno.
    local lock_file="$INSTALL_DIR/logs/wrapper.lock"
    local old_pids; old_pids="$(pgrep -u "$USER" -f "$INSTALL_DIR/opensagra-wrapper" || true)"
    if [ -n "$old_pids" ]; then
        kill -TERM $old_pids 2>/dev/null || true
        local tries=0
        while pgrep -u "$USER" -f "$INSTALL_DIR/opensagra-wrapper" >/dev/null 2>&1 && [ $tries -lt 20 ]; do
            sleep 0.5; tries=$((tries + 1))
        done
    fi
    rm -f "$lock_file"

    nohup "$INSTALL_DIR/opensagra-wrapper" -autostarted -register-autostart \
        >>"$LOG_FILE" 2>&1 &
    disown

    local pid="" tries=0
    while [ $tries -lt 20 ]; do
        pid="$(head -1 "$lock_file" 2>/dev/null || true)"
        [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null && break
        sleep 0.5; tries=$((tries + 1))
    done

    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        # Grace period PRIMA di fermarla: bug reale trovato il 2026-09-29 su
        # un'installazione davvero da zero (nessuna CA locale precedente). Il
        # file di lock appare appena status.start() riesce - PRIMA che
        # FrankenPHP (avviato da sup.Start poco sopra, fire-and-forget, non
        # atteso) abbia finito la SUA inizializzazione interna, che al primo
        # avvio in assoluto include la generazione della CA locale (root.key/
        # root.crt/intermediate.key/intermediate.crt, piu' file scritti in
        # sequenza). Un SIGTERM troppo tempestivo (il poll sopra ha
        # granularita' 0.5s) puo' arrivare a meta' di quella scrittura,
        # lasciando una CA corrotta (es. intermediate.key mancante) che blocca
        # OGNI riavvio successivo finche' qualcuno non cancella a mano
        # ~/.local/share/caddy - non basta ripetere l'installazione, il danno
        # e' gia' fatto sul disco. 5s di margine bastano abbondantemente per
        # la generazione della CA (operazione singola, mai piu' ripetuta una
        # volta che i file esistono) anche su hardware lento come un Pi.
        sleep 5
        kill -TERM "$pid" 2>/dev/null || true
        tries=0
        while kill -0 "$pid" 2>/dev/null && [ $tries -lt 20 ]; do sleep 0.5; tries=$((tries + 1)); done
    else
        log "ATTENZIONE: nessuna istanza rilevata viva dopo la registrazione autostart (dettagli in $LOG_FILE)"
    fi

    systemctl --user start opensagra-wrapper.service 2>>"$LOG_FILE" || true
    sleep 2
    if systemctl --user is-active --quiet opensagra-wrapper.service 2>/dev/null; then
        ok "OpenSagra avviato (systemd --user: opensagra-wrapper.service)"
    else
        log "ATTENZIONE: opensagra-wrapper.service non risulta attivo dopo l'avvio - controlla 'systemctl --user status opensagra-wrapper' e $LOG_FILE"
    fi
}

set_firewall_rules() {
    if command -v ufw >/dev/null 2>&1; then
        sudo ufw allow 80/tcp >/dev/null 2>&1 || true
        sudo ufw allow 443/tcp >/dev/null 2>&1 || true
        sudo ufw allow 3306/tcp >/dev/null 2>&1 || true
        ok "Regole ufw configurate (80, 443, 3306)"
    else
        log "ufw non installato: nessuna regola firewall da configurare (se ne usi un altro, apri tu le porte 80/443/3306)."
    fi
}

# register_local_ca_trust: stesso ragionamento di Register-LocalCaTrust in
# install.ps1 - `frankenphp trust` chiede all'API admin di Caddy (127.0.0.1:
# 2019, su fino a via HTTP anche se il sito vero e' su tls internal) il
# certificato locale e lo installa nei trust store, cosi' l'utente non vede
# l'avviso "connessione non sicura" al primo avvio. Necessario apposta per un
# processo NON privilegiato (`frankenphp trust --help`: "it might fail if
# Caddy doesn't have the appropriate permissions... if the server process
# runs as an unprivileged user (such as via systemd)" - esattamente il nostro
# caso). Verificato dal vivo sul Pi: FrankenPHP installa gia' da solo la CA
# nello store di sistema (Debian/Raspbian, via update-ca-certificates) al
# primo avvio anche senza questo passo - `frankenphp trust` qui serve
# soprattutto per gli store NSS separati di Firefox/Chrome (via `certutil`,
# non tocca lo store di sistema), e come rete di sicurezza esplicita se
# l'installazione automatica fosse fallita silenziosamente. Best-effort come
# su Windows: non fa fallire l'installer, un avviso una-tantum nel browser
# resta un fallback accettabile.
register_local_ca_trust() {
    [ -x "$FRANKEN_DIR/frankenphp" ] || return

    # certutil (pacchetto libnss3-tools) e' quello che `frankenphp trust`
    # usa per scrivere nei database NSS di Firefox/Chrome - senza, tocca solo
    # lo store di sistema (comunque sufficiente per curl/wget/app non-browser).
    if ! command -v certutil >/dev/null 2>&1; then
        log "installo libnss3-tools (certutil, per fidare la CA locale anche in Firefox/Chrome)..."
        sudo apt-get update -qq && sudo apt-get install -y -qq libnss3-tools 2>>"$LOG_FILE" || \
            log "installazione di libnss3-tools fallita (non bloccante, resta il trust dello store di sistema)"
    fi

    local admin_up=0 i
    for i in $(seq 1 20); do
        curl -fsS --max-time 2 http://127.0.0.1:2019/config/ >/dev/null 2>&1 && { admin_up=1; break; }
        sleep 1
    done
    if [ "$admin_up" -ne 1 ]; then
        log "register_local_ca_trust: l'API admin di Caddy (127.0.0.1:2019) non risponde dopo 20s, salto."
        return
    fi

    if "$FRANKEN_DIR/frankenphp" trust --address 127.0.0.1:2019 >>"$LOG_FILE" 2>&1; then
        ok "Certificato locale HTTPS fidato"
    else
        log "Certificato locale HTTPS: da confermare al primo avvio nel browser (dettagli in $LOG_FILE)"
    fi
}

# ============================================================================
# Orchestrazione
# ============================================================================

log "avvio install.sh (PID $$) - sorgente: $SOURCE_DIR -> destinazione: $INSTALL_DIR"

test_prerequisites
install_frankenphp
grant_bind_service_capability
set_php_ini
install_mariadb

copy_app_files
MERCURE_SECRET="$(get_or_new_mercure_secret)"
new_env_file "$MERCURE_SECRET"
new_caddy_config "$MERCURE_SECRET"

invoke_database_provisioning
invoke_migrations
set_mercure_secret_in_db "$MERCURE_SECRET"

install_wrapper
install_uninstaller
new_desktop_launcher

set_firewall_rules

start_wrapper_and_autostart
register_local_ca_trust

log "Installazione completata. Log completo in $LOG_FILE"
