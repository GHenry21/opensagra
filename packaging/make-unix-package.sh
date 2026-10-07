#!/bin/bash
# Pacchetto di release OpenSagra per Linux/macOS - equivalente Unix di
# packaging/make-installer.ps1. Produce, alla radice del repo:
#
#   opensagra-<versione>-<linux|macos>-<x86_64|aarch64|arm64>.tar.gz
#
# con dentro una cartella opensagra-<versione>/ pronta per l'installazione:
# codice app (working tree, come make-installer.ps1), vendor/, il wrapper
# compilato per quell'OS/architettura, FrankenPHP della versione fissata
# (impronta verificata contro packaging/frankenphp.sha256) e il file VERSION.
# L'utente estrae l'archivio e lancia ./install.sh (Linux) o
# ./install-macos.sh (macOS): nessun download di FrankenPHP all'installazione.
#
# Uso:
#   packaging/make-unix-package.sh linux x86_64
#   packaging/make-unix-package.sh linux aarch64
#   packaging/make-unix-package.sh macos arm64
#   packaging/make-unix-package.sh macos x86_64
#
# Richiede: git, go, curl, tar, sha256sum (o shasum), vendor/ gia' presente
# (composer install). Gira su Linux, macOS o Git Bash su Windows (il wrapper
# si cross-compila, FrankenPHP si scarica soltanto).

set -euo pipefail

OS="${1:-}"
ARCH="${2:-}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCK="$REPO_ROOT/packaging/frankenphp.sha256"

die() { echo "ERRORE: $*" >&2; exit 1; }

case "$OS/$ARCH" in
    linux/x86_64)  goos=linux;  goarch=amd64; asset=frankenphp-linux-x86_64;  wrapper_name=opensagra-wrapper ;;
    linux/aarch64) goos=linux;  goarch=arm64; asset=frankenphp-linux-aarch64; wrapper_name=opensagra-wrapper ;;
    macos/arm64)   goos=darwin; goarch=arm64; asset=frankenphp-mac-arm64;     wrapper_name=opensagra-wrapper-darwin-arm64 ;;
    macos/x86_64)  goos=darwin; goarch=amd64; asset=frankenphp-mac-x86_64;    wrapper_name=opensagra-wrapper-darwin-amd64 ;;
    *) die "uso: $0 <linux|macos> <x86_64|aarch64|arm64> (combinazioni: linux x86_64, linux aarch64, macos arm64, macos x86_64)" ;;
esac

[ -d "$REPO_ROOT/vendor" ] || die "vendor/ non trovato: esegui 'composer install' prima di impacchettare."

sha256() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1
    else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

# --- versione app: tag git esatto su HEAD, come Get-ReleaseVersion.ps1 ---
tag="$(git -C "$REPO_ROOT" describe --tags --exact-match HEAD 2>/dev/null || true)"
version="${tag#v}"; [ -n "$version" ] || version="0.0.0-dev"

# --- FrankenPHP: versione fissata + impronta verificata (cache in .cache/) ---
fp_version="$(sed -n 's/^#[[:space:]]*version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$LOCK" | head -1)"
fp_hash="$(awk -v n="$asset" '$2==n {print $1}' "$LOCK")"
[ -n "$fp_version" ] && [ -n "$fp_hash" ] || die "versione o impronta di $asset mancante in $LOCK"
cache="$REPO_ROOT/.cache/frankenphp/v$fp_version"
mkdir -p "$cache"
fp_file="$cache/$asset"
if [ ! -f "$fp_file" ] || [ "$(sha256 "$fp_file")" != "$fp_hash" ]; then
    echo "  scarico $asset (FrankenPHP v$fp_version)..."
    curl -fsSL --retry 3 -o "$fp_file" "https://github.com/php/frankenphp/releases/download/v$fp_version/$asset"
fi
actual="$(sha256 "$fp_file")"
if [ "$actual" != "$fp_hash" ]; then
    rm -f "$fp_file"
    die "FrankenPHP v$fp_version '$asset' e' cambiato su GitHub rispetto all'impronta in packaging/frankenphp.sha256 (atteso $fp_hash, trovato $actual). FrankenPHP ha ricompilato l'asset: verificare la Mercure incorporata (go version -m) prima di aggiornare l'impronta."
fi
echo "  FrankenPHP v$fp_version $asset: impronta verificata"

# --- staging ---
name="opensagra-$version"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
stage="$work/$name"
mkdir -p "$stage"

# File dell'app dal working tree (tracciati + nuovi non ignorati), come
# make-installer.ps1 - esclusi il materiale di sviluppo e il sorgente Go del
# wrapper (al suo posto va il binario compilato, sotto).
(cd "$REPO_ROOT" && git ls-files -z --cached --others --exclude-standard) |
    while IFS= read -r -d '' rel; do
        case "$rel" in
            # installer/: sorgente Go dell'installer grafico (branch gui-installer) -
            # come per wrapper/, nel pacchetto andra' solo il binario compilato.
            .github/*|.claude/*|e2e/*|docs/*|node_modules/*|wrapper/*|installer/*|packaging/*|private/*) continue ;;
            *.ps1) continue ;; # installer/uninstaller Windows: non servono qui
            package.json|package-lock.json|playwright.config.js|bt.html|"navbar example.html"|.gitignore|.gitattributes) continue ;;
        esac
        [ -f "$REPO_ROOT/$rel" ] || continue
        mkdir -p "$stage/$(dirname "$rel")"
        cp -p "$REPO_ROOT/$rel" "$stage/$rel"
    done
cp -Rp "$REPO_ROOT/vendor" "$stage/vendor"

# Wrapper per l'OS/architettura di destinazione (pure Go, CGO_ENABLED=0)
mkdir -p "$stage/wrapper"
echo "  compilo il wrapper ($goos/$goarch)..."
(cd "$REPO_ROOT/wrapper" && GOOS=$goos GOARCH=$goarch CGO_ENABLED=0 go build -trimpath -o "$stage/wrapper/$wrapper_name" .)

mkdir -p "$stage/frankenphp"
cp "$fp_file" "$stage/frankenphp/$asset"
chmod +x "$stage/frankenphp/$asset" "$stage/wrapper/$wrapper_name"
chmod +x "$stage"/install*.sh "$stage"/uninstall*.sh 2>/dev/null || true
printf '%s' "$version" > "$stage/VERSION"

# Manifesto dei file installati (wrapper/manifest): install.sh lo copia in
# config/ con il resto, e il wrapper lo confronta con quello di una versione
# nuova per decidere offline se basta l'aggiornamento leggero.
(cd "$REPO_ROOT/wrapper" && go run ./cmd/mkmanifest -root .. -version "$version" -out "$stage/config/.app_manifest.json")

out="$REPO_ROOT/$name-$OS-$ARCH.tar.gz"
tar -C "$work" -czf "$out" "$name"
size_mb=$(( $(wc -c < "$out") / 1048576 ))
echo "OK: $out (${size_mb} MB)"
