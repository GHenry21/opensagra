package main

// Traduzione dell'output di install.sh / install-macos.sh nello stato della
// finestra (barra, testo del passo, elenco con i pallini) - lo stesso che
// install.ps1 disegna su Windows con Set-InstallProgress/Add-InstallChecklistItem.
//
// Gli script NON sanno di avere una GUI davanti: si leggono le righe che gia'
// emettono le loro funzioni log/ok/die (contratto concordato con chi mantiene
// gli script - non cambiano prefissi ne' nomi):
//
//	"[<data>] testo"   log()  -> testo del passo in corso
//	"  OK: testo"      ok()   -> pallino verde nell'elenco
//	"ERRORE: testo"    die()  -> errore (lo script esce subito dopo)
//
// La data NON va interpretata: Linux usa `date -Iseconds` (+02:00), macOS
// `date '+%Y-%m-%dT%H:%M:%S%z'` (+0200, BSD date non ha -I). Tutto il resto
// (apt, Homebrew, curl...) e' testo libero e si ignora.

import (
	"regexp"
	"strings"
	"sync"
)

var (
	reLog = regexp.MustCompile(`^\[[^\]]+\]\s*(.*)$`)
	reOK  = regexp.MustCompile(`^\s*OK:\s*(.*)$`)
	reErr = regexp.MustCompile(`^ERRORE:\s*(.*)$`)
)

// nextStatus: dopo un passo completato (riga OK) quale testo mostrare sopra la
// barra - frasi "parlanti" come quelle di install.ps1 invece del nome tecnico
// dell'ultimo passo. Se un passo nuovo non e' in tabella resta il testo
// precedente: si degrada, non si rompe.
type statusRule struct {
	re     *regexp.Regexp
	linux  string
	darwin string // "" = come linux
}

var statusRules = []statusRule{
	{regexp.MustCompile(`^prerequisiti`), "Installazione di FrankenPHP...", "Installazione di Homebrew..."},
	{regexp.MustCompile(`^Homebrew`), "", "Installazione di FrankenPHP..."},
	{regexp.MustCompile(`^FrankenPHP .*(installato|presente|sostituito)`), "Permessi di rete per FrankenPHP...", "Configurazione delle estensioni PHP..."},
	{regexp.MustCompile(`porte 80/443`), "Configurazione delle estensioni PHP...", ""},
	{regexp.MustCompile(`^php\.ini`), "Installazione di MariaDB...", ""},
	{regexp.MustCompile(`^MariaDB (installato|gia)`), "Avvio del servizio database...", ""},
	{regexp.MustCompile(`^Servizio MariaDB`), "Copia dei file dell'app...", ""},
	// macOS server (branch macos-server): dopo "Servizio MariaDB attivo".
	{regexp.MustCompile(`^MariaDB raggiungibile dalla LAN`), "Copia dei file dell'app...", ""},
	{regexp.MustCompile(`^File dell'app`), "Configurazione dell'app...", ""},
	{regexp.MustCompile(`variabili\.env`), "Configurazione del server web...", ""},
	{regexp.MustCompile(`^Caddyfile`), "Configurazione del database...", ""},
	{regexp.MustCompile(`^Database creato`), "Configurazione del database...", ""},
	{regexp.MustCompile(`^Segreto Mercure`), "Aggiornamento del database...", ""},
	{regexp.MustCompile(`^Migrazioni`), "Installazione del pannello OpenSagra...", ""},
	{regexp.MustCompile(`^Disinstaller`), "Creazione del collegamento...", ""},
	{regexp.MustCompile(`^(Collegamento|App OpenSagra creata)`), "Avvio automatico all'accensione...", "Avvio di OpenSagra..."},
	{regexp.MustCompile(`^Linger`), "Avvio di OpenSagra...", ""},
	{regexp.MustCompile(`^OpenSagra avviato`), "Configurazione delle regole di rete...", ""},
	{regexp.MustCompile(`(ufw|[Ff]irewall)`), "Certificato HTTPS locale...", ""},
}

// expectedSteps: righe OK di un'installazione tipica, per stimare la
// percentuale (anche su Windows la barra va a scatti, non e' un tempo reale).
// Se gli script ne aggiungono qualcuna la barra si ferma al 95% fino alla
// fine invece di superare il 100.
func expectedSteps(goos string) int {
	if goos == "darwin" {
		return 18
	}
	return 19
}

type item struct {
	Text  string `json:"text"`
	State string `json:"state"` // ok | warn | error
}

// Phase: cosa mostra la pagina.
const (
	phaseStarting = "starting"
	phasePassword = "password" // serve la password per sudo
	phaseRunning  = "running"
	phaseDone     = "done"
	phaseError    = "error"
)

// view: quanto vede la pagina (GET /api/state).
type view struct {
	Phase   string `json:"phase"`
	Percent int    `json:"percent"`
	Status  string `json:"status"`
	Items   []item `json:"items"`
	Error   string `json:"error,omitempty"`
	OS      string `json:"os"`
	LogPath string `json:"logPath"`
}

type state struct {
	mu sync.Mutex
	view

	goos      string
	expected  int
	sawErrRow bool
}

func newState(goos, logPath string) *state {
	return &state{
		goos: goos, expected: expectedSteps(goos),
		view: view{Phase: phaseStarting, Status: "Avvio...", Items: []item{}, OS: goos, LogPath: logPath},
	}
}

func (s *state) snapshot() view {
	s.mu.Lock()
	defer s.mu.Unlock()
	cp := s.view
	cp.Items = append([]item{}, s.Items...)
	return cp
}

func (s *state) update(fn func(*state)) {
	s.mu.Lock()
	defer s.mu.Unlock()
	fn(s)
}

func (s *state) recomputePercent() {
	done := 0
	for _, it := range s.Items {
		if it.State == "ok" {
			done++
		}
	}
	p := 3 + done*92/s.expected
	if p > 95 {
		p = 95
	}
	if p > s.Percent {
		s.Percent = p
	}
}

// feedLine: una riga di output dello script (stdout o stderr, gia' senza \n).
func (s *state) feedLine(line string) {
	line = strings.TrimRight(line, "\r")
	s.mu.Lock()
	defer s.mu.Unlock()

	if m := reOK.FindStringSubmatch(line); m != nil {
		text := strings.TrimSpace(m[1])
		s.Items = append(s.Items, item{Text: text, State: "ok"})
		for _, r := range statusRules {
			if r.re.MatchString(text) {
				st := r.linux
				if s.goos == "darwin" && r.darwin != "" {
					st = r.darwin
				}
				if st != "" {
					s.Status = st
				}
				break
			}
		}
		s.recomputePercent()
		return
	}
	if m := reErr.FindStringSubmatch(line); m != nil {
		s.Error = strings.TrimSpace(m[1])
		if !s.sawErrRow {
			s.Items = append(s.Items, item{Text: s.Error, State: "error"})
			s.sawErrRow = true
		}
		return
	}
	if m := reLog.FindStringSubmatch(line); m != nil {
		text := strings.TrimSpace(m[1])
		switch {
		case strings.HasPrefix(text, "avvio install"), strings.HasPrefix(text, "Installazione completata"):
			// righe di servizio per il file di log, non per chi guarda
		case strings.HasPrefix(text, "ATTENZIONE:"):
			s.Items = append(s.Items, item{Text: strings.TrimSpace(strings.TrimPrefix(text, "ATTENZIONE:")), State: "warn"})
		default:
			s.Status = text
		}
	}
}
