package main

import (
	"os/exec"
	"regexp"
	"sync"
	"time"
)

// frankenphpVersions: legge "FrankenPHP <ver> PHP <ver> Caddy <ver> ..." da
// `frankenphp version` e la mostra nella finestra di stato accanto al
// processo frankenphp - cosi' non va aggiornata a mano nel codice ad ogni
// update del binario, e' sempre quella realmente installata su questa
// macchina. Cache lunga (il binario non cambia mentre il wrapper gira); se il
// comando fallisce (es. all'avvio, prima che il path sia risolto) si ritenta
// dopo un breve intervallo invece di restare vuoto per sempre.
var (
	fpVerMu sync.Mutex
	fpVerAt time.Time
	fpVer   string
	phpVer  string
)

var frankenphpVersionRe = regexp.MustCompile(`FrankenPHP\s+(\S+)\s+PHP\s+(\S+)`)

func frankenphpVersions(cfg *Config) (frankenphp, php string) {
	fpVerMu.Lock()
	defer fpVerMu.Unlock()
	if fpVer != "" {
		return fpVer, phpVer
	}
	if !fpVerAt.IsZero() && time.Since(fpVerAt) < 30*time.Second {
		return "", ""
	}
	fpVerAt = time.Now()

	out, err := exec.Command(cfg.Frankenphp, "version").Output()
	if err != nil {
		return "", ""
	}
	m := frankenphpVersionRe.FindStringSubmatch(string(out))
	if m == nil {
		return "", ""
	}
	fpVer, phpVer = m[1], m[2]
	return fpVer, phpVer
}
