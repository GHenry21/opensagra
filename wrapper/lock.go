package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

// Lock file di istanza: <logdir>/wrapper.lock con PID + URL della finestra di
// stato. Il named mutex da solo ha rifiutato l'avvio ("gia' in esecuzione")
// con nessun processo vivo (corsa con la chiusura dell'istanza precedente) -
// vedi PIANO-MODIFICHE-WRAPPER.md sez. 2. Col lock file: se il mutex risulta
// occupato ma il PID nel file e' morto, si prosegue; se e' vivo, si apre la
// sua finestra invece di uscire in silenzio.

func lockFilePath(logDir string) string { return filepath.Join(logDir, "wrapper.lock") }

// readLock: (istanza viva?, URL della sua finestra di stato).
func readLock(logDir string) (alive bool, statusURL string) {
	b, err := os.ReadFile(lockFilePath(logDir))
	if err != nil {
		return false, ""
	}
	lines := strings.Split(strings.ReplaceAll(strings.TrimSpace(string(b)), "\r\n", "\n"), "\n")
	if len(lines) == 0 {
		return false, ""
	}
	pid, err := strconv.Atoi(strings.TrimSpace(lines[0]))
	if err != nil || pid <= 0 || pid == os.Getpid() || !processAlive(pid) {
		return false, ""
	}
	if len(lines) >= 2 {
		statusURL = strings.TrimSpace(lines[1])
	}
	return true, statusURL
}

// lockPID: PID scritto nel lock file, se punta a un processo vivo. Usato dal
// flag -quit (main.go) per sapere chi terminare.
func lockPID(logDir string) (pid int, alive bool) {
	b, err := os.ReadFile(lockFilePath(logDir))
	if err != nil {
		return 0, false
	}
	lines := strings.Split(strings.ReplaceAll(strings.TrimSpace(string(b)), "\r\n", "\n"), "\n")
	if len(lines) == 0 {
		return 0, false
	}
	pid, err = strconv.Atoi(strings.TrimSpace(lines[0]))
	if err != nil || pid <= 0 || pid == os.Getpid() || !processAlive(pid) {
		return 0, false
	}
	return pid, true
}

func writeLock(logDir, statusURL string) {
	_ = os.WriteFile(lockFilePath(logDir),
		[]byte(fmt.Sprintf("%d\n%s\n", os.Getpid(), statusURL)), 0o644)
}

func removeLock(logDir string) { _ = os.Remove(lockFilePath(logDir)) }
