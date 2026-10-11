package main

import (
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"

	"opensagra/wrapper/manifest"
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

// Asset dell'aggiornamento leggero (solo codice app + vendor/,
// packaging/make-update.ps1), allegati dalla CI a OGNI release: lo zip, la sua
// impronta e il manifesto (manifest.Manifest). Se usarli o reinstallare lo
// decide il wrapper confrontando il manifesto INSTALLATO con quello nuovo
// (manifest.Plan, piano Fase 6c punto C): offline, quindi vale anche per una
// cassa client senza Internet. Prima la decisione era della CI (contro il
// solo tag precedente: chi saltava una release da reinstallare riceveva lo
// zip lo stesso), poi dell'API compare di GitHub (che un client in sagra
// spesso non raggiunge).
//
// Nome diverso dal vecchio "opensagra-update.zip" APPOSTA: i wrapper gia'
// installati (fino alla v1.0.0) applicano quello alla cieca; non trovandolo
// ricadono da soli su "serve una reinstallazione completa", che installa
// questo wrapper.
const (
	updateZipAssetName      = "opensagra-app-update.zip"
	updateSHA256AssetName   = updateZipAssetName + ".sha256"
	updateManifestAssetName = "opensagra-app-update.manifest.json"
)

// keepOnRemovePrefixes: file spariti fra due versioni che l'aggiornamento NON
// cancella - uploads/ contiene anche le foto caricate dall'utente (e foto
// seed a cui un prodotto puo' ancora puntare).
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
	// Source: da dove arriva il pacchetto - "github" o "server" (il PC server
	// in LAN, per una cassa client; vedi dist.go).
	Source    string
	ZipURL    string // "" se da QUESTA installazione serve una reinstallazione completa
	SHA256URL string // impronta dello zip, obbligatoria (ApplyUpdate rifiuta senza)
	Removed   []string
	// FullReason: perche' serve la reinstallazione (valorizzato se ZipURL == "").
	FullReason string
	Changelog  string // note della release (vuoto se non disponibile o nessun aggiornamento)
	// lan: il pacchetto si scarica dal PC server, il cui certificato viene
	// dalla SUA CA locale (non fidata dal sistema di questa macchina). La
	// cassa si fida gia' del server per tutto (ne scrive il database); lo zip
	// e' comunque verificato con l'impronta.
	lan bool
}

// Cache: per il server/indipendente la richiesta a GitHub parte al massimo
// ogni 12h (quota non autenticata: 60/h per IP); per un client si ricontrolla
// piu' spesso (solo LAN) e subito se la versione del server cambia.
const (
	updateCacheGitHub = 12 * time.Hour
	updateCacheClient = 10 * time.Minute
)

var (
	updateMu   sync.Mutex
	updateAt   time.Time
	updateKey  string // ruolo + versione del server: se cambia, la cache non vale
	cachedInfo UpdateInfo
)

// invalidateUpdateCache: forza checkForUpdate() a rifare il controllo alla
// prossima chiamata - dopo un aggiornamento applicato, altrimenti l'interfaccia
// continuerebbe a offrire per ore un aggiornamento gia' installato.
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

// installedManifest: nil se manca (installazione precedente al manifesto o
// copia di sviluppo) o se e' di un'altra versione (una reinstallazione da un
// pacchetto senza manifesto lascia quello vecchio: le copie sovrappongono,
// non cancellano) - manifest.Plan lo tratta come "serve reinstallare".
func installedManifest(cfg *Config) *manifest.Manifest {
	m, err := manifest.Load(filepath.Join(cfg.AppRoot, filepath.FromSlash(manifest.InstalledPath)))
	if err != nil || m.Version != installedVersion(cfg) {
		return nil
	}
	return m
}

// inLocalFallback: la cassa e' client ma lavora sul proprio DB perche' il
// server e' caduto (FALLBACK_ORIGIN_HOST, vedi config/env_reader.php). In
// quel momento DB_POS_HOST e' 127.0.0.1 e sembrerebbe un server: non si
// propone nessun aggiornamento, altrimenti supererebbe la versione del server.
func inLocalFallback(cfg *Config) bool {
	env := readEnvFile(filepath.Join(cfg.AppRoot, "config", "variabili.env"))
	return strings.TrimSpace(env["FALLBACK_ORIGIN_HOST"]) != ""
}

// checkForUpdate: cache-and-refresh, sempre sicuro da chiamare spesso (poll
// della finestra di stato compreso).
func checkForUpdate(cfg *Config) UpdateInfo {
	server := isThisMachineServer(cfg) && !inLocalFallback(cfg)
	serverVer, _ := versionPeers()
	key := "server"
	ttl := updateCacheGitHub
	if !server {
		key = "client:" + serverVer
		ttl = updateCacheClient
	}

	updateMu.Lock()
	defer updateMu.Unlock()
	if !updateAt.IsZero() && updateKey == key && time.Since(updateAt) < ttl {
		return cachedInfo
	}
	updateAt, updateKey = time.Now(), key

	switch {
	case server:
		cachedInfo = updateFromGitHubLatest(cfg)
	case inLocalFallback(cfg):
		cachedInfo = UpdateInfo{}
	default:
		cachedInfo = updateFromServer(cfg, serverVer)
	}
	return cachedInfo
}

// updateFromGitHubLatest: server/indipendente - l'ultima release pubblicata.
func updateFromGitHubLatest(cfg *Config) UpdateInfo {
	rel, err := fetchRelease(fmt.Sprintf("https://api.github.com/repos/%s/releases/latest", UpdateRepo))
	if err != nil {
		return UpdateInfo{}
	}
	latestVer := strings.TrimPrefix(strings.TrimSpace(rel.TagName), "v")
	info := UpdateInfo{Latest: latestVer, HTMLURL: rel.HTMLURL, Source: "github"}
	if latestVer == "" || !isNewerVersion(latestVer, installedVersion(cfg)) {
		return info
	}
	info.Available = true
	info.Changelog = rel.Body
	planFromAssets(cfg, &info, rel)
	return info
}

// updateFromServer: cassa client - si va SEMPRE alla versione del server,
// mai oltre (piano, Fase 6c punto C), e la si scarica dal server in LAN.
// Solo se il server non ha il pacchetto si prova GitHub per quella stessa
// versione (serve Internet su questa cassa).
func updateFromServer(cfg *Config, serverVer string) UpdateInfo {
	if serverVer == "" || !isNewerVersion(serverVer, installedVersion(cfg)) {
		// "" = server con wrapper precedente, o non raggiungibile: niente.
		return UpdateInfo{Latest: serverVer}
	}
	info := UpdateInfo{
		Available: true,
		Latest:    serverVer,
		HTMLURL:   fmt.Sprintf("https://github.com/%s/releases/tag/v%s", UpdateRepo, serverVer),
	}

	base := "https://" + net.JoinHostPort(strings.TrimSpace(cfg.DbHost()), "443") + "/api/update_dist.php"
	if raw, err := httpGet(lanHTTPClient(10*time.Second), base+"?f=manifest", 4<<20); err == nil {
		if m, err := manifest.Parse(raw); err == nil && m.Version == serverVer {
			info.Source, info.lan = "server", true
			removed, reason := manifest.Plan(installedManifest(cfg), m, keepOnRemovePrefixes)
			if reason != "" {
				info.FullReason = reason
				return info
			}
			info.ZipURL, info.SHA256URL, info.Removed = base+"?f=zip", base+"?f=sha", removed
			return info
		}
	}

	rel, err := fetchRelease(fmt.Sprintf("https://api.github.com/repos/%s/releases/tags/v%s", UpdateRepo, serverVer))
	if err != nil {
		info.FullReason = fmt.Sprintf("il pacchetto della v%s non e' disponibile ne' sul PC server ne' da GitHub (%v)", serverVer, err)
		return info
	}
	info.Source, info.HTMLURL, info.Changelog = "github", rel.HTMLURL, rel.Body
	planFromAssets(cfg, &info, rel)
	return info
}

// planFromAssets: scarica il manifesto della release e decide (offline).
func planFromAssets(cfg *Config, info *UpdateInfo, rel ghRelease) {
	var zipURL, shaURL, manURL string
	for _, a := range rel.Assets {
		switch a.Name {
		case updateZipAssetName:
			zipURL = a.BrowserDownloadURL
		case updateSHA256AssetName:
			shaURL = a.BrowserDownloadURL
		case updateManifestAssetName:
			manURL = a.BrowserDownloadURL
		}
	}
	if zipURL == "" || shaURL == "" || manURL == "" {
		info.FullReason = "la release non include il pacchetto di aggiornamento leggero"
		return
	}
	raw, err := httpGet(&http.Client{Timeout: 30 * time.Second}, manURL, 4<<20)
	if err != nil {
		info.FullReason = "manifesto della release non scaricabile"
		return
	}
	m, err := manifest.Parse(raw)
	if err != nil || m.Version != info.Latest {
		info.FullReason = "manifesto della release non valido"
		return
	}
	removed, reason := manifest.Plan(installedManifest(cfg), m, keepOnRemovePrefixes)
	if reason != "" {
		info.FullReason = reason
		return
	}
	info.ZipURL, info.SHA256URL, info.Removed = zipURL, shaURL, removed
}

func fetchRelease(url string) (ghRelease, error) {
	var rel ghRelease
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		return rel, err
	}
	// User-Agent obbligatorio per l'API di GitHub, altrimenti risponde 403.
	req.Header.Set("User-Agent", "opensagra-wrapper")
	req.Header.Set("Accept", "application/vnd.github+json")
	resp, err := (&http.Client{Timeout: 5 * time.Second}).Do(req)
	if err != nil {
		return rel, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return rel, fmt.Errorf("HTTP %d", resp.StatusCode)
	}
	err = json.NewDecoder(resp.Body).Decode(&rel)
	return rel, err
}

func httpGet(client *http.Client, url string, max int64) ([]byte, error) {
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", "opensagra-wrapper")
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("HTTP %d", resp.StatusCode)
	}
	return io.ReadAll(io.LimitReader(resp.Body, max))
}

// lanHTTPClient: verso il PC server in LAN (vedi UpdateInfo.lan).
func lanHTTPClient(timeout time.Duration) *http.Client {
	return &http.Client{
		Timeout:   timeout,
		Transport: &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}},
	}
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

// isPlainVersion: solo "a.b.c" numerico - "0.0.0-dev" o simili non hanno una
// release su GitHub.
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
