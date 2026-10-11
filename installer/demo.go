package main

// -demo: righe finte nello stesso formato degli script veri, per provare la
// grafica (anche su Windows) senza installare nulla. Le righe passano dallo
// stesso parser (feedLine) dell'installazione reale.

import (
	"time"
)

var demoLines = []string{
	"[2026-10-02T10:00:00+0200] avvio install.sh (PID 1234) - sorgente: /x -> destinazione: /y",
	"  OK: prerequisiti di base",
	"Reading package lists... (testo libero di apt, ignorato)",
	"  OK: FrankenPHP 1.12.6 installato (frankenphp-linux-aarch64)",
	"  OK: Permesso di legarsi alle porte 80/443 concesso a FrankenPHP (setcap)",
	"  OK: php.ini scritto, estensioni PHP verificate",
	"[2026-10-02T10:00:09+02:00] Installo mariadb-server...",
	"  OK: MariaDB installato",
	"  OK: Servizio MariaDB attivo",
	"  OK: File dell'app copiati in /home/sagra/opensagra",
	"  OK: config/variabili.env creato",
	"  OK: Caddyfile generato (host: localhost 192.168.1.20)",
	"  OK: Database creato/verificato",
	"  OK: Segreto Mercure salvato in app_config",
	"  OK: Migrazioni database applicate",
	"  OK: Wrapper copiato",
	"  OK: Disinstaller copiato",
	"  OK: Collegamento sul desktop creato",
	"  OK: Linger abilitato per sagra (l'unit systemd --user sopravvive al logout/riparte al boot)",
	"  OK: OpenSagra avviato (systemd --user: opensagra-wrapper.service)",
	"[2026-10-02T10:00:20+02:00] ufw non installato: nessuna regola firewall da configurare (se ne usi un altro, apri tu le porte 80/443/3306).",
	"  OK: Certificato locale HTTPS fidato",
	"[2026-10-02T10:00:21+02:00] Installazione completata. Log completo in /tmp/opensagra-install.log",
}

func runDemo(st *state, srv *server, mode string) bool {
	if mode == "password" {
		st.update(func(s *state) { s.Phase, s.Status = phasePassword, "In attesa della password..." })
		for req := range srv.password {
			time.Sleep(time.Second) // come sudo -v
			if req.password == "demo" {
				req.reply <- ""
				break
			}
			req.reply <- "Password errata, riprova. (in demo e' \"demo\")"
		}
	}
	st.update(func(s *state) { s.Phase, s.Status = phaseRunning, "Verifica dei prerequisiti..." })
	for i, line := range demoLines {
		time.Sleep(450 * time.Millisecond)
		if mode == "error" && i == 9 {
			st.feedLine("ERRORE: Impossibile avviare il servizio mariadb")
			st.update(func(s *state) { s.Phase = phaseError })
			return false
		}
		st.feedLine(line)
	}
	st.update(func(s *state) { s.Phase, s.Percent, s.Status = phaseDone, 100, "Installazione completata" })
	return true
}
