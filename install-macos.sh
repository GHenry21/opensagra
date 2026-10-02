#!/bin/bash
# Installer OpenSagra per macOS (13 Ventura e successivi, Apple Silicon e
# Intel) - equivalente di install.sh (Linux) e install.ps1 (Windows). Porta un
# Mac "di fabbrica" a "app funzionante": FrankenPHP + MariaDB (Homebrew) +
# wrapper come supervisore dei processi, avviato al login da un LaunchAgent.
# Stessa configurazione "indipendente" di default - il passaggio a client si
# fa DOPO, dalla pagina Configurazione Rete dentro l'app.
#
# Script separato da install.sh, non un ramo dentro lo stesso file: su macOS
# cambia quasi ogni passo (Homebrew invece di apt, launchd invece di systemd,
# niente setcap/loginctl/ip/grep -P/sed -i GNU, bash 3.2 di serie). La logica
# condivisa (variabili.env, Caddyfile, provisioning DB, migrazioni) e' la
# stessa di install.sh, riscritta con strumenti BSD/POSIX.
#
# Differenze di rilievo rispetto a Linux:
#   - porte 80/443: da macOS 10.14 un processo NON root puo' gia' legarsi alle
#     porte <1024 su tutte le interfacce - niente equivalente di setcap.
#   - MariaDB via Homebrew + `brew services` (LaunchAgent dell'utente): parte
#     al login, come il wrapper. Per un Mac server senza nessuno davanti va
#     attivato il login automatico (Impostazioni > Utenti e gruppi).
#   - Gatekeeper: i file del pacchetto scaricati da un browser ereditano
#     l'attributo com.apple.quarantine, che blocca all'avvio binari non
#     firmati da uno sviluppatore Apple (wrapper, FrankenPHP) - si rimuove.
#
# Idempotente come install.sh: rilanciarlo non rompe nulla.
#
# Uso:
#   ./install-macos.sh
#
# Da lanciare come utente normale (amministratore del Mac): chiede la password
# per sudo solo dove serve (Homebrew alla prima installazione, firewall,
# certificato HTTPS nel portachiavi di sistema).
#
# ATTENZIONE: scritto senza hardware macOS a disposizione - da verificare dal
# vivo (Mac mini Scaleway, Apple Silicon) prima di considerarlo stabile.

set -euo pipefail

# ============================================================================
# Configurazione
# ============================================================================

INSTALL_DIR="$HOME/opensagra"
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRANKEN_DIR="$HOME/.frankenphp"
LAUNCH_AGENT_LABEL="com.opensagra.wrapper"
LAUNCH_AGENT_PLIST="$HOME/Library/LaunchAgents/$LAUNCH_AGENT_LABEL.plist"
APP_BUNDLE="$HOME/Applications/OpenSagra.app"
LOG_FILE="/tmp/opensagra-install.log"
: > "$LOG_FILE"

# La build statica di FrankenPHP per macOS NON cerca il php.ini accanto al
# binario (verificato sul runner macOS: nessun file caricato, limiti di
# default) - PHPRC glielo indica esplicitamente. Vale per i php-cli lanciati
# da questo script; per i figli del wrapper lo imposta il wrapper stesso
# (childEnv in supervisor.go).
export PHPRC="$FRANKEN_DIR"

# date -Iseconds e' GNU: il date BSD di macOS vuole il formato esplicito.
log() { echo "[$(date '+%Y-%m-%dT%H:%M:%S%z')] $*" | tee -a "$LOG_FILE"; }
ok()  { echo "  OK: $*" | tee -a "$LOG_FILE"; }
die() { echo "ERRORE: $*" | tee -a "$LOG_FILE" >&2; exit 1; }

# Stessa lista di install.sh (materiale di sviluppo, non serve a chi usa l'app).
EXCLUDE_FROM_COPY=(
    .git .github .claude .vscode e2e docs node_modules
    install.ps1 install.sh install-macos.sh
    uninstall.ps1 uninstall.sh uninstall-macos.sh
    .gitignore .gitattributes .DS_Store
    playwright-report test-results package.json package-lock.json playwright.config.js
    "bt.html" "navbar example.html"
    private packaging
    wrapper
    VERSION # metadato del pacchetto (letto da app_version), non un file dell'app
    frankenphp # binario incluso nel pacchetto, lo installa install_frankenphp
)

# --- helper variabili.env: sostituti BSD di grep -P / sed -i GNU ---

env_value() { # file chiave -> valore (vuoto se assente)
    [ -f "$1" ] || return 0
    sed -n -E "s/^[[:space:]]*$2[[:space:]]*=[[:space:]]*([^[:space:]]+).*/\1/p" "$1" 2>/dev/null | head -1 || true
}
env_has_key() { grep -Eq "^[[:space:]]*$2[[:space:]]*=" "$1" 2>/dev/null; }

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
    [ "$(uname -s)" = "Darwin" ] || die "Questo installer e' per macOS. Su Linux usa install.sh, su Windows install.ps1."
    [ "$EUID" -ne 0 ] || die "Non lanciare con sudo/come root: il LaunchAgent e Homebrew vanno installati come utente normale. Lo script chiede la password da solo dove serve."
    local major; major="$(sw_vers -productVersion | cut -d. -f1)"
    [ "$major" -ge 13 ] || die "Serve macOS 13 Ventura o successivo (trovato $(sw_vers -productVersion))."
    ok "prerequisiti di base (macOS $(sw_vers -productVersion), $(uname -m))"
}

# brew_path: Homebrew sta in /opt/homebrew su Apple Silicon e in /usr/local su
# Intel - e una shell lanciata da SSH spesso non ha nessuno dei due nel PATH.
brew_path() {
    local p
    for p in /opt/homebrew/bin/brew /usr/local/bin/brew; do
        [ -x "$p" ] && { echo "$p"; return; }
    done
    command -v brew 2>/dev/null || true
}

# install_homebrew: installer ufficiale in modalita' non interattiva. Chiede
# la password (sudo) per creare /opt/homebrew e installa da solo i Command
# Line Tools di Xcode se mancano - alla prima volta puo' durare parecchi
# minuti.
install_homebrew() {
    local brew; brew="$(brew_path)"
    if [ -z "$brew" ]; then
        log "Installo Homebrew (chiedera' la password del Mac)..."
        NONINTERACTIVE=1 /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" \
            || die "Installazione di Homebrew fallita"
        brew="$(brew_path)"
        [ -n "$brew" ] || die "Homebrew installato ma 'brew' non trovato"
        ok "Homebrew installato"
    else
        ok "Homebrew gia' presente ($brew)"
    fi
    eval "$("$brew" shellenv)"
    export HOMEBREW_NO_AUTO_UPDATE=1 HOMEBREW_NO_INSTALL_CLEANUP=1 HOMEBREW_NO_ENV_HINTS=1
}

# install_frankenphp: binario statico ufficiale per macOS, stessa logica di
# install.sh - UNA versione fissata per tutti gli OS (mai "latest", vedi
# packaging/frankenphp.sha256 e php/frankenphp#2685), presa dal pacchetto di
# release se c'e' (frankenphp/<asset>), altrimenti scaricata (copia del
# repo). Una versione diversa gia' presente viene sostituita (rm+mv, sicuro
# anche col processo attivo). Il binario e' firmato ad-hoc ma non
# notarizzato: si toglie l'eventuale attributo di quarantena.
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
        arm64)  asset="frankenphp-mac-arm64" ;;
        x86_64) asset="frankenphp-mac-x86_64" ;;
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
    xattr -d com.apple.quarantine "$tmp" 2>/dev/null || true
    "$tmp" version >/dev/null 2>&1 || { rm -f "$tmp"; die "FrankenPHP non si avvia (binario incompatibile con questo Mac?)"; }
    rm -f "$FRANKEN_DIR/frankenphp"
    mv "$tmp" "$FRANKEN_DIR/frankenphp"
    if [ -n "$current" ]; then
        ok "FrankenPHP $current sostituito con $FRANKENPHP_VERSION ($asset)"
    else
        ok "FrankenPHP $FRANKENPHP_VERSION installato ($asset)"
    fi
}

# set_php_ini: come install.sh - la build statica ha gia' le estensioni, il
# php.ini (accanto al binario) serve solo per i limiti runtime. Qui in piu' si
# verifica che venga DAVVERO letto: sulla build Linux e' stato provato dal
# vivo, su quella macOS non ancora.
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
    local loaded ext missing=""
    loaded="$("$FRANKEN_DIR/frankenphp" php-cli -r 'echo implode(",", get_loaded_extensions());' 2>>"$LOG_FILE")"
    for ext in "${required[@]}"; do
        case ",$loaded," in *",$ext,"*) ;; *) missing="$missing $ext" ;; esac
    done
    [ -z "$missing" ] || die "Estensioni PHP mancanti:$missing (dettagli in $LOG_FILE)"

    local upl
    upl="$("$FRANKEN_DIR/frankenphp" php-cli -r 'echo ini_get("upload_max_filesize");' 2>>"$LOG_FILE" || true)"
    [ "$upl" = "40M" ] || die "php.ini in $FRANKEN_DIR non letto da FrankenPHP nemmeno con PHPRC (upload_max_filesize=$upl)"
    ok "php.ini scritto, estensioni PHP verificate"
}

# install_mariadb: formula Homebrew + `brew services` (LaunchAgent utente).
# Rifiuta di procedere se c'e' gia' la formula `mysql`: le due condividono
# porta 3306 e cartella dati, convivenza non supportata.
install_mariadb() {
    if brew list --formula mysql >/dev/null 2>&1; then
        die "Trovato MySQL installato via Homebrew: OpenSagra usa MariaDB e i due vanno in conflitto (porta 3306). Rimuovilo prima (brew services stop mysql && brew uninstall mysql)."
    fi
    if brew list --formula mariadb >/dev/null 2>&1; then
        ok "MariaDB gia' presente"
    else
        log "Installo MariaDB (Homebrew)..."
        brew install mariadb >>"$LOG_FILE" 2>&1 || die "Installazione di MariaDB fallita (dettagli in $LOG_FILE)"
        ok "MariaDB installato"
    fi
    brew services start mariadb >>"$LOG_FILE" 2>&1 || log "brew services start mariadb ha dato errore (dettagli in $LOG_FILE) - verifico se risponde comunque"

    local i
    for i in $(seq 1 30); do
        mariadb -e 'SELECT 1' >/dev/null 2>&1 && { ok "Servizio MariaDB attivo"; return; }
        sleep 1
    done
    die "MariaDB non risponde dopo 30s (prova: brew services list; log in $(brew --prefix)/var/mysql/*.err)"
}

copy_app_files() {
    mkdir -p "$INSTALL_DIR"
    shopt -s dotglob
    local entry name skip ex
    for entry in "$SOURCE_DIR"/*; do
        name="$(basename "$entry")"
        skip=0
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
    # Pacchetto scaricato dal browser = ogni file in quarantena (Gatekeeper).
    xattr -dr com.apple.quarantine "$INSTALL_DIR" 2>/dev/null || true
    ok "File dell'app copiati in $INSTALL_DIR"
}

get_or_new_mercure_secret() {
    local existing
    existing="$(env_value "$INSTALL_DIR/config/variabili.env" MERCURE_JWT_SECRET)"
    if [ -n "$existing" ]; then
        echo "$existing"
        return
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

    local added=""
    if [ -z "$(env_value "$env_path" MERCURE_JWT_SECRET)" ]; then
        sed -i '' -E '/^[[:space:]]*MERCURE_JWT_SECRET[[:space:]]*=/d' "$env_path"
        echo "MERCURE_JWT_SECRET=$mercure_secret" >> "$env_path"
        added="$added MERCURE_JWT_SECRET"
    fi
    env_has_key "$env_path" MERCURE_JWT_SECRET_REMOTE || { echo "MERCURE_JWT_SECRET_REMOTE=" >> "$env_path"; added="$added MERCURE_JWT_SECRET_REMOTE"; }
    env_has_key "$env_path" PRINT_BRIDGE_CASSE        || { echo "PRINT_BRIDGE_CASSE=" >> "$env_path"; added="$added PRINT_BRIDGE_CASSE"; }

    if [ -n "$added" ]; then
        ok "config/variabili.env aggiornato ($added )"
    else
        ok "config/variabili.env gia' completo, non toccato"
    fi
}

# detect_lan_ip: l'IP dell'interfaccia della route di default (equivalente di
# `ip route get` su Linux) - macOS non ha `ip`.
detect_lan_ip() {
    local iface
    iface="$(route -n get default 2>/dev/null | awk '/interface:/{print $2; exit}' || true)"
    [ -n "$iface" ] && ipconfig getifaddr "$iface" 2>/dev/null || true
}

# Identico a new_caddy_config di install.sh (vedi li' il perche' del solo HTTPS).
new_caddy_config() {
    local mercure_secret="$1"
    local lan_ip; lan_ip="$(detect_lan_ip)"
    local hosts=(localhost)
    [ -n "$lan_ip" ] && hosts+=("$lan_ip")

    local https_hosts https_cors
    https_hosts="$(printf 'https://%s, ' "${hosts[@]}")"; https_hosts="${https_hosts%, }"
    https_cors="$(printf 'https://%s ' "${hosts[@]}")"; https_cors="${https_cors% }"

    cat > "$INSTALL_DIR/Caddyfile" <<EOF
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

invoke_php_cli() {
    "$FRANKEN_DIR/frankenphp" php-cli "$@"
}

# invoke_database_provisioning: stessa idea di install.sh (utente di bootstrap
# TEMPORANEO con privilegi da root, eliminato subito dopo), ma senza sudo: la
# MariaDB di Homebrew crea al primo avvio un account <utente macOS>@localhost
# con unix_socket e tutti i privilegi, quindi `mariadb` lanciato da questo
# utente entra gia' come amministratore. Ripiego su `sudo mariadb -u root`
# (root@localhost, sempre unix_socket) se l'account utente non c'e' (es.
# MariaDB installata a suo tempo da un altro utente del Mac).
mariadb_admin() {
    if mariadb -e 'SELECT 1' >/dev/null 2>&1; then
        mariadb "$@"
    else
        sudo "$(command -v mariadb)" -u root "$@"
    fi
}

invoke_database_provisioning() {
    local tmp_user="opensagra_setup_tmp"
    local tmp_pass; tmp_pass="$(openssl rand -hex 16)"

    mariadb_admin -e "
        DROP USER IF EXISTS '$tmp_user'@'127.0.0.1';
        CREATE USER '$tmp_user'@'127.0.0.1' IDENTIFIED BY '$tmp_pass';
        GRANT ALL PRIVILEGES ON *.* TO '$tmp_user'@'127.0.0.1' WITH GRANT OPTION;
        FLUSH PRIVILEGES;
    " || die "Creazione dell'utente di bootstrap DB fallita"

    local rc=0
    invoke_php_cli "$INSTALL_DIR/config/crea_dbtable_and_user.php" \
        --root-host=127.0.0.1 --root-user="$tmp_user" --root-pass="$tmp_pass" \
        >>"$LOG_FILE" 2>&1 || rc=$?

    mariadb_admin -e "DROP USER IF EXISTS '$tmp_user'@'127.0.0.1';" || true

    [ "$rc" -eq 0 ] || die "Provisioning del database fallito (dettagli in $LOG_FILE)"
    ok "Database creato/verificato"
}

set_mercure_secret_in_db() {
    local mercure_secret="$1"
    local seed_script; seed_script="$(mktemp /tmp/opensagra-seed-mercure.XXXXXX)"
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
    local dir="$INSTALL_DIR/config/migrations" f
    [ -d "$dir" ] || return 0
    for f in "$dir"/*.php; do
        [ -e "$f" ] || continue
        invoke_php_cli "$f" >>"$LOG_FILE" 2>&1 || die "Migrazione fallita: $(basename "$f") (dettagli in $LOG_FILE)"
    done
    ok "Migrazioni database applicate"
}

# install_wrapper: cerca prima il binario gia' compilato per questa
# architettura (pacchetto di release: wrapper/opensagra-wrapper-darwin-<arch>,
# cross-compilato da Windows/Linux con GOOS=darwin), poi ripiega su una
# compilazione al volo se sul Mac c'e' Go (copia di sviluppo del repo). I
# binari darwin/arm64 prodotti dal linker Go sono gia' firmati ad-hoc, quanto
# basta ad Apple Silicon per eseguirli.
install_wrapper() {
    local goarch
    case "$(uname -m)" in arm64) goarch=arm64 ;; *) goarch=amd64 ;; esac
    local src="$SOURCE_DIR/wrapper/opensagra-wrapper-darwin-$goarch"
    if [ ! -f "$src" ]; then
        if command -v go >/dev/null 2>&1 && [ -f "$SOURCE_DIR/wrapper/go.mod" ]; then
            log "Wrapper precompilato non trovato, lo compilo con Go..."
            (cd "$SOURCE_DIR/wrapper" && CGO_ENABLED=0 go build -o "opensagra-wrapper-darwin-$goarch" .) >>"$LOG_FILE" 2>&1 \
                || die "Compilazione del wrapper fallita (dettagli in $LOG_FILE)"
        else
            die "Wrapper non trovato ($src). Compilalo prima: cd wrapper && GOOS=darwin GOARCH=$goarch CGO_ENABLED=0 go build -o opensagra-wrapper-darwin-$goarch ."
        fi
    fi
    # rm prima di cp, come su Linux: sostituisce il file senza toccare
    # l'inode che un'istanza gia' in esecuzione sta usando.
    rm -f "$INSTALL_DIR/opensagra-wrapper"
    cp "$src" "$INSTALL_DIR/opensagra-wrapper"
    chmod +x "$INSTALL_DIR/opensagra-wrapper"
    xattr -d com.apple.quarantine "$INSTALL_DIR/opensagra-wrapper" 2>/dev/null || true
    ok "Wrapper copiato ($goarch)"
}

install_uninstaller() {
    local src="$SOURCE_DIR/uninstall-macos.sh"
    [ -f "$src" ] || { log "uninstall-macos.sh non trovato in $SOURCE_DIR, salto la copia (non blocca l'installazione)."; return; }
    cp "$src" "$INSTALL_DIR/uninstall-macos.sh"
    chmod +x "$INSTALL_DIR/uninstall-macos.sh"
    ok "Disinstaller copiato"
}

# new_app_launcher: equivalente del collegamento sul desktop di Windows / del
# .desktop di Linux. Un mini bundle .app (Info.plist + script) in
# ~/Applications - lo trova Spotlight e il Launchpad, si trascina nel Dock -
# piu' un alias sulla Scrivania. Cliccato col wrapper gia' attivo, il wrapper
# rileva l'istanza viva e apre solo la sua finestra di stato (main.go).
# Creato localmente da questo script = nessuna quarantena, Gatekeeper non lo
# blocca anche senza firma.
new_app_launcher() {
    local macos_dir="$APP_BUNDLE/Contents/MacOS"
    mkdir -p "$macos_dir" "$APP_BUNDLE/Contents/Resources"
    cat > "$APP_BUNDLE/Contents/Info.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
	<key>CFBundleName</key><string>OpenSagra</string>
	<key>CFBundleDisplayName</key><string>OpenSagra</string>
	<key>CFBundleIdentifier</key><string>com.opensagra.launcher</string>
	<key>CFBundleExecutable</key><string>OpenSagra</string>
	<key>CFBundlePackageType</key><string>APPL</string>
	<key>CFBundleShortVersionString</key><string>1.0</string>
	<key>LSUIElement</key><true/>
</dict></plist>
EOF
    # Se il LaunchAgent e' registrato e OpenSagra e' fermo (es. dopo "Esci"),
    # lo si fa ripartire ATTRAVERSO launchd (kickstart) e si aspetta che
    # prenda il lock: lanciato direttamente, il wrapper girerebbe fuori da
    # launchd e un suo crash non verrebbe piu' riavviato fino al prossimo
    # login (visto dal vivo sul runner macOS, 2026-10-01). Poi l'exec trova
    # l'istanza viva e apre solo la sua finestra di stato (main.go).
    cat > "$macos_dir/OpenSagra" <<EOF
#!/bin/bash
target="gui/\$(id -u)/$LAUNCH_AGENT_LABEL"
if launchctl print "\$target" >/dev/null 2>&1 && \\
   ! launchctl print "\$target" | grep -q 'state = running'; then
    launchctl kickstart "\$target"
    for i in \$(seq 1 20); do
        pid="\$(head -1 "$INSTALL_DIR/logs/wrapper.lock" 2>/dev/null)"
        [ -n "\$pid" ] && kill -0 "\$pid" 2>/dev/null && break
        sleep 0.5
    done
fi
exec "$INSTALL_DIR/opensagra-wrapper"
EOF
    chmod +x "$macos_dir/OpenSagra"
    if [ -d "$HOME/Desktop" ]; then
        ln -sfn "$APP_BUNDLE" "$HOME/Desktop/OpenSagra"
    fi
    ok "App OpenSagra creata in ~/Applications (e collegamento sulla Scrivania)"
}

# start_wrapper_and_autostart: stessa danza di install.sh. -register-autostart
# fa scrivere al wrapper il proprio LaunchAgent (setAutostart in
# platform_darwin.go) e il bootstrap con RunAtLoad fa partire SUBITO una
# seconda istanza: le due si contendono il lock e non e' detto che vinca
# quella di launchd. Si ferma quindi l'istanza viva (SIGTERM = uscita pulita,
# codice 0 -> KeepAlive/SuccessfulExit=false NON la fa ripartire) e la si
# riavvia esplicitamente sotto launchd con `launchctl kickstart`.
#
# Il dominio gui/<uid> esiste solo con una sessione grafica attiva per questo
# utente: da SSH senza nessuno loggato a video il bootstrap fallisce. In quel
# caso si avvia il wrapper fuori da launchd (funziona, ma non riparte da solo)
# e si avvisa - basta rilanciare l'installer dopo un login grafico.
start_wrapper_and_autostart() {
    local uid; uid="$(id -u)"
    local lock_file="$INSTALL_DIR/logs/wrapper.lock"
    local exe="$INSTALL_DIR/opensagra-wrapper"
    local old_pids tries pid

    old_pids="$(pgrep -u "$uid" -f "$exe" || true)"
    if [ -n "$old_pids" ]; then
        kill -TERM $old_pids 2>/dev/null || true
        tries=0
        while pgrep -u "$uid" -f "$exe" >/dev/null 2>&1 && [ $tries -lt 20 ]; do
            sleep 0.5; tries=$((tries + 1))
        done
    fi
    rm -f "$lock_file"
    mkdir -p "$INSTALL_DIR/logs"

    nohup "$exe" -autostarted -register-autostart >>"$LOG_FILE" 2>&1 &
    disown

    pid="" tries=0
    while [ $tries -lt 20 ]; do
        pid="$(head -1 "$lock_file" 2>/dev/null || true)"
        [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null && break
        sleep 0.5; tries=$((tries + 1))
    done

    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        # Grace period per la generazione della CA locale al primo avvio -
        # vedi il commento omonimo in install.sh (bug reale del 2026-09-29).
        sleep 5
        kill -TERM "$pid" 2>/dev/null || true
        tries=0
        while kill -0 "$pid" 2>/dev/null && [ $tries -lt 20 ]; do sleep 0.5; tries=$((tries + 1)); done
    else
        log "ATTENZIONE: nessuna istanza rilevata viva dopo la registrazione autostart (dettagli in $LOG_FILE e $INSTALL_DIR/logs/wrapper.log)"
    fi

    if launchctl kickstart "gui/$uid/$LAUNCH_AGENT_LABEL" >>"$LOG_FILE" 2>&1; then
        sleep 2
        if launchctl print "gui/$uid/$LAUNCH_AGENT_LABEL" 2>/dev/null | grep -q 'state = running'; then
            ok "OpenSagra avviato (LaunchAgent $LAUNCH_AGENT_LABEL, riparte da solo al login)"
            return
        fi
    fi
    log "ATTENZIONE: LaunchAgent non avviabile ora (nessuna sessione grafica attiva per $USER? es. installazione da SSH). Avvio OpenSagra fuori da launchd: funziona, ma non ripartira' da solo. Rilancia l'installer dopo aver fatto login sul Mac."
    nohup "$exe" -autostarted >>"$LOG_FILE" 2>&1 &
    disown
}

# set_firewall_rules: il firewall applicativo di macOS (spento di default)
# ragiona per eseguibile, non per porta: se e' acceso, FrankenPHP va
# autorizzato a ricevere connessioni, altrimenti le casse in LAN non lo
# raggiungono (e senza firma Apple, ad ogni avvio comparirebbe la richiesta).
# MariaDB (3306) per ora resta solo locale, come su Linux.
set_firewall_rules() {
    local fw=/usr/libexec/ApplicationFirewall/socketfilterfw
    if ! "$fw" --getglobalstate 2>/dev/null | grep -qi 'enabled'; then
        ok "Firewall di macOS spento: nessuna regola da aggiungere"
        return
    fi
    sudo "$fw" --add "$FRANKEN_DIR/frankenphp" >>"$LOG_FILE" 2>&1 || true
    sudo "$fw" --unblockapp "$FRANKEN_DIR/frankenphp" >>"$LOG_FILE" 2>&1 || true
    ok "FrankenPHP autorizzato nel firewall di macOS"
}

# register_local_ca_trust: come su Linux/Windows. Su macOS `frankenphp trust`
# aggiunge la CA al portachiavi di Sistema via `security add-trusted-cert`
# (chiede la password, o una conferma a video). Safari e Chrome usano il
# portachiavi; Firefox ha il suo store e mostrera' comunque l'avviso.
register_local_ca_trust() {
    [ -x "$FRANKEN_DIR/frankenphp" ] || return 0

    local admin_up=0 i
    for i in $(seq 1 20); do
        curl -fsS --max-time 2 http://127.0.0.1:2019/config/ >/dev/null 2>&1 && { admin_up=1; break; }
        sleep 1
    done
    if [ "$admin_up" -ne 1 ]; then
        log "register_local_ca_trust: l'API admin di Caddy (127.0.0.1:2019) non risponde dopo 20s, salto."
        return 0
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

log "avvio install-macos.sh (PID $$) - sorgente: $SOURCE_DIR -> destinazione: $INSTALL_DIR"

test_prerequisites
install_homebrew
install_frankenphp
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
new_app_launcher

set_firewall_rules

start_wrapper_and_autostart
register_local_ca_trust

log "Installazione completata: apri https://localhost (o l'app OpenSagra). Log completo in $LOG_FILE"
