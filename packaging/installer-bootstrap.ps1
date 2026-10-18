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

# Finestrella "Avvio dell'installazione..." dal doppio click fino alla finestra
# di install.ps1: su un PC lento o con l'antivirus al lavoro passano decine di
# secondi (scompattare, poi PowerShell che legge install.ps1 e carica WPF mentre
# Defender analizza i file appena estratti - misurato sulla VM: ~7 s senza nulla
# a video fra le due), e senza nulla a video l'utente rilancia l'exe.
# Ricalca la parte alta della finestra di install.ps1 (stessa larghezza,
# stessa posizione, stessi colori, margini e font del XAML di Show-InstallWindow)
# con una barra che scorre al posto della percentuale: il passaggio da una
# all'altra sembra la stessa finestra che si allunga. WPF come install.ps1:
# bordi arrotondati e ombra disegnati da WPF, uguali su ogni PC (con WinForms
# gli angoli li arrotondava Windows 11, e su una VM senza scheda video no).
# Gira su un thread suo (STA, di sottofondo, con ShowDialog), cosi' resta viva
# mentre questo thread scompatta e poi esegue install.ps1; si chiude da sola
# appena il processo mostra un'altra finestra (quella di install.ps1 o un
# messaggio di errore), quando questo script lo chiede, o dopo 3 minuti.
Add-Type -Namespace OpenSagraSplash -Name Native -MemberDefinition @'
private delegate bool EnumProc(System.IntPtr h, System.IntPtr l);
[System.Runtime.InteropServices.DllImport("user32.dll")]
private static extern bool EnumWindows(EnumProc f, System.IntPtr l);
[System.Runtime.InteropServices.DllImport("user32.dll")]
private static extern bool IsWindowVisible(System.IntPtr h);
[System.Runtime.InteropServices.DllImport("user32.dll")]
private static extern uint GetWindowThreadProcessId(System.IntPtr h, out uint pid);
[System.Runtime.InteropServices.DllImport("user32.dll")]
private static extern int GetWindowTextLength(System.IntPtr h);
// Finestre visibili con titolo di questo processo, esclusa 'self'.
public static long[] OtherVisibleWindows(System.IntPtr self) {
    uint me = (uint)System.Diagnostics.Process.GetCurrentProcess().Id;
    var found = new System.Collections.Generic.List<long>();
    EnumWindows(delegate (System.IntPtr h, System.IntPtr l) {
        uint pid;
        GetWindowThreadProcessId(h, out pid);
        if (pid == me && h != self && IsWindowVisible(h) && GetWindowTextLength(h) > 0) { found.Add(h.ToInt64()); }
        return true;
    }, System.IntPtr.Zero);
    return found.ToArray();
}
'@

$splashScript = {
    param($sync)
    Add-Type -AssemblyName PresentationFramework, PresentationCore, WindowsBase, System.Xaml, System.IO.Compression.FileSystem

    # Stesso XAML della parte alta di Show-InstallWindow in install.ps1 (bordo
    # arrotondato 16 + ombra disegnati da WPF, quindi uguali su ogni PC), con
    # una barra che scorre al posto di percentuale ed elenco dei passi.
    $xaml = @'
<Window xmlns="http://schemas.microsoft.com/winfx/2006/xaml/presentation"
        xmlns:x="http://schemas.microsoft.com/winfx/2006/xaml"
        Title="Installazione OpenSagra" Width="460" Height="180"
        WindowStyle="None" AllowsTransparency="True" Background="Transparent"
        WindowStartupLocation="Manual" Topmost="True" ResizeMode="NoResize" ShowInTaskbar="True">
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
                        <TextBlock Text="Preparo i file dell'installazione, un momento" FontSize="11" Foreground="#5F6773"/>
                    </StackPanel>
                </StackPanel>
                <Grid Height="10">
                    <Grid.Clip><RectangleGeometry Rect="0,0,404,10" RadiusX="5" RadiusY="5"/></Grid.Clip>
                    <Border CornerRadius="5" Background="#F3F4F6"/>
                    <Border x:Name="Runner" HorizontalAlignment="Left" Width="120" CornerRadius="5" Background="#E0B020">
                        <Border.RenderTransform><TranslateTransform x:Name="RunnerX" X="-120"/></Border.RenderTransform>
                    </Border>
                </Grid>
                <TextBlock Margin="0,10,0,0" Text="Avvio..." FontSize="12" Foreground="#5F6773"/>
            </StackPanel>
        </Grid>
    </Border>
</Window>
'@
    $window = [Windows.Markup.XamlReader]::Parse($xaml)

    # In alto allineata alla finestra di install.ps1 (460x360 al centro dell'area
    # di lavoro): quando arriva, la barra gialla resta dov'e' e la finestra si allunga.
    $wa = [System.Windows.SystemParameters]::WorkArea
    $window.Left = $wa.Left + ($wa.Width - 460) / 2
    $window.Top = $wa.Top + ($wa.Height - 360) / 2

    # Il logo e' dentro il payload, non ancora estratto: lo si legge dallo zip.
    try {
        $z = [System.IO.Compression.ZipFile]::OpenRead($sync.Zip)
        $entry = $z.GetEntry('assets/logo.png')
        if ($entry) {
            $ms = New-Object System.IO.MemoryStream
            $s = $entry.Open(); $s.CopyTo($ms); $s.Dispose(); $ms.Position = 0
            $bmp = New-Object System.Windows.Media.Imaging.BitmapImage
            $bmp.BeginInit(); $bmp.CacheOption = 'OnLoad'; $bmp.StreamSource = $ms; $bmp.EndInit()
            $window.FindName('Logo').Source = $bmp
        }
        $z.Dispose()
    } catch { }

    # Barra che scorre da sinistra a destra, all'infinito.
    $anim = New-Object System.Windows.Media.Animation.DoubleAnimation(-120, 404, [TimeSpan]::FromMilliseconds(1400))
    $anim.RepeatBehavior = [System.Windows.Media.Animation.RepeatBehavior]::Forever
    $window.FindName('RunnerX').BeginAnimation([System.Windows.Media.TranslateTransform]::XProperty, $anim)

    $state = @{ Before = $null; OtherSince = $null; Started = Get-Date }
    $timer = New-Object System.Windows.Threading.DispatcherTimer
    $timer.Interval = [TimeSpan]::FromMilliseconds(100)
    $timer.Add_Tick({
        try {
            $self = (New-Object System.Windows.Interop.WindowInteropHelper $window).Handle
            # Contano solo le finestre comparse dopo la finestrella: quella di
            # install.ps1 o un messaggio di errore. Ancora 300 ms perche' abbia
            # finito di disegnarsi, poi si chiude.
            $wins = [OpenSagraSplash.Native]::OtherVisibleWindows($self)
            if ($null -eq $state.Before) { $state.Before = @($wins) }
            elseif (-not $state.OtherSince -and @($wins | Where-Object { $state.Before -notcontains $_ }).Count -gt 0) { $state.OtherSince = Get-Date }
            $other = $state.OtherSince -and ((Get-Date) - $state.OtherSince).TotalMilliseconds -ge 300
            if ($sync.Close -or $other -or ((Get-Date) - $state.Started).TotalMinutes -ge 3) {
                $timer.Stop()
                $window.Close()
            }
        } catch { }
    }.GetNewClosure())
    $window.Add_ContentRendered({ $sync.Shown = $true }.GetNewClosure())
    # Un errore di disegno non deve mai diventare un dialogo di errore.
    $window.Dispatcher.Add_UnhandledException({ param($s, $e) $e.Handled = $true })

    $timer.Start()
    try { [void]$window.ShowDialog() } catch { }
    $sync.Done = $true
}

# La finestrella gira su un thread di SOTTOFONDO (IsBackground): quando il
# processo finisce - anche con l'"exit" di install.ps1, che non torna mai qui -
# Windows lo chiude con lui. Bug reale (VM, 2026-10-18): con un runspace
# PowerShell normale (thread non di sottofondo, ReuseThread) l'exe ps2exe
# restava vivo, invisibile, dopo la fine dell'installazione.
Add-Type -ReferencedAssemblies ([psobject].Assembly.Location) -TypeDefinition @'
using System.Management.Automation;
using System.Management.Automation.Runspaces;
using System.Threading;
namespace OpenSagraSplash {
    public static class Runner {
        public static void StartBackground(string script, object arg) {
            var t = new Thread(() => {
                try {
                    var rs = RunspaceFactory.CreateRunspace();
                    rs.ApartmentState = ApartmentState.STA;
                    rs.ThreadOptions = PSThreadOptions.UseCurrentThread;
                    rs.Open();
                    using (var ps = PowerShell.Create()) {
                        ps.Runspace = rs;
                        ps.AddScript(script).AddArgument(arg);
                        ps.Invoke();
                    }
                    rs.Close();
                } catch { }
            });
            t.IsBackground = true;
            t.SetApartmentState(ApartmentState.STA);
            t.Start();
        }
    }
}
'@

function Start-StartupSplash([string]$PayloadZip) {
    $sync = [hashtable]::Synchronized(@{ Zip = $PayloadZip; Close = $false; Shown = $false; Done = $false })
    [OpenSagraSplash.Runner]::StartBackground($splashScript.ToString(), $sync)
    # Si aspetta che sia a video (al massimo 3 s), poi si scompatta.
    $t = (Get-Date).AddSeconds(3)
    while (-not $sync.Shown -and (Get-Date) -lt $t) { Start-Sleep -Milliseconds 30 }
    return $sync
}

function Stop-StartupSplash($sync) {
    if (-not $sync) { return }
    $sync.Close = $true
    $t = (Get-Date).AddSeconds(2)
    while (-not $sync.Done -and (Get-Date) -lt $t) { Start-Sleep -Milliseconds 30 }
}

$splash = $null
try {
    if (Test-OpenSagraRunning) {
        Remove-Item $zipPath -Force -ErrorAction SilentlyContinue
        [System.Windows.Forms.MessageBox]::Show(
            "OpenSagra è aperto su questo PC.`n`nChiudilo (icona OpenSagra vicino all'orologio > Esci) e rilancia l'installazione. Vendite, catalogo e impostazioni restano come sono.",
            'Installazione OpenSagra',
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Warning) | Out-Null
        exit 1
    }

    $splash = Start-StartupSplash $zipPath

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    [System.IO.Compression.ZipFile]::ExtractToDirectory($zipPath, $dest)
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

    # La finestrella resta aperta: si chiude da sola quando compare la finestra
    # di install.ps1 (o un suo messaggio di errore).
    # Nota: install.ps1 chiama "exit 1" nel suo blocco catch, non lancia
    # un'eccezione .NET - se fallisce il PROCESSO finisce li' (exit termina
    # sempre il processo, anche invocato con &), $dest resta per il
    # post-mortem. Se va bene, si ricade qui sotto e si ripulisce.
    & $installScript

    Stop-StartupSplash $splash
    Remove-Item $dest -Recurse -Force -ErrorAction SilentlyContinue
    exit 0
} catch {
    Stop-StartupSplash $splash
    # -noConsole: nessuna finestra per vedere l'errore. Lo lascio su file (e
    # NON cancello $dest, utile per capire cos'e' andato storto).
    $_ | Out-File (Join-Path $env:TEMP 'opensagra-installer-error.log') -Append
    exit 1
}
