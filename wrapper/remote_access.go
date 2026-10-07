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
// dell'utente 2026-10-07): OpenSagra aperto -> aperto, OpenSagra chiuso ->
// chiuso, e su una cassa client sempre chiuso (lavora sul DB del centrale, il
// suo non serve a nessuno; i telefoni/tablet sono solo browser e non espongono
// nulla). Le porte web (80/443) seguono gia' OpenSagra da sole: FrankenPHP e'
// un figlio del wrapper. MariaDB invece e' un servizio di sistema e resta
// acceso: per la 3306 si blocca/sblocca l'account con cui entrano le casse
// client, con la procedura creata dall'installer (config/remote_access.php).
// Niente permessi di amministratore, niente firewall da toccare.
//
// Se il wrapper muore di colpo (crash, PC spento) il blocco d'uscita non
// avviene: con OpenSagra spento la 443 non risponde comunque, e al riavvio
// il wrapper rimette lo stato giusto.
const (
	remoteAccessEvery   = 15 * time.Second
	remoteAccessRefresh = 5 * time.Minute // riapplica comunque, nel caso qualcuno l'abbia cambiato
)

func wantRemoteAccess(cfg *Config) bool {
	return isThisMachineServer(cfg) && !inLocalFallback(cfg)
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

// watchRemoteAccess: applica lo stato voluto all'avvio, poi a ogni cambio
// (ruolo, fallback) e ogni remoteAccessRefresh. MariaDB puo' partire dopo il
// wrapper al boot: un errore si ritenta al giro dopo.
func watchRemoteAccess(ctx context.Context, cfg *Config) {
	applied, lastOK, lastErr := false, time.Time{}, ""
	var appliedOpen bool
	t := time.NewTicker(remoteAccessEvery)
	defer t.Stop()
	for {
		want := wantRemoteAccess(cfg)
		if !applied || want != appliedOpen || time.Since(lastOK) > remoteAccessRefresh {
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
				if !applied || want != appliedOpen {
					log.Printf("accesso dalla rete al database: %s", map[bool]string{true: "aperto", false: "chiuso"}[want])
				}
				applied, appliedOpen, lastOK, lastErr = true, want, time.Now(), ""
			}
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
