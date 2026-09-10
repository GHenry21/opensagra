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

var (
	mariadbMu   sync.Mutex
	mariadbAt   time.Time
	mariadbLast bool
)

func mariadbUp(cfg *Config) bool {
	mariadbMu.Lock()
	defer mariadbMu.Unlock()
	if !mariadbAt.IsZero() && time.Since(mariadbAt) < 5*time.Second {
		return mariadbLast
	}
	mariadbLast = pingMariadb(cfg)
	mariadbAt = time.Now()
	return mariadbLast
}

func pingMariadb(cfg *Config) bool {
	db, err := sql.Open("mysql", fmt.Sprintf(
		"%s:%s@tcp(127.0.0.1:3306)/?timeout=2s&readTimeout=2s&writeTimeout=2s",
		cfg.DBUser, cfg.DBPass))
	if err != nil {
		return false
	}
	defer db.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	return db.PingContext(ctx) == nil
}
