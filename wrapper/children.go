package main

import (
	"path/filepath"
	"time"
)

// buildChildren: i processi che il wrapper supervisiona.
//
// Contratto exit-code (vedi README):
//   - frankenphp e' il server: AlwaysRestart. Qualsiasi uscita = riavvio con backoff.
//   - relay/bridge fanno exit(0) quando "non c'e' niente da fare" (relay: non e'
//     un client; bridge: nessuna cassa o segreto Mercure non ancora sincronizzato
//     da conf_rete). exit(0) => NON martellare: si riprova dopo IdleRecheck, cosi'
//     quando conf_rete scrive il segreto il bridge riparte da solo. exit!=0 =>
//     crash vero => backoff.
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
	}

	for _, cassa := range cfg.BridgeCasse {
		children = append(children, &Child{
			Name:        "bridge:" + cassa,
			Dir:         cfg.AppRoot,
			Bin:         cfg.Frankenphp,
			Args:        phpCli("bin/opensagra-print-bridge.php", "--cassa="+cassa),
			IdleRecheck: 30 * time.Second, // exit(0) = segreto non ancora sincronizzato: riprova presto
		})
	}
	return children
}
