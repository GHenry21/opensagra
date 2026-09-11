# Bootstrap dell'installer a-un-file (opensagra-installer.exe, compilato da
# make-installer.ps1 con ps2exe). Il payload (snapshot dei file tracciati +
# vendor/ + wrapper\opensagra-wrapper.exe, zippati) e' incorporato nell'exe
# via -embedFiles e ps2exe lo estrae da solo in %TEMP% prima che questo script
# parta - qui c'e' solo da scompattarlo ed eseguire install.ps1.
#
# L'exe e' compilato con -requireAdmin: UAC scatta UNA VOLTA al doppio click,
# prima ancora che questo codice giri - install.ps1 (che pretende di essere
# amministratore) lo trova gia' vero, nessuna elevazione aggiuntiva qui.

$ErrorActionPreference = 'Stop'

$zipPath = Join-Path $env:TEMP 'opensagra-installer-payload.zip'
$dest = Join-Path $env:TEMP ('opensagra-install-' + [guid]::NewGuid().ToString('N').Substring(0, 8))

try {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    [System.IO.Compression.ZipFile]::ExtractToDirectory($zipPath, $dest)
    Remove-Item $zipPath -Force -ErrorAction SilentlyContinue

    $installScript = Join-Path $dest 'install.ps1'
    if (-not (Test-Path $installScript)) {
        throw "install.ps1 non trovato nel pacchetto estratto ($dest) - pacchetto corrotto o incompleto."
    }

    # Nota: install.ps1 chiama "exit 1" nel suo blocco catch, non lancia
    # un'eccezione .NET - se fallisce il PROCESSO finisce li' (exit termina
    # sempre il processo, anche invocato con &), $dest resta per il
    # post-mortem. Se va bene, si ricade qui sotto e si ripulisce.
    & $installScript

    Remove-Item $dest -Recurse -Force -ErrorAction SilentlyContinue
    exit 0
} catch {
    # -noConsole: nessuna finestra per vedere l'errore. Lo lascio su file (e
    # NON cancello $dest, utile per capire cos'e' andato storto).
    $_ | Out-File (Join-Path $env:TEMP 'opensagra-installer-error.log') -Append
    exit 1
}
