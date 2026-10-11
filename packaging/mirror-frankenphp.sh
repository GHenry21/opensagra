#!/usr/bin/env bash
# mirror-frankenphp.sh: copia i cinque asset di FrankenPHP fissati in
# packaging/frankenphp.sha256 in una release di QUESTO repo
# (frankenphp-v<versione>), da cui poi scaricano make-unix-package.sh,
# Get-FrankenPHP.ps1 e, come ripiego, gli installer.
#
#   bash packaging/mirror-frankenphp.sh
#
# Perche': FrankenPHP ricompila ogni notte gli asset della sua ULTIMA release
# e li sostituisce con lo stesso nome. Le impronte cambiano e a volte cambia
# anche la Mercure, solo su Linux/macOS (la 1.13.0 del 4 ottobre aveva Mercure
# 1.0.3 ovunque; l'11 ottobre 1.1.1 su Linux/macOS e 1.0.3 su Windows). Senza
# copia, dopo una di queste notti i nostri pacchetti non si costruiscono piu'
# (impronta diversa) fino alla release successiva di FrankenPHP.
#
# Va lanciato SUBITO dopo set-frankenphp-version.sh e i test, finche' gli
# asset ufficiali sono ancora quelli provati: li scarica dalla release
# ufficiale e li carica solo se l'impronta coincide con frankenphp.sha256.
#
# La release e' una PRE-RELEASE e non "latest": il wrapper cerca gli
# aggiornamenti di OpenSagra su releases/latest (che ignora le pre-release), e
# release.yml parte solo con i tag v* (questo e' frankenphp-v*). Se la release
# esiste gia', controlla le impronte e aggiunge solo gli asset mancanti; un
# asset gia' caricato e diverso e' un errore, mai sovrascritto.
#
# Serve gh autenticato con permesso di scrittura sul repo.
set -euo pipefail

REPO="GHenry21/opensagra"
root="$(cd "$(dirname "$0")/.." && pwd)"
lock="$root/packaging/frankenphp.sha256"
assets=(frankenphp-linux-aarch64 frankenphp-linux-x86_64 frankenphp-mac-arm64 frankenphp-mac-x86_64 frankenphp-windows-x86_64.zip)

die() { echo "ERRORE: $*" >&2; exit 1; }
sha256_of() { if command -v sha256sum >/dev/null; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
command -v gh >/dev/null 2>&1 || die "serve gh (GitHub CLI) autenticato"

version="$(sed -n 's/^#[[:space:]]*version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$lock" | head -1)"
[ -n "$version" ] || die "versione mancante in $lock"
tag="frankenphp-v$version"
hash_of() { awk -v n="$1" '$2==n {print $1}' "$lock"; }

cache="$root/.cache/frankenphp/v$version"
mkdir -p "$cache"
echo "FrankenPHP $version -> $REPO, release $tag"
for a in "${assets[@]}"; do
    want="$(hash_of "$a")"
    [ -n "$want" ] || die "impronta di $a mancante in $lock"
    f="$cache/$a"
    if [ ! -f "$f" ] || [ "$(sha256_of "$f")" != "$want" ]; then
        curl -fsL --retry 3 -o "$f" "https://github.com/php/frankenphp/releases/download/v$version/$a" || die "download di $a fallito"
    fi
    [ "$(sha256_of "$f")" = "$want" ] \
        || die "$a ufficiale e' gia' cambiato rispetto a frankenphp.sha256 (ricompilato?): rilancia set-frankenphp-version.sh e i test prima di copiarlo"
    echo "  impronta ok  $a"
done

notes="Copia degli asset di FrankenPHP $version usati nei pacchetti di OpenSagra, con le stesse impronte di packaging/frankenphp.sha256. Non e' una versione di OpenSagra. Gli originali: https://github.com/php/frankenphp/releases/tag/v$version (FrankenPHP e' rilasciato sotto licenza MIT)."

if gh release view "$tag" --repo "$REPO" >/dev/null 2>&1; then
    echo "La release $tag esiste: controllo gli asset gia' caricati"
    existing="$(gh release view "$tag" --repo "$REPO" --json assets --jq '.assets[] | .name + " " + (.digest // "")')"
    for a in "${assets[@]}"; do
        line="$(printf '%s\n' "$existing" | awk -v n="$a" '$1==n')"
        if [ -z "$line" ]; then
            gh release upload "$tag" "$cache/$a" --repo "$REPO"
            echo "  caricato     $a"
        else
            have="${line#* }"; have="${have#sha256:}"
            [ "$have" = "$(hash_of "$a")" ] || die "$a nella release $tag ha un'impronta diversa ($have): non lo sovrascrivo"
            echo "  gia' presente $a"
        fi
    done
else
    files=(); for a in "${assets[@]}"; do files+=("$cache/$a"); done
    gh release create "$tag" "${files[@]}" --repo "$REPO" \
        --prerelease --latest=false \
        --title "FrankenPHP $version (copia per i pacchetti di OpenSagra)" \
        --notes "$notes"
    echo "  release $tag creata"
fi

# Verifica finale: le impronte calcolate da GitHub sui file caricati
gh release view "$tag" --repo "$REPO" --json assets --jq '.assets[] | .name + " " + (.digest // "")' | while read -r n d; do
    [ "${d#sha256:}" = "$(hash_of "$n")" ] || die "$n: impronta su GitHub diversa da frankenphp.sha256"
done
echo "OK: https://github.com/$REPO/releases/tag/$tag"
