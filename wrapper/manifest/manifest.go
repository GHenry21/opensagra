// Package manifest: cosa contiene un'installazione di OpenSagra, per decidere
// OFFLINE se un aggiornamento leggero basta o serve la reinstallazione, e
// quali file cancellare (piano, Fase 6c punto C).
//
// Ogni pacchetto (installer Windows, pacchetti Linux/macOS, zip
// dell'aggiornamento leggero) porta config/.app_manifest.json, generato da
// cmd/mkmanifest al momento della build. L'installazione lo conserva; il
// confronto fra quello installato e quello della versione nuova sostituisce
// l'API compare di GitHub, che un client in sagra spesso non puo' raggiungere.
//
// Le impronte dei file "guard" sono gli id dei blob git (git hash-object):
// applicano la normalizzazione dei fine riga di .gitattributes, quindi una
// build su Windows e una su Linux dello stesso commit danno le stesse.
package manifest

import (
	"encoding/json"
	"fmt"
	"os"
	"sort"
	"strings"
)

// InstalledPath: relativo alla radice dell'app installata.
const InstalledPath = "config/.app_manifest.json"

type Manifest struct {
	Version string `json:"version"`
	// Files: ogni file del codice app installato (relativo, con "/"),
	// vendor/ compreso. Serve a sapere cosa sparisce fra due versioni.
	Files []string `json:"files"`
	// Guard: file che, se cambiano, richiedono la reinstallazione - lo zip
	// porta solo il codice app e non rigenera Caddyfile, php.ini, FrankenPHP,
	// wrapper. path -> id del blob git.
	Guard map[string]string `json:"guard"`
}

// GuardPrefixes: composer.*: una nuova estensione PHP richiesta non si
// abilita copiando vendor/. "install" copre anche installer/ (installer
// grafico) e "uninstall" i disinstaller.
var GuardPrefixes = []string{"wrapper/", "packaging/", "install", "uninstall", "composer.json", "composer.lock"}

func IsGuard(path string) bool {
	for _, p := range GuardPrefixes {
		if strings.HasPrefix(path, p) {
			return true
		}
	}
	return false
}

// ExcludedTopLevel: cartelle/file del repo che non fanno parte del codice app
// installato (materiale di sviluppo, installer, documentazione). L'unica
// lista usata sia per il manifesto sia per lo zip dell'aggiornamento leggero
// (packaging/make-update.ps1 legge Files dal manifesto).
var ExcludedTopLevel = map[string]bool{
	".git": true, ".github": true, ".vscode": true, ".claude": true, ".cache": true,
	".gitignore": true, ".gitattributes": true,
	"e2e": true, "docs": true, "archive": true, "node_modules": true,
	"playwright-report": true, "test-results": true,
	"package.json": true, "package-lock.json": true, "playwright.config.js": true,
	"bt.html": true, "navbar example.html": true,
	"wrapper": true, "packaging": true, "installer": true, "private": true,
	"install.ps1": true, "uninstall.ps1": true,
	"install.sh": true, "uninstall.sh": true, "install-macos.sh": true, "uninstall-macos.sh": true,
	"sync-vm.ps1": true, "README.md": true, "Caddyfile.example": true, "Caddyfile": true,
}

func IsAppFile(path string) bool {
	top := path
	if i := strings.IndexByte(path, '/'); i >= 0 {
		top = path[:i]
	}
	return !ExcludedTopLevel[top]
}

func Load(path string) (*Manifest, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	return Parse(raw)
}

func Parse(raw []byte) (*Manifest, error) {
	var m Manifest
	if err := json.Unmarshal(raw, &m); err != nil {
		return nil, err
	}
	if m.Version == "" || len(m.Files) == 0 || m.Guard == nil {
		return nil, fmt.Errorf("manifesto incompleto")
	}
	return &m, nil
}

// Plan: removed = file dell'installazione che la versione nuova non ha piu'
// (esclusi i prefissi in keep, es. uploads/ con le foto dell'utente);
// reason != "" se serve la reinstallazione.
func Plan(installed, latest *Manifest, keep []string) (removed []string, reason string) {
	if installed == nil {
		return nil, "l'installazione non ha il manifesto dei file (versione precedente o copia di sviluppo)"
	}
	if latest == nil {
		return nil, "il pacchetto nuovo non ha il manifesto dei file"
	}
	paths := map[string]bool{}
	for p := range installed.Guard {
		paths[p] = true
	}
	for p := range latest.Guard {
		paths[p] = true
	}
	var changed []string
	for p := range paths {
		if installed.Guard[p] != latest.Guard[p] {
			changed = append(changed, p)
		}
	}
	if len(changed) > 0 {
		sort.Strings(changed)
		return nil, "questa versione aggiorna anche " + changed[0]
	}

	have := map[string]bool{}
	for _, f := range latest.Files {
		have[f] = true
	}
	for _, f := range installed.Files {
		if have[f] {
			continue
		}
		skip := false
		for _, k := range keep {
			if strings.HasPrefix(f, k) {
				skip = true
				break
			}
		}
		if !skip {
			removed = append(removed, f)
		}
	}
	sort.Strings(removed)
	return removed, ""
}
