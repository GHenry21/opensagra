#!/bin/bash
# Disinstaller OpenSagra per Linux - equivalente di uninstall.ps1 per Windows.
# Smonta un'installazione fatta da install.sh: ferma e rimuove il wrapper (e
# con lui frankenphp/relay/bridge/snapshot, suoi processi figli), disinstalla
# MariaDB e FrankenPHP, rimuove $HOME/opensagra, le regole firewall e
# l'autostart. Come uninstall.ps1: chiede conferma esplicita (operazione
# distruttiva e irreversibile) e fa un backup del database PRIMA di toccare
# MariaDB - l'unica rete di sicurezza contro un lancio sbagliato.
#
# Uso:
#   ./uninstall.sh          # chiede conferma
#   ./uninstall.sh --force  # salta la conferma (utile per smontare macchine
#                            # di test/VM in automatico) - il backup del
#                            # database viene comunque tentato.

set -euo pipefail

FORCE=0
case "${1:-}" in
    --force|-y) FORCE=1 ;;
esac

# ============================================================================
# Configurazione (stessi valori di install.sh - il disinstaller deve poter
# girare anche dopo che $INSTALL_DIR e' stato rimosso, quindi non li legge da
# li' ma li ripete qui)
# ============================================================================

INSTALL_DIR="$HOME/opensagra"
FRANKEN_DIR="$HOME/.frankenphp"
CADDY_DATA_DIR="$HOME/.local/share/caddy"
CADDY_CONFIG_DIR="$HOME/.config/caddy"
UPLOAD_TMP_DIR="/tmp/opensagra-php-upload"
# $USER non e' garantito (docker exec, cron, alcuni servizi): con set -u
# una variabile non impostata ferma lo script (visto nel test Fedora).
USER="${USER:-$(id -un)}"
LOG_FILE="/tmp/opensagra-uninstall.log"
: > "$LOG_FILE"

log() { echo "[$(date -Iseconds)] $*" | tee -a "$LOG_FILE"; }
ok()  { echo "  OK: $*" | tee -a "$LOG_FILE"; }
die() { echo "ERRORE: $*" | tee -a "$LOG_FILE" >&2; exit 1; }

# Famiglia della distribuzione. Cambiano i comandi dei pacchetti, la cartella
# dei .cnf di MariaDB, il firewall e lo store dei certificati: famiglia
# "debian" (Debian, Raspberry Pi OS, Ubuntu - apt) o "fedora" (Fedora e
# derivate RHEL - dnf). Stesso blocco in install.sh e uninstall.sh.
if command -v apt-get >/dev/null 2>&1; then
    DISTRO_FAMILY=debian
elif command -v dnf >/dev/null 2>&1; then
    DISTRO_FAMILY=fedora
else
    DISTRO_FAMILY=unsupported
fi

# pkg_installed: dpkg -s / rpm -q, non `command -v`: i binari di sistema
# stanno spesso in /usr/sbin, che una shell utente non ha nel $PATH (falso
# "assente" e reinstallazione a ogni rilancio, visto sul Pi 2026-10-02).
pkg_installed() {
    case "$DISTRO_FAMILY" in
        debian) dpkg -s "$1" >/dev/null 2>&1 ;;
        fedora) rpm -q "$1" >/dev/null 2>&1 ;;
        *) return 1 ;;
    esac
}

# ============================================================================
# Passi della disinstallazione
# ============================================================================

test_prerequisites() {
    [ "$(uname -s)" = "Linux" ] || die "Questo disinstaller supporta solo Linux."
    [ "$EUID" -ne 0 ] || die "Non lanciare come root: come install.sh, chiede sudo da solo dove serve."
    command -v sudo >/dev/null || die "sudo non trovato."
}

confirm_uninstall() {
    [ "$FORCE" -eq 1 ] && return
    cat <<EOF
Verranno rimossi in modo permanente:

  - MariaDB (servizio e pacchetto), dopo un backup del database in $HOME
  - FrankenPHP e la CA HTTPS locale
  - Tutti i file dell'app in $INSTALL_DIR
  - Le regole firewall e l'avvio automatico di OpenSagra

Questa operazione non si puo' annullare.
EOF
    read -r -p "Continuare? [s/N] " reply
    case "$reply" in
        s|S|si|Si|SI|y|Y|yes|Yes) ;;
        *) echo "Annullato."; exit 0 ;;
    esac
}

# readEnvValue: stesso parsing "chiave=valore" usato da install.sh. Il
# `|| true` finale non e' decorativo: sotto `set -e` + `pipefail`, un
# `var=$(...)` con un grep che non trova nulla (caso normale, non un errore)
# farebbe uscire TUTTO lo script - stesso pattern gia' usato in install.sh
# (get_or_new_mercure_secret).
readEnvValue() {
    local path="$1" key="$2"
    [ -f "$path" ] || return 0
    grep -oP "^\s*$key\s*=\s*\K\S+" "$path" 2>/dev/null | head -1 || true
}

# backup_database: PRIMA di toccare MariaDB - stessa cautela di uninstall.ps1
# (Backup-Database), l'unica rete di sicurezza contro un click/lancio
# sbagliato, visto che il progetto non ha nessun altro strumento di backup.
backup_database() {
    local env_path="$INSTALL_DIR/config/variabili.env"
    if [ ! -f "$env_path" ] || ! systemctl is-active --quiet mariadb 2>/dev/null; then
        ok "Nessun database locale attivo da salvare"
        return
    fi

    local dump_bin
    dump_bin="$(command -v mariadb-dump || command -v mysqldump || true)"
    [ -n "$dump_bin" ] || die "Nessuno strumento di export (mariadb-dump/mysqldump) trovato - impossibile fare un backup sicuro prima di rimuovere MariaDB."

    local db_user db_pass
    db_user="$(readEnvValue "$env_path" DB_POS_USER)"
    db_pass="$(readEnvValue "$env_path" DB_POS_PASS)"
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

# stop_opensagra_wrapper: come uninstall.ps1 (Stop-OpenSagraWrapper), tenta
# prima un avviso alle eventuali casse client collegate (rilevante solo se
# questa macchina e' il server), poi ferma per davvero - stesso approccio
# "trova ed elimina qualunque istanza viva" di install.sh
# (start_wrapper_and_autostart), non solo systemctl stop: una macchina di
# test puo' avere un'istanza lanciata a mano, fuori da systemd.
stop_opensagra_wrapper() {
    local frankenphp="$FRANKEN_DIR/frankenphp"
    local announce="$INSTALL_DIR/bin/opensagra-announce.php"
    if [ -x "$frankenphp" ] && [ -f "$announce" ]; then
        timeout 5 "$frankenphp" php-cli "$announce" --kind=shutdown >>"$LOG_FILE" 2>&1 || true
    fi

    systemctl --user stop opensagra-wrapper.service 2>>"$LOG_FILE" || true

    local pids; pids="$(pgrep -u "$USER" -f "$INSTALL_DIR/opensagra-wrapper" || true)"
    if [ -n "$pids" ]; then
        kill -TERM $pids 2>/dev/null || true
        local tries=0
        while pgrep -u "$USER" -f "$INSTALL_DIR/opensagra-wrapper" >/dev/null 2>&1 && [ $tries -lt 20 ]; do
            sleep 0.5; tries=$((tries + 1))
        done
        pkill -u "$USER" -KILL -f "$INSTALL_DIR/opensagra-wrapper" 2>/dev/null || true
    fi
    # Rete di sicurezza: senza il wrapper vivo a supervisionarli, eventuali
    # figli frankenphp/snapshot rimasti orfani (es. un crash pregresso del
    # wrapper) vanno fermati esplicitamente - su Windows lo fa il Job Object
    # da solo, qui non c'e' un equivalente una volta che il genitore e' morto.
    pkill -u "$USER" -TERM -f "$FRANKEN_DIR/frankenphp" 2>/dev/null || true

    ok "OpenSagra (wrapper e processi supervisionati) fermato"
}

remove_wrapper_autostart() {
    systemctl --user disable opensagra-wrapper.service 2>>"$LOG_FILE" || true
    rm -f "$HOME/.config/systemd/user/opensagra-wrapper.service"
    systemctl --user daemon-reload 2>>"$LOG_FILE" || true
    # loginctl enable-linger (scritto da install.sh) NON si disabilita qui:
    # e' un'impostazione condivisa dell'utente, non specifica di OpenSagra -
    # altri servizi systemd --user installati nel frattempo potrebbero
    # dipenderne. Stessa scelta di uninstall.ps1 di non toccare il Visual C++
    # Redistributable (componente di sistema condiviso).
    ok "Avvio automatico rimosso"
}

# remove_mariadb: come uninstall.ps1 (Remove-MariaDB) - questa installazione
# di MariaDB e' quella fatta da install.sh apposta per OpenSagra, quindi va
# rimossa per intero, dati compresi (il backup e' gia' fatto sopra). Se questo
# non vale per la tua macchina (MariaDB gia' c'era per altro), rispondi "N"
# alla conferma e rimuovilo a mano con piu' cautela.
remove_mariadb() {
    if ! pkg_installed mariadb-server; then
        ok "MariaDB non installato dal gestore pacchetti, nessuna rimozione pacchetto"
        return
    fi
    sudo systemctl stop mariadb 2>>"$LOG_FILE" || true
    case "$DISTRO_FAMILY" in
        debian)
            sudo apt-get purge -y -qq mariadb-server mariadb-server-core mariadb-client mariadb-client-core mariadb-common 2>>"$LOG_FILE" || true
            sudo apt-get autoremove -y -qq 2>>"$LOG_FILE" || true ;;
        fedora)
            sudo dnf remove -y -q mariadb-server mariadb 2>>"$LOG_FILE" || true ;;
    esac
    sudo rm -rf /var/lib/mysql
    # Non appartiene a nessun pacchetto: il purge non lo toglie (install.sh,
    # allow_lan_mariadb). Percorso Debian o Fedora.
    sudo rm -f /etc/mysql/mariadb.conf.d/99-opensagra.cnf /etc/my.cnf.d/99-opensagra.cnf
    ok "MariaDB disinstallato"
}

# remove_frankenphp_and_ca: rimuove il binario, lo stato di Caddy (certificati,
# CA locale - ~/.local/share/caddy, XDG data dir) e SOLO il file della CA di
# Caddy nello store di sistema (pattern "Caddy_Local_Authority_*" - MAI un
# rm indiscriminato di /usr/local/share/ca-certificates/ o, su Fedora, di
# /etc/pki/ca-trust/source/anchors/: potrebbero contenere
# certificati di altro software sulla stessa macchina, es. un client QZ Tray).
# Best-effort come su Windows (Remove-FrankenPHP): un certificato radice
# residuo e' innocuo (non associato a nessun sito che l'utente visiti
# davvero), non vale bloccare la disinstallazione per questo.
remove_frankenphp_and_ca() {
    local ca_files d
    ca_files=(/usr/local/share/ca-certificates/Caddy_Local_Authority_*.crt)
    if [ -e "${ca_files[0]}" ]; then
        sudo rm -f "${ca_files[@]}"
        sudo update-ca-certificates >>"$LOG_FILE" 2>&1 || true
    fi
    ca_files=(/etc/pki/ca-trust/source/anchors/Caddy_Local_Authority_*)
    if [ -e "${ca_files[0]}" ]; then
        sudo rm -f "${ca_files[@]}"
        sudo update-ca-trust >>"$LOG_FILE" 2>&1 || true
    fi
    # La stessa CA che install.sh (trust_ca_in_browser_stores) mette nei
    # database NSS dei browser - stessi percorsi, stesso nome.
    if command -v certutil >/dev/null 2>&1; then
        for d in "$HOME/.pki/nssdb" "$HOME"/.mozilla/firefox/*/ \
                 "$HOME"/snap/firefox/common/.mozilla/firefox/*/ "$HOME/snap/chromium/current/.pki/nssdb"; do
            d="${d%/}"
            [ -f "$d/cert9.db" ] || continue
            certutil -D -d "sql:$d" -n "OpenSagra Local CA" >/dev/null 2>&1 || true
        done
    fi
    rm -rf "$FRANKEN_DIR" "$CADDY_DATA_DIR" "$CADDY_CONFIG_DIR"
    ok "FrankenPHP e CA locale rimossi"
}

remove_app_files() {
    rm -rf "$INSTALL_DIR"
    rm -f "$HOME/Desktop/OpenSagra.desktop"
    rm -rf "$UPLOAD_TMP_DIR"
    ok "File dell'app rimossi"
}

remove_firewall_rules() {
    if command -v ufw >/dev/null 2>&1; then
        sudo ufw delete allow 80/tcp >/dev/null 2>&1 || true
        sudo ufw delete allow 443/tcp >/dev/null 2>&1 || true
        sudo ufw delete allow 3306/tcp >/dev/null 2>&1 || true
        ok "Regole ufw rimosse"
    elif systemctl is-active --quiet firewalld 2>/dev/null; then
        sudo firewall-cmd --quiet --permanent --remove-port=80/tcp --remove-port=443/tcp --remove-port=3306/tcp >/dev/null 2>&1 || true
        sudo firewall-cmd --quiet --reload >/dev/null 2>&1 || true
        ok "Regole firewalld rimosse"
    fi
}

# ============================================================================
# Orchestrazione
# ============================================================================

log "avvio uninstall.sh (PID $$)"

test_prerequisites
confirm_uninstall

backup_database

stop_opensagra_wrapper
remove_wrapper_autostart

remove_mariadb
remove_frankenphp_and_ca

remove_app_files
remove_firewall_rules

log "Disinstallazione completata. Log completo in $LOG_FILE"
