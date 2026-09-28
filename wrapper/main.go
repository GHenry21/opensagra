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
)

func main() {
	cfg, err := loadConfig()
	if err != nil {
		log.Fatalf("config: %v", err)
	}

	if cfg.Quit {
		requestQuit(cfg)
		return
	}

	if err := os.MkdirAll(cfg.LogDir, 0o755); err != nil {
		log.Fatalf("logdir %s: %v", cfg.LogDir, err)
	}
	// Build -H=windowsgui non ha stdout/stderr: manda il log del wrapper su file.
	if f, err := os.OpenFile(filepath.Join(cfg.LogDir, "wrapper.log"),
		os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644); err == nil {
		log.SetOutput(f)
	}
	log.Printf("wrapper: root=%s frankenphp=%s bridge=%v", cfg.AppRoot, cfg.Frankenphp, cfg.BridgeEnabled)

	// Diagnostica: i figli falliscono in modo poco chiaro se mancano i file
	// per-macchina (non tracciati in git, li genera l'installer).
	if !fileExists(filepath.Join(cfg.AppRoot, "Caddyfile")) {
		log.Printf("ATTENZIONE: manca %s\\Caddyfile - FrankenPHP non partira'. "+
			"Genera i file con install.ps1, o passa -root alla cartella giusta.", cfg.AppRoot)
	}
	if !fileExists(filepath.Join(cfg.AppRoot, "config", "variabili.env")) {
		log.Printf("ATTENZIONE: manca %s\\config\\variabili.env - niente credenziali DB/segreto Mercure.", cfg.AppRoot)
	}
	if !fileExists(filepath.Join(cfg.AppRoot, "bin", "opensagra-realtime-relay.php")) {
		log.Printf("ATTENZIONE: %s non sembra la radice di OpenSagra (manca bin/). Usa -root.", cfg.AppRoot)
	}

	if cfg.RegisterAutostart {
		if err := setAutostart(true); err != nil {
			log.Printf("register-autostart: %v", err)
		} else {
			log.Print("register-autostart: chiave di avvio scritta")
		}
	}

	release, fresh := acquireSingleInstance("opensagra-wrapper")
	defer release()
	if !fresh {
		if alive, statusURL := readLock(cfg.LogDir); alive {
			log.Printf("wrapper: un'altra istanza e' gia' viva - apro la sua finestra di stato (%s)", statusURL)
			if statusURL != "" {
				// Stessa finestra app-mode (senza barra/tab) dell'avvio
				// normale, non un tab qualunque del browser di default -
				// e' quello che ci si aspetta cliccando il collegamento sul
				// desktop mentre il wrapper e' gia' in esecuzione.
				cmd := appWindowCmd(statusURL, filepath.Join(cfg.LogDir, ".statuswin"))
				if cmd == nil || cmd.Start() != nil {
					openURL(statusURL)
				}
			}
			return
		}
		log.Print("wrapper: il mutex 'opensagra-wrapper' risulta occupato ma nessun processo vivo lo tiene (stantio) - proseguo")
	}

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
	// Lo stato iniziale del figlio snapshot (pausa se server/indipendente) e la
	// sua regolazione al cambio ruolo li gestisce watchDbHost, piu' sotto.

	// Finestra di stato: server HTTP locale + pagina aperta in browser app-mode.
	status := newStatusServer(ctx, cfg, sup)
	if err := status.start(); err != nil {
		log.Printf("finestra di stato: server non avviato: %v", err)
		writeLock(cfg.LogDir, "")
	} else {
		log.Printf("finestra di stato: %s", status.url())
		writeLock(cfg.LogDir, status.url()) // PID + URL: lo legge un secondo avvio
		if !cfg.Autostarted {
			status.openWindow() // all'avvio manuale la si mostra; al logon no
		}
	}
	defer removeLock(cfg.LogDir)

	// Quando FrankenPHP e' su, pulisci il banner "server giu'" sui client
	// (simmetrico all'announce shutdown fatto in quit()).
	go announceBackWhenUp(ctx, sup, cfg)

	// conf_rete / il fallback locale riscrivono DB_POS_HOST a caldo: rileggilo
	// cosi' la finestra di stato e l'avviso d'uscita non restano sul ruolo
	// d'avvio. Al cambio ruolo mette in pausa / riprende anche il figlio
	// snapshot (il relay si autoregola gia' da solo con exit(0)).
	go watchDbHost(ctx, cfg, sup)

	// Sequenza di uscita pulita. Su Windows e' il menu tray "Esci" (dopo
	// conferma) a invocarla; su Linux/macOS la invoca runEventLoop quando
	// arriva un segnale di arresto (systemctl/launchctl stop) o -quit
	// (requestQuit, sotto) lo richiede da un'altra invocazione del wrapper.
	quit := func() {
		log.Print("wrapper: uscita richiesta")
		removeLock(cfg.LogDir)
		announceShutdown(cfg) // avvisa le casse PRIMA di fermare FrankenPHP
		cancel()              // exec.CommandContext uccide i figli
		sup.Wait()
		sup.Close()
		if job != nil {
			job.close() // rete di sicurezza per eventuali superstiti
		}
	}

	// runEventLoop: bloccante, implementazione diversa per OS (main_windows.go:
	// tray + systray.Run; main_unix.go: attesa di un segnale) - vedi i due file.
	runEventLoop(ctx, cfg, sup, status, quit)
}

// requestQuit (-quit): chiede a un'istanza gia' in esecuzione di terminare,
// con lo stesso avviso mostrato dal menu tray su Windows - rilevante ovunque,
// non solo li', perche' fermare il PC server rompe le casse client collegate.
// Pensato per un lanciatore desktop "Ferma OpenSagra" su Linux/macOS (niente
// tray la' per ospitare una voce "Esci"), ma non e' specifico di un OS.
func requestQuit(cfg *Config) {
	pid, alive := lockPID(cfg.LogDir)
	if !alive {
		log.Print("wrapper: -quit, nessuna istanza in esecuzione")
		return
	}
	if !confirmQuit("OpenSagra",
		"Vuoi davvero chiudere OpenSagra?",
		"⚠️ Fermando questo PC le casse in rete non potranno più registrare vendite finché non riavvii OpenSagra.") {
		return
	}
	if err := signalQuit(pid); err != nil {
		log.Printf("wrapper: -quit, impossibile fermare il processo %d: %v", pid, err)
	}
}
