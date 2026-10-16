package main

import (
	"path/filepath"
	"time"
)

// snapshotChildName: nome del figlio che esegue bin/opensagra-snapshot.php.
// Riferito anche da main.go / cluster.go per metterlo in pausa quando la
// macchina non e' un client.
const snapshotChildName = "snapshot"

// bridgeChildName: nome del figlio che esegue bin/opensagra-print-bridge.php.
// Riferito anche da cluster.go per metterlo in pausa/ripresa quando
// PRINT_BRIDGE_CASSE cambia mentre il wrapper gira (api/set_bridge_mode.php).
const bridgeChildName = "bridge"

// buildChildren: i processi che il wrapper supervisiona.
//
// Contratto exit-code (vedi README):
//   - frankenphp e' il server: AlwaysRestart. Qualsiasi uscita = riavvio con backoff.
//   - relay/bridge fanno exit(0) quando "non c'e' niente da fare" (relay: non e'
//     un client; bridge: nessuna cassa configurata, o MERCURE_JWT_SECRET locale
//     assente in variabili.env - il bridge ascolta sempre sul proprio hub
//     locale, punto-punto, non dipende piu' da DB_POS_HOST/conf_rete). exit(0)
//     => NON martellare: si riprova dopo IdleRecheck. exit!=0 => crash vero =>
//     backoff.
func buildChildren(cfg *Config) []*Child {
	// I bin sono script CLI eseguiti dall'interprete PHP dentro FrankenPHP:
	//   frankenphp php-cli <script assoluto> [args]
	phpCli := func(relScript string, extra ...string) []string {
		return append([]string{"php-cli", filepath.Join(cfg.AppRoot, relScript)}, extra...)
	}

	children := []*Child{
		{
			Name:          "frankenphp",
			Dir:           cfg.AppRoot,
			Bin:           cfg.Frankenphp,
			Args:          []string{"run", "--config", filepath.Join(cfg.AppRoot, "Caddyfile")},
			AlwaysRestart: true,
		},
		{
			Name:        "relay",
			Dir:         cfg.AppRoot,
			Bin:         cfg.Frankenphp,
			Args:        phpCli("bin/opensagra-realtime-relay.php"),
			IdleRecheck: 60 * time.Second, // exit(0) = non e' un client: ricontrolla di rado
		},
		{
			// Fase 4 punto 4: tiene aggiornato il MariaDB locale del client
			// (catalogo, casse, config) cosi' che il "Fallback locale una-via"
			// abbia un DB pronto se il centrale cade. Non fa exit(0): resta in
			// loop e va idle da solo quando non e' un client o e' in fallback.
			Name:          snapshotChildName,
			Dir:           cfg.AppRoot,
			Bin:           cfg.Frankenphp,
			Args:          phpCli("bin/opensagra-snapshot.php"),
			AlwaysRestart: true,
		},
	}

	// Punto-punto: un solo processo basta per PC, qualunque sia il numero di
	// casse/stampanti servite - il topic Mercure e' fisso, la stampante fisica
	// la sceglie il payload (vedi bin/opensagra-print-bridge.php). Sempre
	// presente nell'elenco (non solo se cfg.BridgeEnabled all'avvio): lo stato
	// iniziale e i cambi a caldo di PRINT_BRIDGE_CASSE li gestisce
	// watchBridgeEnabled via pause/resume, non l'esclusione dalla lista -
	// altrimenti accenderlo da conf_rete.php richiederebbe sempre un riavvio
	// del wrapper.
	children = append(children, &Child{
		Name:        bridgeChildName,
		Dir:         cfg.AppRoot,
		Bin:         cfg.Frankenphp,
		Args:        phpCli("bin/opensagra-print-bridge.php"),
		IdleRecheck: 30 * time.Second, // exit(0) = MERCURE_JWT_SECRET locale assente: riprova presto
	})
	return children
}
