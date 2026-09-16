#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Disinstaller OpenSagra per Windows.

.DESCRIPTION
    Smonta un'installazione fatta da install.ps1: ferma il wrapper (e con lui
    frankenphp/relay/bridge, via il Job Object), disinstalla MariaDB e
    FrankenPHP, rimuove C:\opensagra, le regole firewall e l'autostart, e - a
    differenza dell'installer, che non fa mai domande - chiede una conferma
    esplicita prima di procedere, essendo un'operazione distruttiva e
    irreversibile. Prima di toccare MariaDB esporta il database su un file sul
    Desktop: non esiste nel progetto nessun altro strumento di backup, ed e'
    l'unica rete di sicurezza contro un click sbagliato.

    Non tocca il Visual C++ Redistributable (componente di sistema condiviso
    con altro software eventualmente installato sulla stessa macchina).

.PARAMETER Force
    Salta la finestra di conferma Si'/No (utile per smontare macchine di
    test/VM in automatico). Il backup del database viene comunque tentato.
#>
param(
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

# Log minimo scritto SEMPRE, dal primissimo istante - stesso motivo di
# install.ps1: impacchettato con ps2exe (-noConsole) un fallimento precoce
# altrimenti non lascia nessuna traccia visibile.
$Script:LogPath = Join-Path $env:TEMP 'opensagra-uninstall.log'
try { "[$(Get-Date -Format o)] avvio uninstall.ps1 (PID $PID)" | Out-File $Script:LogPath -Append -Encoding utf8 } catch {}

# Invoke-NativeCaptured: esegue un eseguibile a console (mariadbd.exe,
# mariadb-dump.exe, ecc.) catturandone stdout/stderr SENZA passare da
# console. Bug reale (2026-09-14, trovato su una VM Hyper-V vera): `& exe
# args 2>&1` da un processo -noConsole (l'exe compilato con ps2exe) puo'
# restare bloccato a tempo indeterminato - Windows alloca un conhost.exe
# "orfano" per il figlio (nessuna console da ereditare) e qualcosa
# nell'interazione tra quella console e la cattura di PowerShell si impianta,
# non e' un crash ne' un errore, il processo resta li' a CPU zero. Start-
# Process con -RedirectStandardOutput/-RedirectStandardError usa pipe/file
# veri, mai una console. Quota anche gli argomenti con spazi al loro interno
# (bug reale separato, gia' visto con --custom di winget e --datadir di
# mariadb-install-db.exe - Start-Process -ArgumentList non lo fa da solo).
function Invoke-NativeCaptured {
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [string[]]$ArgumentList = @()
    )
    $ArgumentList = $ArgumentList | ForEach-Object {
        if ($_ -match '\s' -and $_ -notmatch '^".*"$') { "`"$_`"" } else { $_ }
    }
    $outFile = [System.IO.Path]::GetTempFileName()
    $errFile = [System.IO.Path]::GetTempFileName()
    try {
        $p = Start-Process -FilePath $FilePath -ArgumentList $ArgumentList `
            -RedirectStandardOutput $outFile -RedirectStandardError $errFile `
            -WindowStyle Hidden -Wait -PassThru
        $out = Get-Content $outFile -Raw -ErrorAction SilentlyContinue
        $err = Get-Content $errFile -Raw -ErrorAction SilentlyContinue
        $combined = (@($out, $err) -join "`n").Trim()
        if ($combined) { $combined | Out-File $Script:LogPath -Append -Encoding utf8 }
        [pscustomobject]@{ ExitCode = $p.ExitCode; Output = $out }
    } finally {
        Remove-Item $outFile, $errFile -Force -ErrorAction SilentlyContinue
    }
}

# ============================================================================
# Configurazione (stessi valori di install.ps1 - l'uninstaller deve poter
# girare anche dopo che C:\opensagra e' stato rimosso, quindi non li legge da
# li' ma li ripete qui)
# ============================================================================

$Script:InstallPath = 'C:\opensagra'
$Script:FrankenDir = "$env:USERPROFILE\.frankenphp"
$Script:MariaDbDir = 'C:\Program Files\MariaDB 12.3'
$Script:ProgramDataDir = 'C:\ProgramData\opensagra'

# ============================================================================
# Interfaccia grafica (stessa identica finestra di install.ps1, testi
# adattati - ps2exe compila UN SOLO file quindi non e' condivisibile via
# dot-source con install.ps1 senza un payload a parte, non ne vale la pena
# per ~130 righe stabili di boilerplate WPF)
# ============================================================================

function Show-InstallWindow {
    $Script:SyncHash = [hashtable]::Synchronized(@{ Ready = $false })
    # Bug reale (2026-09-14, trovato testando l'exe compilato su una VM Hyper-V
    # via PowerShell Direct): $PSScriptRoot e' una stringa VUOTA (non null) nel
    # contesto dell'exe compilato con ps2exe - Join-Path con un path vuoto come
    # primo argomento lancia da solo "Impossibile associare l'argomento al
    # parametro 'Path' perche' e' una stringa vuota", PRIMA ancora di arrivare
    # al Test-Path piu' sotto (che infatti era gia' protetto, ma troppo tardi:
    # il crash vero era qui). Riprodotto anche in locale: Join-Path '' 'x' da'
    # lo stesso identico errore.
    $logoPath = if ($PSScriptRoot) { Join-Path $PSScriptRoot 'assets\logo.png' } else { $null }

    $xamlString = @"
<Window xmlns="http://schemas.microsoft.com/winfx/2006/xaml/presentation"
        xmlns:x="http://schemas.microsoft.com/winfx/2006/xaml"
        Title="OpenSagra" Width="460" Height="360"
        WindowStyle="None" AllowsTransparency="True" Background="Transparent"
        WindowStartupLocation="CenterScreen" Topmost="True" ResizeMode="NoResize">
    <Border CornerRadius="16" Background="#FEFEFE" BorderBrush="#33222E2E" BorderThickness="1">
        <Border.Effect><DropShadowEffect BlurRadius="24" ShadowDepth="4" Opacity="0.25" Color="Black"/></Border.Effect>
        <Grid>
            <Grid.RowDefinitions>
                <RowDefinition Height="44"/>
                <RowDefinition Height="*"/>
            </Grid.RowDefinitions>
            <Border Grid.Row="0" CornerRadius="16,16,0,0" Background="#E0B020">
                <Grid>
                    <TextBlock Text="Disinstallazione OpenSagra" Foreground="#33260A" FontWeight="Bold"
                               FontSize="14" VerticalAlignment="Center" Margin="18,0,0,0"/>
                    <Button x:Name="MinimizeButton" Content="&#8212;" Width="32" Height="32"
                            HorizontalAlignment="Right" VerticalAlignment="Center" Margin="0,0,4,0"
                            Background="Transparent" BorderThickness="0" Foreground="#33260A"
                            FontSize="14" FontWeight="Bold" Cursor="Hand"/>
                </Grid>
            </Border>
            <StackPanel Grid.Row="1" Margin="28,20,28,20">
                <StackPanel Orientation="Horizontal" Margin="0,0,0,16">
                    <Image x:Name="Logo" Width="42" Height="42"/>
                    <StackPanel Margin="14,0,0,0" VerticalAlignment="Center">
                        <TextBlock Text="Sto smontando OpenSagra" FontSize="15" FontWeight="Bold" Foreground="#111827"/>
                        <TextBlock Text="Questa finestra si chiudera' da sola a fine disinstallazione" FontSize="11" Foreground="#5F6773"/>
                    </StackPanel>
                </StackPanel>
                <Border Height="10" CornerRadius="5" Background="#F3F4F6">
                    <Border x:Name="ProgressFill" HorizontalAlignment="Left" Width="0" Height="10" CornerRadius="5" Background="#E0B020"/>
                </Border>
                <Grid Margin="0,10,0,0">
                    <TextBlock x:Name="StatusText" Text="Avvio..." FontSize="12" Foreground="#5F6773" HorizontalAlignment="Left"/>
                    <TextBlock x:Name="PctText" Text="0%" FontSize="12" FontWeight="Bold" Foreground="#33260A" HorizontalAlignment="Right"/>
                </Grid>
                <ScrollViewer Margin="0,18,0,0" MaxHeight="160" VerticalScrollBarVisibility="Auto" HorizontalScrollBarVisibility="Disabled">
                    <StackPanel x:Name="ChecklistPanel"/>
                </ScrollViewer>
            </StackPanel>
        </Grid>
    </Border>
</Window>
"@

    $runspace = [runspacefactory]::CreateRunspace()
    $runspace.ApartmentState = 'STA'
    $runspace.ThreadOptions = 'ReuseThread'
    $runspace.Open()
    $runspace.SessionStateProxy.SetVariable('syncHash', $Script:SyncHash)
    $runspace.SessionStateProxy.SetVariable('xamlString', $xamlString)
    $runspace.SessionStateProxy.SetVariable('logoPath', $logoPath)

    $ps = [powershell]::Create()
    $ps.Runspace = $runspace
    [void]$ps.AddScript({
        Add-Type -AssemblyName PresentationFramework, PresentationCore, WindowsBase
        [xml]$xaml = $xamlString
        $reader = New-Object System.Xml.XmlNodeReader $xaml
        $window = [Windows.Markup.XamlReader]::Load($reader)
        $syncHash.Window = $window
        $syncHash.ProgressFill = $window.FindName('ProgressFill')
        $syncHash.StatusText = $window.FindName('StatusText')
        $syncHash.PctText = $window.FindName('PctText')
        $syncHash.ChecklistPanel = $window.FindName('ChecklistPanel')
        $window.FindName('MinimizeButton').Add_Click({ $window.WindowState = 'Minimized' }.GetNewClosure())
        $logoCtrl = $window.FindName('Logo')
        if ($logoPath -and (Test-Path $logoPath)) {
            $logoCtrl.Source = [System.Windows.Media.Imaging.BitmapImage]::new((New-Object System.Uri($logoPath)))
        }
        $syncHash.Ready = $true
        [void]$window.ShowDialog()
    })
    $Script:GuiAsyncResult = $ps.BeginInvoke()
    $Script:GuiPowerShell = $ps
    $Script:GuiRunspace = $runspace

    $timeout = (Get-Date).AddSeconds(10)
    while (-not $Script:SyncHash.Ready -and (Get-Date) -lt $timeout) { Start-Sleep -Milliseconds 50 }
    if (-not $Script:SyncHash.Ready) { throw 'La finestra di disinstallazione non si e'' aperta in tempo.' }
}

function Set-InstallProgress {
    param([int]$Percent, [string]$Status)
    if (-not $Script:SyncHash.Ready) { return }
    $Script:SyncHash.Window.Dispatcher.Invoke([action]{
        $Script:SyncHash.ProgressFill.Width = 384 * ([Math]::Min([Math]::Max($Percent, 0), 100) / 100)
        $Script:SyncHash.StatusText.Text = $Status
        $Script:SyncHash.PctText.Text = "$Percent%"
    })
}

function Add-InstallChecklistItem {
    param([string]$Text, [ValidateSet('ok', 'error')]$State = 'ok')
    if (-not $Script:SyncHash.Ready) { return }
    $color = if ($State -eq 'ok') { '#0F9D5F' } else { '#C73646' }
    $Script:SyncHash.Window.Dispatcher.Invoke([action]{
        $row = New-Object System.Windows.Controls.StackPanel
        $row.Orientation = 'Horizontal'
        $row.Margin = '0,4,0,0'
        $dot = New-Object System.Windows.Shapes.Ellipse
        $dot.Width = 8; $dot.Height = 8; $dot.Margin = '0,0,8,0'
        $dot.Fill = [System.Windows.Media.BrushConverter]::new().ConvertFrom($color)
        $label = New-Object System.Windows.Controls.TextBlock
        $label.Text = $Text; $label.FontSize = 11
        $label.Foreground = [System.Windows.Media.BrushConverter]::new().ConvertFrom('#5F6773')
        [void]$row.Children.Add($dot)
        [void]$row.Children.Add($label)
        [void]$Script:SyncHash.ChecklistPanel.Children.Add($row)
    })
}

function Close-InstallWindow {
    param([switch]$Success, [string]$ErrorMessage)
    if (-not $Script:SyncHash.Ready) { return }
    if ($Success) {
        Set-InstallProgress -Percent 100 -Status 'Disinstallazione completata'
        Start-Sleep -Seconds 2
    } else {
        $Script:SyncHash.Window.Dispatcher.Invoke([action]{
            $Script:SyncHash.StatusText.Text = "Errore: $ErrorMessage"
            $Script:SyncHash.StatusText.Foreground = [System.Windows.Media.BrushConverter]::new().ConvertFrom('#C73646')
        })
        Start-Sleep -Seconds 6
    }
    $Script:SyncHash.Window.Dispatcher.Invoke([action]{ $Script:SyncHash.Window.Close() })
    $Script:GuiPowerShell.EndInvoke($Script:GuiAsyncResult)
    $Script:GuiPowerShell.Dispose()
    $Script:GuiRunspace.Close()
}

# ============================================================================
# Passi della disinstallazione
# ============================================================================

function Test-Prerequisites {
    if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Questo script va eseguito come amministratore.'
    }
    if ([System.Environment]::OSVersion.Platform -ne 'Win32NT') {
        throw 'Questo disinstaller supporta solo Windows.'
    }
}

function Confirm-Uninstall {
    if ($Force) { return }
    Add-Type -AssemblyName PresentationFramework
    $msg = "Verranno rimossi in modo permanente:`n`n" +
        "- MariaDB (servizio e programma), dopo un backup del database sul Desktop`n" +
        "- FrankenPHP`n" +
        "- Tutti i file dell'app in $Script:InstallPath`n" +
        "- Le regole firewall e l'avvio automatico di OpenSagra`n`n" +
        "Questa operazione non si puo' annullare. Continuare?"
    $result = [System.Windows.MessageBox]::Show(
        $msg, 'Disinstallare OpenSagra?',
        [System.Windows.MessageBoxButton]::YesNo, [System.Windows.MessageBoxImage]::Warning)
    if ($result -ne [System.Windows.MessageBoxResult]::Yes) {
        exit 0
    }
}

# readEnvValue: stesso parsing "chiave=valore" usato da install.ps1
# (Get-OrNewMercureSecret) - un file .env di poche righe, niente serve una
# libreria per questo.
function readEnvValue {
    param([string]$Path, [string]$Key)
    if (-not (Test-Path $Path)) { return $null }
    $m = Select-String -Path $Path -Pattern "^\s*$Key\s*=\s*(\S*)" | Select-Object -First 1
    if ($m) { return $m.Matches[0].Groups[1].Value }
    return $null
}

function Backup-Database {
    $envPath = Join-Path $Script:InstallPath 'config\variabili.env'
    $svc = Get-Service -Name 'MariaDB' -ErrorAction SilentlyContinue
    if (-not (Test-Path $envPath) -or -not $svc -or $svc.Status -ne 'Running') {
        Add-InstallChecklistItem 'Nessun database locale attivo da salvare'
        return
    }

    $dumpExe = Get-ChildItem -Path (Join-Path $Script:MariaDbDir 'bin') -Filter 'mariadb-dump.exe' -ErrorAction SilentlyContinue |
        Select-Object -First 1 -ExpandProperty FullName
    if (-not $dumpExe) {
        $dumpExe = Get-ChildItem -Path (Join-Path $Script:MariaDbDir 'bin') -Filter 'mysqldump.exe' -ErrorAction SilentlyContinue |
            Select-Object -First 1 -ExpandProperty FullName
    }
    if (-not $dumpExe) {
        throw "Nessuno strumento di export (mariadb-dump/mysqldump) trovato in $Script:MariaDbDir\bin - impossibile fare un backup sicuro prima di rimuovere MariaDB."
    }

    $dbUser = readEnvValue -Path $envPath -Key 'DB_POS_USER'
    $dbPass = readEnvValue -Path $envPath -Key 'DB_POS_PASS'
    if (-not $dbUser) {
        throw "DB_POS_USER non trovato in $envPath - impossibile fare un backup sicuro prima di rimuovere MariaDB."
    }

    # Redirect diretto (non Invoke-NativeCaptured: qui lo stdout E' il dump
    # vero, non testo da loggare) ma stessa cautela sulla console - vedi nota
    # su Invoke-NativeCaptured piu' in alto nel file.
    $backupPath = Join-Path ([Environment]::GetFolderPath('Desktop')) "opensagra-backup-$(Get-Date -Format 'yyyyMMdd-HHmmss').sql"
    $dumpErrFile = [System.IO.Path]::GetTempFileName()
    try {
        $p = Start-Process -FilePath $dumpExe -ArgumentList @("--user=$dbUser", "--password=$dbPass", '--host=127.0.0.1', 'opensagra_pos') `
            -RedirectStandardOutput $backupPath -RedirectStandardError $dumpErrFile -WindowStyle Hidden -Wait -PassThru
        $dumpErr = Get-Content $dumpErrFile -Raw -ErrorAction SilentlyContinue
        if ($dumpErr) { $dumpErr.TrimEnd() | Out-File $Script:LogPath -Append -Encoding utf8 }
    } finally {
        Remove-Item $dumpErrFile -Force -ErrorAction SilentlyContinue
    }
    if ($p.ExitCode -ne 0 -or -not (Test-Path $backupPath) -or (Get-Item $backupPath).Length -eq 0) {
        Remove-Item $backupPath -Force -ErrorAction SilentlyContinue
        throw "Backup del database fallito (dettagli in $Script:LogPath) - disinstallazione interrotta prima di toccare MariaDB."
    }
    Add-InstallChecklistItem "Database salvato in $backupPath"
}

function Stop-OpenSagraWrapper {
    # Tentativo best-effort di avvisare le eventuali casse client collegate
    # prima del kill (rilevante solo se questa macchina e' il server) - come
    # fa il wrapper stesso all'uscita normale (main.go, tray "Esci").
    $frankenphp = Join-Path $Script:FrankenDir 'frankenphp.exe'
    $announce = Join-Path $Script:InstallPath 'bin\opensagra-announce.php'
    if ((Test-Path $frankenphp) -and (Test-Path $announce)) {
        try {
            $job = Start-Job -ScriptBlock { & $using:frankenphp php-cli $using:announce --kind=shutdown }
            Wait-Job $job -Timeout 5 | Out-Null
            Remove-Job $job -Force -ErrorAction SilentlyContinue
        } catch {}
    }

    Get-Process -Name 'opensagra-wrapper' -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    # Difensivo: nel caso un'installazione precedente sia stata interrotta a
    # meta' (install.ps1 lo disiscrive da solo a fine passo, in teoria non
    # dovrebbe mai essere qui).
    Unregister-ScheduledTask -TaskName 'OpenSagra-FirstRun' -Confirm:$false -ErrorAction SilentlyContinue
    Add-InstallChecklistItem 'OpenSagra (wrapper e processi supervisionati) fermato'
}

function Remove-WrapperAutostart {
    Remove-ItemProperty -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run' -Name 'OpenSagra' -ErrorAction SilentlyContinue
    Add-InstallChecklistItem 'Avvio automatico rimosso'
}

function Remove-MariaDB {
    $svc = Get-Service -Name 'MariaDB' -ErrorAction SilentlyContinue
    if ($svc) {
        if ($svc.Status -ne 'Stopped') { Stop-Service -Name 'MariaDB' -Force }
        $mariadbd = Join-Path $Script:MariaDbDir 'bin\mariadbd.exe'
        if (Test-Path $mariadbd) {
            Invoke-NativeCaptured -FilePath $mariadbd -ArgumentList @('--remove', 'MariaDB') | Out-Null
        }
    }

    if (Test-Path $Script:MariaDbDir) {
        # `winget uninstall --silent` non garantisce un /quiet reale sul
        # pacchetto MSI sottostante (visto testando: si apre comunque la
        # finestra dell'uninstaller nativo di MariaDB) - si aggira del tutto
        # winget e si chiama msiexec direttamente col SUO flag ufficiale
        # (/quiet, sempre rispettato), risalendo al product code dal registro
        # Uninstall di Windows.
        $entry = Get-ItemProperty -Path @(
            'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\*',
            'HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
        ) -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName -like 'MariaDB*' } | Select-Object -First 1

        if ($entry -and $entry.UninstallString -match '\{[0-9A-Fa-f-]+\}') {
            $productCode = $Matches[0]
            Start-Process -FilePath 'msiexec.exe' -ArgumentList @('/x', $productCode, '/quiet', '/norestart') -WindowStyle Hidden -Wait -ErrorAction SilentlyContinue | Out-Null
        } else {
            # Fallback se non si trova la voce nel registro (installato in
            # altro modo) - meglio winget silenzioso a meta' che niente.
            $wingetArgs = @('uninstall', '--id', 'MariaDB.Server', '-e', '--silent', '--disable-interactivity')
            Start-Process -FilePath 'winget' -ArgumentList $wingetArgs -WindowStyle Hidden -Wait -ErrorAction SilentlyContinue | Out-Null
        }

        # L'MSI spesso lascia la cartella dati per sicurezza - il backup e'
        # gia' fatto sopra, quindi qui si puo' spazzare via senza remore.
        Remove-Item $Script:MariaDbDir -Recurse -Force -ErrorAction SilentlyContinue
    }
    Add-InstallChecklistItem 'MariaDB disinstallato'
}

function Remove-FrankenPHP {
    # La CA locale di Caddy/FrankenPHP andrebbe rimossa dal trust store PRIMA
    # di cancellare la cartella che la contiene, altrimenti resta un
    # certificato radice fidato "orfano" (nessun file corrispondente sul
    # disco) - innocuo (non e' associato a nessun sito che l'utente visiti
    # davvero), ma non pulito. Bug reale (2026-09-16): rimuovere un
    # certificato da CurrentUser\Root si e' bloccato a tempo indeterminato
    # dentro l'exe compilato - sia X509Store.Remove() che l'equivalente
    # "certutil -delstore" hanno dato "0x80070032 ERROR_NOT_SUPPORTED" nei
    # test isolati (Windows protegge deliberatamente il trust store da
    # rimozioni automatiche), ma qui il tentativo si impantana invece di
    # fallire in fretta. Gira in un job separato con un timeout breve - se
    # Windows lo rifiuta o si blocca, si prosegue comunque: non vale
    # bloccare tutta la disinstallazione per un certificato residuo innocuo.
    $caRoot = Join-Path $env:APPDATA 'Caddy\pki\authorities\local\root.crt'
    if (Test-Path $caRoot) {
        try {
            $job = Start-Job -ScriptBlock {
                param($path)
                $cert = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new($path)
                $store = [System.Security.Cryptography.X509Certificates.X509Store]::new('Root', 'CurrentUser')
                $store.Open('ReadWrite')
                $store.Remove($cert)
                $store.Close()
            } -ArgumentList $caRoot
            Wait-Job $job -Timeout 5 | Out-Null
            Remove-Job $job -Force -ErrorAction SilentlyContinue
        } catch {}
    }
    Remove-Item (Join-Path $env:APPDATA 'Caddy') -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item $Script:FrankenDir -Recurse -Force -ErrorAction SilentlyContinue
    # Il Visual C++ Redistributable NON si tocca: componente di sistema
    # condiviso, altro software installato sulla stessa macchina puo' farne uso.
    Add-InstallChecklistItem 'FrankenPHP e CA locale rimossi'
}

function Remove-AppFiles {
    Remove-Item $Script:InstallPath -Recurse -Force -ErrorAction SilentlyContinue
    # Collegamento sul desktop pubblico creato da install.ps1 (New-WrapperShortcut) -
    # altrimenti resta un collegamento rotto dopo la disinstallazione.
    Remove-Item (Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) 'OpenSagra.lnk') -Force -ErrorAction SilentlyContinue
    Add-InstallChecklistItem 'File dell''app rimossi'
}

function Remove-ProgramDataDir {
    Remove-Item $Script:ProgramDataDir -Recurse -Force -ErrorAction SilentlyContinue
    Add-InstallChecklistItem 'Dati temporanei rimossi'
}

function Remove-FirewallRules {
    Remove-NetFirewallRule -DisplayName 'opensagra HTTP/HTTPS' -ErrorAction SilentlyContinue
    Remove-NetFirewallRule -DisplayName 'opensagra MariaDB' -ErrorAction SilentlyContinue
    Add-InstallChecklistItem 'Regole firewall rimosse'
}

# ============================================================================
# Orchestrazione
# ============================================================================

try {
    Test-Prerequisites
    Confirm-Uninstall
    Show-InstallWindow

    Set-InstallProgress -Percent 5 -Status 'Backup del database...'
    Backup-Database

    Set-InstallProgress -Percent 25 -Status 'Arresto di OpenSagra...'
    Stop-OpenSagraWrapper
    Remove-WrapperAutostart

    Set-InstallProgress -Percent 45 -Status 'Disinstallazione di MariaDB...'
    Remove-MariaDB

    Set-InstallProgress -Percent 65 -Status 'Disinstallazione di FrankenPHP...'
    Remove-FrankenPHP

    Set-InstallProgress -Percent 80 -Status 'Rimozione dei file dell''app...'
    Remove-AppFiles
    Remove-ProgramDataDir

    Set-InstallProgress -Percent 95 -Status 'Rimozione delle regole di rete...'
    Remove-FirewallRules

    Close-InstallWindow -Success
} catch {
    # NIENTE Write-Error qui - vedi install.ps1 per il perche' (sotto
    # $ErrorActionPreference='Stop' diventa esso stesso terminante e salta
    # il resto di questo catch, incluso il fallback che mostra l'errore).
    try { ($_ | Out-String) | Out-File $Script:LogPath -Append -Encoding utf8 } catch {}

    $wasReady = $Script:SyncHash -and $Script:SyncHash.Ready
    Close-InstallWindow -ErrorMessage $_.Exception.Message
    if (-not $wasReady) {
        try {
            Add-Type -AssemblyName PresentationFramework
            [System.Windows.MessageBox]::Show(
                "Disinstallazione fallita:`n`n$($_.Exception.Message)`n`nDettagli completi in $Script:LogPath",
                'Errore disinstallazione OpenSagra',
                [System.Windows.MessageBoxButton]::OK, [System.Windows.MessageBoxImage]::Error) | Out-Null
        } catch {}
    }
    exit 1
}
