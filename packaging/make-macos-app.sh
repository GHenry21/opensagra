#!/bin/bash
# App macOS "Installa OpenSagra" - installer grafico (installer/) con DENTRO
# il pacchetto di release Unix. Produce, alla radice del repo:
#
#   Installa-OpenSagra-macos-<arm64|x86_64>.zip
#
# con dentro "Installa OpenSagra.app": l'utente fa doppio click sullo zip e
# poi sull'app (guida utente, §1 "Su Mac"). Va lanciato SU UN MAC: la
# finestra usa la webview di sistema (WKWebView, cgo) e non si
# cross-compila da Linux. Un Mac Apple Silicon compila anche per Intel.
#
# Perche' il pacchetto sta dentro l'app (Contents/Resources/payload) e non
# accanto: App Translocation (un'app scaricata e non spostata gira da una
# copia di sola lettura che contiene solo il bundle) e install-macos.sh che
# copia in ~/opensagra tutto il contenuto della sua cartella. Vedi il piano,
# sezione 5h, e findScript in installer/main.go.
#
# Firma ad-hoc dell'INTERO bundle (gratuita, nessun account Apple): senza,
# un'app scaricata viene presentata come "danneggiata" e non si puo' aprire
# nemmeno da Privacy e sicurezza -> "Apri comunque" (visto in CI, 2026-10-02).
#
# Uso:
#   packaging/make-macos-app.sh arm64
#   packaging/make-macos-app.sh x86_64
#
# Richiede: macOS con Xcode Command Line Tools (clang), go, git, curl,
# vendor/ gia' presente (composer install).

set -euo pipefail

ARCH="${1:-}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
die() { echo "ERRORE: $*" >&2; exit 1; }

[ "$(uname -s)" = Darwin ] || die "va lanciato su macOS (l'installer usa la webview di sistema, cgo)."
case "$ARCH" in
    arm64)  goarch=arm64 ;;
    x86_64) goarch=amd64 ;;
    *) die "uso: $0 <arm64|x86_64>" ;;
esac

# Pacchetto Unix per macOS (codice app, vendor/, wrapper, FrankenPHP).
bash "$REPO_ROOT/packaging/make-unix-package.sh" macos "$ARCH"
tarball="$(ls -t "$REPO_ROOT"/opensagra-*-macos-"$ARCH".tar.gz | head -1)"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
app="$work/Installa OpenSagra.app"
mkdir -p "$app/Contents/MacOS" "$app/Contents/Resources/payload"
tar -xzf "$tarball" -C "$work"
pkg="$(ls -d "$work"/opensagra-*/)"
cp -Rp "$pkg." "$app/Contents/Resources/payload/"
version="$(cat "$app/Contents/Resources/payload/VERSION")"

echo "  compilo l'installer grafico (darwin/$goarch, cgo)..."
(cd "$REPO_ROOT/installer" && CGO_ENABLED=1 GOOS=darwin GOARCH=$goarch \
    CC="clang -arch $ARCH" go build -trimpath -o "$app/Contents/MacOS/opensagra-installer" .)

# CFBundleShortVersionString: solo numeri e punti (0.0.0-dev -> 0.0.0).
cat > "$app/Contents/Info.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>CFBundleExecutable</key><string>opensagra-installer</string>
  <key>CFBundleIdentifier</key><string>com.opensagra.installer</string>
  <key>CFBundleName</key><string>Installa OpenSagra</string>
  <key>CFBundleDisplayName</key><string>Installa OpenSagra</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleShortVersionString</key><string>${version%%-*}</string>
  <key>CFBundleVersion</key><string>$version</string>
  <key>LSMinimumSystemVersion</key><string>13.0</string>
  <key>NSHighResolutionCapable</key><true/>
</dict></plist>
EOF
plutil -lint "$app/Contents/Info.plist" >/dev/null

codesign --force --sign - --timestamp=none "$app"
codesign --verify --strict "$app" || die "firma ad-hoc del bundle non valida"

out="$REPO_ROOT/Installa-OpenSagra-macos-$ARCH.zip"
rm -f "$out"
# ditto: lo zip "alla Apple" (permessi, attributi e firma del bundle intatti)
ditto -c -k --keepParent "$app" "$out"
size_mb=$(( $(wc -c < "$out") / 1048576 ))
echo "OK: $out (${size_mb} MB, versione $version)"
