#!/usr/bin/env bash
# set-frankenphp-version.sh: porta OpenSagra a un'altra versione di FrankenPHP
# con un comando, invece di toccare a mano quattro file.
#
#   bash packaging/set-frankenphp-version.sh 1.13.1
#   bash packaging/set-frankenphp-version.sh 1.13.1 --no-check   # solo impronte e installer
#
# Cosa fa:
#   1. legge dalla release ufficiale (php/frankenphp) le impronte SHA-256 dei
#      cinque asset inclusi nei pacchetti di OpenSagra;
#   2. controlli (salvo --no-check), gli stessi di
#      .github/workflows/frankenphp-watch.yml:
#      - Mercure uguale nei binari Linux, macOS e Windows (`go version -m`,
#        serve Go) - php/frankenphp#2685;
#      - php-cli passa gli argomenti a getopt() (php/frankenphp#2690), sul
#        binario del sistema dove gira lo script (Linux, macOS o Git Bash su
#        Windows);
#   3. SOLO se tutto e' a posto, scrive la versione e le impronte in
#      packaging/frankenphp.sha256 e la versione in install.sh,
#      install-macos.sh e install.ps1 (release.yml verifica che coincidano).
#
# Non cambia la sintassi di Mercure ne' altro codice: se la nuova versione
# porta una Mercure con regole diverse, va letto il changelog (vedi il piano,
# Fase 4). Dopo: test degli installer (test-macos.yml anche con
# runner=macos-15-intel, test-ubuntu.yml, test-fedora.yml, Pi, VM Windows).
#
# Servono: bash, curl, sha256sum o shasum, unzip; gh (consigliato, evita il
# limite di richieste anonime all'API di GitHub) e go per il controllo di
# Mercure.
set -euo pipefail

usage() { echo "uso: bash $0 <versione, es. 1.13.1> [--no-check | --mercure-patch-ok]" >&2; exit 2; }
[ $# -ge 1 ] || usage
version="${1#v}"
check=1
patch_ok=0
case "${2:-}" in
    "") ;;
    --no-check) check=0 ;;
    # Mercure diversa fra gli asset SOLO nella patch (es. 1.1.1 / 1.1.2): le
    # build statiche prendono l'ultima Mercure a ogni ricompilazione notturna,
    # quella Windows resta al go.mod del tag, quindi gli asset non tornano piu'
    # uguali da soli. Ammesso solo dopo aver letto il changelog di Mercure
    # (successo con la 1.13.1, 2026-10-17: la 1.1.2 cambia solo il caso dietro
    # reverse proxy con trusted_proxies, che OpenSagra non usa).
    --mercure-patch-ok) patch_ok=1 ;;
    *) usage ;;
esac
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || usage

root="$(cd "$(dirname "$0")/.." && pwd)"
lock="$root/packaging/frankenphp.sha256"
assets=(frankenphp-linux-aarch64 frankenphp-linux-x86_64 frankenphp-mac-arm64 frankenphp-mac-x86_64 frankenphp-windows-x86_64.zip)

die() { echo "ERRORE: $*" >&2; exit 1; }
sha256_of() { if command -v sha256sum >/dev/null; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }

# --- 1. impronte ufficiali -------------------------------------------------
echo "FrankenPHP v$version: leggo la release..."
# Righe "<nome asset> sha256:<impronta>", lette con uno strumento JSON vero
# (gh --jq, jq o python3): nel JSON di GitHub fra name e digest c'e' l'oggetto
# uploader, un grep non basta.
release_lines() { # "owner/repo" tag -> righe "<asset> sha256:<impronta>" (vuoto se non c'e')
    local api="repos/$1/releases/tags/$2" jq_expr='.assets[] | .name + " " + (.digest // "")' json
    if command -v gh >/dev/null 2>&1; then
        gh api "$api" --jq "$jq_expr" 2>/dev/null || true
    else
        json="$(curl -fsSL "https://api.github.com/$api" 2>/dev/null)" || json=""
        if [ -z "$json" ]; then :
        elif command -v jq >/dev/null 2>&1; then printf '%s' "$json" | jq -r "$jq_expr"
        elif command -v python3 >/dev/null 2>&1; then
            printf '%s' "$json" | python3 -c 'import json,sys; [print(a["name"], a.get("digest") or "") for a in json.load(sys.stdin)["assets"]]'
        else die "serve gh, jq o python3 per leggere la release"; fi
    fi
}
lines="$(release_lines php/frankenphp "v$version")"
[ -n "$lines" ] || die "release v$version non trovata (non ancora pubblicata, o ancora in bozza)"
# Asset che FrankenPHP non pubblica piu' e che costruiamo noi: dalla 1.13.2
# frankenphp-mac-x86_64 (Mac Intel), fatto da build-frankenphp-mac-intel.yml
# con il build-static.sh ufficiale e caricato nella NOSTRA pre-release
# frankenphp-v<versione>. Si usa solo se manca in quella ufficiale.
SELF_BUILT_REPO="GHenry21/opensagra"
self_built=" frankenphp-mac-x86_64 "
own_lines=""

# Niente array associativi: la bash di macOS e' la 3.2.
digests=()
urls=()
digest_of() { local i; for i in "${!assets[@]}"; do [ "${assets[$i]}" = "$1" ] && { echo "${digests[$i]}"; return; }; done; }
url_of() { local i; for i in "${!assets[@]}"; do [ "${assets[$i]}" = "$1" ] && { echo "${urls[$i]}"; return; }; done; }
pick() { printf '%s\n' "$1" | awk -v n="$2" '$1 == n { sub(/^sha256:/, "", $2); print $2 }' | head -1; }
for a in "${assets[@]}"; do
    d="$(pick "$lines" "$a")"
    [[ "$d" =~ ^[0-9a-f]{64}$ ]] || d=""
    url="https://github.com/php/frankenphp/releases/download/v$version/$a"
    note=""
    if [ -z "$d" ] && [ "${self_built#* $a }" != "$self_built" ]; then
        [ -n "$own_lines" ] || own_lines="$(release_lines "$SELF_BUILT_REPO" "frankenphp-v$version")"
        d="$(pick "$own_lines" "$a")"
        [[ "$d" =~ ^[0-9a-f]{64}$ ]] || d=""
        [ -n "$d" ] || die "asset $a assente nella release ufficiale v$version e nella nostra frankenphp-v$version: lancia prima il workflow build-frankenphp-mac-intel.yml"
        url="https://github.com/$SELF_BUILT_REPO/releases/download/frankenphp-v$version/$a"
        note="  (build nostra)"
    fi
    [ -n "$d" ] || die "asset $a assente nella release v$version (o senza impronta): build ancora in corso?"
    digests+=("$d")
    urls+=("$url")
    echo "  $d  $a$note"
done

# --- 2. controlli ------------------------------------------------------------
if [ "$check" -eq 1 ]; then
    cache="$root/.cache/frankenphp/v$version"
    mkdir -p "$cache"
    get() { # asset -> file verificato nella cache
        local a="$1" f="$cache/$1"
        if [ ! -f "$f" ] || [ "$(sha256_of "$f")" != "$(digest_of "$a")" ]; then
            curl -fL --retry 3 -s -o "$f" "$(url_of "$a")" || die "download di $a fallito"
        fi
        [ "$(sha256_of "$f")" = "$(digest_of "$a")" ] || die "impronta di $a diversa da quella della release"
        echo "$f"
    }
    lin="$(get frankenphp-linux-x86_64)"
    mac="$(get frankenphp-mac-arm64)"
    winzip="$(get frankenphp-windows-x86_64.zip)"
    rm -rf "$cache/win" && mkdir -p "$cache/win" && unzip -qo "$winzip" -d "$cache/win" # intero: frankenphp.exe da solo non parte senza le DLL di PHP
    win="$cache/win/frankenphp.exe"

    if command -v go >/dev/null 2>&1; then
        mver() { go version -m "$1" | awk '$2=="github.com/dunglas/mercure" {print $3}'; }
        # Tutti e cinque gli asset: ognuno puo' essere ricompilato in una notte
        # diversa (2026-10-17: solo mac-arm64 aveva gia' una Mercure piu' nuova).
        versions=""
        for a in frankenphp-linux-x86_64 frankenphp-linux-aarch64 frankenphp-mac-arm64 frankenphp-mac-x86_64; do
            f="$(get "$a")"; v="$(mver "$f")"
            echo "  Mercure $v  $a"
            versions="$versions $v"
        done
        v="$(mver "$win")"; echo "  Mercure $v  frankenphp-windows-x86_64.zip"; versions="$versions $v"
        distinct="$(printf '%s\n' $versions | sort -u)"
        minors="$(printf '%s\n' $versions | sed -E 's/^(v?[0-9]+\.[0-9]+)\..*/\1/' | sort -u)"
        if printf '%s\n' $versions | grep -qvE '^v?[0-9]+\.[0-9]+\.[0-9]+'; then
            die "Mercure non leggibile in qualche asset: non aggiorno"
        elif [ "$(printf '%s\n' "$distinct" | wc -l)" -gt 1 ]; then
            if [ "$(printf '%s\n' "$minors" | wc -l)" -eq 1 ] && [ "$patch_ok" -eq 1 ]; then
                echo "ATTENZIONE: Mercure diversa solo nella patch ($(echo $distinct)): accettata con --mercure-patch-ok"
            elif [ "$(printf '%s\n' "$minors" | wc -l)" -eq 1 ]; then
                die "Mercure diversa solo nella patch ($(echo $distinct)): leggi il changelog di dunglas/mercure e, se non tocca OpenSagra, rilancia con --mercure-patch-ok"
            else
                die "Mercure diversa tra gli OS ($(echo $distinct), php/frankenphp#2685): non aggiorno"
            fi
        fi
    else
        echo "ATTENZIONE: go non installato, controllo di Mercure saltato (fallo in CI o installa Go)"
    fi

    case "$(uname -s)/$(uname -m)" in
        Linux/x86_64) bin="$lin" ;;
        Linux/aarch64) bin="$(get frankenphp-linux-aarch64)" ;;
        Darwin/arm64) bin="$mac" ;;
        Darwin/x86_64) bin="$(get frankenphp-mac-x86_64)" ;;
        MINGW*|MSYS*|CYGWIN*) bin="$win" ;;
        *) bin="" ;;
    esac
    if [ -n "$bin" ]; then
        chmod +x "$bin" 2>/dev/null || true
        [ "$(uname -s)" = Darwin ] && xattr -d com.apple.quarantine "$bin" 2>/dev/null || true
        probe="$cache/argv.php"
        printf '%s\n' '<?php echo json_encode(getopt("", ["a:"])), "\n";' > "$probe"
        out="$("$bin" php-cli "$probe" --a=1 2>&1 | tr -d '\r' || true)"
        echo "php-cli getopt: $out"
        [ "$out" = '{"a":"1"}' ] || die "php-cli non passa gli argomenti agli script (php/frankenphp#2690?): non aggiorno"
    else
        echo "ATTENZIONE: sistema $(uname -s)/$(uname -m) non riconosciuto, controllo di php-cli saltato"
    fi
fi

# --- 3. scrittura ------------------------------------------------------------
# Regex ERE senza intervalli {n} (mawk di Debian non li supporta) e con [$]
# per un $ letterale (awk -v interpreta i backslash).
replace_line() { # file regex nuova-riga (una sola occorrenza attesa)
    local f="$1" re="$2" new="$3" n
    n="$(grep -cE "$re" "$f" || true)"
    [ "$n" = 1 ] || die "$f: attesa 1 riga che corrisponde a /$re/, trovate $n"
    awk -v re="$re" -v new="$new" '{ if ($0 ~ re) { cr = (sub(/\r$/, "") ? "\r" : ""); print new cr } else print }' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
}

replace_line "$lock" '^#[[:space:]]*version:' "# version: $version"
for a in "${assets[@]}"; do
    replace_line "$lock" "^[0-9a-f]+  ${a//./[.]}[[:space:]]*\$" "$(digest_of "$a")  $a"
done
replace_line "$root/install.sh" '^FRANKENPHP_VERSION="' "FRANKENPHP_VERSION=\"$version\""
replace_line "$root/install-macos.sh" '^FRANKENPHP_VERSION="' "FRANKENPHP_VERSION=\"$version\""
replace_line "$root/install.ps1" "^[$]Script:FrankenPhpVersion = '" "\$Script:FrankenPhpVersion = '$version'"

echo
echo "OK: FrankenPHP $version in packaging/frankenphp.sha256, install.sh, install-macos.sh, install.ps1."
echo "Ora: rileggi il changelog di FrankenPHP/Mercure, poi i test degli installer (vedi intestazione),"
echo "poi SUBITO la copia degli asset nel nostro repo: bash packaging/mirror-frankenphp.sh"
