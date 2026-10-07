package main

// Pagina della finestra + API, servite su 127.0.0.1 (porta casuale). La stessa
// pagina va in qualunque "contenitore": webview incorporata (macOS), browser
// Chromium in modalita' app o scheda del browser predefinito (Linux) - la
// logica sta tutta qui e nella pagina, il contenitore la mostra soltanto.
//
// Ogni richiesta API porta un token casuale generato all'avvio: la porta e'
// raggiungibile da qualunque processo locale, e /api/askpass consegna la
// password dell'utente.

import (
	"crypto/rand"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

//go:embed page.html
var pageHTML []byte

type server struct {
	st      *state
	token   string
	logoDir string // cartella assets/ del pacchetto (logo.svg), "" se assente
	addr    string

	// password: richieste della pagina (POST /api/password) verso la
	// validazione in sudo_unix.go; closeReq: pulsante "Chiudi".
	password chan pwRequest
	closeReq chan struct{}
	askpass  func() (string, bool) // nil fuori da Unix
	openLog  func()
}

func newServer(st *state, logoDir string) *server {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return &server{
		st: st, token: hex.EncodeToString(b), logoDir: logoDir,
		password: make(chan pwRequest), closeReq: make(chan struct{}, 1),
	}
}

// pwRequest: password inserita nella pagina + canale su cui rispondere con
// l'esito della verifica ("" = giusta, altrimenti il messaggio da mostrare).
type pwRequest struct {
	password string
	reply    chan string
}

func (s *server) start() error {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return err
	}
	s.addr = ln.Addr().String()
	mux := http.NewServeMux()
	mux.HandleFunc("/", s.handleIndex)
	mux.HandleFunc("/logo.svg", s.handleLogo)
	mux.HandleFunc("/api/state", s.auth(s.handleState))
	mux.HandleFunc("/api/password", s.auth(s.handlePassword))
	mux.HandleFunc("/api/close", s.auth(s.handleClose))
	mux.HandleFunc("/api/openlog", s.auth(s.handleOpenLog))
	mux.HandleFunc("/api/askpass", s.auth(s.handleAskpass))
	go func() { _ = http.Serve(ln, mux) }()
	return nil
}

// url: il token viaggia nel fragment (#...), che il browser non manda mai al
// server ne' scrive nei log di richiesta; la pagina lo legge da location.hash
// e lo rimanda come header. display: webview | app | browser (vedi page.html).
func (s *server) url(display string) string {
	return "http://" + s.addr + "/?d=" + display + "#" + s.token
}

func (s *server) auth(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Token") != s.token {
			http.Error(w, "token mancante o errato", http.StatusForbidden)
			return
		}
		next(w, r)
	}
}

func (s *server) handleIndex(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/" {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	_, _ = w.Write(pageHTML)
}

func (s *server) handleLogo(w http.ResponseWriter, r *http.Request) {
	if s.logoDir == "" {
		http.NotFound(w, r)
		return
	}
	b, err := os.ReadFile(filepath.Join(s.logoDir, "logo.svg"))
	if err != nil {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", "image/svg+xml")
	_, _ = w.Write(b)
}

func (s *server) handleState(w http.ResponseWriter, r *http.Request) {
	snap := s.st.snapshot()
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	_ = json.NewEncoder(w).Encode(&snap)
}

func (s *server) handlePassword(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "solo POST", http.StatusMethodNotAllowed)
		return
	}
	var body struct {
		Password string `json:"password"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || strings.TrimSpace(body.Password) == "" {
		http.Error(w, "password vuota", http.StatusBadRequest)
		return
	}
	req := pwRequest{password: body.Password, reply: make(chan string, 1)}
	select {
	case s.password <- req:
	default:
		http.Error(w, "nessuna password richiesta ora", http.StatusConflict)
		return
	}
	// Risposta solo a verifica finita (sudo -v, 1-2 s): la pagina tiene il
	// pulsante su "Verifica..." fino a qui. "" = password giusta.
	writeJSONResp(w, map[string]string{"error": <-req.reply})
}

func writeJSONResp(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	_ = json.NewEncoder(w).Encode(v)
}

func (s *server) handleClose(w http.ResponseWriter, r *http.Request) {
	select {
	case s.closeReq <- struct{}{}:
	default:
	}
	w.WriteHeader(http.StatusNoContent)
}

func (s *server) handleOpenLog(w http.ResponseWriter, r *http.Request) {
	if s.openLog != nil {
		s.openLog()
	}
	w.WriteHeader(http.StatusNoContent)
}

// handleAskpass: chiamato dall'helper SUDO_ASKPASS (stesso eseguibile con
// --askpass) quando un sudo dello script vuole la password. Restituisce
// quella gia' verificata; 404 se non ce n'e' una (sudo allora fallisce come
// farebbe senza terminale, e lo script esce con il suo ERRORE).
func (s *server) handleAskpass(w http.ResponseWriter, r *http.Request) {
	if s.askpass == nil {
		http.NotFound(w, r)
		return
	}
	pw, ok := s.askpass()
	if !ok {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	_, _ = w.Write([]byte(pw))
}
