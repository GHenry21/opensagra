package main

import (
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

// WrapperVersion: versione del BINARIO del wrapper, iniettata a build time
// da wrapper/build.ps1 via "-ldflags -X main.WrapperVersion=..." (dallo
// stesso tag git usato per la VERSIONINFO dell'exe e per config/.installed_version -
// vedi packaging/Get-ReleaseVersion.ps1). Il valore qui e' solo il fallback
// per chi lancia `go build` a mano senza passare da build.ps1. Serve solo
// come valore di partenza per installedVersion() la prima volta (vedi sotto)
// - il confronto vero e proprio usa il marcatore su disco, non questa
// variabile, perche' un aggiornamento "leggero" (solo codice PHP, vedi
// self_update.go) non ricompila il wrapper.
var WrapperVersion = "0.0.0-dev"

// UpdateRepo: repo GitHub "owner/nome" da cui leggere le release.
const UpdateRepo = "GHenry21/opensagra"

// updateZipAssetName: pacchetto dell'aggiornamento leggero (solo codice app +
// vendor/, packaging/make-update.ps1), allegato dalla CI a OGNI release
// insieme al suo .sha256. Se usarlo o no NON lo decide piu' la CI ma questo
// wrapper, confrontando il tag installato con quello nuovo (lightUpdatePlan,
// piano Fase 6a): la CI vedeva solo il tag precedente, e chi saltava una
// release che richiedeva la reinstallazione riceveva lo zip lo stesso.
//
// Nome diverso dal vecchio "opensagra-update.zip" APPOSTA: i wrapper gia'
// installati (fino alla v1.0.0) applicano quello alla cieca; non trovandolo
// ricadono da soli su "serve una reinstallazione completa", che installa
// questo wrapper.
const updateZipAssetName = "opensagra-app-update.zip"

// fullUpdatePaths: se fra installata e nuova cambia uno di questi percorsi,
// serve la reinstallazione completa - lo zip porta solo il codice app e non
// rigenera Caddyfile/php.ini/FrankenPHP/wrapper. composer.*: una nuova
// estensione PHP richiesta non si abilita copiando vendor/. "install" copre
// anche installer/ (installer grafico).
var fullUpdatePrefixes = []string{"wrapper/", "packaging/", "install", "uninstall", "composer.json", "composer.lock"}

// keepOnRemove: file rimossi dal repo che l'aggiornamento NON cancella
// dall'installazione - uploads/ contiene anche le foto caricate dall'utente
// (e foto seed a cui un prodotto puo' ancora puntare).
var keepOnRemovePrefixes = []string{"uploads/"}

// installedVersionFile: relativo ad AppRoot. Scritto da install.ps1 al primo
// setup e da ApplyUpdate() dopo ogni aggiornamento leggero applicato - NON
// e' tracciato da git (un aggiornamento che sostituisce i file dell'app non
// lo tocca, quindi sopravvive intatto attraverso gli aggiornamenti).
const installedVersionFile = "config/.installed_version"

// UpdateInfo: risultato di un controllo aggiornamenti.
type UpdateInfo struct {
	Available bool
	Latest    string
	HTMLURL   string
	ZipURL    string // "" se da QUESTA installazione serve una reinstallazione completa
	SHA256URL string // impronta dello zip, obbligatoria (ApplyUpdate rifiuta senza)
	Removed   []string
	// FullReason: perche' serve la reinstallazione (valorizzato se ZipURL == "").
	FullReason string
	Changelog  string // note della release (vuoto se non disponibile o nessun aggiornamento)
}

// L'UNICO punto di tutto il wrapper che tocca la rete pubblica (Internet),
// insieme al download in self_update.go - tutto il resto e' solo-LAN/loopback,
// e l'app funziona senza Internet. Cache lunga apposta: nessun bisogno di
// ricontrollare piu' spesso di qualche ora, ed evita di consumare la quota di
// richieste non autenticate di GitHub (60/h per IP; un controllo con
// aggiornamento disponibile ne usa due: release + compare).
var (
	updateMu   sync.Mutex
	updateAt   time.Time
	cachedInfo UpdateInfo
)

// invalidateUpdateCache: forza checkForUpdate() a rifare la richiesta a
// GitHub alla prossima chiamata, invece di aspettare le 12h di cache - senza
// questo, dopo un aggiornamento leggero applicato con successo l'interfaccia
// continuerebbe a offrire per ore un aggiornamento gia' installato (il
// confronto con installedVersion() non passa mai da qui, solo il timestamp).
func invalidateUpdateCache() {
	updateMu.Lock()
	updateAt = time.Time{}
	updateMu.Unlock()
}

type ghAsset struct {
	Name               string `json:"name"`
	BrowserDownloadURL string `json:"browser_download_url"`
}

type ghRelease struct {
	TagName string    `json:"tag_name"`
	HTMLURL string    `json:"html_url"`
	Body    string    `json:"body"`
	Assets  []ghAsset `json:"assets"`
}

// installedVersion: versione del CODICE applicativo attualmente installato -
// distinta da WrapperVersion (quella del binario, cambia solo ricompilando).
// Se il marcatore manca (installazione precedente a questa funzionalita', o
// test senza passare da install.ps1), si assume WrapperVersion e si scrive
// il marcatore per le prossime volte - auto-riparante, non richiede una
// migrazione esplicita.
func installedVersion(cfg *Config) string {
	path := filepath.Join(cfg.AppRoot, installedVersionFile)
	if b, err := os.ReadFile(path); err == nil {
		if v := strings.TrimSpace(string(b)); v != "" {
			return v
		}
	}
	_ = os.WriteFile(path, []byte(WrapperVersion), 0o644)
	return WrapperVersion
}

// checkForUpdate: cache-and-refresh, sempre sicuro da chiamare spesso (poll
// della finestra di stato compreso) - la vera richiesta di rete parte al
// massimo una volta ogni 12h.
func checkForUpdate(cfg *Config) UpdateInfo {
	updateMu.Lock()
	defer updateMu.Unlock()
	if !updateAt.IsZero() && time.Since(updateAt) < 12*time.Hour {
		return cachedInfo
	}
	updateAt = time.Now()
	cachedInfo = UpdateInfo{}

	req, err := http.NewRequest(http.MethodGet,
		fmt.Sprintf("https://api.github.com/repos/%s/releases/latest", UpdateRepo), nil)
	if err != nil {
		return cachedInfo
	}
	// User-Agent obbligatorio per l'API di GitHub, altrimenti risponde 403.
	req.Header.Set("User-Agent", "opensagra-wrapper")
	req.Header.Set("Accept", "application/vnd.github+json")

	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return cachedInfo
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return cachedInfo
	}

	var rel ghRelease
	if err := json.NewDecoder(resp.Body).Decode(&rel); err != nil {
		return cachedInfo
	}

	latestVer := strings.TrimPrefix(strings.TrimSpace(rel.TagName), "v")
	if latestVer == "" {
		return cachedInfo
	}

	installed := installedVersion(cfg)
	cachedInfo.Latest = latestVer
	cachedInfo.HTMLURL = rel.HTMLURL
	cachedInfo.Available = isNewerVersion(latestVer, installed)
	if !cachedInfo.Available {
		return cachedInfo
	}
	cachedInfo.Changelog = rel.Body

	var zipURL, shaURL string
	for _, a := range rel.Assets {
		switch a.Name {
		case updateZipAssetName:
			zipURL = a.BrowserDownloadURL
		case updateZipAssetName + ".sha256":
			shaURL = a.BrowserDownloadURL
		}
	}
	if zipURL == "" || shaURL == "" {
		cachedInfo.FullReason = "la release non include il pacchetto di aggiornamento leggero"
		return cachedInfo
	}
	removed, reason := lightUpdatePlan(installed, rel.TagName)
	if reason != "" {
		cachedInfo.FullReason = reason
		return cachedInfo
	}
	cachedInfo.ZipURL, cachedInfo.SHA256URL, cachedInfo.Removed = zipURL, shaURL, removed
	return cachedInfo
}

type ghCompare struct {
	Status string `json:"status"` // "ahead" atteso: la nuova discende dall'installata
	Files  []struct {
		Filename         string `json:"filename"`
		Status           string `json:"status"` // added, modified, removed, renamed, ...
		PreviousFilename string `json:"previous_filename"`
	} `json:"files"`
}

// ghCompareFileLimit: l'API compare elenca al massimo 300 file - oltre, la
// lista e' troncata e non si puo' sapere cosa manca.
const ghCompareFileLimit = 300

// lightUpdatePlan: dal diff reale fra tag installato e tag nuovo decide se lo
// zip basta. reason != "" -> serve la reinstallazione completa. Fail-safe:
// qualunque dubbio (versione non x.y.z, GitHub irraggiungibile, tag
// inesistente, storia divergente, lista troncata) porta alla reinstallazione,
// mai a uno zip applicato dove non doveva.
func lightUpdatePlan(installed, latestTag string) (removed []string, reason string) {
	if !isPlainVersion(installed) {
		return nil, fmt.Sprintf("versione installata %q non confrontabile", installed)
	}
	url := fmt.Sprintf("https://api.github.com/repos/%s/compare/v%s...%s", UpdateRepo, installed, latestTag)
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		return nil, "confronto versioni non riuscito"
	}
	req.Header.Set("User-Agent", "opensagra-wrapper")
	req.Header.Set("Accept", "application/vnd.github+json")
	resp, err := (&http.Client{Timeout: 10 * time.Second}).Do(req)
	if err != nil {
		return nil, "confronto versioni non riuscito (GitHub non raggiungibile)"
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Sprintf("confronto versioni non riuscito (HTTP %d)", resp.StatusCode)
	}
	var cmp ghCompare
	if err := json.NewDecoder(resp.Body).Decode(&cmp); err != nil {
		return nil, "confronto versioni non riuscito (risposta illeggibile)"
	}
	if cmp.Status != "ahead" {
		return nil, fmt.Sprintf("la nuova versione non discende da quella installata (%s)", cmp.Status)
	}
	if len(cmp.Files) >= ghCompareFileLimit {
		return nil, "troppi file cambiati per verificarli uno a uno"
	}
	for _, f := range cmp.Files {
		for _, p := range []string{f.Filename, f.PreviousFilename} {
			if p != "" && hasAnyPrefix(p, fullUpdatePrefixes) {
				return nil, "questa versione aggiorna anche " + p
			}
		}
		gone := ""
		switch f.Status {
		case "removed":
			gone = f.Filename
		case "renamed":
			gone = f.PreviousFilename
		}
		if gone != "" && !hasAnyPrefix(gone, keepOnRemovePrefixes) {
			removed = append(removed, gone)
		}
	}
	return removed, ""
}

func hasAnyPrefix(s string, prefixes []string) bool {
	for _, p := range prefixes {
		if strings.HasPrefix(s, p) {
			return true
		}
	}
	return false
}

// isPlainVersion: solo "a.b.c" numerico - "0.0.0-dev" o simili non hanno un
// tag con cui confrontarsi.
func isPlainVersion(v string) bool {
	parts := strings.Split(strings.TrimSpace(v), ".")
	if len(parts) != 3 {
		return false
	}
	for _, p := range parts {
		if _, err := strconv.Atoi(p); err != nil || p == "" {
			return false
		}
	}
	return true
}

// isNewerVersion: confronto semver minimale (solo "a.b.c" numerico) - non
// vale la pena una dipendenza esterna per questo.
func isNewerVersion(remote, local string) bool {
	r := parseVersionParts(remote)
	l := parseVersionParts(local)
	for i := 0; i < 3; i++ {
		if r[i] != l[i] {
			return r[i] > l[i]
		}
	}
	return false
}

func parseVersionParts(v string) [3]int {
	var out [3]int
	parts := strings.SplitN(v, ".", 3)
	for i := 0; i < len(parts) && i < 3; i++ {
		n, _ := strconv.Atoi(strings.TrimSpace(parts[i]))
		out[i] = n
	}
	return out
}
