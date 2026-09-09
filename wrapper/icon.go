package main

import _ "embed"

// iconICO: icona dell'app, incorporata nel binario. Serve alla tray
// (systray.SetIcon) e al /favicon.ico della finestra di stato. Generata da
// assets/favicon/android-chrome-512x512.png -> ICO multi-size (16..256, PNG).
//
//go:embed assets/opensagra.ico
var iconICO []byte
