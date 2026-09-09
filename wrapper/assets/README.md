# assets/

`opensagra.ico` — icona dell'app, **incorporata nel binario** (`icon.go`,
`go:embed`). La usa la tray (`systray.SetIcon`) e il `/favicon.ico` della
finestra di stato.

ICO multi-size (16, 24, 32, 48, 64, 128, 256 px; ogni entry PNG a 32 bit),
generata da `assets/favicon/android-chrome-512x512.png`. Per rigenerarla serve
solo Go (nessun ImageMagick): decodifica il PNG, ridimensiona con
`golang.org/x/image/draw` (CatmullRom), riassembla l'ICO con entry PNG.
