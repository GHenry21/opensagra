package main

import (
	"bufio"
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

// runScript: esegue lo script con bash (sono script bash, anche il 3.2 di
// serie su macOS) e passa ogni riga di stdout+stderr allo stato. Niente stdin:
// gli script non fanno domande, e sudo senza terminale usa SUDO_ASKPASS.
func runScript(st *state, scriptPath string, env []string) error {
	r, w, err := os.Pipe()
	if err != nil {
		return err
	}
	cmd := exec.Command("bash", scriptPath)
	cmd.Dir = filepath.Dir(scriptPath)
	cmd.Env = env
	cmd.Stdout, cmd.Stderr = w, w
	if err := cmd.Start(); err != nil {
		r.Close()
		w.Close()
		return err
	}
	w.Close() // resta aperta solo nei figli

	scanned := make(chan struct{})
	go func() {
		sc := bufio.NewScanner(r)
		sc.Buffer(make([]byte, 64*1024), 1024*1024)
		for sc.Scan() {
			st.feedLine(sc.Text())
		}
		close(scanned)
	}()

	waitErr := cmd.Wait()
	// Un processo lanciato in background dallo script (es. il wrapper avviato
	// fuori da launchd) eredita la pipe e la terrebbe aperta per sempre: a
	// script finito si da' un attimo per svuotarla e poi la si chiude.
	select {
	case <-scanned:
	case <-time.After(2 * time.Second):
		r.Close()
		<-scanned
	}
	r.Close()
	return waitErr
}
