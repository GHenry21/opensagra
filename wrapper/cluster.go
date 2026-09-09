package main

import (
	"context"
	"database/sql"
	"fmt"
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
	switch strings.ToLower(strings.TrimSpace(cfg.DBHost)) {
	case "", "127.0.0.1", "localhost", "::1":
		return true
	default:
		return false
	}
}

// activeClientCount: quanti IP non-locali sono connessi al MariaDB locale.
// Best effort assoluto: qualunque errore -> 0, non deve MAI bloccare l'uscita.
//
// Le casse client fanno connessioni brevi (un poll ogni ~6s, ~20ms l'una):
// un solo SHOW PROCESSLIST le manca quasi sempre. Campioniamo 4 volte in ~1.5s
// e uniamo gli IP visti.
func activeClientCount(cfg *Config) int {
	if !isThisMachineServer(cfg) {
		return 0
	}
	db, err := sql.Open("mysql", fmt.Sprintf("%s:%s@tcp(127.0.0.1:3306)/", cfg.DBUser, cfg.DBPass))
	if err != nil {
		return 0
	}
	defer db.Close()

	seen := map[string]struct{}{}
	for i := 0; i < 4; i++ {
		if i > 0 {
			time.Sleep(400 * time.Millisecond)
		}
		for _, ip := range remoteHostsOnce(db) {
			seen[ip] = struct{}{}
		}
	}
	return len(seen)
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
