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
)

func hideWindow(cmd *exec.Cmd) {}

func acquireSingleInstance(name string) (release func(), ok bool) {
	return func() {}, true // TODO: flock su /tmp/<name>.lock
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
