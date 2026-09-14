package main

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"
)

// WrapperVersion: tenerla allineata a rsrc.rc (FileVersion/ProductVersion) -
// non c'e' un modo semplice di leggere la risorsa Windows da dentro il
// binario stesso senza dipendenze esterne, quindi e' duplicata qui a mano
// ad ogni release.
const WrapperVersion = "0.1.0"

// UpdateRepo: repo GitHub "owner/nome" da cui leggere le release. PLACEHOLDER
// - il progetto non e' ancora pubblicato su GitHub (nessun remote configurato
// al momento in cui questo file e' stato scritto). Aggiornare qui non appena
// il repo esiste davvero, altrimenti il controllo fallisce silenziosamente
// (nessuna release trovata, nessun problema per l'utente - vedi
// checkForUpdate, fallisce chiudendosi in un no-op).
const UpdateRepo = "REPO_OWNER/REPO_NAME"

// L'UNICO punto di tutto il wrapper che tocca la rete pubblica (Internet) -
// tutto il resto e' solo-LAN/loopback. Cache lunga apposta: nessun bisogno
// di ricontrollare piu' spesso di qualche ora, ed evita di consumare la
// quota di richieste non autenticate di GitHub (60/h per IP).
var (
	updateMu        sync.Mutex
	updateCheckedAt time.Time
	updateAvailable bool
	updateLatest    string
	updateURL       string
)

type ghRelease struct {
	TagName string `json:"tag_name"`
	HTMLURL string `json:"html_url"`
}

// checkForUpdate: cache-and-refresh, sempre sicuro da chiamare spesso (poll
// della finestra di stato compreso) - la vera richiesta di rete parte al
// massimo una volta ogni 12h.
func checkForUpdate() (available bool, latest string, url string) {
	updateMu.Lock()
	defer updateMu.Unlock()
	if !updateCheckedAt.IsZero() && time.Since(updateCheckedAt) < 12*time.Hour {
		return updateAvailable, updateLatest, updateURL
	}
	updateCheckedAt = time.Now()

	req, err := http.NewRequest(http.MethodGet,
		fmt.Sprintf("https://api.github.com/repos/%s/releases/latest", UpdateRepo), nil)
	if err != nil {
		return updateAvailable, updateLatest, updateURL
	}
	// User-Agent obbligatorio per l'API di GitHub, altrimenti risponde 403.
	req.Header.Set("User-Agent", "opensagra-wrapper")
	req.Header.Set("Accept", "application/vnd.github+json")

	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return updateAvailable, updateLatest, updateURL
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return updateAvailable, updateLatest, updateURL
	}

	var rel ghRelease
	if err := json.NewDecoder(resp.Body).Decode(&rel); err != nil {
		return updateAvailable, updateLatest, updateURL
	}

	latestVer := strings.TrimPrefix(strings.TrimSpace(rel.TagName), "v")
	if latestVer == "" {
		return updateAvailable, updateLatest, updateURL
	}
	updateAvailable = isNewerVersion(latestVer, WrapperVersion)
	updateLatest = latestVer
	updateURL = rel.HTMLURL
	return updateAvailable, updateLatest, updateURL
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
