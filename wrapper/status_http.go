package main

import (
	"context"
	"crypto/tls"
	_ "embed"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

// La finestra di stato NON e' una webview incorporata (systray + webview_go si
// contendono il message loop di Windows). E' una pagina servita qui su
// 127.0.0.1 e aperta in Edge/Chrome in modalita' app (--app=...). Vedi piano
// sez. 3g, "Finestra di stato/log - ridisegnata 2026-09-09".

//go:embed status_page.html
var statusPageHTML []byte

type statusServer struct {
	ctx context.Context
	cfg *Config
	sup *Supervisor

	addr string // 127.0.0.1:<porta>, valorizzato da start()

	mu          sync.Mutex
	clientCount int
	clientAt    time.Time
	dbToolURL   string
	dbToolAt    time.Time
	winOpen     bool // finestra app-mode gia' aperta
	updating    bool // ApplyUpdate() in corso (asincrono, vedi handleAction "apply-update")
	updateErr   string
}

func newStatusServer(ctx context.Context, cfg *Config, sup *Supervisor) *statusServer {
	return &statusServer{ctx: ctx, cfg: cfg, sup: sup}
}

func (h *statusServer) start() error {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return err
	}
	h.addr = ln.Addr().String()

	mux := http.NewServeMux()
	mux.HandleFunc("/", h.handleIndex)
	mux.HandleFunc("/favicon.ico", func(w http.ResponseWriter, r *http.Request) {
		if len(iconICO) == 0 {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Content-Type", "image/x-icon")
		w.Header().Set("Cache-Control", "max-age=86400")
		w.Write(iconICO)
	})
	mux.HandleFunc("/api/status", h.handleStatus)
	mux.HandleFunc("/api/logs/", h.handleLogs)
	mux.HandleFunc("/api/action", h.handleAction)

	srv := &http.Server{Handler: loopbackOnly(mux), ReadHeaderTimeout: 5 * time.Second}
	go srv.Serve(ln)
	return nil
}

func (h *statusServer) url() string { return "http://" + h.addr + "/" }

// loopbackOnly: difesa in profondita' oltre al bind su 127.0.0.1.
func loopbackOnly(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		host, _, _ := net.SplitHostPort(r.RemoteAddr)
		if ip := net.ParseIP(host); ip == nil || !ip.IsLoopback() {
			http.Error(w, "solo loopback", http.StatusForbidden)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func (h *statusServer) handleIndex(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/" {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Write(statusPageHTML)
}

type procJSON struct {
	Name   string `json:"name"`
	State  string `json:"state"`
	Detail string `json:"detail"`
	PID    int    `json:"pid"`
	Uptime string `json:"uptime"`
	Paused bool   `json:"paused"`
}

type statusJSON struct {
	Role              string     `json:"role"`
	ClientCount       int        `json:"client_count"`
	AppURL            string     `json:"app_url"`
	Autostart         bool       `json:"autostart"`
	AllPaused         bool       `json:"all_paused"`
	MariadbUp         bool       `json:"mariadb_up"`
	MariadbVersion    string     `json:"mariadb_version"`    // "" se il DB non risponde
	FrankenphpVersion string     `json:"frankenphp_version"` // "" se non ancora rilevata
	PhpVersion        string     `json:"php_version"`
	DbToolURL         string     `json:"db_tool_url"` // "" se /db non risponde
	LogDir            string     `json:"log_dir"`
	Procs             []procJSON `json:"procs"`

	WrapperVersion  string `json:"wrapper_version"`  // versione del CODICE app installato (vedi installedVersion)
	UpdateAvailable bool   `json:"update_available"`
	LatestVersion   string `json:"latest_version"`  // valorizzata solo se UpdateAvailable
	UpdateURL       string `json:"update_url"`      // pagina della release su GitHub
	UpdateZipURL    string `json:"update_zip_url"`  // "" se questa release richiede una reinstallazione completa
	UpdateNotes     string `json:"update_notes"`    // note della release, da mostrare prima di confermare
	Updating        bool   `json:"updating"`        // true mentre ApplyUpdate() e' in corso
	UpdateError     string `json:"update_error"`    // ultimo errore di ApplyUpdate(), se c'e'
}

// cachedClientCount: activeClientCount() apre una connessione al DB; con la
// pagina che fa polling ogni 2s conviene una cache breve.
func (h *statusServer) cachedClientCount() int {
	h.mu.Lock()
	defer h.mu.Unlock()
	if !h.clientAt.IsZero() && time.Since(h.clientAt) < 5*time.Second {
		return h.clientCount
	}
	h.clientCount = activeClientCount(h.cfg)
	h.clientAt = time.Now()
	return h.clientCount
}

// cachedDbToolURL: prova <AppURL>/db (lo strumento DB AdminNeo, route
// solo-localhost generato da install.ps1). Cache 30s. "" se non risponde
// -> il tasto "Gestione DB" nella pagina non compare.
func (h *statusServer) cachedDbToolURL() string {
	h.mu.Lock()
	defer h.mu.Unlock()
	if !h.dbToolAt.IsZero() && time.Since(h.dbToolAt) < 30*time.Second {
		return h.dbToolURL
	}
	h.dbToolAt = time.Now()
	h.dbToolURL = ""

	u := strings.TrimRight(h.cfg.AppURL, "/") + "/db"
	client := &http.Client{
		Timeout: 1500 * time.Millisecond,
		Transport: &http.Transport{
			TLSClientConfig: &tls.Config{InsecureSkipVerify: true}, // tls internal, self-signed
		},
	}
	resp, err := client.Get(u)
	if err != nil {
		return ""
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		return ""
	}
	// Un app SPA/catch-all puo' rispondere 200 a /db anche senza il route:
	// serve un marker vero di AdminNeo nel corpo.
	body := make([]byte, 16<<10)
	n, _ := io.ReadFull(resp.Body, body)
	if strings.Contains(strings.ToLower(string(body[:n])), "adminneo") {
		h.dbToolURL = u
	}
	return h.dbToolURL
}

func (h *statusServer) handleStatus(w http.ResponseWriter, r *http.Request) {
	role := "CLIENT"
	if isThisMachineServer(h.cfg) {
		role = "SERVER"
	}
	mdbUp, mdbVer := mariadbStatus(h.cfg)
	fpVer, phpVer := frankenphpVersions(h.cfg)
	upd := checkForUpdate(h.cfg)
	h.mu.Lock()
	updating, updateErr := h.updating, h.updateErr
	h.mu.Unlock()
	out := statusJSON{
		Role:              role,
		ClientCount:       h.cachedClientCount(),
		AppURL:            h.cfg.AppURL,
		Autostart:         autostartEnabled(),
		AllPaused:         h.sup.allPaused(),
		MariadbUp:         mdbUp,
		MariadbVersion:    mdbVer,
		FrankenphpVersion: fpVer,
		PhpVersion:        phpVer,
		DbToolURL:         h.cachedDbToolURL(),
		LogDir:            h.cfg.LogDir,
		WrapperVersion:    installedVersion(h.cfg),
		UpdateAvailable:   upd.Available,
		LatestVersion:     upd.Latest,
		UpdateURL:         upd.HTMLURL,
		UpdateZipURL:      upd.ZipURL,
		UpdateNotes:       upd.Changelog,
		Updating:          updating,
		UpdateError:       updateErr,
	}
	for _, name := range h.sup.names() {
		st := h.sup.get(name)
		p := procJSON{
			Name:   name,
			State:  string(st.State),
			Detail: st.Detail,
			PID:    st.PID,
			Paused: h.sup.isPaused(name),
		}
		if st.State == stateRunning && !st.Since.IsZero() {
			p.Uptime = fmtDur(time.Since(st.Since))
		}
		out.Procs = append(out.Procs, p)
	}
	writeJSON(w, out)
}

func fmtDur(d time.Duration) string {
	d = d.Round(time.Second)
	hh := d / time.Hour
	d -= hh * time.Hour
	mm := d / time.Minute
	d -= mm * time.Minute
	return fmt.Sprintf("%02d:%02d:%02d", hh, mm, d/time.Second)
}

func (h *statusServer) handleLogs(w http.ResponseWriter, r *http.Request) {
	name := strings.TrimPrefix(r.URL.Path, "/api/logs/")
	if name == "" || strings.ContainsAny(name, `/\`) || strings.Contains(name, "..") {
		http.Error(w, "nome non valido", http.StatusBadRequest)
		return
	}
	n := 200
	if v, err := strconv.Atoi(r.URL.Query().Get("n")); err == nil && v > 0 && v <= 2000 {
		n = v
	}
	path := filepath.Join(h.cfg.LogDir, logNameSanitizer.Replace(name)+".log")
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	w.Write([]byte(tailFile(path, n)))
}

func (h *statusServer) handleAction(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "POST", http.StatusMethodNotAllowed)
		return
	}
	op := r.URL.Query().Get("op")
	name := r.URL.Query().Get("name")

	switch op {
	case "restart-all":
		h.sup.restartAll()
		go announceBackWhenUp(h.ctx, h.sup, h.cfg) // pulisce un eventuale banner "server giu'" quando FrankenPHP risale
	case "pause-all":
		// Per un client un server in pausa == server giu': avvisalo PRIMA di
		// fermare FrankenPHP (l'hub deve essere ancora up per pubblicare).
		announceShutdown(h.cfg)
		h.sup.pauseAll()
	case "resume-all":
		h.sup.resumeAll()
		go announceBackWhenUp(h.ctx, h.sup, h.cfg) // pulisce il banner sui client quando risale
	case "restart":
		h.sup.restart(name)
	case "pause":
		h.sup.pause(name)
	case "resume":
		h.sup.resume(name)
	case "open-app":
		openURL(h.cfg.AppURL)
	case "open-db":
		if u := h.cachedDbToolURL(); u != "" {
			openURL(u)
		}
	case "open-update":
		if u := checkForUpdate(h.cfg).HTMLURL; u != "" {
			openURL(u)
		}
	case "apply-update":
		h.mu.Lock()
		alreadyRunning := h.updating
		if !alreadyRunning {
			h.updating = true
			h.updateErr = ""
		}
		h.mu.Unlock()
		if alreadyRunning {
			writeJSON(w, map[string]any{"ok": false, "error": "aggiornamento gia' in corso"})
			return
		}
		info := checkForUpdate(h.cfg)
		go func() {
			err := ApplyUpdate(h.cfg, info)
			h.mu.Lock()
			h.updating = false
			if err != nil {
				h.updateErr = err.Error()
			}
			h.mu.Unlock()
			if err == nil {
				h.sup.restartAll() // il codice nuovo va caricato - stesso effetto di "Riavvia tutto"
				go announceBackWhenUp(h.ctx, h.sup, h.cfg)
			}
		}()
	case "open-logs":
		revealPath(h.cfg.LogDir)
	case "autostart-on":
		if err := setAutostart(true); err != nil {
			writeJSON(w, map[string]any{"ok": false, "error": err.Error()})
			return
		}
	case "autostart-off":
		if err := setAutostart(false); err != nil {
			writeJSON(w, map[string]any{"ok": false, "error": err.Error()})
			return
		}
	default:
		http.Error(w, "op sconosciuta: "+op, http.StatusBadRequest)
		return
	}
	writeJSON(w, map[string]any{"ok": true})
}

// openWindow: apre la pagina in una finestra app-mode; se e' gia' aperta non fa
// nulla. Fallback al browser di default se Edge/Chrome non c'e'.
func (h *statusServer) openWindow() {
	h.mu.Lock()
	if h.winOpen {
		h.mu.Unlock()
		return
	}
	h.mu.Unlock()

	cmd := appWindowCmd(h.url(), filepath.Join(h.cfg.LogDir, ".statuswin"))
	if cmd == nil || cmd.Start() != nil {
		openURL(h.url())
		return
	}
	h.mu.Lock()
	h.winOpen = true
	h.mu.Unlock()
	go func() {
		_ = cmd.Wait()
		h.mu.Lock()
		h.winOpen = false
		h.mu.Unlock()
	}()
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	_ = json.NewEncoder(w).Encode(v)
}
