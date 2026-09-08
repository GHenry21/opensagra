package main

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

type childState string

const (
	stateStopped childState = "fermo"
	stateRunning childState = "attivo"
	stateIdle    childState = "in attesa"
	stateError   childState = "in errore"
)

// Child: specifica di un processo figlio. Solo dati statici, nessuno stato di
// runtime (quello vive nelle mappe del Supervisor, per nome).
type Child struct {
	Name string
	Dir  string
	Bin  string
	Args []string

	// AlwaysRestart: riparte a ogni uscita, qualunque exit code (il server).
	// Se false, exit(0) e' "pulito, niente da fare" -> si riprova dopo IdleRecheck.
	AlwaysRestart bool
	IdleRecheck   time.Duration
}

type childStatus struct {
	State  childState
	Detail string
	Since  time.Time
	PID    int
}

type Supervisor struct {
	logDir string
	job    *jobObject

	mu      sync.Mutex
	order   []string
	status  map[string]childStatus
	logs    map[string]*os.File
	running map[string]*os.Process
	bump    map[string]chan struct{}

	wg sync.WaitGroup
}

func newSupervisor(logDir string, job *jobObject) *Supervisor {
	return &Supervisor{
		logDir:  logDir,
		job:     job,
		status:  map[string]childStatus{},
		logs:    map[string]*os.File{},
		running: map[string]*os.Process{},
		bump:    map[string]chan struct{}{},
	}
}

func (s *Supervisor) Start(ctx context.Context, children []*Child) {
	for _, c := range children {
		s.mu.Lock()
		s.order = append(s.order, c.Name)
		s.status[c.Name] = childStatus{State: stateStopped, Since: time.Now()}
		s.bump[c.Name] = make(chan struct{}, 1)
		s.mu.Unlock()

		s.wg.Add(1)
		go s.loop(ctx, c)
	}
}

func (s *Supervisor) Wait() { s.wg.Wait() }

func (s *Supervisor) Close() {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, f := range s.logs {
		_ = f.Close()
	}
}

func (s *Supervisor) names() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]string(nil), s.order...)
}

func (s *Supervisor) get(name string) childStatus {
	s.mu.Lock()
	defer s.mu.Unlock()
	if st, ok := s.status[name]; ok {
		return st
	}
	return childStatus{State: stateStopped}
}

func (s *Supervisor) set(name string, st childStatus) {
	s.mu.Lock()
	s.status[name] = st
	s.mu.Unlock()
}

// restartAll: uccide i processi in esecuzione (il loop li riavvia subito) e
// sveglia quelli fermi in attesa di un ricontrollo.
func (s *Supervisor) restartAll() {
	s.mu.Lock()
	procs := make([]*os.Process, 0, len(s.running))
	for _, p := range s.running {
		procs = append(procs, p)
	}
	bumps := make([]chan struct{}, 0, len(s.bump))
	for _, b := range s.bump {
		bumps = append(bumps, b)
	}
	s.mu.Unlock()

	for _, p := range procs {
		_ = p.Kill()
	}
	for _, b := range bumps {
		select {
		case b <- struct{}{}:
		default:
		}
	}
}

func (s *Supervisor) loop(ctx context.Context, c *Child) {
	defer s.wg.Done()

	s.mu.Lock()
	bump := s.bump[c.Name]
	s.mu.Unlock()

	backoff := time.Second
	for ctx.Err() == nil {
		started := time.Now()
		code, startErr := s.spawn(ctx, c)

		if ctx.Err() != nil {
			s.set(c.Name, childStatus{State: stateStopped, Detail: "wrapper in chiusura", Since: time.Now()})
			return
		}
		upFor := time.Since(started)

		var wait time.Duration
		switch {
		case startErr != nil:
			s.set(c.Name, childStatus{State: stateError, Detail: "avvio fallito: " + startErr.Error(), Since: time.Now()})
			wait, backoff = backoff, capDur(backoff*2, 30*time.Second)

		case code == 0 && !c.AlwaysRestart:
			d := c.IdleRecheck
			if d <= 0 {
				d = 45 * time.Second
			}
			s.set(c.Name, childStatus{State: stateIdle, Detail: fmt.Sprintf("uscito pulito, ricontrollo tra %s", d), Since: time.Now()})
			wait, backoff = d, time.Second

		default: // exit != 0, oppure AlwaysRestart
			if upFor > 60*time.Second {
				backoff = time.Second // era su da un po': reset del backoff
			}
			s.set(c.Name, childStatus{State: stateError, Detail: fmt.Sprintf("uscito con codice %d, riavvio tra %s", code, backoff), Since: time.Now()})
			wait, backoff = backoff, capDur(backoff*2, 30*time.Second)
		}

		select {
		case <-ctx.Done():
			return
		case <-bump: // restartAll
		case <-time.After(wait):
		}
	}
}

func (s *Supervisor) spawn(ctx context.Context, c *Child) (int, error) {
	lw, err := s.logWriter(c.Name)
	if err != nil {
		return -1, err
	}
	fmt.Fprintf(lw, "\n=== %s  avvio: %s %v  (cwd %s) ===\n",
		time.Now().Format(time.RFC3339), c.Bin, c.Args, c.Dir)

	cmd := exec.CommandContext(ctx, c.Bin, c.Args...)
	cmd.Dir = c.Dir
	cmd.Stdout = lw
	cmd.Stderr = lw
	hideWindow(cmd)

	if err := cmd.Start(); err != nil {
		return -1, err
	}

	if s.job != nil {
		if err := s.job.assign(cmd.Process.Pid); err != nil {
			fmt.Fprintf(lw, "[wrapper] job object: assign pid %d fallito: %v\n", cmd.Process.Pid, err)
		}
	}

	s.mu.Lock()
	s.running[c.Name] = cmd.Process
	s.status[c.Name] = childStatus{State: stateRunning, Since: time.Now(), PID: cmd.Process.Pid}
	s.mu.Unlock()

	waitErr := cmd.Wait()

	s.mu.Lock()
	delete(s.running, c.Name)
	s.mu.Unlock()

	return exitCode(waitErr), nil
}

var logNameSanitizer = strings.NewReplacer(":", "_", "/", "_", "\\", "_", " ", "_")

// logWriter: un file di log in append per figlio, riusato tra i riavvii.
// Nessuna rotazione qui (scaffold) - vedi README.
func (s *Supervisor) logWriter(name string) (*os.File, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if f, ok := s.logs[name]; ok {
		return f, nil
	}
	f, err := os.OpenFile(
		filepath.Join(s.logDir, logNameSanitizer.Replace(name)+".log"),
		os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644)
	if err != nil {
		return nil, err
	}
	s.logs[name] = f
	return f, nil
}
