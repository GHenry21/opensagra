#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Installer OpenSagra per Windows - Fase 3.

.DESCRIPTION
    Installazione completamente automatica, senza domande: porta una macchina
    pulita ad app funzionante (FrankenPHP + MariaDB nativa + QZ Tray), sempre
    con la stessa identica configurazione "indipendente". Se una postazione
    deve diventare client di un'altra installazione, si decide DOPO, in
    qualsiasi momento, dalla pagina Configurazione Rete dentro l'app - non
    e' compito di questo script (vedi docs/PIANO-MIGRAZIONE-FRANKENPHP.md,
    sezione 3a).

    Ogni passo e' pensato per essere idempotente: rilanciare lo script su una
    macchina gia' configurata non deve rompere nulla, deve solo confermare
    che tutto e' gia' a posto e saltare quello che c'e' gia'.

    Interfaccia: finestra WPF (nessuna console visibile se impacchettato con
    ps2exe), non un vero wizard - non ci sono domande da fare, la finestra
    mostra solo l'avanzamento e si chiude da sola a fine installazione.

.NOTES
    Bozza iniziale (2026-09-07): i passi Install-FrankenPHP, Install-
    MariaDBEngine, Register-MariaDBService, Register-FrankenPHPService e
    Install-QZTray sono scritti secondo i comandi gia' documentati e provati
    in questo piano, ma su QUESTA macchina (gia' completamente configurata)
    imboccano sempre il ramo "gia' presente, salto" - il ramo di
    installazione da zero non e' verificabile end-to-end senza una macchina
    pulita o una VM, che non e' disponibile in questa sessione. Vanno
    ritestati per intero su una macchina davvero vergine prima del rilascio.
#>

$ErrorActionPreference = 'Stop'

# ============================================================================
# Configurazione
# ============================================================================

$Script:InstallPath = 'C:\opensagra'
$Script:SourcePath = $PSScriptRoot
$Script:FrankenDir = "$env:USERPROFILE\.frankenphp"
$Script:MariaDbDir = 'C:\Program Files\MariaDB 12.3'
$Script:UploadTmpDir = 'C:\ProgramData\opensagra\php_upload_tmp'
$Script:RequiredExtensions = @('mysqli', 'mbstring', 'gd', 'zip', 'intl', 'curl', 'openssl', 'iconv', 'dom', 'fileinfo')

# Cartelle/file del pacchetto di release da NON copiare nell'installazione
# (materiale di sviluppo, non serve a chi usa l'app)
$Script:ExcludeFromCopy = @(
    '.git', '.vscode', 'e2e', 'docs', 'node_modules', 'install.ps1',
    '.gitignore', '.gitattributes', 'archive', 'playwright-report',
    'test-results', 'package.json', 'package-lock.json', 'playwright.config.js',
    'bt.html', 'navbar example.html',
    # Chiave privata di firma QZ Tray, bundlata accanto a install.ps1 (vedi
    # Install-QZTray/installa_certificati_qz.php) - NON deve mai finire dentro
    # la webroot copiata qui: e' fuori dalla webroot per costruzione, per non
    # essere mai raggiungibile via browser.
    'private'
)

# ============================================================================
# Interfaccia grafica (WPF in un runspace separato, aggiornata dal thread
# principale - vedi docs/PIANO-MIGRAZIONE-FRANKENPHP.md per il perche')
# ============================================================================

function Show-InstallWindow {
    $Script:SyncHash = [hashtable]::Synchronized(@{ Ready = $false })
    $logoPath = Join-Path $Script:SourcePath 'assets\logo.png'

    $xamlString = @"
<Window xmlns="http://schemas.microsoft.com/winfx/2006/xaml/presentation"
        xmlns:x="http://schemas.microsoft.com/winfx/2006/xaml"
        Title="OpenSagra" Width="460" Height="320"
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
                <TextBlock Text="Installazione OpenSagra" Foreground="#33260A" FontWeight="Bold"
                           FontSize="14" VerticalAlignment="Center" Margin="18,0,0,0"/>
            </Border>
            <StackPanel Grid.Row="1" Margin="28,20,28,20">
                <StackPanel Orientation="Horizontal" Margin="0,0,0,16">
                    <Image x:Name="Logo" Width="42" Height="42"/>
                    <StackPanel Margin="14,0,0,0" VerticalAlignment="Center">
                        <TextBlock Text="Sto preparando la tua sagra" FontSize="15" FontWeight="Bold" Foreground="#111827"/>
                        <TextBlock Text="Questa finestra si chiudera' da sola a fine installazione" FontSize="11" Foreground="#5F6773"/>
                    </StackPanel>
                </StackPanel>
                <Border Height="10" CornerRadius="5" Background="#F3F4F6">
                    <Border x:Name="ProgressFill" HorizontalAlignment="Left" Width="0" Height="10" CornerRadius="5" Background="#E0B020"/>
                </Border>
                <Grid Margin="0,10,0,0">
                    <TextBlock x:Name="StatusText" Text="Avvio..." FontSize="12" Foreground="#5F6773" HorizontalAlignment="Left"/>
                    <TextBlock x:Name="PctText" Text="0%" FontSize="12" FontWeight="Bold" Foreground="#33260A" HorizontalAlignment="Right"/>
                </Grid>
                <StackPanel x:Name="ChecklistPanel" Margin="0,18,0,0"/>
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
        $logoCtrl = $window.FindName('Logo')
        if (Test-Path $logoPath) {
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
    if (-not $Script:SyncHash.Ready) { throw 'La finestra di installazione non si e'' aperta in tempo.' }
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
        Set-InstallProgress -Percent 100 -Status 'Installazione completata'
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
# Passi dell'installazione
# ============================================================================

function Test-Prerequisites {
    if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Questo script va eseguito come amministratore.'
    }
    if ([System.Environment]::OSVersion.Platform -ne 'Win32NT') {
        throw 'Questo installer supporta solo Windows (vedi piano, Fase 3: scope limitato a Windows per ora).'
    }
}

function Disable-LegacyXamppServices {
    # Insidia #7 (piano, 2026-09-07): se la macchina ha (o ha avuto) XAMPP,
    # i suoi servizi Windows (Apache2.4, mysql) possono essere rimasti
    # StartType=Automatic anche dopo averli fermati dal pannello di
    # controllo - fermare il processo non cambia il tipo di avvio del
    # servizio. Scoperto con un riavvio reale: al boot successivo httpd.exe
    # ha vinto la porta 80 su frankenphp.exe (Windows non impedisce il
    # doppio bind se nessuno chiede l'esclusiva, ma solo uno riceve il
    # traffico). Va disabilitato esplicitamente l'avvio, non solo fermato.
    $legacyServices = @('Apache2.4', 'mysql')
    foreach ($name in $legacyServices) {
        $svc = Get-Service -Name $name -ErrorAction SilentlyContinue
        if (-not $svc) { continue }
        if ($svc.Status -ne 'Stopped') { Stop-Service -Name $name -Force }
        if ($svc.StartType -ne 'Disabled') { Set-Service -Name $name -StartupType Disabled }
    }
    Add-InstallChecklistItem 'Servizi XAMPP legacy verificati/disattivati'
}

function Install-VCRedist {
    # Scoperto testando su una VM davvero pulita (2026-09-07, mai emerso
    # prima perche' ogni macchina usata finora aveva gia' Visual Studio/altri
    # software che lo installano come effetto collaterale): le DLL delle
    # estensioni PHP (mysqli.dll, mbstring.dll, ecc.) dipendono dal Visual
    # C++ Redistributable. Senza, frankenphp.exe non fallisce con un errore
    # PHP leggibile - il processo intero non parte, exit code -1073741515
    # (0xC0000135, STATUS_DLL_NOT_FOUND di Windows), nessun output.
    if (Test-Path 'C:\Windows\System32\vcruntime140.dll') {
        Add-InstallChecklistItem 'Visual C++ Redistributable gia'' presente'
        return
    }
    $installerPath = "$env:TEMP\vc_redist.x64.exe"
    Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $installerPath
    Start-Process -FilePath $installerPath -ArgumentList '/install', '/quiet', '/norestart' -Wait
    Remove-Item $installerPath -Force -ErrorAction SilentlyContinue
    if (-not (Test-Path 'C:\Windows\System32\vcruntime140.dll')) {
        throw 'Installazione del Visual C++ Redistributable non riuscita.'
    }
    Add-InstallChecklistItem 'Visual C++ Redistributable installato'
}

function Install-FrankenPHP {
    if (Test-Path "$Script:FrankenDir\frankenphp.exe") {
        Add-InstallChecklistItem 'FrankenPHP gia'' presente'
        return
    }
    # Comando ufficiale, verificato in Fase 2 (docs, riga ~118)
    Invoke-Expression (Invoke-RestMethod 'https://frankenphp.dev/install.ps1')
    if (-not (Test-Path "$Script:FrankenDir\frankenphp.exe")) {
        throw 'Installazione di FrankenPHP non riuscita.'
    }
    Add-InstallChecklistItem 'FrankenPHP installato'
}

function Install-WinSW {
    # frankenphp-service.exe non e' un eseguibile di FrankenPHP: e' WinSW
    # (Windows Service Wrapper) scaricato a parte e rinominato, cosi' come
    # fatto a mano in Fase 2 - non arriva con l'installer ufficiale di
    # FrankenPHP, quindi va scaricato qui esplicitamente (bug reale trovato
    # testando su VM pulita, 2026-09-07: il pacchetto di release non lo
    # includeva e Register-FrankenPHPService falliva).
    $winswExe = "$Script:FrankenDir\frankenphp-service.exe"
    if (Test-Path $winswExe) {
        Add-InstallChecklistItem 'WinSW (frankenphp-service.exe) gia'' presente'
        return
    }
    Invoke-WebRequest -Uri 'https://github.com/winsw/winsw/releases/latest/download/WinSW-x64.exe' -OutFile $winswExe
    if (-not (Test-Path $winswExe)) {
        throw 'Download di WinSW (frankenphp-service.exe) non riuscito.'
    }
    Add-InstallChecklistItem 'WinSW (frankenphp-service.exe) scaricato'
}

function Set-PhpExtensions {
    $iniPath = "$Script:FrankenDir\php.ini"
    if (-not (Test-Path $iniPath)) {
        Copy-Item "$Script:FrankenDir\php.ini-production" $iniPath -Force
    }

    New-Item -ItemType Directory -Force -Path $Script:UploadTmpDir | Out-Null
    icacls $Script:UploadTmpDir /grant '*S-1-5-11:(OI)(CI)M' /grant '*S-1-5-18:(OI)(CI)F' | Out-Null

    # Bug reale trovato testando su VM pulita (2026-09-07): FrankenPHP crea
    # gia' da solo un php.ini con voci COMMENTATE (";extension=mysqli") come
    # suggerimento - un controllo ingenuo su una sottostringa le conta come
    # "gia' presenti" e salta l'aggiunta vera, lasciando tutte le estensioni
    # disattivate. Il controllo deve escludere esplicitamente le righe
    # commentate (regex multilinea ancorata all'inizio riga).
    $iniContent = Get-Content $iniPath -Raw
    if ($iniContent -notmatch '(?m)^\s*extension\s*=\s*mysqli\s*$') {
        Add-Content $iniPath @"

extension_dir = "ext"
extension=mysqli
extension=mbstring
extension=gd
extension=zip
extension=intl
extension=curl
extension=openssl
extension=fileinfo
memory_limit = 512M
upload_max_filesize = 40M
post_max_size = 40M
max_execution_time = 120
date.timezone = Europe/Rome
upload_tmp_dir = "$Script:UploadTmpDir"
display_errors = Off
log_errors = On
"@
    }

    # Gate di verifica - vedi Appendice A: -m non funziona su php-cli, si passa da uno script file
    $checkScript = "$env:TEMP\opensagra-check-php.php"
    "<?php echo implode(',', get_loaded_extensions());" | Out-File -FilePath $checkScript -Encoding ascii -Force
    $loaded = (& "$Script:FrankenDir\frankenphp.exe" php-cli $checkScript) -split ','
    $missing = $Script:RequiredExtensions | Where-Object { $loaded -notcontains $_ }
    Remove-Item $checkScript -Force -ErrorAction SilentlyContinue
    if ($missing) {
        throw "Estensioni PHP mancanti dopo la configurazione: $($missing -join ', ')"
    }
    Add-InstallChecklistItem 'Estensioni PHP verificate'
}

function Install-MariaDBEngine {
    if (Test-Path "$Script:MariaDbDir\bin\mariadbd.exe") {
        Add-InstallChecklistItem 'MariaDB gia'' presente'
        return
    }
    winget install --id MariaDB.Server -e --silent --accept-package-agreements --accept-source-agreements
    if (-not (Test-Path "$Script:MariaDbDir\bin\mariadbd.exe")) {
        throw 'Installazione di MariaDB non riuscita (percorso atteso non trovato - verificare la versione installata da winget).'
    }
    Add-InstallChecklistItem 'MariaDB installato'
}

function Register-MariaDBService {
    $existing = Get-Service -Name 'MariaDB' -ErrorAction SilentlyContinue
    if ($existing) {
        if ($existing.Status -ne 'Running') { Start-Service MariaDB }
        Add-InstallChecklistItem 'Servizio MariaDB gia'' registrato'
        return
    }
    Push-Location "$Script:MariaDbDir\bin"
    try {
        & .\mariadbd.exe --install MariaDB --defaults-file="$Script:MariaDbDir\data\my.ini"
        Start-Service MariaDB
        Set-Service MariaDB -StartupType Automatic
    } finally {
        Pop-Location
    }
    Add-InstallChecklistItem 'Servizio MariaDB registrato e avviato'
}

function Copy-AppFiles {
    New-Item -ItemType Directory -Force -Path $Script:InstallPath | Out-Null
    Get-ChildItem -Path $Script:SourcePath -Force | Where-Object {
        $Script:ExcludeFromCopy -notcontains $_.Name
    } | ForEach-Object {
        Copy-Item -Path $_.FullName -Destination $Script:InstallPath -Recurse -Force
    }
    Add-InstallChecklistItem 'File dell''app copiati'
}

function New-EnvFile {
    $envPath = Join-Path $Script:InstallPath 'config\variabili.env'
    if (Test-Path $envPath) {
        Add-InstallChecklistItem 'config/variabili.env gia'' presente, non sovrascritto'
        return
    }
    @'
DB_POS_HOST=127.0.0.1
DB_POS_USER=pos_own
DB_POS_PASS=pos_own1
'@ | Out-File -FilePath $envPath -Encoding ascii -Force
    Add-InstallChecklistItem 'config/variabili.env creato'
}

function New-CaddyConfig {
    $caddyPath = Join-Path $Script:InstallPath 'Caddyfile'
    @"
{
	frankenphp
	auto_https disable_redirects
}

http://:80 {
	root * $Script:InstallPath
	encode zstd gzip
	php_server
}

https://localhost {
	root * $Script:InstallPath
	encode zstd gzip
	php_server
	tls internal
}
"@ | Out-File -FilePath $caddyPath -Encoding ascii -Force
    Add-InstallChecklistItem 'Caddyfile generato'
}

function Invoke-DatabaseProvisioning {
    $script = Join-Path $Script:InstallPath 'config\crea_dbtable_and_user.php'
    & "$Script:FrankenDir\frankenphp.exe" php-cli $script
    if ($LASTEXITCODE -ne 0) {
        throw 'Provisioning del database fallito (vedi output sopra).'
    }
    Add-InstallChecklistItem 'Database creato/verificato'
}

function Invoke-Migrations {
    $migrationsDir = Join-Path $Script:InstallPath 'config\migrations'
    if (-not (Test-Path $migrationsDir)) { return }
    Get-ChildItem -Path $migrationsDir -Filter '*.php' | Sort-Object Name | ForEach-Object {
        & "$Script:FrankenDir\frankenphp.exe" php-cli $_.FullName
        if ($LASTEXITCODE -ne 0) {
            throw "Migrazione fallita: $($_.Name)"
        }
    }
    Add-InstallChecklistItem 'Migrazioni database applicate'
}

function Register-FrankenPHPService {
    $existing = Get-Service -Name 'frankenphp' -ErrorAction SilentlyContinue
    if ($existing) {
        if ($existing.Status -ne 'Running') { Start-Service frankenphp }
        Add-InstallChecklistItem 'Servizio FrankenPHP gia'' registrato'
        return
    }
    $winswExe = "$Script:FrankenDir\frankenphp-service.exe"
    $winswXml = "$Script:FrankenDir\frankenphp-service.xml"
    if (-not (Test-Path $winswExe)) {
        throw 'WinSW (frankenphp-service.exe) non trovato - va incluso nel pacchetto di release.'
    }
    @"
<service>
  <id>frankenphp</id>
  <name>FrankenPHP OpenSagra</name>
  <description>Server FrankenPHP per l'app OpenSagra</description>
  <executable>%BASE%\frankenphp.exe</executable>
  <arguments>run --config "$Script:InstallPath\Caddyfile"</arguments>
  <workingdirectory>$Script:InstallPath</workingdirectory>
  <log mode="roll-by-time">
    <pattern>yyyy-MM-dd</pattern>
  </log>
</service>
"@ | Out-File -FilePath $winswXml -Encoding utf8 -Force

    Push-Location $Script:FrankenDir
    try {
        & .\frankenphp-service.exe install
        & .\frankenphp-service.exe start
    } finally {
        Pop-Location
    }
    Set-Service frankenphp -StartupType Automatic
    Add-InstallChecklistItem 'Servizio FrankenPHP registrato e avviato'
}

function Set-FirewallRules {
    # Regole per-porta esplicite, NON legate al programma: le regole
    # auto-generate da FrankenPHP/MariaDB non sono affidabili su rete
    # Pubblica per un processo eseguito come servizio Windows - vedi
    # Insidia #6 nel piano, verificato con un dispositivo davvero esterno.
    # -LocalPort vuole un array di porte, non una stringa unica con virgole
    # (bug reale trovato testando su VM pulita, 2026-09-07: "80,443" come
    # stringa singola veniva rifiutato con "The port is invalid").
    $rules = @(
        @{ Name = 'opensagra HTTP/HTTPS'; Ports = @('80', '443') }
        @{ Name = 'opensagra MariaDB'; Ports = @('3306') }
    )
    foreach ($rule in $rules) {
        $existing = Get-NetFirewallRule -DisplayName $rule.Name -ErrorAction SilentlyContinue
        if ($existing) { continue }
        New-NetFirewallRule -DisplayName $rule.Name -Direction Inbound -Protocol TCP `
            -LocalPort $rule.Ports -Action Allow -Profile Domain, Private, Public | Out-Null
    }
    Add-InstallChecklistItem 'Regole firewall configurate'
}

function Install-QZTray {
    # Installato sempre, nessuna domanda (deciso 2026-09-07: "deve funzionare
    # tutto subito, chi non ne ha bisogno disinstalla QZ Tray come un
    # programma qualsiasi" - vedi piano, sezione 3a).
    $qzInstalled = Get-ItemProperty 'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\*' -ErrorAction SilentlyContinue |
        Where-Object { $_.DisplayName -like 'QZ Tray*' }
    if (-not $qzInstalled) {
        # Bug reale trovato testando su VM pulita (2026-09-07): download.qz.io
        # non esiste piu' (DNS inesistente) - QZ Tray si distribuisce solo via
        # GitHub Releases, con nome file versionato (es.
        # qz-tray-2.2.6-x86_64.exe), quindi non si puo' linkare un URL fisso:
        # va risolta la release piu' recente tramite l'API GitHub.
        $release = Invoke-RestMethod -Uri 'https://api.github.com/repos/qzind/tray/releases/latest' -Headers @{ 'User-Agent' = 'opensagra-installer' }
        $asset = $release.assets | Where-Object { $_.name -match 'x86_64\.exe$' } | Select-Object -First 1
        if (-not $asset) {
            throw "Impossibile trovare l'installer Windows di QZ Tray nell'ultima release GitHub."
        }
        $installerPath = "$env:TEMP\qz-tray-setup.exe"
        Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $installerPath
        Start-Process -FilePath $installerPath -ArgumentList '/S' -Wait
        Remove-Item $installerPath -Force -ErrorAction SilentlyContinue
    }

    # Posiziona (una tantum, idempotente) la chiave privata di firma per QZ
    # Tray, copiandola dal pacchetto di installazione - vedi
    # config/installa_certificati_qz.php per il perche' (stessa coppia
    # chiave/certificato per ogni installazione, non una generata per
    # macchina: due installazioni con certificati diversi che parlano con lo
    # stesso QZ Tray fisico non si fiderebbero a vicenda senza un passo di
    # sync manuale - bug pratico trovato testando con una VM + la macchina
    # reale nello stesso scenario). La chiave va bundlata a parte nel
    # pacchetto, in una cartella 'private\key.pem' accanto a questo stesso
    # script (mai in git).
    $bundledKeyPath = Join-Path $Script:SourcePath 'private\key.pem'
    & "$Script:FrankenDir\frankenphp.exe" php-cli (Join-Path $Script:InstallPath 'config\installa_certificati_qz.php') --source-key="$bundledKeyPath"
    if ($LASTEXITCODE -ne 0) {
        throw 'Posizionamento della chiave privata QZ Tray fallito (vedi output sopra).'
    }

    # Procedura certificati: override del certificato di firma per sopprimere
    # i popup di consenso. Percorso corretto (verificato nel codice sorgente
    # di QZ Tray, classe qz.auth.Certificate: SystemUtilities.getJarParentPath()
    # + Constants.OVERRIDE_CERT): la cartella di installazione di QZ Tray
    # stessa, NON %APPDATA%\qz - quest'ultima e' solo dove QZ Tray tiene il
    # proprio stato (allowed.dat/blocked.dat/log), il file li' viene ignorato
    # (bug reale di questa sessione: usato quel percorso sbagliato inizialmente,
    # il popup di conferma continuava a comparire nonostante override.crt
    # fosse presente e corretto - solo nel posto sbagliato).
    $qzInstallDir = 'C:\Program Files\QZ Tray'
    $certSource = Join-Path $Script:InstallPath 'cert\cert.pem'
    if (Test-Path $certSource) {
        Copy-Item $certSource (Join-Path $qzInstallDir 'override.crt') -Force

        # QZ Tray si avvia gia' da solo dopo l'installazione silenziosa: se e'
        # gia' in esecuzione, ha in memoria lo stato precedente (senza
        # override) e va riavviato per ricaricare il certificato appena
        # copiato.
        $qzProcess = Get-Process -Name 'javaw' -ErrorAction SilentlyContinue | Where-Object { $_.Path -like '*QZ Tray*' }
        if ($qzProcess) {
            $qzProcess | Stop-Process -Force
            Start-Sleep -Seconds 1
        }
        $qzExe = Join-Path $qzInstallDir 'qz-tray.exe'
        if (Test-Path $qzExe) {
            Start-Process -FilePath $qzExe
        }
    }
    Add-InstallChecklistItem 'QZ Tray installato'
}

# ============================================================================
# Orchestrazione
# ============================================================================

try {
    Test-Prerequisites
    Show-InstallWindow

    Set-InstallProgress -Percent 3 -Status 'Verifica di eventuali installazioni precedenti...'
    Disable-LegacyXamppServices

    Set-InstallProgress -Percent 5 -Status 'Installazione di FrankenPHP...'
    Install-FrankenPHP
    Install-WinSW

    Set-InstallProgress -Percent 10 -Status 'Installazione dei componenti runtime...'
    Install-VCRedist

    Set-InstallProgress -Percent 15 -Status 'Configurazione delle estensioni PHP...'
    Set-PhpExtensions

    Set-InstallProgress -Percent 25 -Status 'Installazione di MariaDB...'
    Install-MariaDBEngine

    Set-InstallProgress -Percent 35 -Status 'Registrazione del servizio database...'
    Register-MariaDBService

    Set-InstallProgress -Percent 45 -Status 'Copia dei file dell''app...'
    Copy-AppFiles
    New-EnvFile
    New-CaddyConfig

    Set-InstallProgress -Percent 60 -Status 'Configurazione del database...'
    Invoke-DatabaseProvisioning
    Invoke-Migrations

    Set-InstallProgress -Percent 75 -Status 'Registrazione dei servizi...'
    Register-FrankenPHPService

    Set-InstallProgress -Percent 85 -Status 'Configurazione delle regole di rete...'
    Set-FirewallRules

    Set-InstallProgress -Percent 95 -Status 'Installazione di QZ Tray...'
    Install-QZTray

    Close-InstallWindow -Success
} catch {
    Write-Error $_
    Close-InstallWindow -ErrorMessage $_.Exception.Message
    exit 1
}
