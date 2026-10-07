#!/bin/bash
# Disinstaller OpenSagra per macOS - equivalente di uninstall.sh (Linux) e
# uninstall.ps1 (Windows). Smonta un'installazione fatta da install-macos.sh:
# ferma e rimuove il wrapper (e con lui frankenphp/relay/bridge/snapshot, suoi
# processi figli) e il suo LaunchAgent, disinstalla MariaDB (Homebrew) e
# FrankenPHP, rimuove $HOME/opensagra e la CA HTTPS locale. Come gli altri:
# chiede conferma esplicita e fa un backup del database PRIMA di toccare
# MariaDB. Homebrew stesso NON viene rimosso (strumento condiviso, altro
# software sul Mac puo' dipenderne - stessa scelta del Visual C++
# Redistributable su Windows).
#
# Uso:
#   ./uninstall-macos.sh          # chiede conferma
#   ./uninstall-macos.sh --force  # salta la conferma (backup comunque tentato)

set -euo pipefail

FORCE=0
case "${1:-}" in
    --force|-y) FORCE=1 ;;
esac

INSTALL_DIR="$HOME/opensagra"
FRANKEN_DIR="$HOME/.frankenphp"
CADDY_DATA_DIR="$HOME/Library/Application Support/Caddy"
CADDY_CONFIG_DIR="$HOME/Library/Application Support/Caddy-config"
LAUNCH_AGENT_LABEL="com.opensagra.wrapper"
LAUNCH_AGENT_PLIST="$HOME/Library/LaunchAgents/$LAUNCH_AGENT_LABEL.plist"
APP_BUNDLE="$HOME/Applications/OpenSagra.app"
UPLOAD_TMP_DIR="/tmp/opensagra-php-upload"
LOG_FILE="/tmp/opensagra-uninstall.log"
: > "$LOG_FILE"

log() { echo "[$(date '+%Y-%m-%dT%H:%M:%S%z')] $*" | tee -a "$LOG_FILE"; }
ok()  { echo "  OK: $*" | tee -a "$LOG_FILE"; }
die() { echo "ERRORE: $*" | tee -a "$LOG_FILE" >&2; exit 1; }

env_value() {
    [ -f "$1" ] || return 0
    sed -n -E "s/^[[:space:]]*$2[[:space:]]*=[[:space:]]*([^[:space:]]+).*/\1/p" "$1" 2>/dev/null | head -1 || true
}

# run_with_timeout: macOS non ha `timeout` (GNU coreutils) - perl si', di serie.
run_with_timeout() {
    local secs="$1"; shift
    perl -e 'alarm shift; exec @ARGV' "$secs" "$@"
}

brew_path() {
    local p
    for p in /opt/homebrew/bin/brew /usr/local/bin/brew; do
        [ -x "$p" ] && { echo "$p"; return; }
    done
    command -v brew 2>/dev/null || true
}

test_prerequisites() {
    [ "$(uname -s)" = "Darwin" ] || die "Questo disinstaller e' per macOS. Su Linux usa uninstall.sh."
    [ "$EUID" -ne 0 ] || die "Non lanciare con sudo/come root: chiede la password da solo dove serve."
    local brew; brew="$(brew_path)"
    [ -n "$brew" ] && eval "$("$brew" shellenv)"
    return 0
}

confirm_uninstall() {
    [ "$FORCE" -eq 1 ] && return
    cat <<EOF
Verranno rimossi in modo permanente:

  - MariaDB (Homebrew) con tutti i dati, dopo un backup del database in $HOME
  - FrankenPHP e la CA HTTPS locale
  - Tutti i file dell'app in $INSTALL_DIR
  - L'app OpenSagra e l'avvio automatico al login

Homebrew resta installato. Questa operazione non si puo' annullare.
EOF
    read -r -p "Continuare? [s/N] " reply
    case "$reply" in
        s|S|si|Si|SI|y|Y|yes|Yes) ;;
        *) echo "Annullato."; exit 0 ;;
    esac
}

backup_database() {
    local env_path="$INSTALL_DIR/config/variabili.env"
    if [ ! -f "$env_path" ] || ! command -v mariadb >/dev/null 2>&1 || ! mariadb-admin --host=127.0.0.1 ping >/dev/null 2>&1; then
        ok "Nessun database locale attivo da salvare"
        return
    fi

    local dump_bin
    dump_bin="$(command -v mariadb-dump || command -v mysqldump || true)"
    [ -n "$dump_bin" ] || die "Nessuno strumento di export (mariadb-dump/mysqldump) trovato - impossibile fare un backup sicuro prima di rimuovere MariaDB."

    local db_user db_pass
    db_user="$(env_value "$env_path" DB_POS_USER)"
    db_pass="$(env_value "$env_path" DB_POS_PASS)"
    [ -n "$db_user" ] || die "DB_POS_USER non trovato in $env_path - impossibile fare un backup sicuro prima di rimuovere MariaDB."

    local backup_path="$HOME/opensagra-backup-$(date +%Y%m%d-%H%M%S).sql"
    if ! "$dump_bin" --user="$db_user" --password="$db_pass" --host=127.0.0.1 opensagra_pos > "$backup_path" 2>>"$LOG_FILE"; then
        rm -f "$backup_path"
        die "Backup del database fallito (dettagli in $LOG_FILE) - disinstallazione interrotta prima di toccare MariaDB."
    fi
    if [ ! -s "$backup_path" ]; then
        rm -f "$backup_path"
        die "Backup del database vuoto - disinstallazione interrotta prima di toccare MariaDB."
    fi
    ok "Database salvato in $backup_path"
}

# stop_opensagra_wrapper: bootout del LaunchAgent PRIMA del kill (altrimenti
# launchd potrebbe farlo ripartire), poi la stessa rete di sicurezza di
# uninstall.sh per istanze lanciate a mano e figli rimasti orfani - su macOS
# non c'e' Pdeathsig, un orfano di frankenphp e' piu' probabile che su Linux.
stop_opensagra_wrapper() {
    local frankenphp="$FRANKEN_DIR/frankenphp"
    local announce="$INSTALL_DIR/bin/opensagra-announce.php"
    if [ -x "$frankenphp" ] && [ -f "$announce" ]; then
        run_with_timeout 5 "$frankenphp" php-cli "$announce" --kind=shutdown >>"$LOG_FILE" 2>&1 || true
    fi

    launchctl bootout "gui/$(id -u)/$LAUNCH_AGENT_LABEL" >>"$LOG_FILE" 2>&1 || true

    local uid; uid="$(id -u)"
    local pids tries
    pids="$(pgrep -u "$uid" -f "$INSTALL_DIR/opensagra-wrapper" || true)"
    if [ -n "$pids" ]; then
        kill -TERM $pids 2>/dev/null || true
        tries=0
        while pgrep -u "$uid" -f "$INSTALL_DIR/opensagra-wrapper" >/dev/null 2>&1 && [ $tries -lt 20 ]; do
            sleep 0.5; tries=$((tries + 1))
        done
        pkill -KILL -u "$uid" -f "$INSTALL_DIR/opensagra-wrapper" 2>/dev/null || true
    fi
    pkill -TERM -u "$uid" -f "$FRANKEN_DIR/frankenphp" 2>/dev/null || true

    ok "OpenSagra (wrapper e processi supervisionati) fermato"
}

remove_wrapper_autostart() {
    rm -f "$LAUNCH_AGENT_PLIST"
    launchctl enable "gui/$(id -u)/$LAUNCH_AGENT_LABEL" >/dev/null 2>&1 || true # pulisce un eventuale override "disabled"
    ok "Avvio automatico rimosso"
}

remove_mariadb() {
    if ! command -v brew >/dev/null 2>&1 || ! brew list --formula mariadb >/dev/null 2>&1; then
        ok "MariaDB non installato via Homebrew, nessuna rimozione"
        return
    fi
    local prefix; prefix="$(brew --prefix)"
    # Regola del firewall di macOS aggiunta da install-macos.sh
    # (set_firewall_rules): va tolta finche' l'eseguibile esiste ancora.
    local mariadbd_bin
    mariadbd_bin="$(cd -P "$(brew --prefix mariadb)/bin" 2>/dev/null && pwd)/mariadbd"
    sudo /usr/libexec/ApplicationFirewall/socketfilterfw --remove "$mariadbd_bin" >>"$LOG_FILE" 2>&1 || true
    brew services stop mariadb >>"$LOG_FILE" 2>&1 || true
    brew uninstall mariadb >>"$LOG_FILE" 2>&1 || true
    rm -rf "$prefix/var/mysql" "$prefix/etc/my.cnf" "$prefix/etc/my.cnf.d"
    ok "MariaDB disinstallato"
}

# remove_frankenphp_and_ca: la CA locale di Caddy ("Caddy Local Authority -
# <anno> ECC Root") e' nel portachiavi di Sistema: si rimuove SOLO quella,
# per nome, best-effort come su Linux/Windows.
remove_frankenphp_and_ca() {
    local hashes h
    hashes="$(security find-certificate -a -c "Caddy Local Authority" -Z /Library/Keychains/System.keychain 2>/dev/null | awk '/SHA-1 hash:/{print $3}' || true)"
    for h in $hashes; do
        sudo security delete-certificate -Z "$h" /Library/Keychains/System.keychain >>"$LOG_FILE" 2>&1 || true
    done
    sudo /usr/libexec/ApplicationFirewall/socketfilterfw --remove "$FRANKEN_DIR/frankenphp" >>"$LOG_FILE" 2>&1 || true
    rm -rf "$FRANKEN_DIR" "$CADDY_DATA_DIR" "$CADDY_CONFIG_DIR"
    ok "FrankenPHP e CA locale rimossi"
}

remove_app_files() {
    rm -rf "$INSTALL_DIR" "$APP_BUNDLE" "$UPLOAD_TMP_DIR"
    [ -L "$HOME/Desktop/OpenSagra" ] && rm -f "$HOME/Desktop/OpenSagra"
    ok "File dell'app rimossi"
}

log "avvio uninstall-macos.sh (PID $$)"

test_prerequisites
confirm_uninstall

backup_database

stop_opensagra_wrapper
remove_wrapper_autostart

remove_mariadb
remove_frankenphp_and_ca

remove_app_files

log "Disinstallazione completata. Log completo in $LOG_FILE"
