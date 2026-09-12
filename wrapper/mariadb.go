package main

import (
	"context"
	"database/sql"
	"fmt"
	"sync"
	"time"
)

// MariaDB e' un servizio Windows a se': il wrapper NON lo avvia/ferma (gira
// non elevato, e' lo stato durevole condiviso - vedi PIANO-MODIFICHE-WRAPPER.md
// sez. 3). Qui solo un ping read-only sulla 3306 per mostrarne lo stato nella
// finestra e dire "Database fermo" invece di lasciare frankenphp a sbattere.
// SELECT VERSION() fa da ping E da sorgente della versione mostrata nella
// finestra di stato (vedi statusJSON.MariadbVersion) - niente da aggiornare a
// mano ad ogni update di MariaDB, e' sempre quella del servizio realmente in
// esecuzione su questa macchina.

var (
	mariadbMu   sync.Mutex
	mariadbAt   time.Time
	mariadbLast bool
	mariadbVer  string
)

func mariadbStatus(cfg *Config) (up bool, version string) {
	mariadbMu.Lock()
	defer mariadbMu.Unlock()
	if !mariadbAt.IsZero() && time.Since(mariadbAt) < 5*time.Second {
		return mariadbLast, mariadbVer
	}
	mariadbLast, mariadbVer = queryMariadb(cfg)
	mariadbAt = time.Now()
	return mariadbLast, mariadbVer
}

func queryMariadb(cfg *Config) (bool, string) {
	db, err := sql.Open("mysql", fmt.Sprintf(
		"%s:%s@tcp(127.0.0.1:3306)/?timeout=2s&readTimeout=2s&writeTimeout=2s",
		cfg.DBUser, cfg.DBPass))
	if err != nil {
		return false, ""
	}
	defer db.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	var version string
	if err := db.QueryRowContext(ctx, "SELECT VERSION()").Scan(&version); err != nil {
		return false, ""
	}
	return true, version
}
