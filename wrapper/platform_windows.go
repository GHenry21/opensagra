//go:build windows

package main

import (
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

const (
	_CREATE_NO_WINDOW = 0x08000000
	// JOBOBJECTINFOCLASS.JobObjectExtendedLimitInformation. Definito qui per
	// non dipendere dall'export della costante in x/sys/windows (versioni
	// diverse la espongono o no).
	_JobObjectExtendedLimitInformation = 9
)

// hideWindow: niente finestra di console che lampeggia per ogni figlio.
func hideWindow(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{
		HideWindow:    true,
		CreationFlags: _CREATE_NO_WINDOW,
	}
}

// --- istanza singola: named mutex + (in main) lock file ---
//
// `fresh` = true se questo processo ha CREATO il mutex (nessun altro lo tiene).
// Se false, main.go consulta il lock file: PID vivo -> apre la sua finestra ed
// esce; PID morto -> mutex stantio, prosegue. `release` chiude sempre l'handle.
func acquireSingleInstance(name string) (release func(), fresh bool) {
	n, err := windows.UTF16PtrFromString(`Global\` + name)
	if err != nil {
		return func() {}, true // in dubbio, non impedire l'avvio
	}
	h, err := windows.CreateMutex(nil, false, n)
	if h == 0 {
		return func() {}, true
	}
	return func() { windows.CloseHandle(h) }, !errors.Is(err, windows.ERROR_ALREADY_EXISTS)
}

// processAlive: true se esiste un processo con quel PID ancora in esecuzione.
func processAlive(pid int) bool {
	h, err := windows.OpenProcess(windows.PROCESS_QUERY_LIMITED_INFORMATION, false, uint32(pid))
	if err != nil {
		return false // nessun processo (o accesso negato: comunque non "nostro")
	}
	defer windows.CloseHandle(h)
	var code uint32
	if err := windows.GetExitCodeProcess(h, &code); err != nil {
		return true // non si sa -> prudenza, non calpestare
	}
	const stillActive = 259
	return code == stillActive
}

// --- job object: KILL_ON_JOB_CLOSE = i figli muoiono col wrapper ---

type jobObject struct{ h windows.Handle }

func newJobObject() (*jobObject, error) {
	h, err := windows.CreateJobObject(nil, nil)
	if err != nil {
		return nil, err
	}
	info := windows.JOBOBJECT_EXTENDED_LIMIT_INFORMATION{
		BasicLimitInformation: windows.JOBOBJECT_BASIC_LIMIT_INFORMATION{
			LimitFlags: windows.JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE,
		},
	}
	if _, err := windows.SetInformationJobObject(
		h,
		_JobObjectExtendedLimitInformation,
		uintptr(unsafe.Pointer(&info)),
		uint32(unsafe.Sizeof(info)),
	); err != nil {
		windows.CloseHandle(h)
		return nil, err
	}
	return &jobObject{h: h}, nil
}

// assign: da chiamare subito dopo cmd.Start(). C'e' una finestra di corsa
// minima (il figlio potrebbe fare fork prima dell'assign) - accettabile:
// frankenphp/php non generano nipoti nei primi millisecondi.
func (j *jobObject) assign(pid int) error {
	p, err := windows.OpenProcess(windows.PROCESS_SET_QUOTA|windows.PROCESS_TERMINATE, false, uint32(pid))
	if err != nil {
		return err
	}
	defer windows.CloseHandle(p)
	return windows.AssignProcessToJobObject(j.h, p)
}

func (j *jobObject) close() { windows.CloseHandle(j.h) }

// --- conferma uscita: TaskDialog (Vista+), fallback a MessageBox ---

var (
	_user32          = windows.NewLazySystemDLL("user32.dll")
	_procMessageBoxW = _user32.NewProc("MessageBoxW")
	_comctl32        = windows.NewLazySystemDLL("comctl32.dll")
	_procTaskDialog  = _comctl32.NewProc("TaskDialog")
)

const (
	_MB_YESNO         = 0x00000004
	_MB_ICONWARNING   = 0x00000030
	_MB_DEFBUTTON2    = 0x00000100 // default = "No"
	_MB_SETFOREGROUND = 0x00010000
	_MB_TOPMOST       = 0x00040000
	_IDYES            = 6

	_TDCBF_YES_BUTTON = 0x0001
	_TDCBF_NO_BUTTON  = 0x0002
	_TD_WARNING_ICON  = 0xFFFF // MAKEINTRESOURCE(-1)
)

// confirmQuit: title = titolo finestra; heading = istruzione grande in grassetto
// (blu); body = testo sotto. Su Windows moderno usa TaskDialog (aspetto app,
// DPI-aware — richiede il manifest Common-Controls v6, incluso via .syso).
func confirmQuit(title, heading, body string) bool {
	if ok, done := taskDialogYesNo(title, heading, body); done {
		return ok
	}
	// fallback: MessageBox classico
	t, _ := windows.UTF16PtrFromString(title)
	b, _ := windows.UTF16PtrFromString(heading + "\n\n" + body)
	ret, _, _ := _procMessageBoxW.Call(0,
		uintptr(unsafe.Pointer(b)), uintptr(unsafe.Pointer(t)),
		uintptr(_MB_YESNO|_MB_ICONWARNING|_MB_DEFBUTTON2|_MB_SETFOREGROUND|_MB_TOPMOST))
	return ret == _IDYES
}

// taskDialogYesNo: (rispostaSì, riuscito). done=false -> TaskDialog non
// disponibile, usa il fallback.
func taskDialogYesNo(title, heading, body string) (yes bool, done bool) {
	if err := _procTaskDialog.Find(); err != nil {
		return false, false
	}
	tw, _ := windows.UTF16PtrFromString(title)
	hw, _ := windows.UTF16PtrFromString(heading)
	bw, _ := windows.UTF16PtrFromString(body)
	var pressed int32
	// TaskDialog(hwndParent, hInstance, pszWindowTitle, pszMainInstruction,
	//   pszContent, dwCommonButtons, pszIcon, *pnButton) HRESULT
	hr, _, _ := _procTaskDialog.Call(
		0, 0,
		uintptr(unsafe.Pointer(tw)),
		uintptr(unsafe.Pointer(hw)),
		uintptr(unsafe.Pointer(bw)),
		uintptr(_TDCBF_YES_BUTTON|_TDCBF_NO_BUTTON),
		uintptr(_TD_WARNING_ICON),
		uintptr(unsafe.Pointer(&pressed)),
	)
	if hr != 0 { // non S_OK
		return false, false
	}
	return pressed == _IDYES, true
}

// --- apertura URL / cartella ---

func openURL(u string) {
	_ = exec.Command("rundll32", "url.dll,FileProtocolHandler", u).Start()
}

func revealPath(p string) {
	_ = exec.Command("explorer", p).Start()
}

// appWindowCmd: un browser Chromium (Edge/Chrome/Brave/Vivaldi/…) in modalita'
// app — finestra senza tab/barra indirizzi. nil se non ne trova nessuno
// (openWindow ripiega sul browser di default, tab normale). Un `--user-data-dir`
// dedicato isola dal profilo dell'utente e dà la single-instance: un secondo
// lancio sullo stesso URL/profilo porta in primo piano la finestra gia' aperta.
func appWindowCmd(url, profileDir string) *exec.Cmd {
	if exe := findChromium(); exe != "" {
		return exec.Command(exe,
			"--app="+url,
			"--user-data-dir="+profileDir,
			"--window-size=470,660",
			"--no-first-run", "--no-default-browser-check")
	}
	return nil
}

func findChromium() string {
	pf := os.Getenv("ProgramFiles")
	pf86 := os.Getenv("ProgramFiles(x86)")
	la := os.Getenv("LOCALAPPDATA")

	var cands []string
	for _, base := range []string{pf, pf86, la} {
		if base == "" {
			continue
		}
		cands = append(cands,
			filepath.Join(base, `Microsoft\Edge\Application\msedge.exe`),
			filepath.Join(base, `Google\Chrome\Application\chrome.exe`),
			filepath.Join(base, `BraveSoftware\Brave-Browser\Application\brave.exe`),
			filepath.Join(base, `Vivaldi\Application\vivaldi.exe`),
			filepath.Join(base, `Chromium\Application\chrome.exe`),
		)
	}
	for _, p := range cands {
		if fileExists(p) {
			return p
		}
	}
	// Registro: App Paths (copre installazioni in percorsi non standard).
	for _, name := range []string{"msedge.exe", "chrome.exe", "brave.exe", "vivaldi.exe"} {
		if p := appPathFromRegistry(name); p != "" && fileExists(p) {
			return p
		}
	}
	// Ultimo tentativo: PATH.
	for _, name := range []string{"chrome", "msedge", "brave", "vivaldi", "chromium"} {
		if p, err := exec.LookPath(name); err == nil {
			return p
		}
	}
	return ""
}

func appPathFromRegistry(exeName string) string {
	sub := `SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\` + exeName
	for _, root := range []registry.Key{registry.CURRENT_USER, registry.LOCAL_MACHINE} {
		if k, err := registry.OpenKey(root, sub, registry.QUERY_VALUE); err == nil {
			v, _, err := k.GetStringValue("")
			k.Close()
			if err == nil {
				return v
			}
		}
	}
	return ""
}

// --- "avvia all'accensione": chiave di registro HKCU\...\Run ---
//
// Non un Scheduled Task: registrarne uno at-logon dà "Accesso negato" a un
// utente non elevato (la cartella task radice non e' scrivibile). La chiave
// HKCU Run e' sempre scrivibile dall'utente, nessuna elevazione, ed e' il modo
// standard per l'autostart di un'app desktop.

const (
	_runKeyPath   = `Software\Microsoft\Windows\CurrentVersion\Run`
	_runValueName = "OpenSagra"
)

func autostartEnabled() bool {
	k, err := registry.OpenKey(registry.CURRENT_USER, _runKeyPath, registry.QUERY_VALUE)
	if err != nil {
		return false
	}
	defer k.Close()
	_, _, err = k.GetStringValue(_runValueName)
	return err == nil
}

func setAutostart(enable bool) error {
	k, err := registry.OpenKey(registry.CURRENT_USER, _runKeyPath, registry.SET_VALUE)
	if err != nil {
		return err
	}
	defer k.Close()

	if !enable {
		if err := k.DeleteValue(_runValueName); err != nil && err != registry.ErrNotExist {
			return err
		}
		return nil
	}

	exe, err := os.Executable()
	if err != nil {
		return err
	}
	return k.SetStringValue(_runValueName, `"`+exe+`" -autostarted`)
}
