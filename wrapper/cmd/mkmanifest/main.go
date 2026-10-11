// mkmanifest: genera config/.app_manifest.json per un pacchetto di OpenSagra
// (vedi il package manifest). Usato da packaging/make-update.ps1,
// make-installer.ps1 e make-unix-package.sh, in locale e in CI:
//
//	cd wrapper && go run ./cmd/mkmanifest -root .. -version 1.2.0 -out <file>
//
// Elenca i file come li vede git (tracciati + non tracciati non ignorati,
// cioe' il working tree che i pacchetti impacchettano) piu' vendor/.
package main

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"

	"opensagra/wrapper/manifest"
)

func main() {
	root := flag.String("root", "..", "radice del repository")
	version := flag.String("version", "", "versione del pacchetto (es. 1.2.0)")
	out := flag.String("out", "", "file di uscita")
	flag.Parse()
	if *version == "" || *out == "" {
		fail("uso: mkmanifest -root <repo> -version <x.y.z> -out <file>")
	}

	listed, err := gitLsFiles(*root)
	if err != nil {
		fail("git ls-files: %v", err)
	}

	m := manifest.Manifest{Version: *version, Guard: map[string]string{}}
	var guardPaths []string
	for _, p := range listed {
		if manifest.IsGuard(p) {
			guardPaths = append(guardPaths, p)
		}
		if manifest.IsAppFile(p) && !strings.HasPrefix(p, "vendor/") {
			m.Files = append(m.Files, p)
		}
	}

	vendor := filepath.Join(*root, "vendor")
	if _, err := os.Stat(vendor); err != nil {
		fail("vendor/ non trovato in %s - esegui 'composer install' prima", *root)
	}
	_ = filepath.WalkDir(vendor, func(p string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return err
		}
		rel, _ := filepath.Rel(*root, p)
		m.Files = append(m.Files, filepath.ToSlash(rel))
		return nil
	})
	sort.Strings(m.Files)

	ids, err := gitHashObjects(*root, guardPaths)
	if err != nil {
		fail("git hash-object: %v", err)
	}
	for i, p := range guardPaths {
		m.Guard[p] = ids[i]
	}

	raw, _ := json.MarshalIndent(m, "", " ")
	if err := os.MkdirAll(filepath.Dir(*out), 0o755); err != nil {
		fail("%v", err)
	}
	if err := os.WriteFile(*out, raw, 0o644); err != nil {
		fail("%v", err)
	}
	fmt.Printf("manifesto %s: %d file, %d guard -> %s\n", *version, len(m.Files), len(m.Guard), *out)
}

// gitLsFiles: come i pacchetti, file tracciati + non tracciati non ignorati,
// solo quelli che esistono davvero sul disco (un file cancellato ma non
// ancora committato resta in --cached).
func gitLsFiles(root string) ([]string, error) {
	cmd := exec.Command("git", "-C", root, "ls-files", "-z", "--cached", "--others", "--exclude-standard")
	raw, err := cmd.Output()
	if err != nil {
		return nil, err
	}
	seen := map[string]bool{}
	var out []string
	for _, p := range strings.Split(string(raw), "\x00") {
		if p == "" || seen[p] {
			continue
		}
		seen[p] = true
		if st, err := os.Stat(filepath.Join(root, filepath.FromSlash(p))); err == nil && st.Mode().IsRegular() {
			out = append(out, p)
		}
	}
	sort.Strings(out)
	return out, nil
}

// gitHashObjects: id del blob git per ogni file, con i filtri di
// .gitattributes (fine riga normalizzati) - uguali su Windows e Linux.
func gitHashObjects(root string, paths []string) ([]string, error) {
	if len(paths) == 0 {
		return nil, nil
	}
	cmd := exec.Command("git", "-C", root, "hash-object", "--stdin-paths")
	cmd.Stdin = strings.NewReader(strings.Join(paths, "\n") + "\n")
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	raw, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("%v (%s)", err, strings.TrimSpace(stderr.String()))
	}
	ids := strings.Fields(string(raw))
	if len(ids) != len(paths) {
		return nil, fmt.Errorf("attesi %d id, ricevuti %d", len(paths), len(ids))
	}
	return ids, nil
}

func fail(f string, a ...any) {
	fmt.Fprintf(os.Stderr, "mkmanifest: "+f+"\n", a...)
	os.Exit(1)
}
