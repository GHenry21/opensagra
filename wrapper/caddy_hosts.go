package main

import (
	"context"
	"log"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"
)

// IP del Caddyfile sempre allineati a quelli della macchina (piano, Fase 6,
// "certificato legato all'IP del giorno dell'installazione"). L'installer
// scrive nel Caddyfile gli indirizzi che la macchina ha in quel momento; Caddy
// (`tls internal`) emette un certificato solo per quelli. Se poi il DHCP
// assegna un altro IP (visto sulla VM: .39 -> .18), via IP il TLS fallisce del
// tutto e telefoni/tablet perdono l'app. Qui, all'avvio (prima di lanciare
// FrankenPHP) e ogni caddyHostsEvery, si riscrivono gli IP della riga degli
// host e di `cors_origins` (Mercure rifiuta il cookie da un'origine non
// elencata); se cambiano, reload graceful di FrankenPHP. Gli host che non sono
// IP (localhost, nomi) non si toccano. Il certificato nuovo viene dalla stessa
// CA locale: i dispositivi che si fidavano gia' continuano a fidarsi.
//
// Non risolve le casse CLIENT, che hanno l'IP del server in DB_POS_HOST: per
// quelle serve un IP fisso o una prenotazione DHCP sul router (guida utente).
// Per questo, sul server, la finestra di stato avvisa quando l'IP cambia.
const caddyHostsEvery = time.Minute

var (
	// "https://localhost, https://192.168.1.5 {" - la riga del sito.
	caddySiteLine = regexp.MustCompile(`(?m)^(https://localhost(?:,[ \t]*https://[^\s,{]+)*)[ \t]*\{[ \t]*\r?$`)
	// "		cors_origins https://localhost https://192.168.1.5"
	caddyCorsLine = regexp.MustCompile(`(?m)^([ \t]*cors_origins)[ \t]+([^\r\n]+?)[ \t]*\r?$`)
)

var (
	ipChangeMu   sync.Mutex
	ipChangeNote string // per la finestra di stato: "" se nessun cambio da quando gira
)

// lanIPv4s: indirizzi IPv4 delle interfacce attive, esclusi loopback e
// link-local (169.254.x.x, assegnato quando il DHCP non risponde).
func lanIPv4s() []string {
	var out []string
	ifaces, err := net.Interfaces()
	if err != nil {
		return nil
	}
	for _, ifc := range ifaces {
		if ifc.Flags&net.FlagUp == 0 || ifc.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, _ := ifc.Addrs()
		for _, a := range addrs {
			ipn, ok := a.(*net.IPNet)
			if !ok {
				continue
			}
			ip4 := ipn.IP.To4()
			if ip4 == nil || ip4.IsLoopback() || ip4.IsLinkLocalUnicast() {
				continue
			}
			out = append(out, ip4.String())
		}
	}
	sort.Strings(out)
	return dedup(out)
}

func dedup(s []string) []string {
	var out []string
	for i, v := range s {
		if i == 0 || v != s[i-1] {
			out = append(out, v)
		}
	}
	return out
}

func isIPv4Literal(host string) bool {
	ip := net.ParseIP(host)
	return ip != nil && ip.To4() != nil
}

// hostOf: "https://192.168.1.5" -> "192.168.1.5"; "https://x:8443" -> "x".
func hostOf(entry string) string {
	h := entry
	if i := strings.Index(h, "://"); i >= 0 {
		h = h[i+3:]
	}
	if hh, _, err := net.SplitHostPort(h); err == nil {
		return hh
	}
	return h
}

// rewriteHostList: tiene le voci non-IP nell'ordine originale, sostituisce
// quelle IP con `ips`. scheme = schema da usare per le voci nuove.
func rewriteHostList(entries []string, ips []string, scheme string) (out []string, oldIPs []string) {
	for _, e := range entries {
		if isIPv4Literal(hostOf(e)) {
			oldIPs = append(oldIPs, hostOf(e))
			continue
		}
		out = append(out, e)
	}
	for _, ip := range ips {
		out = append(out, scheme+"://"+ip)
	}
	sort.Strings(oldIPs)
	return out, dedup(oldIPs)
}

// syncCaddyfileHosts: ritorna changed=true se ha riscritto il file. Non
// tocca nulla se non trova gli IP della macchina (rete giu' all'avvio: meglio
// tenere quelli di prima che togliere tutto) o se il Caddyfile non ha il
// formato degli installer (es. un Caddyfile scritto a mano).
func syncCaddyfileHosts(path string) (changed bool, oldIPs, newIPs []string, err error) {
	ips := lanIPv4s()
	if len(ips) == 0 {
		return false, nil, nil, nil
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return false, nil, nil, err
	}
	src := string(raw)
	m := caddySiteLine.FindStringSubmatchIndex(src)
	if m == nil {
		return false, nil, nil, nil
	}

	siteEntries := strings.Split(src[m[2]:m[3]], ",")
	for i := range siteEntries {
		siteEntries[i] = strings.TrimSpace(siteEntries[i])
	}
	newSite, oldIPs := rewriteHostList(siteEntries, ips, "https")
	if equalStrings(oldIPs, ips) {
		return false, oldIPs, ips, nil
	}
	out := src[:m[2]] + strings.Join(newSite, ", ") + src[m[3]:]

	out = caddyCorsLine.ReplaceAllStringFunc(out, func(line string) string {
		sm := caddyCorsLine.FindStringSubmatch(line)
		entries := strings.Fields(sm[2])
		if len(entries) == 0 || !strings.Contains(entries[0], "://") {
			return line
		}
		scheme := entries[0][:strings.Index(entries[0], "://")]
		newCors, _ := rewriteHostList(entries, ips, scheme)
		suffix := ""
		if strings.HasSuffix(line, "\r") {
			suffix = "\r"
		}
		return sm[1] + " " + strings.Join(newCors, " ") + suffix
	})

	tmp := path + ".os-new"
	if err := os.WriteFile(tmp, []byte(out), 0o644); err != nil {
		return false, nil, nil, err
	}
	if err := os.Rename(tmp, path); err != nil {
		os.Remove(tmp)
		return false, nil, nil, err
	}
	return true, oldIPs, ips, nil
}

func equalStrings(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}

// syncCaddyHostsAtStart: prima di avviare FrankenPHP, nessun reload necessario.
func syncCaddyHostsAtStart(cfg *Config) {
	path := filepath.Join(cfg.AppRoot, "Caddyfile")
	changed, oldIPs, newIPs, err := syncCaddyfileHosts(path)
	if err != nil {
		log.Printf("caddyfile: allineamento IP fallito: %v", err)
		return
	}
	if changed {
		log.Printf("caddyfile: IP aggiornati all'avvio %v -> %v", oldIPs, newIPs)
		noteIPChange(oldIPs, newIPs)
	}
}

// watchCaddyHosts: ricontrolla periodicamente; se gli IP cambiano mentre
// gira, riscrive e ricarica FrankenPHP (graceful: le richieste in corso non
// cadono), ripiegando su un riavvio del figlio se il reload non riesce.
func watchCaddyHosts(ctx context.Context, cfg *Config, sup *Supervisor) {
	path := filepath.Join(cfg.AppRoot, "Caddyfile")
	t := time.NewTicker(caddyHostsEvery)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
		changed, oldIPs, newIPs, err := syncCaddyfileHosts(path)
		if err != nil {
			log.Printf("caddyfile: allineamento IP fallito: %v", err)
			continue
		}
		if !changed {
			continue
		}
		log.Printf("caddyfile: IP cambiati %v -> %v, ricarico FrankenPHP", oldIPs, newIPs)
		noteIPChange(oldIPs, newIPs)
		if sup.isPaused("frankenphp") {
			continue // ripartira' gia' col file nuovo
		}
		rctx, cancel := context.WithTimeout(ctx, 30*time.Second)
		cmd := exec.CommandContext(rctx, cfg.Frankenphp, "reload", "--config", path, "--adapter", "caddyfile")
		cmd.Dir = cfg.AppRoot
		hideWindow(cmd)
		out, err := cmd.CombinedOutput()
		cancel()
		if err != nil {
			log.Printf("caddyfile: reload fallito (%v: %s), riavvio FrankenPHP", err, strings.TrimSpace(string(out)))
			sup.restart("frankenphp")
		}
	}
}

// noteIPChange: avvisa solo se un indirizzo di prima e' SPARITO - e' quello che
// rompe le casse client che lo hanno in DB_POS_HOST. Un IP in piu' (es. la
// scheda di Hyper-V, che l'installer Windows non elenca) non e' un cambio.
func noteIPChange(oldIPs, newIPs []string) {
	have := map[string]bool{}
	for _, ip := range newIPs {
		have[ip] = true
	}
	var gone []string
	for _, ip := range oldIPs {
		if !have[ip] {
			gone = append(gone, ip)
		}
	}
	if len(gone) == 0 {
		return
	}
	ipChangeMu.Lock()
	ipChangeNote = strings.Join(gone, ", ") + " → " + strings.Join(newIPs, ", ")
	ipChangeMu.Unlock()
}

func lastIPChange() string {
	ipChangeMu.Lock()
	defer ipChangeMu.Unlock()
	return ipChangeNote
}
