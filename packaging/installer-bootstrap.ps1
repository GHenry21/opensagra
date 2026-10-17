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

Add-Type -AssemblyName System.Windows.Forms, System.Drawing

# OpenSagra aperto -> ci si ferma qui, prima di scompattare: stesso controllo
# di Assert-OpenSagraClosed in install.ps1 (che resta, come difesa), ma senza
# far aspettare all'utente lo scompattamento del payload per sentirselo dire.
# Percorsi uguali a $Script:InstallPath / $Script:FrankenDir di install.ps1.
function Test-OpenSagraRunning {
    $installPath = 'C:\opensagra'
    $frankenDir = "$env:USERPROFILE\.frankenphp"
    $running = Get-Process -Name 'opensagra-wrapper', 'frankenphp' -ErrorAction SilentlyContinue | Where-Object {
        $_.Path -and (
            $_.Path.StartsWith($installPath, [StringComparison]::OrdinalIgnoreCase) -or
            $_.Path.StartsWith($frankenDir, [StringComparison]::OrdinalIgnoreCase))
    }
    return [bool]$running
}

# Finestrella "Avvio dell'installazione..." mentre si scompatta: su un PC lento
# o con l'antivirus al lavoro passano decine di secondi prima che compaia la
# finestra di install.ps1, e senza nulla a video l'utente rilancia l'exe.
function Show-StartupSplash {
    $form = New-Object System.Windows.Forms.Form
    $form.Text = 'Installazione OpenSagra'
    $form.FormBorderStyle = 'FixedDialog'
    $form.ControlBox = $false
    $form.StartPosition = 'CenterScreen'
    $form.ClientSize = New-Object System.Drawing.Size(360, 90)
    $form.TopMost = $true
    $form.ShowInTaskbar = $true
    $label = New-Object System.Windows.Forms.Label
    $label.Text = "Avvio dell'installazione di OpenSagra..."
    $label.Font = New-Object System.Drawing.Font('Segoe UI', 11)
    $label.AutoSize = $false
    $label.TextAlign = 'MiddleCenter'
    $label.Dock = 'Fill'
    $form.Controls.Add($label)
    $form.Show()
    [System.Windows.Forms.Application]::DoEvents()
    return $form
}

$splash = $null
try {
    if (Test-OpenSagraRunning) {
        Remove-Item $zipPath -Force -ErrorAction SilentlyContinue
        [System.Windows.Forms.MessageBox]::Show(
            "OpenSagra e' aperto su questo PC.`n`nChiudilo (icona OpenSagra vicino all'orologio > Esci) e rilancia l'installazione. Vendite, catalogo e impostazioni restano come sono.",
            'Installazione OpenSagra',
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Warning) | Out-Null
        exit 1
    }

    $splash = Show-StartupSplash

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    # Estrazione in un runspace a parte: il thread di questo script resta
    # libero di far ridisegnare la finestrella (altrimenti Windows la segna
    # "Non risponde" dopo pochi secondi).
    $extract = [PowerShell]::Create()
    [void]$extract.AddScript({
        param($zip, $to)
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        [System.IO.Compression.ZipFile]::ExtractToDirectory($zip, $to)
    }).AddArgument($zipPath).AddArgument($dest)
    $handle = $extract.BeginInvoke()
    while (-not $handle.IsCompleted) {
        [System.Windows.Forms.Application]::DoEvents()
        Start-Sleep -Milliseconds 50
    }
    $extract.EndInvoke($handle)
    if ($extract.HadErrors) { throw $extract.Streams.Error[0].Exception }
    $extract.Dispose()
    Remove-Item $zipPath -Force -ErrorAction SilentlyContinue

    $installScript = Join-Path $dest 'install.ps1'
    if (-not (Test-Path $installScript)) {
        throw "install.ps1 non trovato nel pacchetto estratto ($dest) - pacchetto corrotto o incompleto."
    }

    # Su una macchina con la ExecutionPolicy di default (Restricted - il caso
    # normale su un Windows pulito/appena attivato, a differenza di
    # un'immagine di sviluppo che spesso l'ha gia' rilassata) "& $installScript"
    # sotto viene rifiutato con "L'esecuzione di script e' disabilitata nel
    # sistema in uso" (scoperto testando su una VM davvero pulita, 2026-09-12).
    # -Scope Process: vale solo per QUESTO processo del bootstrap, non tocca
    # la policy della macchina/utente - non serve e non deve persistere.
    Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force

    # La finestrella resta fino al lancio di install.ps1, che apre la propria.
    $splash.Close(); $splash.Dispose(); $splash = $null

    # Nota: install.ps1 chiama "exit 1" nel suo blocco catch, non lancia
    # un'eccezione .NET - se fallisce il PROCESSO finisce li' (exit termina
    # sempre il processo, anche invocato con &), $dest resta per il
    # post-mortem. Se va bene, si ricade qui sotto e si ripulisce.
    & $installScript

    Remove-Item $dest -Recurse -Force -ErrorAction SilentlyContinue
    exit 0
} catch {
    if ($splash) { $splash.Close(); $splash.Dispose() }
    # -noConsole: nessuna finestra per vedere l'errore. Lo lascio su file (e
    # NON cancello $dest, utile per capire cos'e' andato storto).
    $_ | Out-File (Join-Path $env:TEMP 'opensagra-installer-error.log') -Append
    exit 1
}
