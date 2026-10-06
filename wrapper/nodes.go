package main

import (
	"context"
	"database/sql"
	"fmt"
	"log"
	"net"
	"os"
	"strings"
	"sync"
	"time"
)

// Versioni fra PC collegati (piano, Fase 6c punto A). Ogni client esegue il
// PROPRIO codice PHP ma scrive sul DB del server: se le versioni divergono
// (schema, messaggi Mercure, push del fallback) qualcuno se ne deve accorgere.
//
// Ogni wrapper, all'avvio e ogni nodeHeartbeatEvery, scrive sul DB a cui punta
// (DB_POS_HOST) una riga in app_nodi con hostname/ruolo/versione; il wrapper
// del SERVER scrive anche app_config.SERVER_APP_VERSION, che e' quello che un
// client confronta con la propria versione (qui per la finestra di stato,
// api/db_status.php per l'avviso in sidebar dell'app). Un client in fallback
// locale (DB_POS_HOST=127.0.0.1) risulta "server" del proprio DB locale: scrive
// li', innocuo.
const (
	nodeHeartbeatEvery = 60 * time.Second
	// nodeFreshWindow: oltre questo silenzio una macchina non si mostra piu'
	// fra quelle collegate (3 battiti persi).
	nodeFreshWindow = 3 * time.Minute

	serverVersionKey = "SERVER_APP_VERSION"
)

// nodeInfo: una riga di app_nodi, per la finestra di stato del server.
type nodeInfo struct {
	Hostname string `json:"hostname"`
	Role     string `json:"role"`
	Version  string `json:"version"`
	LastSeen string `json:"last_seen"` // "HH:MM:SS", ora del DB
	Self     bool   `json:"self"`
}

var (
	peersMu       sync.Mutex
	peersServer   string     // SERVER_APP_VERSION letta all'ultimo battito ("" = ignota)
	peersNodes    []nodeInfo // solo se questa macchina e' server
	peersLastErr  string
	peersLastSeen time.Time
)

// versionPeers: ultimo stato noto, per handleStatus (nessun accesso al DB qui:
// la pagina fa polling ogni 2s, il DB lo tocca solo nodeHeartbeatLoop).
func versionPeers() (serverVersion string, nodes []nodeInfo) {
	peersMu.Lock()
	defer peersMu.Unlock()
	return peersServer, append([]nodeInfo(nil), peersNodes...)
}

// nodeHeartbeatLoop: un battito subito, poi ogni nodeHeartbeatEvery. Best
// effort: un DB irraggiungibile non deve mai disturbare il resto del wrapper.
func nodeHeartbeatLoop(ctx context.Context, cfg *Config) {
	nodeHeartbeat(cfg)
	t := time.NewTicker(nodeHeartbeatEvery)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			nodeHeartbeat(cfg)
		}
	}
}

func nodeHeartbeat(cfg *Config) {
	serverVer, nodes, err := nodeHeartbeatOnce(cfg)
	peersMu.Lock()
	defer peersMu.Unlock()
	if err != nil {
		// Logga solo il cambio di errore, non 60 righe l'ora uguali.
		if msg := err.Error(); msg != peersLastErr {
			log.Printf("wrapper: battito versione verso il DB fallito: %v", err)
			peersLastErr = msg
		}
		// Oltre la finestra il dato vecchio non e' piu' affidabile.
		if time.Since(peersLastSeen) > nodeFreshWindow {
			peersServer, peersNodes = "", nil
		}
		return
	}
	peersLastErr = ""
	peersLastSeen = time.Now()
	peersServer, peersNodes = serverVer, nodes
}

func nodeHeartbeatOnce(cfg *Config) (string, []nodeInfo, error) {
	host := strings.TrimSpace(cfg.DbHost())
	if host == "" {
		host = "127.0.0.1"
	}
	dsn := fmt.Sprintf("%s:%s@tcp(%s)/%s?timeout=3s&readTimeout=3s&writeTimeout=3s",
		cfg.DBUser, cfg.DBPass, net.JoinHostPort(host, "3306"), cfg.DBName)
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return "", nil, err
	}
	defer db.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()

	isServer := isThisMachineServer(cfg)
	role := "client"
	if isServer {
		role = "server"
	}
	version := installedVersion(cfg)
	hostname, _ := os.Hostname()
	if hostname == "" {
		hostname = "sconosciuto"
	}

	// Tabelle create qui al volo (stesso schema di ensureAppConfigTable in
	// config/app_config.php): il battito di un client puo' arrivare su un
	// server con codice piu' vecchio, che non le ha ancora.
	if _, err := db.ExecContext(ctx, "CREATE TABLE IF NOT EXISTS app_nodi ("+
		" hostname VARCHAR(255) NOT NULL PRIMARY KEY,"+
		" ruolo VARCHAR(16) NOT NULL,"+
		" versione VARCHAR(64) NOT NULL,"+
		" last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"+
		") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); err != nil {
		return "", nil, err
	}
	if _, err := db.ExecContext(ctx,
		"INSERT INTO app_nodi (hostname, ruolo, versione, last_seen) VALUES (?, ?, ?, CURRENT_TIMESTAMP)"+
			" ON DUPLICATE KEY UPDATE ruolo = VALUES(ruolo), versione = VALUES(versione), last_seen = CURRENT_TIMESTAMP",
		hostname, role, version); err != nil {
		return "", nil, err
	}

	if isServer {
		if _, err := db.ExecContext(ctx, "CREATE TABLE IF NOT EXISTS app_config ("+
			" chiave VARCHAR(64) NOT NULL PRIMARY KEY,"+
			" valore TEXT NOT NULL,"+
			" updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"+
			") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); err != nil {
			return "", nil, err
		}
		if _, err := db.ExecContext(ctx,
			"INSERT INTO app_config (chiave, valore) VALUES (?, ?) ON DUPLICATE KEY UPDATE valore = VALUES(valore)",
			serverVersionKey, version); err != nil {
			return "", nil, err
		}

		rows, err := db.QueryContext(ctx,
			"SELECT hostname, ruolo, versione, DATE_FORMAT(last_seen, '%H:%i:%s') FROM app_nodi"+
				" WHERE last_seen > NOW() - INTERVAL ? SECOND ORDER BY ruolo DESC, hostname",
			int(nodeFreshWindow/time.Second))
		if err != nil {
			return "", nil, err
		}
		defer rows.Close()
		var nodes []nodeInfo
		for rows.Next() {
			var n nodeInfo
			if err := rows.Scan(&n.Hostname, &n.Role, &n.Version, &n.LastSeen); err != nil {
				return "", nil, err
			}
			n.Self = n.Hostname == hostname
			nodes = append(nodes, n)
		}
		return version, nodes, rows.Err()
	}

	var serverVer string
	err = db.QueryRowContext(ctx, "SELECT valore FROM app_config WHERE chiave = ? LIMIT 1", serverVersionKey).Scan(&serverVer)
	if err == sql.ErrNoRows || isMissingTable(err) {
		return "", nil, nil // server con wrapper precedente a questa funzionalita'
	}
	if err != nil {
		return "", nil, err
	}
	return strings.TrimSpace(serverVer), nil, nil
}

// isMissingTable: errore MySQL 1146 (tabella inesistente) - app_config manca
// su un server che non l'ha mai creata.
func isMissingTable(err error) bool {
	return err != nil && strings.Contains(err.Error(), "1146")
}

// sameAppVersion: confronto per l'avviso "versioni diverse" - ignora una "v"
// iniziale e gli spazi.
func sameAppVersion(a, b string) bool {
	norm := func(s string) string { return strings.TrimPrefix(strings.TrimSpace(s), "v") }
	return norm(a) == norm(b)
}
