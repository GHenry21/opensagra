//go:build unix

package main

// Password per sudo, chiesta nella finestra invece che in un terminale che
// non c'e'. Gli script girano come utente normale e chiamano `sudo` per nome
// solo dove serve (apt, systemctl, setcap, portachiavi...): qui si mette in
// testa al PATH un sostituto che chiama il sudo vero con -A, cioe' "chiedi la
// password a SUDO_ASKPASS" - che e' questo stesso eseguibile in modalita'
// --askpass e restituisce la password gia' verificata. L'installer di
// Homebrew (lanciato da install-macos.sh) chiama /usr/bin/sudo per percorso
// assoluto e non vede il sostituto, ma aggiunge -A da solo quando trova
// SUDO_ASKPASS nell'ambiente.
//
// La password si verifica PRIMA di avviare lo script (sudo -S -v), cosi'
// l'errore "password errata" resta nella schermata della password invece di
// far fallire lo script a meta'. Resta solo in memoria di questo processo.

import (
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
)

func prepareSudo(st *state, srv *server) (env []string, cleanup func(), ok bool) {
	env = os.Environ()
	cleanup = func() {}

	realSudo, err := exec.LookPath("sudo")
	if err != nil {
		return env, cleanup, true // lo script stesso si ferma con "sudo non trovato"
	}
	exe, err := os.Executable()
	if err != nil {
		return env, cleanup, true
	}
	dir, err := os.MkdirTemp("", "opensagra-installer-")
	if err != nil {
		return env, cleanup, true
	}
	cleanup = func() { _ = os.RemoveAll(dir) }

	askpass := filepath.Join(dir, "askpass")
	binDir := filepath.Join(dir, "bin")
	_ = os.MkdirAll(binDir, 0o700)
	_ = os.WriteFile(askpass, []byte("#!/bin/sh\nexec "+shQuote(exe)+" --askpass \"$@\"\n"), 0o700)
	_ = os.WriteFile(filepath.Join(binDir, "sudo"), []byte("#!/bin/sh\nexec "+shQuote(realSudo)+" -A \"$@\"\n"), 0o700)

	env = setEnv(env, "PATH", binDir+":"+os.Getenv("PATH"))
	env = setEnv(env, "SUDO_ASKPASS", askpass)
	env = setEnv(env, "OPENSAGRA_INSTALLER_ADDR", srv.addr)
	env = setEnv(env, "OPENSAGRA_INSTALLER_TOKEN", srv.token)

	var mu sync.Mutex
	var cached string
	srv.askpass = func() (string, bool) {
		mu.Lock()
		defer mu.Unlock()
		return cached, cached != ""
	}

	// sudo senza password (NOPASSWD, o credenziali ancora valide): nessuna domanda.
	if exec.Command(realSudo, "-n", "true").Run() == nil {
		return env, cleanup, true
	}

	st.update(func(s *state) { s.Phase, s.Status = phasePassword, "In attesa della password..." })
	for req := range srv.password {
		msg, fatal := validateSudo(realSudo, req.password)
		if msg == "" {
			mu.Lock()
			cached = req.password
			mu.Unlock()
			req.reply <- ""
			return env, cleanup, true
		}
		if fatal {
			st.update(func(s *state) { s.Phase, s.Error = phaseError, msg })
			req.reply <- msg
			return env, cleanup, false
		}
		req.reply <- msg
	}
	return env, cleanup, false
}

// validateSudo: "" se la password e' giusta; fatal se e' giusta o sbagliata
// non importa perche' l'utente non puo' usare sudo affatto.
func validateSudo(realSudo, password string) (msg string, fatal bool) {
	cmd := exec.Command(realSudo, "-S", "-v", "-p", "")
	cmd.Stdin = strings.NewReader(password + "\n")
	cmd.Env = setEnv(os.Environ(), "LC_ALL", "C") // messaggi di sudo in inglese, riconoscibili
	out, err := cmd.CombinedOutput()
	if err == nil {
		return "", false
	}
	o := string(out)
	if strings.Contains(o, "sudoers") || strings.Contains(o, "may not run sudo") {
		if runtime.GOOS == "darwin" {
			return "il tuo utente non e' amministratore di questo Mac: accedi con un utente amministratore e riapri l'installer", true
		}
		return "il tuo utente non puo' usare sudo: accedi con un utente amministratore e riapri l'installer", true
	}
	return "Password errata, riprova.", false
}

func shQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'"
}

func setEnv(env []string, key, val string) []string {
	prefix := key + "="
	out := env[:0:0]
	for _, kv := range env {
		if !strings.HasPrefix(kv, prefix) {
			out = append(out, kv)
		}
	}
	return append(out, prefix+val)
}
