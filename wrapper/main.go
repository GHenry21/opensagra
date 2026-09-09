// Comando `wrapper`: tray-app che supervisiona i processi di OpenSagra su una
// postazione (FrankenPHP + relay realtime + bridge di stampa nativo). MariaDB
// resta un servizio a parte, non e' gestito qui.
//
// Modello deciso nel piano (docs/PIANO-MIGRAZIONE-FRANKENPHP.md, sezione 3g):
//   - X sulla finestra = vai in tray, non chiude
//   - Esci vero = menu tray -> Esci -> conferma
//   - all'uscita, se questa macchina e' il server con casse collegate, avviso
//     rinforzato + `bin/opensagra-announce.php --kind=shutdown`
//
// Build Windows senza console:
//
//	go build -ldflags "-H=windowsgui" -o opensagra-wrapper.exe ./...
package main

import (
	"context"
	"log"
	"os"
	"path/filepath"
	"time"

	"fyne.io/systray"
)

func main() {
	cfg, err := loadConfig()
	if err != nil {
		log.Fatalf("config: %v", err)
	}

	if err := os.MkdirAll(cfg.LogDir, 0o755); err != nil {
		log.Fatalf("logdir %s: %v", cfg.LogDir, err)
	}
	// Build -H=windowsgui non ha stdout/stderr: manda il log del wrapper su file.
	if f, err := os.OpenFile(filepath.Join(cfg.LogDir, "wrapper.log"),
		os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644); err == nil {
		log.SetOutput(f)
	}
	log.Printf("wrapper: root=%s frankenphp=%s casse-bridge=%v", cfg.AppRoot, cfg.Frankenphp, cfg.BridgeCasse)

	if cfg.RegisterAutostart {
		if err := setAutostart(true); err != nil {
			log.Printf("register-autostart: %v", err)
		} else {
			log.Print("register-autostart: chiave di avvio scritta")
		}
	}

	release, ok := acquireSingleInstance("opensagra-wrapper")
	if !ok {
		log.Print("un'altra istanza del wrapper e' gia' in esecuzione, esco.")
		return
	}
	defer release()

	// Job object: quando il wrapper muore (anche crash), Windows termina tutto
	// l'albero dei figli. Senza, un crash lascia frankenphp.exe orfano che
	// tiene la porta 80.
	job, err := newJobObject()
	if err != nil {
		log.Printf("job object non disponibile (%v): i figli potrebbero sopravvivere a un crash del wrapper", err)
		job = nil
	}

	ctx, cancel := context.WithCancel(context.Background())

	sup := newSupervisor(cfg.LogDir, job)
	sup.Start(ctx, buildChildren(cfg))

	// Finestra di stato: server HTTP locale + pagina aperta in browser app-mode.
	status := newStatusServer(cfg, sup)
	if err := status.start(); err != nil {
		log.Printf("finestra di stato: server non avviato: %v", err)
	} else {
		log.Printf("finestra di stato: %s", status.url())
		if !cfg.Autostarted {
			status.openWindow() // all'avvio manuale la si mostra; al logon no
		}
	}

	// Quando FrankenPHP e' su, pulisci il banner "server giu'" sui client
	// (simmetrico all'announce shutdown fatto in quit()).
	go func() {
		for i := 0; i < 60; i++ {
			select {
			case <-ctx.Done():
				return
			case <-time.After(time.Second):
			}
			if sup.get("frankenphp").State == stateRunning {
				announceBack(ctx, cfg)
				return
			}
		}
	}()

	// Sequenza di uscita pulita, invocata dal menu tray dopo conferma.
	quit := func() {
		log.Print("wrapper: uscita richiesta")
		announceShutdown(cfg) // avvisa le casse PRIMA di fermare FrankenPHP
		cancel()              // exec.CommandContext uccide i figli
		sup.Wait()
		sup.Close()
		if job != nil {
			job.close() // rete di sicurezza per eventuali superstiti
		}
		systray.Quit()
	}

	t := &tray{cfg: cfg, sup: sup, status: status, quit: quit}
	systray.Run(t.onReady, t.onExit) // blocca finche' systray.Quit()
}
