#Requires -Version 5.1
<#
.SYNOPSIS
    Unica fonte di verita' per "che versione e' questa release": dal tag git
    esatto su HEAD, o '0.0.0-dev' per una build locale non taggata.

.DESCRIPTION
    Dot-sourced sia da wrapper\build.ps1 (per la VERSIONINFO del binario,
    generata via goversioninfo) sia da packaging\make-installer.ps1 (per il
    file VERSION nel payload, letto da install.ps1 per config/.installed_version).
    Prima di questo file i due punti erano un numero scritto a mano in due posti
    diversi (rsrc.rc e install.ps1) - facile da dimenticare di aggiornare ad ogni
    release, e infatti e' successo (visto su VM: installazioni pulite che
    continuavano a scrivere una versione vecchia).

    In CI (release.yml) HEAD e' sempre esattamente il commit del tag appena
    pushato, quindi il caso normale e' sempre un match esatto. '0.0.0-dev' e'
    solo per chi lancia make-installer.ps1/build.ps1 a mano tra due tag.
#>

function Get-ReleaseVersion {
    param([Parameter(Mandatory)][string]$RepoRoot)
    $exactTag = git -C $RepoRoot describe --tags --exact-match HEAD 2>$null
    if ($LASTEXITCODE -eq 0 -and $exactTag) {
        return ($exactTag.Trim() -replace '^v', '')
    }
    return '0.0.0-dev'
}

# Spezza "1.2.3" (o "0.0.0-dev") in tre interi per FixedFileInfo - un
# suffisso non numerico dopo una cifra (il "-dev") si scarta, mai un errore.
function ConvertTo-VersionParts {
    param([Parameter(Mandatory)][string]$Version)
    $parts = $Version -split '\.'
    $nums = for ($i = 0; $i -lt 3; $i++) {
        if ($i -lt $parts.Count) {
            $digits = ($parts[$i] -replace '[^0-9].*$', '')
            if ($digits -eq '') { 0 } else { [int]$digits }
        } else { 0 }
    }
    return @($nums)
}
