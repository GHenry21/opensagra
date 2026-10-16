package main

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"sort"
	"strings"
	"time"
)

// Installer completi della PROPRIA versione, conservati dalla cassa centrale
// per le piattaforme delle sue casse (piano, 6c punto C3): quando una
// versione richiede la reinstallazione (cambiano wrapper, installer o
// FrankenPHP), una cassa senza Internet la fa dalla centrale invece che con
// una chiavetta USB. Si scaricano da GitHub quando la centrale ha rete
// (ensureDistLoop) e li serve api/update_dist.php: al wrapper della cassa
// (pulsante "Aggiorna") e al browser (pagina Configurazione Rete della
// centrale), per le casse con una versione che non sa ancora chiederli.
//
// Solo su una cassa centrale (IS_CENTRAL): un'indipendente non scarica nulla
// in piu'. Piattaforme: quella della centrale (le casse sono spesso dello
// stesso tipo, e quelle con un wrapper precedente non dicono la loro) piu'
// quelle delle casse viste nel battito negli ultimi installersSeenDays.
const (
	installersDirName  = "installers"
	installersIndex    = "index.json"
	installersSeenDays = 60
	installerTimeout   = 30 * time.Minute // ~80 MB anche su una LTE lenta
)

type platform struct{ OS, Arch string }

// installerAssetName: il file della release che una persona scaricherebbe
// per quella piattaforma (guida utente §1). "" = piattaforma non pubblicata.
func installerAssetName(p platform, version string) string {
	switch p {
	case platform{"windows", "amd64"}:
		return "opensagra-installer.exe"
	case platform{"linux", "amd64"}:
		return fmt.Sprintf("opensagra-%s-linux-x86_64.tar.gz", version)
	case platform{"linux", "arm64"}:
		return fmt.Sprintf("opensagra-%s-linux-aarch64.tar.gz", version)
	case platform{"darwin", "arm64"}:
		return "Installa-OpenSagra-macos-arm64.zip"
	case platform{"darwin", "amd64"}:
		return "Installa-OpenSagra-macos-x86_64.zip"
	}
	return ""
}

type installerEntry struct {
	OS     string `json:"os"`
	Arch   string `json:"arch"`
	Name   string `json:"name"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256"`
}

type installersIndexFile struct {
	Version    string           `json:"version"`
	Installers []installerEntry `json:"installers"`
}

func installersDir() string {
	d := distDir()
	if d == "" {
		return ""
	}
	return filepath.Join(d, installersDirName)
}

func readInstallersIndex(dir string) installersIndexFile {
	var idx installersIndexFile
	if b, err := os.ReadFile(filepath.Join(dir, installersIndex)); err == nil {
		_ = json.Unmarshal(b, &idx)
	}
	return idx
}

func writeInstallersIndex(dir string, idx installersIndexFile) error {
	sort.Slice(idx.Installers, func(i, j int) bool { return idx.Installers[i].Name < idx.Installers[j].Name })
	b, _ := json.MarshalIndent(idx, "", " ")
	tmp := filepath.Join(dir, installersIndex+".os-new")
	if err := os.WriteFile(tmp, b, 0o644); err != nil {
		return err
	}
	return os.Rename(tmp, filepath.Join(dir, installersIndex))
}

// wantedPlatforms: la propria piu' quelle delle casse viste di recente, dal
// DB locale (su una centrale e' il DB in cui le casse scrivono il battito).
func wantedPlatforms(cfg *Config) []platform {
	seen := map[platform]bool{{runtime.GOOS, runtime.GOARCH}: true}
	db, err := sql.Open("mysql", fmt.Sprintf("%s:%s@tcp(127.0.0.1:3306)/%s?timeout=3s&readTimeout=3s",
		cfg.DBUser, cfg.DBPass, cfg.DBName))
	if err == nil {
		defer db.Close()
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		rows, err := db.QueryContext(ctx, "SELECT DISTINCT os, arch FROM app_nodi"+
			" WHERE ruolo = 'client' AND os <> '' AND last_seen > NOW() - INTERVAL ? DAY", installersSeenDays)
		if err == nil {
			defer rows.Close()
			for rows.Next() {
				var p platform
				if rows.Scan(&p.OS, &p.Arch) == nil {
					seen[p] = true
				}
			}
		}
		// Errore (es. app_nodi non ancora creata): basta la propria piattaforma.
	}
	var out []platform
	for p := range seen {
		out = append(out, p)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].OS+out[i].Arch < out[j].OS+out[j].Arch })
	return out
}

// ensureInstallers: porta la cartella degli installer alla versione
// `version`, per le piattaforme volute. Ogni file e' verificato con
// l'impronta che GitHub pubblica per l'asset ("digest"); senza impronta non
// si conserva (la cassa non potrebbe verificarlo). Errori per singola
// piattaforma: si va avanti con le altre e si riprova al giro dopo.
func ensureInstallers(cfg *Config, version string) error {
	dir := installersDir()
	if dir == "" {
		return fmt.Errorf("cartella config utente non disponibile")
	}
	idx := readInstallersIndex(dir)
	if idx.Version != version {
		// Versione cambiata: via gli installer vecchi (servono solo quelli
		// della versione della centrale, le casse si allineano a lei).
		_ = os.RemoveAll(dir)
		idx = installersIndexFile{Version: version}
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}

	have := map[string]bool{}
	for _, e := range idx.Installers {
		if _, err := os.Stat(filepath.Join(dir, e.Name)); err == nil {
			have[e.Name] = true
		}
	}
	var missing []platform
	for _, p := range wantedPlatforms(cfg) {
		if name := installerAssetName(p, version); name != "" && !have[name] {
			missing = append(missing, p)
		}
	}
	if len(missing) == 0 {
		return nil
	}

	rel, err := fetchRelease(fmt.Sprintf("https://api.github.com/repos/%s/releases/tags/v%s", UpdateRepo, version))
	if err != nil {
		return err
	}
	assets := map[string]ghAsset{}
	for _, a := range rel.Assets {
		assets[a.Name] = a
	}

	var firstErr error
	for _, p := range missing {
		name := installerAssetName(p, version)
		e, err := fetchInstaller(dir, assets[name], p)
		if err != nil {
			log.Printf("dist: installer %s/%s v%s non scaricato: %v", p.OS, p.Arch, version, err)
			if firstErr == nil {
				firstErr = err
			}
			continue
		}
		// Indice riscritto dopo ogni file: chi legge vede solo file completi.
		idx.Installers = append(idx.Installers, e)
		if err := writeInstallersIndex(dir, idx); err != nil {
			return err
		}
		log.Printf("dist: installer %s (%s/%s) pronto per le casse", name, p.OS, p.Arch)
	}
	return firstErr
}

func fetchInstaller(dir string, a ghAsset, p platform) (installerEntry, error) {
	if a.Name == "" {
		return installerEntry{}, fmt.Errorf("asset assente nella release")
	}
	want := strings.TrimPrefix(strings.ToLower(a.Digest), "sha256:")
	if len(want) != 64 {
		return installerEntry{}, fmt.Errorf("GitHub non pubblica l'impronta di %s", a.Name)
	}
	tmp := filepath.Join(dir, a.Name+".os-new")
	if err := downloadTo(&http.Client{Timeout: installerTimeout}, a.BrowserDownloadURL, tmp); err != nil {
		os.Remove(tmp)
		return installerEntry{}, err
	}
	got, err := fileSHA256(tmp)
	if err != nil || got != want {
		os.Remove(tmp)
		return installerEntry{}, fmt.Errorf("impronta di %s diversa da quella pubblicata", a.Name)
	}
	st, err := os.Stat(tmp)
	if err != nil {
		return installerEntry{}, err
	}
	if err := os.Rename(tmp, filepath.Join(dir, a.Name)); err != nil {
		os.Remove(tmp)
		return installerEntry{}, err
	}
	return installerEntry{OS: p.OS, Arch: p.Arch, Name: a.Name, Size: st.Size(), SHA256: got}, nil
}

// --- lato cassa: scaricare l'installer dalla centrale ---

// downloadsDir: la cartella Download dell'utente (su Linux quella di XDG,
// che in italiano e' spesso "Scaricati"), creata se manca.
func downloadsDir() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	dir := filepath.Join(home, "Downloads")
	if runtime.GOOS == "linux" {
		if out, err := exec.Command("xdg-user-dir", "DOWNLOAD").Output(); err == nil {
			if d := strings.TrimSpace(string(out)); d != "" && d != home {
				dir = d
			}
		}
	}
	return dir, os.MkdirAll(dir, 0o755)
}

// DownloadInstaller: scarica dalla centrale l'installer di questa
// piattaforma nella cartella Download, lo verifica con l'impronta e lo mette
// a portata di mano: su Windows lo avvia (chiede la conferma di Windows,
// l'installer ha bisogno dei permessi di amministratore e chiude da se'
// OpenSagra), su Linux estrae il pacchetto e apre la cartella con "Installa
// OpenSagra", su Mac apre la cartella con lo zip. Ritorna cosa dire
// all'utente.
func DownloadInstaller(info UpdateInfo) (string, error) {
	dest, err := fetchInstallerToDownloads(info)
	if err != nil {
		return "", err
	}
	return openDownloadedInstaller(info, dest)
}

// fetchInstallerToDownloads: download dalla centrale + verifica; ritorna il
// percorso del file nella cartella Download.
func fetchInstallerToDownloads(info UpdateInfo) (string, error) {
	if info.InstallerURL == "" || len(info.InstallerSHA256) != 64 {
		return "", fmt.Errorf("la centrale non ha l'installer per questa cassa")
	}
	dir, err := downloadsDir()
	if err != nil {
		return "", err
	}
	dest := filepath.Join(dir, filepath.Base(info.InstallerName))
	tmp := dest + ".os-new"
	if err := downloadTo(lanHTTPClient(installerTimeout), info.InstallerURL, tmp); err != nil {
		os.Remove(tmp)
		return "", fmt.Errorf("download dalla centrale fallito: %w", err)
	}
	if got, err := fileSHA256(tmp); err != nil || got != info.InstallerSHA256 {
		os.Remove(tmp)
		return "", fmt.Errorf("l'installer scaricato non corrisponde all'impronta: riprova")
	}
	if err := os.Rename(tmp, dest); err != nil {
		os.Remove(tmp)
		return "", err
	}
	return dest, nil
}

// openDownloadedInstaller: lo mette a portata di mano secondo il sistema.
func openDownloadedInstaller(info UpdateInfo, dest string) (string, error) {
	dir := filepath.Dir(dest)
	switch runtime.GOOS {
	case "windows":
		openURL(dest) // ShellExecute: Windows chiede la conferma da amministratore
		return fmt.Sprintf("Installer v%s scaricato in %s e avviato: conferma la richiesta di Windows e segui la finestra di installazione.", info.Latest, dir), nil
	case "linux":
		top, err := extractTarGz(dest, dir)
		if err != nil {
			revealPath(dir)
			return "", fmt.Errorf("pacchetto scaricato in %s, ma non estratto: %w", dir, err)
		}
		folder := filepath.Join(dir, top)
		revealPath(folder)
		return fmt.Sprintf("Pacchetto v%s pronto in %s: nella cartella che si e' aperta fai doppio clic su \"Installa OpenSagra\".", info.Latest, folder), nil
	default:
		revealPath(dir)
		return fmt.Sprintf("Installer v%s scaricato in %s: fai doppio clic sullo zip, poi sull'app \"Installa OpenSagra\".", info.Latest, dir), nil
	}
}

// extractTarGz: estrae il pacchetto Linux (una cartella opensagra-<v>/) in
// destDir conservando i permessi di esecuzione; ritorna il nome della
// cartella di primo livello.
func extractTarGz(path, destDir string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		return "", err
	}
	defer gz.Close()
	tr := tar.NewReader(gz)
	clean := filepath.Clean(destDir)
	top := ""
	for {
		h, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return "", err
		}
		target := filepath.Join(clean, filepath.FromSlash(h.Name))
		if !strings.HasPrefix(target, clean+string(os.PathSeparator)) {
			continue // difesa contro ".." nei nomi
		}
		if top == "" {
			top = strings.SplitN(strings.TrimPrefix(h.Name, "./"), "/", 2)[0]
		}
		switch h.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0o755); err != nil {
				return "", err
			}
		case tar.TypeReg:
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return "", err
			}
			out, err := os.OpenFile(target, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, os.FileMode(h.Mode)&0o777)
			if err != nil {
				return "", err
			}
			if _, err := io.Copy(out, tr); err != nil {
				out.Close()
				return "", err
			}
			if err := out.Close(); err != nil {
				return "", err
			}
		}
	}
	if top == "" {
		return "", fmt.Errorf("pacchetto vuoto")
	}
	return top, nil
}
