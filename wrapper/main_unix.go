//go:build unix

package main

import (
	"context"
	"log"
	"os"
	"os/signal"
	"syscall"
)

// runEventLoop (Linux/macOS): niente tray (decisione presa, vedi
// docs/PIANO-MODIFICHE-WRAPPER.md §5) - il pannello (status_http.go, identico
// a Windows) resta raggiungibile dal collegamento desktop "Apri OpenSagra".
// L'unico modo di fermare il wrapper e' un segnale (systemctl --user stop /
// launchctl unload mandano SIGTERM, o -quit da un'altra invocazione tramite
// signalQuit) - un arresto avviato dal sistema non chiede conferma (nessun
// servizio lo fa, ne' su Windows con Stop-Service), la conferma con l'avviso
// sulle casse client resta solo nel percorso -quit/requestQuit (main.go).
func runEventLoop(ctx context.Context, cfg *Config, sup *Supervisor, status *statusServer, quit func()) {
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGTERM, syscall.SIGINT, syscall.SIGHUP)
	log.Printf("wrapper: pannello su %s (nessuna tray su questo OS - apri il collegamento sul desktop)", status.url())

	select {
	case s := <-sigCh:
		log.Printf("wrapper: segnale di arresto ricevuto (%v)", s)
	case <-ctx.Done():
	}
	quit()
}
