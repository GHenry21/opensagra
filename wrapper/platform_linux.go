//go:build linux

package main

import (
	"encoding/hex"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
)

// hideWindow: nessuna finestra da nascondere su Linux (nome mantenuto per
// simmetria con platform_windows.go, e' il punto in cui supervisor.go chiama
// per ogni figlio prima di cmd.Start()). Setpgid isola ogni figlio nel proprio
// process group; Pdeathsig (solo Linux, prctl(PR_SET_PDEATHSIG)) fa si' che il
// figlio riceva SIGTERM da solo se il wrapper muore per qualunque motivo,
// crash o kill -9 incluso - stessa garanzia del Job Object di Windows
// ("il server non resta orfano a tenere la porta 80"), ma qui e' il kernel a
// farla rispettare, non serve un oggetto da chiudere esplicitamente.
func hideWindow(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{
		Setpgid:   true,
		Pdeathsig: syscall.SIGTERM,
	}
}

// confirmQuit: zenity (GNOME/la maggior parte delle distro desktop), poi
// kdialog (KDE), poi il fallback testuale condiviso (platform_unix.go) se
// nessuno dei due e' installato.
func confirmQuit(title, heading, body string) bool {
	text := heading + "\n\n" + body
	if p, err := exec.LookPath("zenity"); err == nil {
		cmd := exec.Command(p, "--question", "--title="+title, "--text="+text, "--width=360")
		return cmd.Run() == nil // zenity esce 0 su "Si'", diverso da 0 su "No"/chiusura
	}
	if p, err := exec.LookPath("kdialog"); err == nil {
		cmd := exec.Command(p, "--title", title, "--yesno", text)
		return cmd.Run() == nil
	}
	return confirmQuitStdinFallback(title, heading, body)
}

// appWindowCmd: cerca un browser Chromium in modalita' app. Oltre al $PATH
// (gia' sufficiente per una shell interattiva) controlla anche percorsi
// assoluti comuni: un servizio systemd --user ha spesso un $PATH piu' povero
// di una sessione desktop.
func appWindowCmd(url, profileDir string) *exec.Cmd {
	names := []string{"google-chrome", "google-chrome-stable", "chromium",
		"chromium-browser", "microsoft-edge", "microsoft-edge-stable"}
	for _, name := range names {
		if p, err := exec.LookPath(name); err == nil {
			return exec.Command(p, "--app="+url, "--user-data-dir="+profileDir, "--window-size=470,660")
		}
	}
	absPaths := []string{
		"/usr/bin/google-chrome", "/usr/bin/google-chrome-stable",
		"/usr/bin/chromium", "/usr/bin/chromium-browser",
		"/snap/bin/chromium", "/usr/bin/microsoft-edge-stable",
	}
	for _, p := range absPaths {
		if fileExists(p) {
			return exec.Command(p, "--app="+url, "--user-data-dir="+profileDir, "--window-size=470,660")
		}
	}
	return nil // openWindow ripiega su xdg-open (openURL, sotto)
}

func openURL(u string) {
	_ = exec.Command("xdg-open", u).Start()
}

// --- autostart: systemd --user ---
//
// Nessuna elevazione: la unit vive nella home dell'utente che lancia il
// wrapper, esattamente come la chiave HKCU Run su Windows. Per un ruolo
// client/server headless (nessun login interattivo mai fatto) serve in piu'
// `loginctl enable-linger <utente>` (richiede root) perche' la unit parta
// gia' al boot - lo fa install.sh, non il wrapper, che gira sempre non
// elevato.

const systemdUnitName = "opensagra-wrapper.service"

func systemdUnitPath() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, ".config", "systemd", "user", systemdUnitName), nil
}

func autostartEnabled() bool {
	out, err := exec.Command("systemctl", "--user", "is-enabled", systemdUnitName).Output()
	if err != nil {
		return false
	}
	return strings.TrimSpace(string(out)) == "enabled"
}

func setAutostart(enable bool) error {
	if !enable {
		_ = exec.Command("systemctl", "--user", "disable", systemdUnitName).Run()
		return nil
	}
	path, err := systemdUnitPath()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	unit := fmt.Sprintf(`[Unit]
Description=OpenSagra
After=network.target

[Service]
ExecStart=%s -autostarted
Restart=on-failure

[Install]
WantedBy=default.target
`, exe)
	if err := os.WriteFile(path, []byte(unit), 0o644); err != nil {
		return err
	}
	_ = exec.Command("systemctl", "--user", "daemon-reload").Run()
	return exec.Command("systemctl", "--user", "enable", "--now", systemdUnitName).Run()
}

// --- "N casse collegate": connessioni TCP ESTABLISHED verso 80/443 ---
//
// Prima scelta: `ss` (pacchetto iproute2, presente su quasi ogni distro
// moderna) - output regolare, un comando solo. Fallback: lettura diretta di
// /proc/net/tcp (sempre presente su un kernel Linux, anche senza iproute2),
// solo IPv4 - degradazione accettabile per l'emergenza, il caso comune e'
// gia' coperto da ss.
func platformWebClientIPs() []string {
	if ips, ok := webClientIPsSS(); ok {
		return ips
	}
	return webClientIPsProcNetTCP()
}

func webClientIPsSS() (ips []string, ok bool) {
	p, err := exec.LookPath("ss")
	if err != nil {
		return nil, false
	}
	out, err := exec.Command(p, "-Htn", "state", "established").Output()
	if err != nil {
		return nil, false
	}
	locals := localIPSet()
	seen := map[string]struct{}{}
	for _, line := range strings.Split(string(out), "\n") {
		f := strings.Fields(line)
		// State Recv-Q Send-Q Local:Port Peer:Port [...]
		if len(f) < 5 {
			continue
		}
		if hostPortSplit(f[3]) != "80" && hostPortSplit(f[3]) != "443" {
			continue
		}
		ip := ipFromHostPort(f[4])
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
	return out2, true
}

// webClientIPsProcNetTCP: solo IPv4 (fallback di emergenza se manca `ss`).
// Formato di /proc/net/tcp: "sl  local_address rem_address st ..." con
// indirizzo:porta in esadecimale, IP in ordine di byte nativo (little-endian
// su x86/arm - va invertito), st "01" = ESTABLISHED.
func webClientIPsProcNetTCP() []string {
	b, err := os.ReadFile("/proc/net/tcp")
	if err != nil {
		return nil
	}
	locals := localIPSet()
	seen := map[string]struct{}{}
	lines := strings.Split(string(b), "\n")
	for i, line := range lines {
		if i == 0 {
			continue // header
		}
		f := strings.Fields(line)
		if len(f) < 4 {
			continue
		}
		if f[3] != "01" { // TCP_ESTABLISHED
			continue
		}
		localPort := hexPort(f[1])
		if localPort != "80" && localPort != "443" {
			continue
		}
		ip := hexIPv4(f[2])
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
	out := make([]string, 0, len(seen))
	for ip := range seen {
		out = append(out, ip)
	}
	return out
}

func hexPort(addrPort string) string {
	parts := strings.SplitN(addrPort, ":", 2)
	if len(parts) != 2 {
		return ""
	}
	n, err := strconv.ParseUint(parts[1], 16, 32)
	if err != nil {
		return ""
	}
	return strconv.FormatUint(n, 10)
}

func hexIPv4(addrPort string) string {
	parts := strings.SplitN(addrPort, ":", 2)
	if len(parts) != 2 || len(parts[0]) != 8 {
		return ""
	}
	b, err := hex.DecodeString(parts[0])
	if err != nil || len(b) != 4 {
		return ""
	}
	// Byte order nel file e' invertito rispetto all'ordine di rete.
	return net.IPv4(b[3], b[2], b[1], b[0]).String()
}
