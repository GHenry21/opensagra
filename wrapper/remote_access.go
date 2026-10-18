package main

import (
	"context"
	"database/sql"
	"fmt"
	"log"
	"strings"
	"time"
)

// Accesso dalla rete al database di questo PC (piano, Fase 6c, regola
// dell'utente 2026-10-07): il database di un'indipendente lo usa solo lei; si
// apre quando una cassa sceglie questo PC come centrale (api/accept_client.php
// scrive app_config.IS_CENTRAL=1), e da li' resta aperto mentre OpenSagra gira
// e chiuso quando si chiude. Su una cassa client sempre chiuso (lavora sul DB
// del centrale, il suo non serve a nessuno; set_network_config.php azzera
// IS_CENTRAL); i telefoni/tablet sono solo browser e non espongono nulla. Le porte web (80/443) seguono gia' OpenSagra da sole: FrankenPHP e'
// un figlio del wrapper. MariaDB invece e' un servizio di sistema e resta
// acceso: per la 3306 si blocca/sblocca l'account con cui entrano le casse
// client, con la procedura creata dall'installer (config/remote_access.php).
// Niente permessi di amministratore, niente firewall da toccare.
//
// Se il wrapper muore di colpo (crash, PC spento) il blocco d'uscita non
// avviene: con OpenSagra spento la 443 non risponde comunque, e al riavvio
// il wrapper rimette lo stato giusto.
const remoteAccessEvery = 15 * time.Second

func wantRemoteAccess(cfg *Config) bool {
	return isThisMachineServer(cfg) && !inLocalFallback(cfg) && isCentral(cfg)
}

// isCentral: app_config.IS_CENTRAL del DB locale. Tabella o riga assente,
// o MariaDB non ancora su -> false (chiuso: il caso sicuro).
func isCentral(cfg *Config) bool {
	db, err := sql.Open("mysql", fmt.Sprintf("%s:%s@tcp(127.0.0.1:3306)/%s?timeout=3s&readTimeout=3s",
		cfg.DBUser, cfg.DBPass, cfg.DBName))
	if err != nil {
		return false
	}
	defer db.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 4*time.Second)
	defer cancel()
	var v string
	if err := db.QueryRowContext(ctx, "SELECT valore FROM app_config WHERE chiave = 'IS_CENTRAL' LIMIT 1").Scan(&v); err != nil {
		return false
	}
	return strings.TrimSpace(v) == "1"
}

// setRemoteAccess: true = aperto. Errore "procedura assente" (1305) =
// installazione precedente a questa funzionalita'.
func setRemoteAccess(cfg *Config, open bool) error {
	db, err := sql.Open("mysql", fmt.Sprintf("%s:%s@tcp(127.0.0.1:3306)/%s?timeout=3s&readTimeout=3s&writeTimeout=3s",
		cfg.DBUser, cfg.DBPass, cfg.DBName))
	if err != nil {
		return err
	}
	defer db.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	action := "lock"
	if open {
		action = "unlock"
	}
	rows, err := db.QueryContext(ctx, "CALL opensagra_remote_access(?)", action)
	if err != nil {
		return err
	}
	return rows.Close()
}

// watchRemoteAccess: applica lo stato voluto a ogni giro (non solo ai cambi di
// ruolo/fallback) - cosi' se l'account pos_own@'%' viene toccato alle spalle
// del wrapper (es. un installer rilanciato ad app aperta) il riallineamento
// avviene entro remoteAccessEvery, non entro i minuti che ci metterebbe a
// notare un cambio di "want". Visto dal vivo (installer rilanciato con
// l'account gia' sbloccato): le casse restavano fuori per diversi minuti
// nonostante il wrapper pensasse di aver gia' aperto l'accesso. MariaDB puo'
// partire dopo il wrapper al boot: un errore si ritenta al giro dopo.
func watchRemoteAccess(ctx context.Context, cfg *Config) {
	logged, lastErr := false, ""
	var loggedOpen bool
	t := time.NewTicker(remoteAccessEvery)
	defer t.Stop()
	for {
		want := wantRemoteAccess(cfg)
		if err := setRemoteAccess(cfg, want); err != nil {
			if msg := err.Error(); msg != lastErr {
				if strings.Contains(msg, "1305") {
					log.Print("accesso dalla rete: procedura assente (installazione precedente) - reinstalla per attivarlo")
				} else {
					log.Printf("accesso dalla rete: impossibile applicarlo (%v), riprovo", err)
				}
				lastErr = msg
			}
		} else {
			if !logged || want != loggedOpen {
				log.Printf("accesso dalla rete al database: %s", map[bool]string{true: "aperto", false: "chiuso"}[want])
			}
			logged, loggedOpen, lastErr = true, want, ""
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

// closeRemoteAccessOnExit: OpenSagra si chiude -> accesso chiuso.
func closeRemoteAccessOnExit(cfg *Config) {
	if err := setRemoteAccess(cfg, false); err != nil {
		log.Printf("accesso dalla rete: chiusura all'uscita non riuscita: %v", err)
	}
}
