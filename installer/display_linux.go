package main

import (
	"os"
	"os/exec"
	"syscall"
)

// ownProcessGroup / killBrowser: Chromium apre molti processi figli (GPU,
// renderer...); uccidendo solo il principale restano vivi e la cartella del
// profilo non si cancella. In un gruppo di processi proprio si chiudono tutti.
func ownProcessGroup(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
}

func killBrowser(cmd *exec.Cmd) {
	_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGTERM)
}

// findChromium: stessi nomi e percorsi di appWindowCmd nel wrapper
// (wrapper/platform_linux.go).
func findChromium() string {
	for _, name := range []string{"google-chrome", "google-chrome-stable", "chromium",
		"chromium-browser", "microsoft-edge", "microsoft-edge-stable"} {
		if p, err := exec.LookPath(name); err == nil {
			return p
		}
	}
	for _, p := range []string{"/usr/bin/google-chrome", "/usr/bin/google-chrome-stable",
		"/usr/bin/chromium", "/usr/bin/chromium-browser", "/snap/bin/chromium",
		"/usr/bin/microsoft-edge-stable"} {
		if fi, err := os.Stat(p); err == nil && !fi.IsDir() {
			return p
		}
	}
	return ""
}

func openURL(u string) { _ = exec.Command("xdg-open", u).Start() }

func openPath(p string) { _ = exec.Command("xdg-open", p).Start() }
