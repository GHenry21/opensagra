package main

// Solo per -demo (lavorare sulla grafica da Windows): Edge in modalita' app.

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
)

func findChromium() string {
	for _, env := range []string{"ProgramFiles(x86)", "ProgramFiles", "LocalAppData"} {
		base := os.Getenv(env)
		if base == "" {
			continue
		}
		for _, rel := range []string{`Microsoft\Edge\Application\msedge.exe`, `Google\Chrome\Application\chrome.exe`} {
			p := filepath.Join(base, rel)
			if _, err := os.Stat(p); err == nil {
				return p
			}
		}
	}
	return ""
}

func ownProcessGroup(cmd *exec.Cmd) {}

func killBrowser(cmd *exec.Cmd) {
	_ = exec.Command("taskkill", "/T", "/F", "/PID", fmt.Sprint(cmd.Process.Pid)).Run()
}

func openURL(u string) { _ = exec.Command("rundll32", "url.dll,FileProtocolHandler", u).Start() }

func openPath(p string) { _ = exec.Command("notepad", p).Start() }
