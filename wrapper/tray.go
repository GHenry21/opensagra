package main

import (
	"fmt"
	"log"
	"time"

	"fyne.io/systray"
)

type tray struct {
	cfg    *Config
	sup    *Supervisor
	status *statusServer
	quit   func() // sequenza di uscita pulita (definita in main)
}

func (t *tray) onReady() {
	if len(iconICO) > 0 {
		systray.SetIcon(iconICO) // .ico multi-size incorporato
	}
	systray.SetTitle("OpenSagra")
	systray.SetTooltip("OpenSagra — server locale")

	mWindow := systray.AddMenuItem("Finestra di stato", "Apri il pannello di stato di OpenSagra")
	mOpen := systray.AddMenuItem("Apri OpenSagra", "Apri l'app nel browser")

	systray.AddSeparator()
	hdr := systray.AddMenuItem("Stato", "")
	hdr.Disable()
	statusItems := map[string]*systray.MenuItem{}
	for _, name := range t.sup.names() {
		it := systray.AddMenuItem("  "+name+": …", "")
		it.Disable()
		statusItems[name] = it
	}

	systray.AddSeparator()
	mRestart := systray.AddMenuItem("Riavvia tutto", "Ferma e riavvia i processi")
	mLogs := systray.AddMenuItem("Apri cartella log", t.cfg.LogDir)
	mAutostart := systray.AddMenuItemCheckbox("Avvia all'accensione", "Avvia OpenSagra al login di Windows", autostartEnabled())

	systray.AddSeparator()
	mQuit := systray.AddMenuItem("Esci", "Ferma il server locale ed esci")

	go func() {
		tick := time.NewTicker(time.Second)
		defer tick.Stop()
		for {
			select {
			case <-mWindow.ClickedCh:
				if t.status != nil {
					t.status.openWindow()
				}
			case <-mOpen.ClickedCh:
				openURL(t.cfg.AppURL)
			case <-mLogs.ClickedCh:
				revealPath(t.cfg.LogDir)
			case <-mRestart.ClickedCh:
				t.sup.restartAll()
			case <-mAutostart.ClickedCh:
				want := !mAutostart.Checked()
				if err := setAutostart(want); err != nil {
					log.Printf("avvio all'accensione: %v", err)
				} else if want {
					mAutostart.Check()
				} else {
					mAutostart.Uncheck()
				}
			case <-mQuit.ClickedCh:
				if t.confirmQuit() {
					t.quit()
					return
				}
			case <-tick.C:
				for name, it := range statusItems {
					st := t.sup.get(name)
					label := fmt.Sprintf("  %s: %s", name, st.State)
					if st.Detail != "" {
						label += " — " + st.Detail
					}
					it.SetTitle(label)
				}
			}
		}
	}()
}

func (t *tray) onExit() {}

func (t *tray) confirmQuit() bool {
	heading := "Vuoi davvero chiudere OpenSagra?"
	body := "Il server locale su questa macchina si fermerà."
	if n := activeClientCount(t.cfg); n > 0 {
		heading = fmt.Sprintf("ATTENZIONE: %d cassa/e collegate perderanno il database quando questo PC si ferma.", n)
		body = "Chiudere comunque OpenSagra?"
	}
	return confirmQuit("OpenSagra", heading, body)
}
