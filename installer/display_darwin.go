package main

// macOS: la pagina in una webview incorporata (WKWebView, sempre presente
// nel sistema - nessun browser da cercare: su un Mac appena acceso c'e' solo
// Safari, che non ha una modalita' "app"). La barra del titolo nativa diventa
// la testata gialla della finestra Windows: trasparente sopra lo sfondo
// giallo della finestra, con il titolo e i semafori di sistema; la pagina
// nasconde la propria testata (?d=webview). Il pulsante di chiusura e'
// disattivato: come su Windows, la finestra si chiude da sola a fine
// installazione (o con "Chiudi" se qualcosa va storto).
//
// Richiede cgo: si compila su un Mac (runner GitHub macos-*), non in
// cross-compilazione.

/*
#cgo CFLAGS: -x objective-c
#cgo LDFLAGS: -framework Cocoa
#import <Cocoa/Cocoa.h>

static void styleInstallerWindow(void *p) {
	NSWindow *w = (NSWindow *)p;
	w.titlebarAppearsTransparent = YES;
	w.backgroundColor = [NSColor colorWithSRGBRed:0xE0/255.0 green:0xB0/255.0 blue:0x20/255.0 alpha:1.0];
	// Titolo scuro sul giallo anche con il Mac in modalita' scura.
	w.appearance = [NSAppearance appearanceNamed:NSAppearanceNameAqua];
	w.styleMask &= ~(NSWindowStyleMaskClosable | NSWindowStyleMaskResizable);
	[w center];
	[NSApp activateIgnoringOtherApps:YES];
}
*/
import "C"

import (
	"os/exec"
	"runtime"

	webview "github.com/webview/webview_go"
)

// Cocoa vuole la finestra sul thread principale: main() chiama showWindow
// direttamente, senza goroutine in mezzo.
func init() { runtime.LockOSThread() }

func showWindow(s *server, quit <-chan struct{}) {
	w := webview.New(false)
	defer w.Destroy()
	w.SetTitle("Installazione OpenSagra")
	// 460x360 come su Windows, meno i 44 px della testata (qui e' la barra nativa).
	w.SetSize(460, 316, webview.HintFixed)
	C.styleInstallerWindow(w.Window())
	w.Navigate(s.url("webview"))
	go func() {
		<-quit
		w.Dispatch(w.Terminate)
	}()
	w.Run()
}

func openURL(u string) { _ = exec.Command("open", u).Start() }

// openPath: il log e' testo semplice, si apre in TextEdit.
func openPath(p string) { _ = exec.Command("open", "-t", p).Start() }
