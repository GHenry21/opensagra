#Requires -Version 5.1
<#
.SYNOPSIS
    Compila uninstall.ps1 in un unico opensagra-uninstaller.exe.

.DESCRIPTION
    Molto piu' semplice di make-installer.ps1: uninstall.ps1 non ha bisogno di
    nessun payload (non copia nessun file applicativo - legge/rimuove cose
    gia' presenti sulla macchina target, con percorsi fissi), quindi si
    compila direttamente con ps2exe, senza script-bootstrap intermedio e
    senza zip incorporato.

.PARAMETER Out
    Percorso dell'exe risultante. Default: opensagra-uninstaller.exe nella
    root del repo (gitignored, come opensagra-installer.exe).

.EXAMPLE
    .\packaging\make-uninstaller.ps1
#>
[CmdletBinding()]
param(
    [string]$Out
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
if (-not $Out) { $Out = Join-Path $RepoRoot 'opensagra-uninstaller.exe' }

if (-not (Get-Module -ListAvailable -Name ps2exe)) {
    throw "Modulo ps2exe non installato. Install-Module ps2exe -Scope CurrentUser -Force"
}
Import-Module ps2exe -Force

$inputScript = Join-Path $RepoRoot 'uninstall.ps1'
$iconPath = Join-Path $RepoRoot 'wrapper\assets\opensagra.ico'

$ps2exeArgs = @{
    inputFile    = $inputScript
    outputFile   = $Out
    noConsole    = $true
    requireAdmin = $true
    title        = 'Disinstallazione OpenSagra'
    product      = 'OpenSagra'
    description  = 'Uninstaller'
    version      = '0.1.0.0'
}
if (Test-Path $iconPath) { $ps2exeArgs.iconFile = $iconPath }

Write-Host 'Compilo uninstall.ps1 con ps2exe...' -ForegroundColor Cyan
Invoke-ps2exe @ps2exeArgs
if (-not (Test-Path $Out)) { throw 'ps2exe non ha prodotto l''exe.' }

$info = Get-Item $Out
Write-Host ''
Write-Host "OK: $Out" -ForegroundColor Green
Write-Host ('  {0:N1} MB - {1}' -f ($info.Length / 1MB), $info.LastWriteTime)
