#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Installer OpenSagra per Windows - Fase 3.

.DESCRIPTION
    Installazione completamente automatica, senza domande: porta una macchina
    pulita ad app funzionante (FrankenPHP + MariaDB nativa), sempre con la
    stessa identica configurazione "indipendente". Se una postazione
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
    MariaDBEngine, Register-MariaDBService sono scritti secondo i comandi gia'
    documentati e provati in questo piano, ma su QUESTA macchina (gia'
    completamente configurata) imboccano sempre il ramo "gia' presente, salto"
    - il ramo di installazione da zero non e' verificabile end-to-end senza una
    macchina pulita o una VM. Vanno ritestati per intero su una macchina
    davvero vergine prima del rilascio.

    2026-09-09: FrankenPHP non e' piu' un servizio WinSW. Lo avvia e sorveglia
    il wrapper/tray-app (Install-Wrapper copia l'exe, Start-Wrapper lo lancia
    de-elevato: e' lui a scriversi l'autostart in HKCU\...\Run). Il wrapper va
    COMPILATO e incluso nel pacchetto di release come
    wrapper\opensagra-wrapper.exe. MariaDB resta un servizio.
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
    # Sorgente Go del wrapper: nella webroot non serve. L'eseguibile gia'
    # compilato (wrapper\opensagra-wrapper.exe) lo copia Install-Wrapper.
    'wrapper',
    # Materiale sensibile bundlato accanto a install.ps1: mai dentro la webroot.
    'private'
)

# Eseguibile del wrapper/tray-app: precompilato nel pacchetto di release
# (`cd wrapper; go build -ldflags "-H=windowsgui" -o opensagra-wrapper.exe ./...`).
# L'autostart lo scrive il wrapper stesso (chiave HKCU\...\Run), non l'installer.
$Script:WrapperExeName = 'opensagra-wrapper.exe'

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
    #
    # `frankenphp`: su un'installazione OpenSagra precedente era un servizio
    # WinSW. Ora lo gestisce il wrapper; il servizio va fermato e disabilitato,
    # altrimenti si contende con il figlio del wrapper la porta admin 2019
    # (e 80/443) e il wrapper mostra FrankenPHP "in errore".
    $legacyServices = @('Apache2.4', 'mysql', 'frankenphp')
    foreach ($name in $legacyServices) {
        $svc = Get-Service -Name $name -ErrorAction SilentlyContinue
        if (-not $svc) { continue }
        if ($svc.Status -ne 'Stopped') { Stop-Service -Name $name -Force }
        if ($svc.StartType -ne 'Disabled') { Set-Service -Name $name -StartupType Disabled }
    }
    Add-InstallChecklistItem 'Servizi legacy (XAMPP / servizio FrankenPHP) verificati/disattivati'
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

# NOTA: Install-WinSW e Register-FrankenPHPService sono state RIMOSSE con la
# revisione del modello servizi del 2026-09-08 (piano sez. 3g). FrankenPHP non
# e' piu' un servizio Windows: lo avvia e sorveglia il wrapper/tray-app
# (Install-Wrapper + Register-WrapperAutostart, sotto). Il codice WinSW resta
# nella storia git (fino al commit che ha introdotto questa nota) come
# riferimento. MariaDB resta un servizio, installato dall'MSI.

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

function Get-OrNewMercureSecret {
    # Riusa il segreto gia' in variabili.env se il file c'e' (rilancio
    # idempotente: Caddyfile e riga app_config restano allineati a quello).
    # Altrimenti ne genera uno nuovo - 256 bit esadecimali, cosi' non ha
    # caratteri da quotare nel Caddyfile.
    $envPath = Join-Path $Script:InstallPath 'config\variabili.env'
    if (Test-Path $envPath) {
        $m = Select-String -Path $envPath -Pattern '^\s*MERCURE_JWT_SECRET\s*=\s*(\S+)' |
            Select-Object -First 1
        if ($m) { return $m.Matches[0].Groups[1].Value }
    }
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function New-EnvFile {
    param([Parameter(Mandatory)][string]$MercureSecret)

    $envPath = Join-Path $Script:InstallPath 'config\variabili.env'

    if (-not (Test-Path $envPath)) {
        @"
DB_POS_HOST=127.0.0.1
DB_POS_USER=pos_own
DB_POS_PASS=pos_own1

# Realtime Mercure (Fase 4). Lo stesso valore va nel blocco mercure{} del
# Caddyfile e nella riga app_config del DB - li allinea tutti l'installer.
MERCURE_JWT_SECRET=$MercureSecret
# Lo scrive la pagina Rete al passaggio a client (segreto dell'hub del
# server). Vuoto su server / installazione indipendente.
MERCURE_JWT_SECRET_REMOTE=
# PC-ponte: id-topic di stampa serviti da questo PC, separati da virgola.
PRINT_BRIDGE_CASSE=
"@ | Out-File -FilePath $envPath -Encoding ascii -Force
        Add-InstallChecklistItem 'config/variabili.env creato'
        return
    }

    # File gia' presente: non si toccano le credenziali, ma si aggiungono le
    # chiavi di Fase 4 mancanti (rilancio idempotente su installazione vecchia).
    $lines = @(Get-Content $envPath)
    $joined = $lines -join "`n"
    $added = @()

    if ($joined -notmatch '(?m)^\s*MERCURE_JWT_SECRET\s*=\s*\S') {
        $lines = @($lines | Where-Object { $_ -notmatch '^\s*MERCURE_JWT_SECRET\s*=' })
        $lines += "MERCURE_JWT_SECRET=$MercureSecret"
        $added += 'MERCURE_JWT_SECRET'
    }
    if ($joined -notmatch '(?m)^\s*MERCURE_JWT_SECRET_REMOTE\s*=') {
        $lines += 'MERCURE_JWT_SECRET_REMOTE='
        $added += 'MERCURE_JWT_SECRET_REMOTE'
    }
    if ($joined -notmatch '(?m)^\s*PRINT_BRIDGE_CASSE\s*=') {
        $lines += 'PRINT_BRIDGE_CASSE='
        $added += 'PRINT_BRIDGE_CASSE'
    }

    if ($added.Count -gt 0) {
        $lines | Out-File -FilePath $envPath -Encoding ascii -Force
        Add-InstallChecklistItem ('config/variabili.env aggiornato (' + ($added -join ', ') + ')')
    } else {
        Add-InstallChecklistItem 'config/variabili.env gia'' completo, non toccato'
    }
}

function New-CaddyConfig {
    param([Parameter(Mandatory)][string]$MercureSecret)

    # Bug reale trovato in fase di doc-review (2026-09-07): il blocco HTTPS
    # elencava solo "https://localhost" - mai verificato con l'IP di rete
    # effettivo sull'installazione REALMENTE generata dall'installer (i test
    # precedenti erano contro il Caddyfile di sviluppo, che elenca gia' un IP
    # a mano). Stesso rilevamento IP di detectLocalLanIp() in config/local_ip.php
    # (Insidia #8): l'adattatore con un gateway di default e' quello reale.
    $lanIp = $null
    try {
        $lanIp = (Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -ne $null } |
            Select-Object -First 1 -ExpandProperty IPv4Address | Select-Object -ExpandProperty IPAddress)
    } catch {
        $lanIp = $null
    }
    $hostList = @('localhost')
    if ($lanIp) { $hostList += $lanIp }

    $httpsHosts = ($hostList | ForEach-Object { "https://$_" }) -join ', '
    $httpCors = ($hostList | ForEach-Object { "http://$_" }) -join ' '
    $httpsCors = ($hostList | ForEach-Object { "https://$_" }) -join ' '

    # Hub Mercure (Fase 4) dentro ogni blocco di sito - NON a livello globale
    # (Caddy: "must appear in a site block"). Stesso segreto di variabili.env.
    # cookie_name: obbligatorio, senza l'hub rifiuta con 401 il cookie
    # mercure_authorization, l'unico modo in cui EventSource nel browser
    # autentica. cors_origins: ogni hostname da cui e' servito billing.php.
    # heartbeat: tiene viva una connessione SSE ferma (i sottoscrittori CLI la
    # abortiscono a 45s, l'EventSource del browser flappa).
    $httpMercure = @"
	mercure {
		publisher_jwt $MercureSecret
		subscriber_jwt $MercureSecret
		cookie_name mercure_authorization
		cors_origins $httpCors
		heartbeat 20s
	}
"@
    $httpsMercure = @"
	mercure {
		publisher_jwt $MercureSecret
		subscriber_jwt $MercureSecret
		cookie_name mercure_authorization
		cors_origins $httpsCors
		heartbeat 20s
	}
"@

    # /db -> AdminNeo (tools\adminer.php), SOLO da localhost: dal PC-server si
    # usa il gestore DB, da una cassa in LAN si becca un 403. Identico nei due
    # blocchi di sito. `handle` (non handle_path) + `rewrite * /adminer.php`:
    # AdminNeo e' un file solo, non gli serve il prefisso strippato, e
    # handle_path accetta un solo pattern (non "/db /db/*").
    $dbRoute = @"
	@dbpath path /db /db/*
	@dblocal {
		path /db /db/*
		remote_ip 127.0.0.1 ::1
	}
	handle @dblocal {
		rewrite * /adminer.php
		root * $Script:InstallPath\tools
		php_server
	}
	handle @dbpath {
		respond "Il gestore DB e' raggiungibile solo dal PC server." 403
	}
"@

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

$dbRoute
$httpMercure
}

$httpsHosts {
	root * $Script:InstallPath
	encode zstd gzip
	php_server
	tls internal

$dbRoute
$httpsMercure
}
"@ | Out-File -FilePath $caddyPath -Encoding ascii -Force
    Add-InstallChecklistItem 'Caddyfile generato (Mercure + gestore DB /db)'
}

function Invoke-DatabaseProvisioning {
    $script = Join-Path $Script:InstallPath 'config\crea_dbtable_and_user.php'
    & "$Script:FrankenDir\frankenphp.exe" php-cli $script
    if ($LASTEXITCODE -ne 0) {
        throw 'Provisioning del database fallito (vedi output sopra).'
    }
    Add-InstallChecklistItem 'Database creato/verificato'
}

function Set-MercureSecretInDb {
    # Scrive MERCURE_JWT_SECRET nella tabella app_config del DB. Evita il
    # chicken-egg: un server nuovo altrimenti semina la riga solo alla prima
    # modifica prodotti (seedServerMercureSecret) o al primo giro in conf_rete,
    # e un client che si aggancia prima di allora non trova il segreto da
    # sincronizzare. Da lanciare DOPO il provisioning (DB + utente pos_own +
    # tabelle esistono; app_config si autocrea via ensureAppConfigTable).
    param([Parameter(Mandatory)][string]$MercureSecret)

    $seedScript = Join-Path $env:TEMP 'opensagra-seed-mercure.php'
    @"
<?php
require '$($Script:InstallPath)\config\get_db_connection.php';   // -> `$connectionDB
require '$($Script:InstallPath)\config\app_config.php';
`$secret = getenv('OPENSAGRA_MERCURE_SECRET');
if (!is_string(`$secret) || `$secret === '') { fwrite(STDERR, "segreto mancante nell'ambiente\n"); exit(1); }
`$ok = setAppConfig(`$connectionDB, 'MERCURE_JWT_SECRET', `$secret);
fwrite(`$ok ? STDOUT : STDERR, (`$ok ? 'app_config.MERCURE_JWT_SECRET scritto' : 'scrittura fallita') . "\n");
exit(`$ok ? 0 : 1);
"@ | Out-File -FilePath $seedScript -Encoding ascii -Force

    $env:OPENSAGRA_MERCURE_SECRET = $MercureSecret
    try {
        & "$Script:FrankenDir\frankenphp.exe" php-cli $seedScript
        $code = $LASTEXITCODE
    } finally {
        Remove-Item Env:\OPENSAGRA_MERCURE_SECRET -ErrorAction SilentlyContinue
        Remove-Item $seedScript -Force -ErrorAction SilentlyContinue
    }
    if ($code -ne 0) {
        throw 'Scrittura di MERCURE_JWT_SECRET in app_config fallita (vedi output sopra).'
    }
    Add-InstallChecklistItem 'Segreto Mercure salvato in app_config (per i client)'
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

function Install-Wrapper {
    # Copia l'eseguibile del wrapper/tray-app (gia' compilato e incluso nel
    # pacchetto di release) accanto all'app. Supervisiona FrankenPHP + relay +
    # snapshot + bridge di stampa come processi figli - sostituisce il servizio
    # WinSW di FrankenPHP (piano sez. 3g). Icona e pagina di stato sono
    # incorporate nell'exe (go:embed), non servono file accanto.
    $src = Join-Path $Script:SourcePath "wrapper\$Script:WrapperExeName"
    if (-not (Test-Path $src)) {
        throw "Wrapper non trovato ($src). Va compilato PRIMA di impacchettare la release: .\wrapper\build.ps1 (macchina di sviluppo, richiede Go - vedi wrapper\README.md)."
    }
    Copy-Item $src (Join-Path $Script:InstallPath $Script:WrapperExeName) -Force
    Add-InstallChecklistItem 'Wrapper/tray-app copiato'
}

function Start-Wrapper {
    # Avvia il wrapper SUBITO e nel contesto NON elevato dell'utente
    # interattivo (l'installer gira elevato: Start-Process erediterebbe il
    # token admin, e con quello FrankenPHP tornerebbe ad avere il problema ACL
    # di LocalSystem; inoltre l'autostart va scritto nell'HKCU giusto).
    #
    # Meccanismo: un task pianificato una-tantum con principal INTERACTIVE
    # (S-1-5-4), RunLevel Limited. Register-ScheduledTask qui riesce perche'
    # l'installer E' elevato. Il wrapper, lanciato dal task, gira come utente
    # normale e:
    #   -autostarted        -> non apre la finestra di stato durante l'install
    #   -register-autostart -> scrive da se' la chiave HKCU\...\Run (il modo in
    #                          cui gestisce l'autostart: un task at-logon non
    #                          elevato darebbe "Accesso negato")
    $exe = Join-Path $Script:InstallPath $Script:WrapperExeName
    $firstRun = 'OpenSagra-FirstRun'
    try {
        $action = New-ScheduledTaskAction -Execute $exe -Argument '-autostarted -register-autostart'
        $principal = New-ScheduledTaskPrincipal -GroupId 'S-1-5-4' -RunLevel Limited
        Register-ScheduledTask -TaskName $firstRun -Action $action -Principal $principal -Force | Out-Null
        Start-ScheduledTask -TaskName $firstRun
        Start-Sleep -Seconds 3
        Unregister-ScheduledTask -TaskName $firstRun -Confirm:$false -ErrorAction SilentlyContinue
        Add-InstallChecklistItem 'OpenSagra avviato'
    } catch {
        Unregister-ScheduledTask -TaskName $firstRun -Confirm:$false -ErrorAction SilentlyContinue
        Add-InstallChecklistItem 'OpenSagra: avvialo dal menu Start' 'error'
    }
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
    $mercureSecret = Get-OrNewMercureSecret
    New-EnvFile -MercureSecret $mercureSecret
    New-CaddyConfig -MercureSecret $mercureSecret

    Set-InstallProgress -Percent 60 -Status 'Configurazione del database...'
    Invoke-DatabaseProvisioning
    Invoke-Migrations
    Set-MercureSecretInDb -MercureSecret $mercureSecret

    Set-InstallProgress -Percent 75 -Status 'Installazione del pannello OpenSagra...'
    Install-Wrapper

    Set-InstallProgress -Percent 90 -Status 'Configurazione delle regole di rete...'
    Set-FirewallRules

    Set-InstallProgress -Percent 97 -Status 'Avvio di OpenSagra...'
    Start-Wrapper

    Close-InstallWindow -Success
} catch {
    Write-Error $_
    Close-InstallWindow -ErrorMessage $_.Exception.Message
    exit 1
}
