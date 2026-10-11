//go:build !darwin

package main

// Linux (e Windows, solo per -demo): la pagina in un browser Chromium in
// modalita' app - finestra piccola senza schede ne' barra degli indirizzi,
// lo stesso meccanismo della finestra di stato del wrapper. Niente webview
// incorporata qui: su Raspberry Pi OS WebKitGTK non c'e' di serie, Chromium
// si'. Senza nessun Chromium: scheda del browser predefinito.

import (
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

func showWindow(s *server, quit <-chan struct{}) {
	if bin := findChromium(); bin != "" {
		profile := chromiumProfileDir()
		args := []string{"--app=" + s.url("app"), "--user-data-dir=" + profile,
			"--window-size=476,420", "--no-first-run", "--no-default-browser-check",
			"--disable-features=Translate"}
		// Wayland nativo se c'e' (Raspberry Pi OS con labwc): il Chromium del
		// Pi parte comunque in X11 e senza $DISPLAY esce subito; ignora anche
		// --ozone-platform-hint=auto (visto dal vivo, 2026-10-02).
		if os.Getenv("WAYLAND_DISPLAY") != "" {
			args = append(args, "--ozone-platform=wayland")
		}
		cmd := exec.Command(bin, args...)
		ownProcessGroup(cmd)
		if cmd.Start() == nil {
			started := time.Now()
			exited := make(chan struct{})
			go func() { _ = cmd.Wait(); close(exited) }()
			select {
			case <-quit:
				killBrowser(cmd)
				<-exited
				removeProfile(profile)
				return
			case <-exited:
				removeProfile(profile)
				// Uscito subito = non e' partito davvero (niente display,
				// profilo bloccato...): si ripiega sul browser predefinito.
				if time.Since(started) > 3*time.Second {
					return
				}
			}
		}
	}
	openURL(s.url("browser"))
	<-quit
}

// removeProfile: i processi figli di Chromium possono scrivere nel profilo
// ancora per un attimo dopo la chiusura - si riprova per qualche secondo.
func removeProfile(dir string) {
	for i := 0; i < 10; i++ {
		if os.RemoveAll(dir) == nil {
			if _, err := os.Stat(dir); os.IsNotExist(err) {
				return
			}
		}
		time.Sleep(300 * time.Millisecond)
	}
}

// chromiumProfileDir: profilo dedicato (altrimenti un Chromium gia' aperto si
// prende la finestra e il processo lanciato esce subito), sotto la home e non
// in /tmp - il Chromium snap di Ubuntu ha un /tmp privato.
func chromiumProfileDir() string {
	base, err := os.UserCacheDir()
	if err != nil {
		base = os.TempDir()
	}
	return filepath.Join(base, "opensagra-installer-window")
}
