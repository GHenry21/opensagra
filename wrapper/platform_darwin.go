//go:build darwin

package main

// ATTENZIONE: nessun hardware macOS disponibile per verificare questo file -
// scritto seguendo Appendice E di docs/PIANO-MIGRAZIONE-FRANKENPHP.md e la
// documentazione ufficiale di launchd/osascript. Da trattare come beta non
// verificata finche' qualcuno non lo prova su un Mac vero (vedi piano, §4).

import (
	"bytes"
	"encoding/xml"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
)

// hideWindow: Setpgid isola ogni figlio nel proprio process group (per un
// eventuale kill di gruppo). A differenza di Linux, macOS/Darwin non ha un
// equivalente di Pdeathsig (prctl PR_SET_PDEATHSIG e' Linux-specifico) -
// limite noto e accettato: un crash duro del wrapper (kill -9, non
// un'uscita pulita) puo' lasciare FrankenPHP orfano a tenere la porta finche'
// non lo si termina a mano. Vedi piano §1.3/§4.
func hideWindow(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
}

// confirmQuit: dialogo nativo via osascript. Distingue "l'utente ha premuto
// Annulla" (nessun fallback, risposta no) da "osascript assente/fallito"
// (fallback stdin) guardando separatamente l'errore di esecuzione dall'esito
// del dialogo.
func confirmQuit(title, heading, body string) bool {
	p, err := exec.LookPath("osascript")
	if err != nil {
		return confirmQuitStdinFallback(title, heading, body)
	}
	script := fmt.Sprintf(
		`display dialog %s with title %s buttons {"Annulla","Esci"} default button "Annulla" with icon caution`,
		osaQuote(heading+"\n\n"+body), osaQuote(title))

	var stdout, stderr bytes.Buffer
	cmd := exec.Command(p, "-e", script)
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		// Exit code 1 con "User canceled" in stderr = l'utente ha premuto
		// Annulla (o chiuso il dialogo) - risposta valida, non un errore di
		// sistema: NON e' il caso di fallback. Qualunque altro errore (osascript
		// rotto, permessi Automazione negati, ecc.) invece si' - vedi commento
		// sopra la funzione.
		if strings.Contains(strings.ToLower(stderr.String()), "user canceled") {
			return false
		}
		return confirmQuitStdinFallback(title, heading, body)
	}
	return strings.Contains(stdout.String(), "Esci")
}

// osaQuote: incornicia una stringa tra virgolette AppleScript, raddoppiando
// le eventuali virgolette gia' presenti nel testo.
func osaQuote(s string) string {
	return `"` + strings.ReplaceAll(s, `"`, `\"`) + `"`
}

// appWindowCmd: cerca un browser Chromium in modalita' app sotto /Applications
// (percorso standard macOS). Senza nessuno di questi, openWindow ripiega su
// `open` (Safari, nessuna modalita' "app" nota da riga di comando).
func appWindowCmd(url, profileDir string) *exec.Cmd {
	cands := []string{
		"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
		"/Applications/Chromium.app/Contents/MacOS/Chromium",
		"/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
		"/Applications/Brave Browser.app/Contents/MacOS/Brave Browser",
	}
	for _, p := range cands {
		if fileExists(p) {
			// --no-first-run come su Windows: senza, il profilo dedicato mostra
			// "Welcome to Google Chrome" sopra la finestra di stato al primo
			// avvio (visto dal vivo sul runner macOS, 2026-10-01).
			return exec.Command(p, "--app="+url, "--user-data-dir="+profileDir, "--window-size=470,660",
				"--no-first-run", "--no-default-browser-check")
		}
	}
	return nil
}

func openURL(u string) {
	_ = exec.Command("open", u).Start()
}

// --- autostart: LaunchAgent per-utente ---
//
// Un LaunchAgent (~/Library/LaunchAgents) parte al login di QUELL'utente e
// NON richiede sudo - a differenza di un LaunchDaemon di sistema
// (/Library/LaunchDaemons, tutti gli utenti anche senza login), scartato per
// restare coerenti con l'uso di Homebrew "senza sudo" nel resto
// dell'installazione macOS (Appendice E del piano di migrazione).

const launchAgentLabel = "com.opensagra.wrapper"

func launchAgentPath() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, "Library", "LaunchAgents", launchAgentLabel+".plist"), nil
}

func autostartEnabled() bool {
	p, err := launchAgentPath()
	return err == nil && fileExists(p)
}

// launchdTarget: "gui/<uid>/<label>" - il dominio della sessione grafica
// dell'utente, quello in cui vivono i LaunchAgent (sintassi launchctl
// moderna bootstrap/bootout/enable, al posto di load/unload deprecati).
func launchdTarget() string {
	return fmt.Sprintf("gui/%d/%s", os.Getuid(), launchAgentLabel)
}

func launchAgentLoaded() bool {
	return exec.Command("launchctl", "print", launchdTarget()).Run() == nil
}

// setAutostart: NON fa mai bootout dell'agente - setAutostart viene chiamato
// anche dal wrapper gia' in esecuzione (pannello, -register-autostart), che
// spesso E' proprio l'istanza gestita da launchd: un bootout le manderebbe
// SIGTERM, cioe' disattivare l'avvio automatico chiuderebbe OpenSagra.
// Spegnere = `launchctl disable` (persistente: al prossimo login non parte)
// + rimozione del plist; il bootout vero lo fa solo uninstall-macos.sh.
func setAutostart(enable bool) error {
	p, err := launchAgentPath()
	if err != nil {
		return err
	}
	if !enable {
		_ = exec.Command("launchctl", "disable", launchdTarget()).Run()
		if err := os.Remove(p); err != nil && !os.IsNotExist(err) {
			return err
		}
		return nil
	}
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		return err
	}
	// KeepAlive/SuccessfulExit=false = riavvia solo se esce con errore (crash),
	// come Restart=on-failure dell'unit systemd su Linux. Un KeepAlive=true
	// secco riavvierebbe in loop (ogni ~10s, throttle di launchd) anche
	// un'uscita pulita: es. l'istanza duplicata che trova il lock occupato ed
	// esce da sola con 0, o un -quit voluto dall'operatore.
	// PATH esplicito: launchd da' ai LaunchAgent solo /usr/bin:/bin:/usr/sbin:
	// /sbin, senza Homebrew (MariaDB/strumenti installati da install-macos.sh).
	logDir := filepath.Join(filepath.Dir(exe), "logs")
	plist := fmt.Sprintf(`<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
	<key>Label</key><string>%s</string>
	<key>ProgramArguments</key><array><string>%s</string><string>-autostarted</string></array>
	<key>WorkingDirectory</key><string>%s</string>
	<key>EnvironmentVariables</key><dict>
		<key>PATH</key><string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin</string>
	</dict>
	<key>StandardOutPath</key><string>%s</string>
	<key>StandardErrorPath</key><string>%s</string>
	<key>RunAtLoad</key><true/>
	<key>KeepAlive</key><dict><key>SuccessfulExit</key><false/></dict>
	<key>ProcessType</key><string>Interactive</string>
</dict></plist>
`, launchAgentLabel, xmlEscape(exe), xmlEscape(filepath.Dir(exe)),
		xmlEscape(filepath.Join(logDir, "launchd.log")), xmlEscape(filepath.Join(logDir, "launchd.log")))
	if err := os.WriteFile(p, []byte(plist), 0o644); err != nil {
		return err
	}
	_ = exec.Command("launchctl", "enable", launchdTarget()).Run()
	if launchAgentLoaded() {
		// Gia' caricato (rilancio dell'installer, o riattivazione dal
		// pannello): il plist aggiornato vale dal prossimo login, nessun
		// bootout - vedi commento sopra la funzione.
		return nil
	}
	if out, err := exec.Command("launchctl", "bootstrap", fmt.Sprintf("gui/%d", os.Getuid()), p).CombinedOutput(); err != nil {
		return fmt.Errorf("launchctl bootstrap: %v: %s", err, strings.TrimSpace(string(out)))
	}
	return nil
}

func xmlEscape(s string) string {
	var b bytes.Buffer
	_ = xml.EscapeText(&b, []byte(s))
	return b.String()
}

// platformWebClientIPs: `lsof` da' un output riga-per-riga piu' regolare del
// BSD `netstat -an` nativo (che mescola IPv4/IPv6 senza header uniforme).
func platformWebClientIPs() []string {
	p, err := exec.LookPath("lsof")
	if err != nil {
		return nil
	}
	out, err := exec.Command(p, "-iTCP", "-sTCP:ESTABLISHED", "-P", "-n").Output()
	if err != nil {
		return nil
	}
	locals := localIPSet()
	seen := map[string]struct{}{}
	for _, line := range strings.Split(string(out), "\n") {
		f := strings.Fields(line)
		// COMMAND PID USER FD TYPE DEVICE SIZE/OFF NODE NAME
		// NAME e' "ip:porta->ip:porta"
		if len(f) < 9 {
			continue
		}
		parts := strings.SplitN(f[8], "->", 2)
		if len(parts) != 2 {
			continue
		}
		if hostPortSplit(parts[0]) != "80" && hostPortSplit(parts[0]) != "443" {
			continue
		}
		ip := ipFromHostPort(parts[1])
		if ip == "" {
			continue
		}
		if _, local := locals[ip]; local {
			continue
		}
		if parsed := net.ParseIP(ip); parsed == nil || parsed.IsLoopback() || parsed.IsUnspecified() {
			continue
		}
		seen[ip] = struct{}{}
	}
	out2 := make([]string, 0, len(seen))
	for ip := range seen {
		out2 = append(out2, ip)
	}
	return out2
}
