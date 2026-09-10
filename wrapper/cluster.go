package main

import (
	"context"
	"database/sql"
	"fmt"
	"log"
	"net"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

// isThisMachineServer: DB_POS_HOST locale => questa installazione E' il server
// (o e' indipendente). Se il DB e' remoto siamo un client: uscendo spegniamo
// solo i nostri processi, nessuno dipende da noi.
func isThisMachineServer(cfg *Config) bool {
	switch strings.ToLower(strings.TrimSpace(cfg.DbHost())) {
	case "", "127.0.0.1", "localhost", "::1":
		return true
	default:
		return false
	}
}

// activeClientCount: quante macchine client stanno usando questo PC come
// server, adesso. Best effort assoluto: qualunque errore -> non conta quella
// sorgente, non deve MAI bloccare l'uscita. Unione di due segnali:
//
//  1. IP non-locali connessi al MariaDB locale (SHOW PROCESSLIST). Le casse
//     fanno connessioni brevi (~20ms ogni ~6s), campioniamo 4x in 1,5s.
//  2. connessioni TCP ESTABLISHED in ingresso su 80/443 da IP non-locali: il
//     relay di ogni client tiene una SSE persistente verso l'hub, e i tablet
//     "thin client" navigano l'app del server. Cattura anche la cassa ferma su
//     billing.php col realtime attivo, che il DB non lo tocca quasi mai.
func activeClientCount(cfg *Config) int {
	if !isThisMachineServer(cfg) {
		return 0
	}
	seen := map[string]struct{}{}

	if db, err := sql.Open("mysql", fmt.Sprintf("%s:%s@tcp(127.0.0.1:3306)/", cfg.DBUser, cfg.DBPass)); err == nil {
		for i := 0; i < 4; i++ {
			if i > 0 {
				time.Sleep(400 * time.Millisecond)
			}
			for _, ip := range remoteHostsOnce(db) {
				seen[ip] = struct{}{}
			}
		}
		db.Close()
	}

	for _, ip := range webClientIPs() {
		seen[ip] = struct{}{}
	}

	return len(seen)
}

// webClientIPs: IP remoti con una connessione TCP ESTABLISHED verso la 80/443
// di questa macchina (da `netstat -an -p TCP`). Esclude loopback e gli IP
// locali.
func webClientIPs() []string {
	cmd := exec.Command("netstat", "-an", "-p", "TCP")
	hideWindow(cmd)
	out, err := cmd.Output()
	if err != nil {
		return nil
	}
	locals := localIPSet()
	seen := map[string]struct{}{}
	for _, line := range strings.Split(string(out), "\n") {
		f := strings.Fields(line)
		// Proto  IndirizzoLocale  IndirizzoEsterno  Stato
		if len(f) < 4 || !strings.EqualFold(f[0], "TCP") || !strings.EqualFold(f[3], "ESTABLISHED") {
			continue
		}
		if p := hostPortSplit(f[1]); p != "80" && p != "443" {
			continue
		}
		ip := ipFromHostPort(f[2])
		if ip == "" {
			continue
		}
		if _, local := locals[ip]; local {
			continue
		}
		if parsed := net.ParseIP(ip); parsed == nil || parsed.IsLoopback() || parsed.IsUnspecified() {
			continue
		}
		seen[ip] = struct{}{}
	}
	out2 := make([]string, 0, len(seen))
	for ip := range seen {
		out2 = append(out2, ip)
	}
	return out2
}

func hostPortSplit(hp string) string { // ritorna la porta
	if i := strings.LastIndexByte(hp, ':'); i >= 0 {
		return hp[i+1:]
	}
	return ""
}

func ipFromHostPort(hp string) string {
	i := strings.LastIndexByte(hp, ':')
	if i < 0 {
		return hp
	}
	ip := strings.TrimSuffix(strings.TrimPrefix(hp[:i], "["), "]")
	return ip
}

func localIPSet() map[string]struct{} {
	m := map[string]struct{}{"0.0.0.0": {}, "::": {}, "127.0.0.1": {}, "::1": {}}
	addrs, _ := net.InterfaceAddrs()
	for _, a := range addrs {
		if ipnet, ok := a.(*net.IPNet); ok {
			m[ipnet.IP.String()] = struct{}{}
		}
	}
	return m
}

func remoteHostsOnce(db *sql.DB) []string {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	rows, err := db.QueryContext(ctx, "SHOW PROCESSLIST")
	if err != nil {
		return nil
	}
	defer rows.Close()

	cols, err := rows.Columns()
	if err != nil {
		return nil
	}
	hostIdx := indexOfFold(cols, "Host")
	if hostIdx < 0 {
		return nil
	}

	cells := make([]sql.NullString, len(cols))
	ptrs := make([]any, len(cols))
	for i := range cells {
		ptrs[i] = &cells[i]
	}
	var out []string
	for rows.Next() {
		if err := rows.Scan(ptrs...); err != nil {
			continue
		}
		host := cells[hostIdx].String
		if i := strings.LastIndexByte(host, ':'); i >= 0 { // "192.168.1.5:53421" -> "192.168.1.5"
			host = host[:i]
		}
		switch strings.ToLower(host) {
		case "", "localhost", "127.0.0.1", "::1":
			continue
		}
		out = append(out, host)
	}
	return out
}

// runAnnounce: riusa il CLI PHP gia' esistente invece di reimplementare la
// firma JWT / il POST Mercure in Go. Ritorna nil solo se l'hub ha accettato
// (opensagra-announce.php esce 0 solo in quel caso).
func runAnnounce(cfg *Config, kind string) error {
	ctx, cancel := context.WithTimeout(context.Background(), 4*time.Second)
	defer cancel()

	cmd := exec.CommandContext(ctx, cfg.Frankenphp, "php-cli",
		filepath.Join(cfg.AppRoot, "bin", "opensagra-announce.php"), "--kind="+kind)
	cmd.Dir = cfg.AppRoot
	hideWindow(cmd)
	return cmd.Run()
}

// announceShutdown: un colpo, best-effort, prima di fermare FrankenPHP. Se
// l'hub e' gia' giu' pazienza, non blocchiamo l'uscita.
func announceShutdown(cfg *Config) {
	if isThisMachineServer(cfg) {
		_ = runAnnounce(cfg, "shutdown")
	}
}

// announceBack: dice ai client di pulire il banner "server giu'". L'hub puo'
// non essere pronto nell'istante della chiamata: qualche tentativo. I client si
// ripuliscono comunque al primo evento non-announce o dopo 10 min.
func announceBack(ctx context.Context, cfg *Config) {
	if !isThisMachineServer(cfg) {
		return
	}
	for i := 0; i < 6; i++ {
		if runAnnounce(cfg, "back") == nil {
			return
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(3 * time.Second):
		}
	}
}

// announceBackWhenUp: aspetta che il figlio frankenphp risulti attivo (max
// ~40s) poi pubblica l'announce "back". Usato all'avvio del wrapper e dopo un
// "Avvia server" dalla finestra di stato.
func announceBackWhenUp(ctx context.Context, sup *Supervisor, cfg *Config) {
	if !isThisMachineServer(cfg) {
		return
	}
	for i := 0; i < 40; i++ {
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
}

// watchDbHost: conf_rete (switch manuale) e api/enter_local_fallback.php
// riscrivono DB_POS_HOST in config/variabili.env mentre il wrapper gira. Senza
// ri-leggerlo, la finestra di stato continuerebbe a dire "CLIENT" (o "SERVER")
// col valore d'avvio, e l'avviso "N casse collegate" all'uscita userebbe il
// ruolo sbagliato. Poll leggero: un file di poche righe ogni 15s.
//
// Al cambio ruolo regola anche il figlio snapshot: gira solo quando la macchina
// e' client (serve a tenere pronto il DB locale per il fallback). Su server /
// indipendente resta in pausa - e' il processo che ha azzerato il catalogo il
// 2026-09-10.
func watchDbHost(ctx context.Context, cfg *Config, sup *Supervisor) {
	syncSnapshotChild(cfg, sup) // stato iniziale coerente col ruolo d'avvio

	t := time.NewTicker(15 * time.Second)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			if cfg.refreshDbHost() {
				role := "CLIENT (DB remoto)"
				if isThisMachineServer(cfg) {
					role = "SERVER / indipendente (DB locale)"
				}
				log.Printf("wrapper: DB_POS_HOST cambiato -> %s | ruolo ora: %s", cfg.DbHost(), role)
				syncSnapshotChild(cfg, sup)
			}
		}
	}
}

// syncSnapshotChild: pausa lo snapshot se la macchina e' server/indipendente,
// lo riprende se e' client. Idempotente (pause/resume su uno stato gia' giusto
// non fanno nulla di dannoso).
func syncSnapshotChild(cfg *Config, sup *Supervisor) {
	if sup == nil {
		return
	}
	if isThisMachineServer(cfg) {
		if !sup.isPaused(snapshotChildName) {
			log.Printf("wrapper: metto in pausa il figlio %q (macchina non client)", snapshotChildName)
		}
		sup.pause(snapshotChildName)
		return
	}
	if sup.isPaused(snapshotChildName) {
		log.Printf("wrapper: riprendo il figlio %q (macchina client)", snapshotChildName)
	}
	sup.resume(snapshotChildName)
}
