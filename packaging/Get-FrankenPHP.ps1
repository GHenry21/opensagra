#Requires -Version 5.1
<#
.SYNOPSIS
    Procura un asset di FrankenPHP della versione fissata in
    packaging\frankenphp.sha256, verificandone l'impronta SHA-256.

.DESCRIPTION
    Dot-sourced da packaging\make-installer.ps1 per includere lo zip di
    FrankenPHP nel payload dell'installer: l'installazione non scarica piu'
    FrankenPHP (nessun download che possa fallire o cambiare dal lato di chi
    installa). Se l'asset su GitHub non corrisponde piu' all'impronta
    registrata (FrankenPHP lo ha ricompilato), fallisce la COSTRUZIONE del
    pacchetto - mai l'installazione di un utente.

    Cache in .cache\frankenphp\ alla radice del repo (gitignored): un asset
    gia' scaricato e verificato non si riscarica.
#>

function Get-FrankenPhpLock {
    param([Parameter(Mandatory)][string]$RepoRoot)
    $lockPath = Join-Path $RepoRoot 'packaging\frankenphp.sha256'
    $version = $null
    $hashes = @{}
    foreach ($line in Get-Content $lockPath) {
        if ($line -match '^#\s*version:\s*(\S+)') { $version = $Matches[1]; continue }
        if ($line -match '^([0-9a-f]{64})\s+(\S+)$') { $hashes[$Matches[2]] = $Matches[1] }
    }
    if (-not $version) { throw "Versione non trovata in $lockPath" }
    [pscustomobject]@{ Version = $version; Hashes = $hashes }
}

function Get-FrankenPhpAsset {
    param(
        [Parameter(Mandatory)][string]$RepoRoot,
        [Parameter(Mandatory)][string]$Name
    )
    $lock = Get-FrankenPhpLock -RepoRoot $RepoRoot
    $expected = $lock.Hashes[$Name]
    if (-not $expected) { throw "Nessuna impronta per '$Name' in packaging\frankenphp.sha256" }

    $cacheDir = Join-Path $RepoRoot ".cache\frankenphp\v$($lock.Version)"
    New-Item -ItemType Directory -Force -Path $cacheDir | Out-Null
    $path = Join-Path $cacheDir $Name

    if (-not (Test-Path $path) -or (Get-FileHash $path -Algorithm SHA256).Hash.ToLower() -ne $expected) {
        # Prima la NOSTRA copia (packaging/mirror-frankenphp.sh, release
        # frankenphp-v<versione> di questo repo): FrankenPHP ricompila ogni notte
        # gli asset della sua ultima release (impronte cambiate, a volte un'altra
        # Mercure solo su Linux/macOS), la copia no. Poi la release ufficiale.
        $ProgressPreference = 'SilentlyContinue' # Invoke-WebRequest e' lentissimo con la barra
        foreach ($base in @("https://github.com/GHenry21/opensagra/releases/download/frankenphp-v$($lock.Version)",
                            "https://github.com/php/frankenphp/releases/download/v$($lock.Version)")) {
            Write-Host "     scarico $Name (FrankenPHP v$($lock.Version)) da $($base -replace '/releases/.*$', '')..." -ForegroundColor DarkGray
            try { Invoke-WebRequest -Uri "$base/$Name" -OutFile $path -UseBasicParsing -ErrorAction Stop }
            catch { Remove-Item $path -Force -ErrorAction SilentlyContinue; continue }
            if ((Get-FileHash $path -Algorithm SHA256).Hash.ToLower() -eq $expected) { break }
            Write-Host "       impronta diversa, provo la fonte successiva" -ForegroundColor DarkGray
        }
        if (-not (Test-Path $path)) { throw "Download di '$Name' (FrankenPHP v$($lock.Version)) fallito da tutte le fonti." }
    }

    $actual = (Get-FileHash $path -Algorithm SHA256).Hash.ToLower()
    if ($actual -ne $expected) {
        Remove-Item $path -Force -ErrorAction SilentlyContinue
        throw ("FrankenPHP v$($lock.Version) '$Name' e' cambiato su GitHub rispetto all'impronta " +
            "registrata in packaging\frankenphp.sha256 (atteso $expected, trovato $actual). " +
            "FrankenPHP ha ricompilato l'asset: verificare la versione di Mercure incorporata " +
            "(go version -m / frankenphp build-info) prima di aggiornare l'impronta.")
    }
    return $path
}
