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

// WrapperVersion: versione del BINARIO del wrapper (tenerla allineata a
// rsrc.rc e ai -version di packaging/make-installer.ps1 / make-uninstaller.ps1
// - non c'e' un modo semplice di leggere la risorsa Windows da dentro il
// binario stesso, quindi e' duplicata a mano ad ogni release). Serve solo
// come valore di partenza per installedVersion() la prima volta (vedi sotto)
// - il confronto vero e proprio usa il marcatore su disco, non questa
// costante, perche' un aggiornamento "leggero" (solo codice PHP, vedi
// self_update.go) non ricompila il wrapper.
const WrapperVersion = "1.0.0"

// UpdateRepo: repo GitHub "owner/nome" da cui leggere le release.
const UpdateRepo = "GHenry21/opensagra"

// updateZipAssetName: se la release piu' recente ha un asset con questo
// nome, e' idonea per l'aggiornamento leggero (solo codice PHP, senza
// reinstallare). La pipeline di release lo allega SOLO se wrapper/,
// install.ps1, uninstall.ps1 e packaging/ non sono cambiati rispetto alla
// release precedente (vedi .github/workflows/release.yml) - se manca,
// questa release richiede una reinstallazione completa.
const updateZipAssetName = "opensagra-update.zip"

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
	ZipURL    string // "" se questa release non include l'aggiornamento leggero
	Changelog string // note della release (vuoto se non disponibile o nessun aggiornamento)
}

// L'UNICO punto di tutto il wrapper che tocca la rete pubblica (Internet) -
// tutto il resto e' solo-LAN/loopback. Cache lunga apposta: nessun bisogno
// di ricontrollare piu' spesso di qualche ora, ed evita di consumare la
// quota di richieste non autenticate di GitHub (60/h per IP).
var (
	updateMu   sync.Mutex
	updateAt   time.Time
	cachedInfo UpdateInfo
)

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

	cachedInfo.Latest = latestVer
	cachedInfo.HTMLURL = rel.HTMLURL
	cachedInfo.Available = isNewerVersion(latestVer, installedVersion(cfg))
	if cachedInfo.Available {
		for _, a := range rel.Assets {
			if a.Name == updateZipAssetName {
				cachedInfo.ZipURL = a.BrowserDownloadURL
				break
			}
		}
		cachedInfo.Changelog = rel.Body
	}
	return cachedInfo
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
