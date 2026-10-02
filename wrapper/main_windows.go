//go:build windows

package main

import (
	"context"

	"fyne.io/systray"
)

// runEventLoop (Windows): tray-app classica. systray.Run blocca finche' non
// si chiama systray.Quit() - qui e' quit() stesso (avvolto) a farlo, dopo la
// sequenza di uscita pulita, cosi' main() puo' tornare e il processo termina.
func runEventLoop(ctx context.Context, cfg *Config, sup *Supervisor, status *statusServer, quit func()) {
	t := &tray{
		ctx: ctx, cfg: cfg, sup: sup, status: status,
		quit: func() {
			quit()
			systray.Quit()
		},
	}
	systray.Run(t.onReady, t.onExit)
}
