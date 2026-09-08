//go:build windows

package main

import (
	"errors"
	"os/exec"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"
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

// --- istanza singola: named mutex ---

func acquireSingleInstance(name string) (release func(), ok bool) {
	n, err := windows.UTF16PtrFromString(`Global\` + name)
	if err != nil {
		return func() {}, true // in dubbio, non impedire l'avvio
	}
	h, err := windows.CreateMutex(nil, false, n)
	if h == 0 {
		return func() {}, true
	}
	if errors.Is(err, windows.ERROR_ALREADY_EXISTS) {
		windows.CloseHandle(h)
		return func() {}, false
	}
	return func() { windows.CloseHandle(h) }, true
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

// --- MessageBox di conferma uscita ---

var (
	_user32          = windows.NewLazySystemDLL("user32.dll")
	_procMessageBoxW = _user32.NewProc("MessageBoxW")
)

const (
	_MB_YESNO         = 0x00000004
	_MB_ICONWARNING   = 0x00000030
	_MB_DEFBUTTON2    = 0x00000100 // default = "No"
	_MB_SETFOREGROUND = 0x00010000
	_MB_TOPMOST       = 0x00040000
	_IDYES            = 6
)

func confirmQuit(title, body string) bool {
	t, _ := windows.UTF16PtrFromString(title)
	b, _ := windows.UTF16PtrFromString(body)
	ret, _, _ := _procMessageBoxW.Call(
		0,
		uintptr(unsafe.Pointer(b)),
		uintptr(unsafe.Pointer(t)),
		uintptr(_MB_YESNO|_MB_ICONWARNING|_MB_DEFBUTTON2|_MB_SETFOREGROUND|_MB_TOPMOST),
	)
	return ret == _IDYES
}

// --- apertura URL / cartella ---

func openURL(u string) {
	_ = exec.Command("rundll32", "url.dll,FileProtocolHandler", u).Start()
}

func revealPath(p string) {
	_ = exec.Command("explorer", p).Start()
}
