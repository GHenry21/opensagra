//go:build !windows

package main

// Stub non-Windows: il wrapper e' cross-OS per costruzione (piano 3g) ma la
// prima release e' Windows. Qui giusto quanto basta perche' compili e giri in
// modo degradato su Linux/macOS per lo sviluppo.

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"syscall"
)

func hideWindow(cmd *exec.Cmd) {}

func acquireSingleInstance(name string) (release func(), fresh bool) {
	return func() {}, true // TODO: flock su /tmp/<name>.lock
}

func processAlive(pid int) bool {
	p, err := os.FindProcess(pid)
	if err != nil {
		return false
	}
	return p.Signal(syscall.Signal(0)) == nil
}

type jobObject struct{}

func newJobObject() (*jobObject, error)   { return nil, fmt.Errorf("job object: solo Windows") }
func (j *jobObject) assign(pid int) error { return nil }
func (j *jobObject) close()               {}

func confirmQuit(title, body string) bool {
	fmt.Printf("\n%s\n%s\n[s/N]: ", title, body)
	s, _ := bufio.NewReader(os.Stdin).ReadString('\n')
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "s", "si", "y", "yes":
		return true
	default:
		return false
	}
}

func openURL(u string) {
	if runtime.GOOS == "darwin" {
		_ = exec.Command("open", u).Start()
		return
	}
	_ = exec.Command("xdg-open", u).Start()
}

func revealPath(p string) { openURL(p) }

func appWindowCmd(url, profileDir string) *exec.Cmd {
	for _, name := range []string{"google-chrome", "chromium", "chromium-browser", "microsoft-edge"} {
		if p, err := exec.LookPath(name); err == nil {
			return exec.Command(p, "--app="+url, "--user-data-dir="+profileDir, "--window-size=470,660")
		}
	}
	return nil // openWindow ripiega su xdg-open/open
}

func autostartEnabled() bool { return false }

func setAutostart(enable bool) error {
	return fmt.Errorf("avvio all'accensione: implementato solo su Windows") // TODO: systemd --user / LaunchAgent
}
