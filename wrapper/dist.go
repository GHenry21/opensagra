package main

import (
	"context"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strings"
	"time"

	"opensagra/wrapper/manifest"
)

// Pacchetto della PROPRIA versione, conservato perche' le casse client lo
// scarichino da questo PC in LAN invece che da GitHub (piano, Fase 6c punto
// C: in sagra spesso solo il server ha Internet, o nessuno). Lo serve
// api/update_dist.php, che trova la cartella in OPENSAGRA_DIST_DIR (impostata
// qui per il wrapper e quindi ereditata da FrankenPHP).
//
// Fuori dalla cartella dell'app: quella e' il webroot (piano, Fase 6b-bis).
// Contiene solo i tre file dell'ultima versione, piu' VERSION.
//
// Riempita (1) da ApplyUpdate dopo un aggiornamento riuscito, coi file appena
// scaricati; (2) da ensureDistLoop dopo una reinstallazione, scaricando da
// GitHub gli asset della release installata appena c'e' rete.
const (
	distEnvVar    = "OPENSAGRA_DIST_DIR"
	distRetry     = time.Hour
	distFirstWait = 2 * time.Minute // non competere con l'avvio di FrankenPHP
)

func distDir() string {
	base, err := os.UserConfigDir()
	if err != nil {
		return ""
	}
	return filepath.Join(base, "opensagra", "dist")
}

func distVersion(dir string) string {
	b, err := os.ReadFile(filepath.Join(dir, "VERSION"))
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(b))
}

// saveDist: copia zip, impronta e manifesto nella cartella dist, poi scrive
// VERSION per ultimo (chi legge vede la versione solo a file completi).
func saveDist(version, zipPath, shaPath, manifestPath string) error {
	dir := distDir()
	if dir == "" {
		return fmt.Errorf("cartella config utente non disponibile")
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	_ = os.Remove(filepath.Join(dir, "VERSION"))
	for src, name := range map[string]string{
		zipPath:      updateZipAssetName,
		shaPath:      updateSHA256AssetName,
		manifestPath: updateManifestAssetName,
	} {
		if err := placeFile(src, filepath.Join(dir, name)); err != nil {
			return err
		}
	}
	return os.WriteFile(filepath.Join(dir, "VERSION"), []byte(version), 0o644)
}

// ensureDistLoop: se dist non ha la versione installata (es. dopo una
// reinstallazione), la scarica dalla release su GitHub. Solo per versioni
// pubblicate (non "0.0.0-dev"); senza Internet riprova ogni ora. Gira su ogni
// macchina: una indipendente puo' diventare server in qualunque momento.
func ensureDistLoop(ctx context.Context, cfg *Config) {
	wait := distFirstWait
	for {
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		}
		wait = distRetry
		installed := installedVersion(cfg)
		if !isPlainVersion(installed) || distVersion(distDir()) == installed {
			continue
		}
		if err := fetchDistFromGitHub(installed); err != nil {
			log.Printf("dist: pacchetto v%s non ancora scaricato: %v", installed, err)
			continue
		}
		log.Printf("dist: pacchetto v%s pronto per le casse client", installed)
	}
}

func fetchDistFromGitHub(version string) error {
	rel, err := fetchRelease(fmt.Sprintf("https://api.github.com/repos/%s/releases/tags/v%s", UpdateRepo, version))
	if err != nil {
		return err
	}
	urls := map[string]string{}
	for _, a := range rel.Assets {
		urls[a.Name] = a.BrowserDownloadURL
	}
	for _, n := range []string{updateZipAssetName, updateSHA256AssetName, updateManifestAssetName} {
		if urls[n] == "" {
			return fmt.Errorf("la release v%s non ha %s", version, n)
		}
	}

	work, err := os.MkdirTemp("", "opensagra-dist-*")
	if err != nil {
		return err
	}
	defer os.RemoveAll(work)
	zipPath := filepath.Join(work, updateZipAssetName)
	shaPath := zipPath + ".sha256"
	manPath := filepath.Join(work, updateManifestAssetName)
	if err := downloadTo(nil, urls[updateZipAssetName], zipPath); err != nil {
		return err
	}
	if err := downloadTo(nil, urls[updateSHA256AssetName], shaPath); err != nil {
		return err
	}
	if err := checkSHA256File(zipPath, shaPath); err != nil {
		return err
	}
	if err := downloadTo(nil, urls[updateManifestAssetName], manPath); err != nil {
		return err
	}
	if m, err := manifest.Load(manPath); err != nil || m.Version != version {
		return fmt.Errorf("manifesto della release non valido")
	}
	return saveDist(version, zipPath, shaPath, manPath)
}
