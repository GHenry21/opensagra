// Installer grafico OpenSagra per Linux e macOS: la stessa finestra di
// avanzamento dell'installer Windows (install.ps1, Show-InstallWindow) sopra
// install.sh / install-macos.sh, che restano gli unici a fare il lavoro e non
// sanno di avere una GUI davanti (vedi progress.go per come si legge il loro
// output).
//
// Uso (doppio click, nessun argomento): cerca lo script accanto a se' - o,
// dentro un bundle .app, accanto al bundle o in Contents/Resources/payload -
// e lo esegue come utente normale; le richieste di password di sudo passano
// dalla finestra (sudo_unix.go).
//
// Per lavorare sulla grafica senza installare nulla (anche da Windows):
//
//	go run . -demo ok|error|password
package main

import (
	"errors"
	"flag"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// Stesso percorso di LOG_FILE in install.sh / install-macos.sh: lo script lo
// scrive, qui serve solo per il pulsante "Apri il log" e il messaggio d'errore.
const scriptLogPath = "/tmp/opensagra-install.log"

func main() {
	// Modalita' helper SUDO_ASKPASS: sudo la lancia con il prompt come unico
	// argomento e legge la password dallo stdout.
	if len(os.Args) > 1 && os.Args[1] == "--askpass" {
		os.Exit(askpassMain())
	}

	demo := flag.String("demo", "", "simula un'installazione senza eseguire nulla: ok | error | password")
	scriptFlag := flag.String("script", "", "percorso dello script da eseguire (default: cercato accanto all'installer)")
	headless := flag.Bool("headless", false, "nessuna finestra: stampa l'URL della pagina (test automatici)")
	flag.Parse()

	logPath := scriptLogPath
	if *demo != "" {
		logPath = filepath.Join(os.TempDir(), "opensagra-installer-demo.log")
	}
	st := newState(runtime.GOOS, logPath)

	var scriptPath string
	var findErr error
	if *demo == "" {
		scriptPath, findErr = findScript(*scriptFlag)
	}
	srv := newServer(st, findLogoDir(scriptPath))
	srv.openLog = func() { openPath(logPath) }
	if err := srv.start(); err != nil {
		fmt.Fprintln(os.Stderr, "ERRORE: impossibile avviare la finestra:", err)
		os.Exit(1)
	}

	quit := make(chan struct{})
	exitCode := make(chan int, 1)
	go func() {
		ok := false
		if findErr != nil {
			st.update(func(s *state) { s.Phase, s.Error = phaseError, findErr.Error() })
		} else {
			ok = runInstall(st, srv, scriptPath, *demo)
		}
		if ok {
			// Come install.ps1: "Installazione completata" resta a video 2 s.
			time.Sleep(2500 * time.Millisecond)
			exitCode <- 0
		} else {
			<-srv.closeReq // in errore la finestra resta aperta finche' non si preme Chiudi
			exitCode <- 1
		}
		close(quit)
	}()

	if *headless {
		fmt.Println(srv.url("app"))
		<-quit
		os.Exit(<-exitCode)
	}

	// La finestra puo' chiudersi prima della fine (Linux: la X di Chromium non
	// si puo' disattivare). L'installazione intanto continua: si riapre, e se
	// non c'e' verso si aspetta la fine senza finestra.
	for attempt := 0; ; attempt++ {
		showWindow(srv, quit)
		select {
		case <-quit:
			os.Exit(<-exitCode)
		default:
		}
		if st.snapshot().Phase == phaseError {
			os.Exit(1) // chiusa dall'utente dopo aver letto l'errore
		}
		if attempt >= 4 {
			<-quit
			os.Exit(<-exitCode)
		}
	}
}

func runInstall(st *state, srv *server, scriptPath, demo string) bool {
	if demo != "" {
		return runDemo(st, srv, demo)
	}
	env, cleanup, ok := prepareSudo(st, srv)
	defer cleanup()
	if !ok {
		return false
	}
	st.update(func(s *state) { s.Phase, s.Status = phaseRunning, "Verifica dei prerequisiti..." })
	if err := runScript(st, scriptPath, env); err != nil {
		st.update(func(s *state) {
			s.Phase = phaseError
			if s.Error == "" { // lo script e' morto senza passare da die()
				s.Error = "l'installazione si e' interrotta (" + err.Error() + ")"
			}
		})
		return false
	}
	st.update(func(s *state) { s.Phase, s.Percent, s.Status = phaseDone, 100, "Installazione completata" })
	return true
}

func scriptName() string {
	if runtime.GOOS == "darwin" {
		return "install-macos.sh"
	}
	return "install.sh"
}

func findScript(override string) (string, error) {
	if override != "" {
		p, err := filepath.Abs(override)
		if err == nil && fileExists(p) {
			return p, nil
		}
		return "", fmt.Errorf("script non trovato: %s", override)
	}
	exe, err := os.Executable()
	if err != nil {
		return "", err
	}
	if r, err := filepath.EvalSymlinks(exe); err == nil {
		exe = r
	}
	// App Translocation di macOS: un'app scaricata (attributo quarantine) e
	// aperta senza essere stata spostata gira da una copia in un percorso
	// casuale di sola lettura - i file accanto al bundle li' non ci sono.
	if strings.Contains(exe, "/AppTranslocation/") {
		return "", errors.New("macOS ha aperto l'installer da una copia temporanea. Sposta la cartella estratta (per esempio sulla Scrivania) e riapri l'installer da li'")
	}
	dirs := []string{filepath.Dir(exe)}
	if i := strings.Index(exe, ".app/Contents/MacOS/"); i >= 0 {
		bundle := exe[:i+len(".app")]
		dirs = append(dirs, filepath.Join(bundle, "Contents", "Resources", "payload"), filepath.Dir(bundle))
	}
	for _, d := range dirs {
		if p := filepath.Join(d, scriptName()); fileExists(p) {
			return p, nil
		}
	}
	return "", fmt.Errorf("non trovo %s accanto all'installer: estrai tutto l'archivio e lancia l'installer dalla cartella estratta", scriptName())
}

// findLogoDir: assets/ del pacchetto (accanto allo script) per il logo; in
// demo, quello del repo (installer/ e' una sottocartella della radice).
func findLogoDir(scriptPath string) string {
	var cands []string
	if scriptPath != "" {
		cands = append(cands, filepath.Join(filepath.Dir(scriptPath), "assets"))
	}
	if wd, err := os.Getwd(); err == nil {
		cands = append(cands, filepath.Join(wd, "..", "assets"), filepath.Join(wd, "assets"))
	}
	for _, d := range cands {
		if fileExists(filepath.Join(d, "logo.svg")) {
			return d
		}
	}
	return ""
}

func fileExists(p string) bool {
	fi, err := os.Stat(p)
	return err == nil && !fi.IsDir()
}

// askpassMain: chiede la password gia' verificata al processo principale
// (indirizzo e token dall'ambiente, impostati da prepareSudo).
func askpassMain() int {
	addr, token := os.Getenv("OPENSAGRA_INSTALLER_ADDR"), os.Getenv("OPENSAGRA_INSTALLER_TOKEN")
	if addr == "" || token == "" {
		return 1
	}
	req, _ := http.NewRequest(http.MethodGet, "http://"+addr+"/api/askpass", nil)
	req.Header.Set("X-Token", token)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return 1
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return 1
	}
	var b strings.Builder
	buf := make([]byte, 4096)
	for {
		n, err := resp.Body.Read(buf)
		b.Write(buf[:n])
		if err != nil {
			break
		}
	}
	fmt.Println(b.String())
	return 0
}
