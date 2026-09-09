package main

import (
	"flag"
	"os"
	"path/filepath"
	"sync"
)

// Config: tutto quello che serve al wrapper per lanciare i figli. Le chiavi
// Mercure NON sono qui di proposito: il wrapper non firma né vede JWT, sono i
// processi PHP a leggersi config/variabili.env da soli. Del file .env qui
// interessa solo PRINT_BRIDGE_CASSE (quante istanze del bridge avviare) e le
// credenziali DB per il check "N casse collegate" all'uscita.
type Config struct {
	AppRoot           string   // cartella con Caddyfile, bin/, config/
	Frankenphp        string   // path assoluto a frankenphp(.exe)
	LogDir            string   // dove finiscono i log dei figli + wrapper.log
	AppURL            string   // URL dell'app vera ("Apri OpenSagra")
	Autostarted       bool     // avviato dall'autostart (-autostarted): non aprire la finestra da solo
	RegisterAutostart bool     // -register-autostart: scrivi la chiave Run e prosegui (usato dall'installer)
	BridgeCasse       []string // PRINT_BRIDGE_CASSE, split su virgola

	DBUser string
	DBPass string
	DBName string

	// dbHost: DB_POS_HOST corrente. conf_rete e il fallback locale lo
	// cambiano MENTRE il wrapper gira, quindi va ri-letto a caldo
	// (watchDbHost, in main) - altrimenti "questa macchina e' client / e' il
	// server" resta congelato al valore d'avvio. Accesso via DbHost().
	mu     sync.RWMutex
	dbHost string
}

// DbHost: DB_POS_HOST corrente (thread-safe).
func (c *Config) DbHost() string {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.dbHost
}

// refreshDbHost: rilegge DB_POS_HOST da config/variabili.env. Ritorna true se
// e' cambiato. Best effort: file assente/illeggibile o chiave mancante ->
// lascia invariato il valore corrente.
func (c *Config) refreshDbHost() bool {
	env := readEnvFile(filepath.Join(c.AppRoot, "config", "variabili.env"))
	raw, ok := env["DB_POS_HOST"]
	if !ok {
		return false
	}
	h := valueOr(raw, "127.0.0.1")
	c.mu.Lock()
	defer c.mu.Unlock()
	if h == c.dbHost {
		return false
	}
	c.dbHost = h
	return true
}

func loadConfig() (*Config, error) {
	var (
		rootFlag = flag.String("root", "", "cartella radice dell'app (default: risalendo dall'eseguibile fino a un Caddyfile)")
		fpFlag   = flag.String("frankenphp", "", "path a frankenphp.exe (default: autorilevato)")
		logFlag  = flag.String("logdir", "", "cartella dei log (default: <eseguibile>/logs)")
		urlFlag  = flag.String("appurl", "", "URL dell'app per \"Apri OpenSagra\" (default: http://localhost/)")
		autoFlag = flag.Bool("autostarted", false, "avviato dall'autostart: non aprire la finestra di stato all'avvio")
		regFlag  = flag.Bool("register-autostart", false, "scrivi la chiave di autostart poi prosegui (usato dall'installer)")
	)
	flag.Parse()

	exe, err := os.Executable()
	if err != nil {
		return nil, err
	}
	exeDir := filepath.Dir(exe)

	root := firstNonEmpty(*rootFlag, os.Getenv("OPENSAGRA_ROOT"))
	if root == "" {
		root = detectAppRoot(exeDir)
	}
	if abs, err := filepath.Abs(root); err == nil {
		root = abs
	}

	fp := firstNonEmpty(*fpFlag, os.Getenv("FRANKENPHP_BIN"))
	if fp == "" {
		fp = detectFrankenphp()
	}

	env := readEnvFile(filepath.Join(root, "config", "variabili.env"))

	return &Config{
		AppRoot:           root,
		Frankenphp:        fp,
		LogDir:            firstNonEmpty(*logFlag, filepath.Join(exeDir, "logs")),
		AppURL:            firstNonEmpty(*urlFlag, os.Getenv("OPENSAGRA_APP_URL"), "http://localhost/"),
		Autostarted:       *autoFlag,
		RegisterAutostart: *regFlag,
		BridgeCasse:       splitCsv(env["PRINT_BRIDGE_CASSE"]),
		dbHost:            valueOr(env["DB_POS_HOST"], "127.0.0.1"),
		DBUser:            env["DB_POS_USER"],
		DBPass:            env["DB_POS_PASS"],
		DBName:            "opensagra_pos", // non ancora parametrizzato lato app, vedi env_reader.php
	}, nil
}
