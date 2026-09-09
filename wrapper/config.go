package main

import (
	"flag"
	"os"
	"path/filepath"
)

// Config: tutto quello che serve al wrapper per lanciare i figli. Le chiavi
// Mercure NON sono qui di proposito: il wrapper non firma né vede JWT, sono i
// processi PHP a leggersi config/variabili.env da soli. Del file .env qui
// interessa solo PRINT_BRIDGE_CASSE (quante istanze del bridge avviare) e le
// credenziali DB per il check "N casse collegate" all'uscita.
type Config struct {
	AppRoot     string   // cartella con Caddyfile, bin/, config/
	Frankenphp  string   // path assoluto a frankenphp(.exe)
	LogDir      string   // dove finiscono i log dei figli + wrapper.log
	AppURL      string   // URL dell'app vera ("Apri OpenSagra")
	Autostarted bool     // lanciato dal task at-logon (-autostarted): non aprire la finestra da solo
	BridgeCasse []string // PRINT_BRIDGE_CASSE, split su virgola

	DBHost string // solo per activeClientCount() all'uscita
	DBUser string
	DBPass string
	DBName string
}

func loadConfig() (*Config, error) {
	var (
		rootFlag = flag.String("root", "", "cartella radice dell'app (default: risalendo dall'eseguibile fino a un Caddyfile)")
		fpFlag   = flag.String("frankenphp", "", "path a frankenphp.exe (default: autorilevato)")
		logFlag  = flag.String("logdir", "", "cartella dei log (default: <eseguibile>/logs)")
		urlFlag  = flag.String("appurl", "", "URL dell'app per \"Apri OpenSagra\" (default: http://localhost/)")
		autoFlag = flag.Bool("autostarted", false, "impostato dal task at-logon: non aprire la finestra di stato all'avvio")
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
		AppRoot:     root,
		Frankenphp:  fp,
		LogDir:      firstNonEmpty(*logFlag, filepath.Join(exeDir, "logs")),
		AppURL:      firstNonEmpty(*urlFlag, os.Getenv("OPENSAGRA_APP_URL"), "http://localhost/"),
		Autostarted: *autoFlag,
		BridgeCasse: splitCsv(env["PRINT_BRIDGE_CASSE"]),
		DBHost:      valueOr(env["DB_POS_HOST"], "127.0.0.1"),
		DBUser:      env["DB_POS_USER"],
		DBPass:      env["DB_POS_PASS"],
		DBName:      "opensagra_pos", // non ancora parametrizzato lato app, vedi env_reader.php
	}, nil
}
