package main

import (
	"archive/zip"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// ApplyUpdate: scarica il pacchetto di aggiornamento (solo codice PHP +
// vendor/, costruito da packaging/make-update.ps1 - vedi il commento su
// updateZipAssetName in update_check.go) e lo estrae SOPRA l'installazione
// esistente: mai un wipe-and-replace. uploads/, config/variabili.env,
// Caddyfile e logs/ non fanno parte del pacchetto (e' costruito solo dai
// file tracciati da git + vendor/), quindi restano intatti senza bisogno di
// nessuna lista di esclusione qui. Tutto in Go puro (niente PowerShell/
// ps2exe) - download, estrazione zip ed esecuzione di un processo esterno
// sono operazioni pulite nella libreria standard, fuori dalla categoria di
// bug (allocazione di console, moduli che non si caricano) che ha afflitto
// tutta la parte installer/uninstaller in PowerShell.
func ApplyUpdate(cfg *Config, info UpdateInfo) error {
	if info.ZipURL == "" {
		return fmt.Errorf("questa versione non ha un pacchetto di aggiornamento leggero (serve una reinstallazione completa)")
	}

	zipPath, err := downloadToTemp(info.ZipURL)
	if err != nil {
		return fmt.Errorf("download fallito: %w", err)
	}
	defer os.Remove(zipPath)

	if err := extractOverlay(zipPath, cfg.AppRoot); err != nil {
		return fmt.Errorf("estrazione fallita: %w", err)
	}

	if err := runNewMigrations(cfg); err != nil {
		return fmt.Errorf("migrazioni fallite: %w", err)
	}

	versionPath := filepath.Join(cfg.AppRoot, installedVersionFile)
	if err := os.WriteFile(versionPath, []byte(info.Latest), 0o644); err != nil {
		return fmt.Errorf("scrittura della versione installata fallita: %w", err)
	}
	return nil
}

func downloadToTemp(url string) (string, error) {
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("User-Agent", "opensagra-wrapper")

	client := &http.Client{Timeout: 60 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("HTTP %d", resp.StatusCode)
	}

	f, err := os.CreateTemp("", "opensagra-update-*.zip")
	if err != nil {
		return "", err
	}
	defer f.Close()
	if _, err := io.Copy(f, resp.Body); err != nil {
		os.Remove(f.Name())
		return "", err
	}
	return f.Name(), nil
}

// extractOverlay: estrae lo zip DENTRO destDir sovrascrivendo i file gia'
// esistenti (os.Create tronca da solo) - i file NON presenti nello zip
// restano semplicemente non toccati.
func extractOverlay(zipPath, destDir string) error {
	r, err := zip.OpenReader(zipPath)
	if err != nil {
		return err
	}
	defer r.Close()

	cleanDest := filepath.Clean(destDir)
	for _, f := range r.File {
		target := filepath.Join(cleanDest, filepath.FromSlash(f.Name))
		// Difesa in profondita' contro un eventuale ".." nel nome di una
		// voce dello zip - il nostro e' costruito da noi stessi, ma non
		// costa nulla controllare.
		if target != cleanDest && !strings.HasPrefix(target, cleanDest+string(os.PathSeparator)) {
			continue
		}
		if f.FileInfo().IsDir() {
			if err := os.MkdirAll(target, 0o755); err != nil {
				return err
			}
			continue
		}
		if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
			return err
		}
		if err := extractFile(f, target); err != nil {
			return err
		}
	}
	return nil
}

func extractFile(f *zip.File, target string) error {
	src, err := f.Open()
	if err != nil {
		return err
	}
	defer src.Close()

	dst, err := os.Create(target) // tronca/sovrascrive se gia' esiste
	if err != nil {
		return err
	}
	defer dst.Close()

	_, err = io.Copy(dst, src)
	return err
}

// runNewMigrations: stessa idempotenza di install.ps1 (Invoke-Migrations) -
// ogni file di config/migrations/ controlla da solo se il proprio passo e'
// gia' applicato, quindi rilanciarli tutti ad ogni aggiornamento e' sicuro.
func runNewMigrations(cfg *Config) error {
	dir := filepath.Join(cfg.AppRoot, "config", "migrations")
	entries, err := os.ReadDir(dir)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	var files []string
	for _, e := range entries {
		if !e.IsDir() && strings.HasSuffix(e.Name(), ".php") {
			files = append(files, e.Name())
		}
	}
	sort.Strings(files)

	for _, name := range files {
		full := filepath.Join(dir, name)
		out, err := exec.Command(cfg.Frankenphp, "php-cli", full).CombinedOutput()
		if err != nil {
			return fmt.Errorf("%s: %w (%s)", name, err, strings.TrimSpace(string(out)))
		}
	}
	return nil
}
