package main

// Su Windows l'installer vero e' install.ps1 (opensagra-installer.exe): qui
// questo programma serve solo per lavorare sulla grafica con -demo.

func prepareSudo(st *state, srv *server) (env []string, cleanup func(), ok bool) {
	st.update(func(s *state) {
		s.Phase, s.Error = phaseError, "su Windows usa opensagra-installer.exe (qui solo -demo)"
	})
	return nil, func() {}, false
}
