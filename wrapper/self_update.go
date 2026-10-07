package main

import (
	"archive/zip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"io/fs"
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

// ApplyUpdate: aggiornamento leggero (solo codice PHP + vendor/, costruito da
// packaging/make-update.ps1 - vedi updateZipAssetName in update_check.go).
// Mai un wipe-and-replace: uploads/, config/variabili.env, Caddyfile e logs/
// non sono nel pacchetto (e' fatto solo dai file tracciati da git + vendor/) e
// restano intatti. Tutto in Go puro (niente PowerShell/ps2exe), fuori dalla
// categoria di bug (console, moduli) che ha afflitto installer/disinstaller.
//
// Sequenza (piano, Fase 6b) - niente tocca l'installazione finche' i passi 1-3
// non sono riusciti:
//  1. download dello zip + verifica sha256
//  2. estrazione completa in una cartella temporanea (staging)
//  3. backup del DB locale (mariadb-dump) - senza, l'aggiornamento non parte
//  4. FrankenPHP in pausa: nessuna richiesta servita da codice a meta'
//  5. file nuovi al loro posto (ciascuno scritto a parte e rinominato), con
//     copia di quelli sostituiti/cancellati per il ripristino
//  6. cancellazione dei file rimossi fra le due versioni (dal diff)
//  7. migrazioni (sul MariaDB di questa macchina, Fase 6c punto B)
//
// Se 5-7 falliscono si rimettono i file di prima (il DB ha il dump del passo 3;
// le migrazioni sono solo additive, Fase 6c punto D). Il chiamante riavvia i
// processi in ogni caso.
func ApplyUpdate(cfg *Config, sup *Supervisor, info UpdateInfo) error {
	if info.ZipURL == "" {
		reason := info.FullReason
		if reason == "" {
			reason = "nessun pacchetto di aggiornamento leggero"
		}
		return fmt.Errorf("serve una reinstallazione completa: %s", reason)
	}
	if info.SHA256URL == "" {
		return fmt.Errorf("impronta del pacchetto mancante - aggiornamento non applicato")
	}

	work, err := os.MkdirTemp("", "opensagra-update-*")
	if err != nil {
		return err
	}
	defer os.RemoveAll(work)

	// 1. download + impronta
	zipPath := filepath.Join(work, "update.zip")
	if err := downloadTo(info.ZipURL, zipPath); err != nil {
		return fmt.Errorf("download fallito: %w", err)
	}
	if err := verifySHA256(zipPath, info.SHA256URL); err != nil {
		return err
	}

	// 2. staging
	staging := filepath.Join(work, "staging")
	if err := extractTo(zipPath, staging); err != nil {
		return fmt.Errorf("estrazione fallita: %w", err)
	}

	// 3. backup del DB
	backupPath, err := backupLocalDatabase(cfg)
	if err != nil {
		return fmt.Errorf("backup del database non riuscito, aggiornamento non applicato: %w", err)
	}
	log.Printf("aggiornamento: database salvato in %s", backupPath)

	// 4. FrankenPHP fermo durante la sostituzione. Solo lui: i client parlano
	// col MariaDB di questa macchina direttamente, quindi niente fallback
	// locale sulle casse (il realtime ripiega da solo sul polling per qualche
	// secondo). Relay/bridge/snapshot li riavvia il chiamante a fine giro.
	wasPaused := sup.isPaused("frankenphp")
	sup.pause("frankenphp")
	defer func() {
		if !wasPaused {
			sup.resume("frankenphp")
		}
	}()
	waitStopped(sup, "frankenphp", 15*time.Second)

	// 5-7, con ripristino
	rb := &rollback{dir: filepath.Join(work, "rollback"), root: cfg.AppRoot}
	if err := installStaged(staging, cfg.AppRoot, rb); err != nil {
		return rb.fail("copia dei file", err)
	}
	if err := removeObsolete(cfg.AppRoot, info.Removed, rb); err != nil {
		return rb.fail("rimozione dei file obsoleti", err)
	}
	if err := runNewMigrations(cfg); err != nil {
		return rb.fail("migrazioni", err)
	}

	versionPath := filepath.Join(cfg.AppRoot, installedVersionFile)
	if err := os.WriteFile(versionPath, []byte(info.Latest), 0o644); err != nil {
		return fmt.Errorf("scrittura della versione installata fallita: %w", err)
	}
	return nil
}

func downloadTo(url, dest string) error {
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		return err
	}
	req.Header.Set("User-Agent", "opensagra-wrapper")

	client := &http.Client{Timeout: 5 * time.Minute}
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("HTTP %d", resp.StatusCode)
	}

	f, err := os.Create(dest)
	if err != nil {
		return err
	}
	if _, err := io.Copy(f, resp.Body); err != nil {
		f.Close()
		return err
	}
	return f.Close()
}

// verifySHA256: il file .sha256 e' nel formato di sha256sum ("<hex>  <nome>"),
// basta il primo campo.
func verifySHA256(path, shaURL string) error {
	shaFile := path + ".sha256"
	if err := downloadTo(shaURL, shaFile); err != nil {
		return fmt.Errorf("download dell'impronta fallito: %w", err)
	}
	raw, err := os.ReadFile(shaFile)
	if err != nil {
		return err
	}
	fields := strings.Fields(string(raw))
	if len(fields) == 0 || len(fields[0]) != 64 {
		return fmt.Errorf("impronta del pacchetto illeggibile - aggiornamento non applicato")
	}
	want := strings.ToLower(fields[0])

	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return err
	}
	if got := hex.EncodeToString(h.Sum(nil)); got != want {
		return fmt.Errorf("il pacchetto scaricato non corrisponde all'impronta pubblicata - aggiornamento non applicato")
	}
	return nil
}

// extractTo: estrae lo zip in una cartella vuota (staging).
func extractTo(zipPath, destDir string) error {
	r, err := zip.OpenReader(zipPath)
	if err != nil {
		return err
	}
	defer r.Close()

	cleanDest := filepath.Clean(destDir)
	for _, f := range r.File {
		target := filepath.Join(cleanDest, filepath.FromSlash(f.Name))
		// Difesa in profondita' contro un ".." nel nome di una voce.
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

	dst, err := os.Create(target)
	if err != nil {
		return err
	}
	if _, err := io.Copy(dst, src); err != nil {
		dst.Close()
		return err
	}
	return dst.Close()
}

// rollback: tiene da parte i file sostituiti o cancellati e quelli aggiunti,
// per rimettere l'installazione com'era se un passo fallisce.
type rollback struct {
	dir   string // copie dei file originali
	root  string
	saved []string // relativi a root, avevano un originale
	added []string // relativi a root, non esistevano
}

// keep: da chiamare PRIMA di sostituire/cancellare root/rel.
func (rb *rollback) keep(rel string) error {
	live := filepath.Join(rb.root, rel)
	if _, err := os.Stat(live); os.IsNotExist(err) {
		rb.added = append(rb.added, rel)
		return nil
	}
	if err := copyFile(live, filepath.Join(rb.dir, rel)); err != nil {
		return err
	}
	rb.saved = append(rb.saved, rel)
	return nil
}

func (rb *rollback) fail(step string, cause error) error {
	var problems []string
	for _, rel := range rb.added {
		if err := os.Remove(filepath.Join(rb.root, rel)); err != nil && !os.IsNotExist(err) {
			problems = append(problems, rel)
		}
	}
	for _, rel := range rb.saved {
		if err := placeFile(filepath.Join(rb.dir, rel), filepath.Join(rb.root, rel)); err != nil {
			problems = append(problems, rel)
		}
	}
	if len(problems) > 0 {
		log.Printf("aggiornamento: ripristino incompleto, file non rimessi: %s", strings.Join(problems, ", "))
		return fmt.Errorf("%s fallita: %v - ATTENZIONE: ripristino incompleto (%d file), serve una reinstallazione", step, cause, len(problems))
	}
	return fmt.Errorf("%s fallita: %v - installazione rimessa com'era", step, cause)
}

// installStaged: ogni file dello staging al suo posto sotto root.
func installStaged(staging, root string, rb *rollback) error {
	return filepath.WalkDir(staging, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return err
		}
		rel, err := filepath.Rel(staging, path)
		if err != nil {
			return err
		}
		if err := rb.keep(rel); err != nil {
			return err
		}
		return placeFile(path, filepath.Join(root, rel))
	})
}

// placeFile: scrive accanto al file finale e poi rinomina - chi legge vede il
// file vecchio o quello nuovo, mai uno troncato a meta'. Copia, non rename
// diretto dallo staging: la cartella temporanea puo' stare su un altro disco.
func placeFile(src, dest string) error {
	if err := os.MkdirAll(filepath.Dir(dest), 0o755); err != nil {
		return err
	}
	tmp := dest + ".os-new"
	if err := copyFile(src, tmp); err != nil {
		os.Remove(tmp)
		return err
	}
	if err := os.Rename(tmp, dest); err != nil {
		os.Remove(tmp)
		return err
	}
	return nil
}

func copyFile(src, dest string) error {
	if err := os.MkdirAll(filepath.Dir(dest), 0o755); err != nil {
		return err
	}
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.Create(dest)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		return err
	}
	return out.Close()
}

// removeObsolete: cancella i file che la nuova versione non ha piu' (stato
// "removed"/"renamed" del diff, vedi lightUpdatePlan). Un file gia' assente va
// bene: molti percorsi del repo (docs/, e2e/...) non vengono mai installati.
func removeObsolete(root string, removed []string, rb *rollback) error {
	cleanRoot := filepath.Clean(root)
	for _, rel := range removed {
		target := filepath.Join(cleanRoot, filepath.FromSlash(rel))
		if !strings.HasPrefix(target, cleanRoot+string(os.PathSeparator)) {
			continue
		}
		if _, err := os.Stat(target); os.IsNotExist(err) {
			continue
		}
		relOS := filepath.FromSlash(rel)
		if err := rb.keep(relOS); err != nil {
			return err
		}
		// Non e' "aggiunto": keep() lo ha salvato, il ripristino lo ricrea.
		if err := os.Remove(target); err != nil {
			return err
		}
	}
	return nil
}

func waitStopped(sup *Supervisor, name string, max time.Duration) {
	deadline := time.Now().Add(max)
	for time.Now().Before(deadline) {
		if sup.get(name).State != stateRunning {
			return
		}
		time.Sleep(200 * time.Millisecond)
	}
	log.Printf("aggiornamento: %s ancora attivo dopo %s, procedo comunque", name, max)
}

// backupsToKeep: dump piu' vecchi oltre questo numero vengono cancellati.
const backupsToKeep = 10

// backupLocalDatabase: mariadb-dump del DB di QUESTA macchina (lo stesso che
// le migrazioni toccano, Fase 6c punto B) in una cartella dell'utente, FUORI
// dalla cartella dell'app: quella e' il webroot di Caddy (piano, Fase 6b-bis).
func backupLocalDatabase(cfg *Config) (string, error) {
	dumpBin := findMariadbDump()
	if dumpBin == "" {
		return "", fmt.Errorf("mariadb-dump non trovato")
	}
	base, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	dir := filepath.Join(base, "opensagra", "backups")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	// Millisecondi nel nome: due aggiornamenti nello stesso secondo (visto in
	// test sul Pi) si sovrascrivevano il dump. Resta ordinabile come stringa.
	out := filepath.Join(dir, "opensagra-preupdate-"+time.Now().Format("20060102-150405.000")+".sql")

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	defer cancel()
	cmd := exec.CommandContext(ctx, dumpBin,
		"--host=127.0.0.1", "--user="+cfg.DBUser, "--single-transaction",
		"--result-file="+out, cfg.DBName)
	// Password via ambiente, non sulla riga di comando (visibile agli altri
	// processi della macchina).
	cmd.Env = append(os.Environ(), "MYSQL_PWD="+cfg.DBPass)
	hideWindow(cmd)
	if msg, err := cmd.CombinedOutput(); err != nil {
		os.Remove(out)
		return "", fmt.Errorf("%w (%s)", err, strings.TrimSpace(string(msg)))
	}
	if st, err := os.Stat(out); err != nil || st.Size() == 0 {
		os.Remove(out)
		return "", fmt.Errorf("dump vuoto")
	}
	pruneBackups(dir)
	return out, nil
}

func pruneBackups(dir string) {
	matches, _ := filepath.Glob(filepath.Join(dir, "opensagra-preupdate-*.sql"))
	sort.Strings(matches) // il timestamp nel nome ordina per data
	for len(matches) > backupsToKeep {
		os.Remove(matches[0])
		matches = matches[1:]
	}
}

// findMariadbDump: nel PATH (Linux/macOS, e Windows se l'MSI l'ha aggiunto),
// altrimenti nella cartella di installazione standard di MariaDB su Windows
// (install.ps1 la fissa a "C:\Program Files\MariaDB <versione>").
func findMariadbDump() string {
	for _, name := range []string{"mariadb-dump", "mysqldump"} {
		if p, err := exec.LookPath(name); err == nil {
			return p
		}
	}
	if runtime.GOOS == "windows" {
		matches, _ := filepath.Glob(filepath.Join(os.Getenv("ProgramFiles"), "MariaDB*", "bin", "mariadb-dump.exe"))
		sort.Sort(sort.Reverse(sort.StringSlice(matches))) // la versione piu' alta per prima
		if len(matches) > 0 {
			return matches[0]
		}
	}
	return ""
}

// runNewMigrations: stessa idempotenza di install.ps1 (Invoke-Migrations) -
// ogni file di config/migrations/ controlla da solo se il proprio passo e'
// gia' applicato, quindi rilanciarli tutti ad ogni aggiornamento e' sicuro.
// Sempre sul MariaDB di QUESTA macchina (OPENSAGRA_DB_HOST, vedi
// config/env_reader.php): su un client migra il DB locale del fallback, il DB
// condiviso lo migra solo l'aggiornamento del server (piano, Fase 6c punto B).
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
		cmd := exec.Command(cfg.Frankenphp, "php-cli", full)
		cmd.Env = append(os.Environ(), "OPENSAGRA_DB_HOST=127.0.0.1")
		hideWindow(cmd)
		out, err := cmd.CombinedOutput()
		if err != nil {
			return fmt.Errorf("%s: %w (%s)", name, err, strings.TrimSpace(string(out)))
		}
	}
	return nil
}
