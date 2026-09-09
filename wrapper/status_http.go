package main

import (
	"context"
	_ "embed"
	"encoding/json"
	"fmt"
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
	winOpen     bool // finestra app-mode gia' aperta
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
	Role        string     `json:"role"`
	ClientCount int        `json:"client_count"`
	AppURL      string     `json:"app_url"`
	Autostart   bool       `json:"autostart"`
	AllPaused   bool       `json:"all_paused"`
	LogDir      string     `json:"log_dir"`
	Procs       []procJSON `json:"procs"`
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

func (h *statusServer) handleStatus(w http.ResponseWriter, r *http.Request) {
	role := "CLIENT"
	if isThisMachineServer(h.cfg) {
		role = "SERVER"
	}
	out := statusJSON{
		Role:        role,
		ClientCount: h.cachedClientCount(),
		AppURL:      h.cfg.AppURL,
		Autostart:   autostartEnabled(),
		AllPaused:   h.sup.allPaused(),
		LogDir:      h.cfg.LogDir,
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
