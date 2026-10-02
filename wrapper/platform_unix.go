//go:build unix

package main

// Codice POSIX condiviso tra Linux e macOS (build tag "unix" = linux, darwin
// e altri BSD - Go 1.19+). Quanto e' davvero specifico di un solo OS Unix
// (autostart, dialogo di conferma, ricerca browser, elenco IP client) vive
// in platform_linux.go / platform_darwin.go.

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// processAlive: true se esiste un processo con quel PID ancora in esecuzione.
// Signal(0) non invia nulla, verifica solo che il processo esista e sia
// raggiungibile (stesso utente, o root) - identico su Linux e macOS.
func processAlive(pid int) bool {
	p, err := os.FindProcess(pid)
	if err != nil {
		return false
	}
	return p.Signal(syscall.Signal(0)) == nil
}

// acquireSingleInstance: flock esclusivo non bloccante su un file in
// os.TempDir(). A differenza del mutex nominale di Windows, il lock si
// rilascia da solo se il processo muore senza chiamare release() (comportamento
// del kernel) - ma lock.go (readLock/writeLock/processAlive) resta comunque
// necessario per recuperare l'URL della finestra di stato dell'istanza viva,
// cosa che flock da solo non da'.
func acquireSingleInstance(name string) (release func(), fresh bool) {
	path := filepath.Join(os.TempDir(), name+".lock")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0o644)
	if err != nil {
		return func() {}, true // in dubbio, non impedire l'avvio
	}
	if err := syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		f.Close()
		return func() {}, false // un altro processo tiene gia' il lock
	}
	return func() {
		syscall.Flock(int(f.Fd()), syscall.LOCK_UN)
		f.Close()
		os.Remove(path)
	}, true
}

// processRunsBinary: true se il processo pid sta eseguendo bin (confronto sul
// primo campo della riga di comando). `ps -o command=` esiste identico su
// Linux (procps) e macOS (BSD) - nessuna lettura di /proc, che macOS non ha.
func processRunsBinary(pid int, bin string) bool {
	out, err := exec.Command("ps", "-o", "command=", "-p", strconv.Itoa(pid)).Output()
	if err != nil {
		return false
	}
	cmdline := strings.TrimSpace(string(out))
	return cmdline == bin || strings.HasPrefix(cmdline, bin+" ")
}

// terminateProcess: SIGTERM (uscita pulita: FrankenPHP rilascia porte e
// mercure.db), poi SIGKILL se dopo grace e' ancora vivo.
func terminateProcess(pid int, grace time.Duration) {
	_ = syscall.Kill(pid, syscall.SIGTERM)
	deadline := time.Now().Add(grace)
	for time.Now().Before(deadline) {
		if !processAlive(pid) {
			return
		}
		time.Sleep(200 * time.Millisecond)
	}
	_ = syscall.Kill(pid, syscall.SIGKILL)
}

// confirmQuitStdinFallback: ultima rete di sicurezza se ne' zenity/kdialog
// (Linux) ne' osascript (macOS) sono disponibili - es. wrapper lanciato da
// SSH/container senza ambiente desktop. Degrada a un prompt testuale invece
// di bloccarsi senza mai poter chiedere conferma.
func confirmQuitStdinFallback(title, heading, body string) bool {
	fmt.Printf("\n%s\n%s\n%s\n[s/N]: ", title, heading, body)
	s, _ := bufio.NewReader(os.Stdin).ReadString('\n')
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "s", "si", "y", "yes":
		return true
	default:
		return false
	}
}

func revealPath(p string) { openURL(p) }

// signalQuit: usato da -quit (main.go) per chiedere a un'istanza gia' in
// esecuzione di terminare. SIGTERM e' colto dal signal handler di
// main_unix.go, che esegue la stessa sequenza di uscita pulita di un normale
// arresto via systemd/launchd.
func signalQuit(pid int) error {
	return syscall.Kill(pid, syscall.SIGTERM)
}

// jobObject: nessun equivalente Unix del Job Object di Windows - la
// protezione contro i figli orfani in caso di crash del wrapper si ottiene
// per-processo in hideWindow (Setpgid + Pdeathsig su Linux, vedi
// platform_linux.go), non con un oggetto separato da assegnare dopo Start().
type jobObject struct{}

func newJobObject() (*jobObject, error)   { return nil, fmt.Errorf("job object: solo Windows") }
func (j *jobObject) assign(pid int) error { return nil }
func (j *jobObject) close()               {}
