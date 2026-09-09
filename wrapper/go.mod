module opensagra/wrapper

go 1.23

// Dipendenze dirette. Non c'e' go.sum nel repo: dopo il primo checkout
//   cd wrapper && go mod tidy
require (
	fyne.io/systray v1.11.0
	github.com/go-sql-driver/mysql v1.8.1
	golang.org/x/sys v0.28.0
)

require (
	filippo.io/edwards25519 v1.1.0 // indirect
	github.com/godbus/dbus/v5 v5.1.0 // indirect
)
