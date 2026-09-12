package main

import (
	"bufio"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

func firstNonEmpty(vs ...string) string {
	for _, v := range vs {
		if strings.TrimSpace(v) != "" {
			return v
		}
	}
	return ""
}

func valueOr(v, def string) string {
	if strings.TrimSpace(v) == "" {
		return def
	}
	return v
}

// readEnvFile: stesso parsing di config/env_reader.php (salta vuote e righe #,
// split sul primo '=', taglia un eventuale commento inline, sbuccia apici/spazi).
func readEnvFile(path string) map[string]string {
	out := map[string]string{}
	f, err := os.Open(path)
	if err != nil {
		return out
	}
	defer f.Close()

	sc := bufio.NewScanner(f)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		eq := strings.IndexByte(line, '=')
		if eq < 0 {
			continue
		}
		key := strings.TrimSpace(line[:eq])
		val := line[eq+1:]
		if h := strings.IndexByte(val, '#'); h >= 0 {
			val = val[:h]
		}
		out[key] = strings.Trim(val, "\"' ")
	}
	return out
}

// detectAppRoot: dalla cartella dell'eseguibile, risale cercando un Caddyfile
// (dev: wrapper/ dentro il repo; installato: wrapper.exe nella root).
// detectAppRoot: risale dall'eseguibile cercando la radice dell'app. Ancora su
// `bin/opensagra-realtime-relay.php` (c'e' sempre, anche su una copia appena
// fatta) PIU' un marcatore di radice — il `Caddyfile` generato dall'installer,
// o `Caddyfile.example` se il Caddyfile reale non e' ancora stato creato.
// Cosi' l'exe sotto `<root>/wrapper/` trova `<root>` anche senza Caddyfile.
func detectAppRoot(start string) string {
	dir := start
	for i := 0; i < 5; i++ {
		hasBin := fileExists(filepath.Join(dir, "bin", "opensagra-realtime-relay.php"))
		hasCaddy := fileExists(filepath.Join(dir, "Caddyfile")) ||
			fileExists(filepath.Join(dir, "Caddyfile.example"))
		if hasBin && hasCaddy {
			return dir
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			break
		}
		dir = parent
	}
	return start
}

func detectFrankenphp() string {
	name := "frankenphp"
	if runtime.GOOS == "windows" {
		name = "frankenphp.exe"
	}
	if home, err := os.UserHomeDir(); err == nil {
		if p := filepath.Join(home, ".frankenphp", name); fileExists(p) {
			return p
		}
	}
	if p, err := exec.LookPath(name); err == nil {
		return p
	}
	return name // ci prova comunque; l'errore di avvio finisce nel log del figlio
}

func fileExists(p string) bool {
	st, err := os.Stat(p)
	return err == nil && !st.IsDir()
}

func capDur(d, max time.Duration) time.Duration {
	if d > max {
		return max
	}
	return d
}

// exitCode: -1 se il processo non e' nemmeno partito / segnale, altrimenti il
// codice di uscita reale.
func exitCode(err error) int {
	if err == nil {
		return 0
	}
	var ee *exec.ExitError
	if errors.As(err, &ee) {
		return ee.ExitCode()
	}
	return -1
}

func indexOfFold(ss []string, target string) int {
	for i, s := range ss {
		if strings.EqualFold(s, target) {
			return i
		}
	}
	return -1
}

// tailFile: le ultime n righe di un file di testo. I log dei figli sono capati
// a 5 MiB dalla rotazione, quindi leggerli interi va bene.
func tailFile(path string, n int) string {
	b, err := os.ReadFile(path)
	if err != nil {
		return "(nessun log)"
	}
	lines := strings.Split(strings.TrimRight(string(b), "\n"), "\n")
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.Join(lines, "\n")
}
