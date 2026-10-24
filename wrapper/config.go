package main

import (
	"flag"
	"os"
	"path/filepath"
	"strings"
	"sync"
)

// Config: tutto quello che serve al wrapper per lanciare i figli. Le chiavi
// Mercure NON sono qui di proposito: il wrapper non firma né vede JWT, sono i
// processi PHP a leggersi config/variabili.env da soli. Del file .env qui
// interessa solo PRINT_BRIDGE_CASSE (se avviare il bridge) e le credenziali
// DB per il check "N casse collegate" all'uscita.
type Config struct {
	AppRoot           string // cartella con Caddyfile, bin/, config/
	Frankenphp        string // path assoluto a frankenphp(.exe)
	LogDir            string // dove finiscono i log dei figli + wrapper.log
	AppURL            string // URL dell'app vera ("Apri OpenSagra")
	Autostarted       bool   // avviato dall'autostart (-autostarted): non aprire la finestra da solo
	RegisterAutostart bool   // -register-autostart: scrivi la chiave Run e prosegui (usato dall'installer)
	// Quit: -quit, ferma un'istanza gia' in esecuzione (dopo conferma) ed esce
	// subito, senza avviare nulla. Serve al lanciatore desktop "Ferma OpenSagra"
	// su Linux/macOS (niente tray li' per fare da menu "Esci" come su Windows).
	Quit bool
	// BridgeEnabled: PRINT_BRIDGE_CASSE acceso all'avvio (vedi bridgeFlagOn:
	// ON di default, spento solo con "0" esplicito) -> questo PC fa da ponte
	// di stampa. Un solo processo bridge (topic Mercure fisso, punto-punto):
	// non serve piu' un id per cassa. Solo per il log d'avvio - lo stato vivo
	// usato per pausare/riprendere il figlio e' cfg.bridgeOn (BridgeOn()),
	// perche' una modifica a mano di variabili.env lo puo' cambiare mentre il wrapper gira.
	BridgeEnabled bool

	DBUser string
	DBPass string
	DBName string

	// dbHost: DB_POS_HOST corrente. conf_rete e il fallback locale lo
	// cambiano MENTRE il wrapper gira, quindi va ri-letto a caldo
	// (watchDbHost, in main) - altrimenti "questa macchina e' client / e' il
	// server" resta congelato al valore d'avvio. Accesso via DbHost().
	mu     sync.RWMutex
	dbHost string
	// bridgeOn: PRINT_BRIDGE_CASSE corrente, vedi bridgeFlagOn. Una modifica a mano
	// lo puo' cambiare MENTRE il wrapper gira - senza rilettura a caldo il figlio bridge resterebbe
	// escluso per sempre da buildChildren se non era acceso all'avvio.
	// Accesso via BridgeOn().
	bridgeOn bool
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

// BridgeOn: PRINT_BRIDGE_CASSE corrente, acceso (thread-safe).
func (c *Config) BridgeOn() bool {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.bridgeOn
}

// bridgeFlagOn: ON di default, spento SOLO con "0" esplicito. Ogni
// installer prima di questo (piano 5e, 2026-10-16) scriveva la chiave vuota -
// e' lo stato di ogni installazione esistente oggi, Pi compreso - quindi
// "vuoto = spento" lascerebbe tutte quelle installazioni senza bridge per
// sempre anche dopo un aggiornamento (la migrazione degli installer aggiunge
// la chiave solo se assente, non la tocca se c'e' gia', anche vuota). Un
// valore vuoto non puo' quindi significare "l'utente l'ha spento di
// proposito" - solo "0" (scritto a mano in variabili.env: lo switch "Ponte di stampa"
// della pagina Rete e' stato tolto il 2026-10-19) lo puo'. Qualsiasi altro
// valore non vuoto (incluso un vecchio id-topic tipo "henry", da prima del
// modello punto-punto) resta ON, compatibilita' invariata.
func bridgeFlagOn(raw string) bool {
	return strings.TrimSpace(raw) != "0"
}

// refreshBridgeEnabled: rilegge PRINT_BRIDGE_CASSE da config/variabili.env.
// Ritorna true se e' cambiato. Best effort: file assente/illeggibile -> lascia
// invariato il valore corrente.
func (c *Config) refreshBridgeEnabled() bool {
	env := readEnvFile(filepath.Join(c.AppRoot, "config", "variabili.env"))
	raw, ok := env["PRINT_BRIDGE_CASSE"]
	if !ok {
		return false
	}
	on := bridgeFlagOn(raw)
	c.mu.Lock()
	defer c.mu.Unlock()
	if on == c.bridgeOn {
		return false
	}
	c.bridgeOn = on
	return true
}

func loadConfig() (*Config, error) {
	var (
		rootFlag = flag.String("root", "", "cartella radice dell'app (default: risalendo dall'eseguibile fino a un Caddyfile)")
		fpFlag   = flag.String("frankenphp", "", "path a frankenphp.exe (default: autorilevato)")
		logFlag  = flag.String("logdir", "", "cartella dei log (default: <eseguibile>/logs)")
		urlFlag  = flag.String("appurl", "", "URL dell'app per \"Apri OpenSagra\" (default: https://localhost/)")
		autoFlag = flag.Bool("autostarted", false, "avviato dall'autostart: non aprire la finestra di stato all'avvio")
		regFlag  = flag.Bool("register-autostart", false, "scrivi la chiave di autostart poi prosegui (usato dall'installer)")
		quitFlag = flag.Bool("quit", false, "ferma un'istanza gia' in esecuzione (con conferma) ed esce")
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
		AppURL:            firstNonEmpty(*urlFlag, os.Getenv("OPENSAGRA_APP_URL"), "https://localhost/"),
		Autostarted:       *autoFlag,
		RegisterAutostart: *regFlag,
		Quit:              *quitFlag,
		BridgeEnabled:     bridgeFlagOn(env["PRINT_BRIDGE_CASSE"]),
		dbHost:            valueOr(env["DB_POS_HOST"], "127.0.0.1"),
		bridgeOn:          bridgeFlagOn(env["PRINT_BRIDGE_CASSE"]),
		DBUser:            env["DB_POS_USER"],
		DBPass:            env["DB_POS_PASS"],
		DBName:            "opensagra_pos", // non ancora parametrizzato lato app, vedi env_reader.php
	}, nil
}
