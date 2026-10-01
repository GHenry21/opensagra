# opensagra-wrapper

Tray-app che supervisiona i processi di OpenSagra su una postazione. Sostituisce
i servizi Windows per FrankenPHP e i processi per-client (relay, bridge di
stampa). Deciso nel piano di migrazione, **sezione 3g** (revisione 2026-09-08).

> **Stato: compila e passa gli smoke test.** Go 1.27 + WinLibs mingw (per il
> cgo futuro della webview) installati 2026-09-09; `go vet` + `go build
> -ldflags "-H=windowsgui"` puliti al primo colpo. Verificato con binari fittizi:
> figlio che fallisce l'avvio → `[wrapper] avvio fallito` nel log + backoff
> `1→2→4→…→30s cap`; figlio `AlwaysRestart` (frankenphp) che esce `0` → riavvio
> a cadenza breve; figlio non-`AlwaysRestart` (relay/bridge) che esce `0` →
> **niente riavvio**, un solo banner, ricontrollo a 60s (il contratto exit-code
> chiave); Job Object + mutex istanza-singola ok, nessun panic.
> **Non ancora provato** con lo stack reale: menu tray e click, sequenza di
> uscita, `announce`, autostart, i tre processi veri in esecuzione.

> **Linux/macOS (2026-09-29):** il wrapper e' cross-OS per davvero, non piu'
> uno stub degradato - vedi "File" e "Finestra di stato / tray" piu' sotto.
> Verificato dal vivo su un Raspberry Pi (Debian/aarch64, `install.sh`): install
> da zero + rilanci idempotenti, systemd `--user` con `loginctl enable-linger`,
> HTTP/HTTPS/AdminNeo tutti funzionanti. macOS resta **non verificato su
> hardware reale** (nessun Mac disponibile) - `platform_darwin.go` e' scritto
> secondo la documentazione ufficiale di launchd/osascript, da trattare come
> beta finche' qualcuno non lo prova su un Mac vero.

## Cosa NON gestisce

- **MariaDB** — resta un servizio di sistema (Windows: installato dall'MSI;
  Linux: pacchetto apt `mariadb-server`). È lo stato condiviso: se questo PC fa
  da server, N casse dipendono dal suo DB, non si può legarne la vita a "c'è
  una finestra aperta".

## Processi supervisionati

| nome | comando | note |
|---|---|---|
| `frankenphp` | `frankenphp run --config <root>/Caddyfile` | il web server |
| `relay` | `frankenphp php-cli bin/opensagra-realtime-relay.php` | inoltro Mercure server→client, solo in modalità rete |
| `snapshot` | `frankenphp php-cli bin/opensagra-snapshot.php` | tiene aggiornato il DB locale del client per il fallback una-via (Fase 4 punto 4); in pausa se la macchina non è un client |
| `bridge` | `frankenphp php-cli bin/opensagra-print-bridge.php` | uno solo, se `PRINT_BRIDGE_CASSE` non è vuoto |

Il wrapper **non tocca JWT/segreti Mercure**: sono i processi PHP a leggersi
`config/variabili.env`. Del `.env` al wrapper interessa solo `PRINT_BRIDGE_CASSE`
(se avviare il bridge - un solo processo basta, punto-punto: il topic Mercure è
fisso, non serve più un id per cassa) e `DB_POS_*` (check "N casse collegate"
all'uscita).

## Contratto exit-code (il punto delicato)

`supervisor.go` decide il riavvio in base al codice di uscita:

- **`frankenphp`** → `AlwaysRestart`: qualsiasi uscita = crash, riavvio con
  backoff `1s → 2s → … → 30s` (reset a 1s se era su da > 60s).
- **`relay` / `bridge`** fanno `exit(0)` quando *non c'è niente da fare*:
  - relay: `DB_POS_HOST` è locale → non è un client;
  - bridge: `PRINT_BRIDGE_CASSE` vuoto, **oppure `MERCURE_JWT_SECRET` locale
    assente** in `variabili.env` (il bridge ascolta sempre sul proprio hub
    locale, punto-punto - non dipende più da `DB_POS_HOST`/`conf_rete`).
  `exit(0)` → **non martellare**: ricontrollo lento (`IdleRecheck`, 30–60 s).
  `exit != 0` → crash vero → backoff.

Il **Punto 4** (fallback locale una-via) vive tutto lato PHP/bridge: per il
wrapper un bridge in fallback è semplicemente "processo vivo". Nessun impatto.

## Finestra di stato / tray

- **Finestra di stato = pagina servita dal wrapper su `127.0.0.1:<porta>`**
  (`net/http` + HTML via `go:embed`), aperta in Edge/Chrome in modalità app
  (`--app=…`, `--user-data-dir` dedicato per la single-instance). Niente webview
  incorporata: `systray` + `webview_go` si contendono il message loop di
  Windows. Si apre da sola all'avvio manuale (non con `-autostarted`) e dal menu
  tray "Finestra di stato". Chiuderla (X) non tocca i processi — sono figli del
  processo Go, non della finestra.
  - API loopback-only: `GET /api/status`, `GET /api/logs/<nome>`,
    `POST /api/action?op=…` (`restart[-all]`, `pause[-all]`, `resume[-all]`,
    `open-app`, `open-logs`, `autostart-on/off`).
  - Pagina: vista semplice (badge stato, "Apri OpenSagra", "Ferma/Avvia server"
    aggregato, spunta autostart) + `<details>` "Dettagli avanzati" con controlli
    e log per singolo processo.
- **Esci**:
  - **Windows** — solo dal menu tray, con **conferma** (`MessageBox`).
  - **Linux/macOS** — niente tray (deciso, vedi `docs/PIANO-MODIFICHE-WRAPPER.md`
    §5): un arresto avviato dal sistema (`systemctl --user stop` / `launchctl
    unload`, un segnale `SIGTERM`/`SIGINT`/`SIGHUP`) esegue la stessa sequenza
    di uscita **senza chiedere conferma** (nessun servizio la chiede, nemmeno
    `Stop-Service` su Windows). La conferma resta solo sul flag **`-quit`**
    (`requestQuit` in `main.go`): pensato per un lanciatore desktop "Ferma
    OpenSagra", chiede con zenity/kdialog/osascript (fallback: prompt testuale
    su stdin) e poi manda `SIGTERM` all'istanza viva.
  Se questa macchina è il server e ha **connessioni client attive**
  (`SHOW PROCESSLIST` + connessioni TCP `ESTABLISHED` verso 80/443, host
  non-locali) → avviso rinforzato *"N casse collegate perderanno il
  database"*, su tutti gli OS.
- **"in pausa"** (`Supervisor`): un figlio in pausa è fermo e **non** viene
  riavviato finché non lo si riprende; il wrapper (e la tray, su Windows)
  restano vivi. È lo stato dietro il bottone "Ferma server".
- All'uscita, se server: `bin/opensagra-announce.php --kind=shutdown` (riusa il
  CLI PHP, niente firma JWT in Go) **prima** di fermare FrankenPHP; all'avvio,
  quando FrankenPHP è su, `--kind=back` (best-effort, qualche tentativo) per
  pulire il banner "server giù" sui client.
- **Avvia all'accensione**:
  - **Windows** — voce di menu con spunta → Scheduled Task at-logon per
    l'utente corrente (via PowerShell, nessuna elevazione richiesta).
  - **Linux** — unit `systemd --user` (`~/.config/systemd/user/
    opensagra-wrapper.service`), scritta e abilitata dal wrapper stesso
    (`-register-autostart`, usato da `install.sh`). Serve `loginctl
    enable-linger <utente>` (lo fa `install.sh`, non il wrapper) perché la unit
    riparta al boot senza un login grafico interattivo.
  - **macOS** — `LaunchAgent` per-utente (`~/Library/LaunchAgents/
    com.opensagra.wrapper.plist`), nessun `sudo` richiesto.
- **Anti-orfani** (un crash del wrapper non deve lasciare FrankenPHP a tenere
  la porta 80): **Windows** — Job Object con `KILL_ON_JOB_CLOSE`; **Linux** —
  `Setpgid` + `Pdeathsig(SIGTERM)` per-processo (il kernel lo garantisce da
  solo, niente oggetto da chiudere); **macOS** — solo `Setpgid` (Darwin non ha
  un equivalente di `Pdeathsig`: un crash duro, `kill -9`, può lasciare
  FrankenPHP orfano - limite noto e accettato).
- **Istanza singola**: **Windows** — named mutex `Global\opensagra-wrapper`;
  **Linux/macOS** — `flock` non bloccante su un file in `os.TempDir()` (si
  rilascia da solo se il processo muore, a differenza del mutex nominale).

## File

| file | contenuto |
|---|---|
| `main.go` | wiring: config → mutex → job object → supervisor → `systray.Run` |
| `config.go` | risoluzione root app / `frankenphp.exe` / logdir; lettura `.env` |
| `supervisor.go` | `Child` + loop Start→Wait→backoff/idle-recheck, pausa/resume, log per figlio (rotazione 5 MiB) |
| `children.go` | definizione dei figli + policy di riavvio |
| `tray.go` | menu, stato live (tick 1s), conferma uscita |
| `cluster.go` | `SHOW PROCESSLIST` + `announce --kind=shutdown`/`--kind=back` |
| `status_http.go` | server HTTP loopback della finestra di stato + apertura in browser app-mode |
| `status_page.html` | pagina della finestra di stato (embedded, vanilla JS) |
| `platform_windows.go` | job object, mutex, `MessageBoxW`, hide-window, autostart (registro), apri URL/cartella/finestra-app, `webClientIPs` via `netstat` |
| `platform_unix.go` | condiviso Linux+macOS (build tag `unix`): `flock` istanza-singola, `processAlive` via `Signal(0)`, fallback conferma-uscita testuale su stdin, `signalQuit` (`SIGTERM`) |
| `platform_linux.go` | `Setpgid`+`Pdeathsig`, conferma uscita via zenity/kdialog, autostart `systemd --user`, `webClientIPs` via `ss`/`/proc/net/tcp` |
| `platform_darwin.go` | `Setpgid`, conferma uscita via `osascript`, autostart `LaunchAgent`, `webClientIPs` via `lsof` — **beta non verificata su hardware reale** |
| `main_windows.go` | `runEventLoop`: tray + `systray.Run` |
| `main_unix.go` | `runEventLoop`: attesa di un segnale di arresto (nessuna tray) |
| `util.go` | parsing `.env`, autorilevamento path, `tailFile`, helper vari |

## Build

Windows:

```powershell
.\wrapper\build.ps1              # vet + build di produzione (-H=windowsgui)
.\wrapper\build.ps1 -Console      # idem ma con console, per debug (stdout visibile)
.\wrapper\build.ps1 -Tidy          # + go mod tidy prima
```

Equivalente manuale:

```sh
cd wrapper
go build -ldflags "-H=windowsgui" -o opensagra-wrapper.exe ./...
```

Linux/macOS (nessuno script `build.sh` ancora, solo build manuale - vale la
pena farne uno se questa build diventa frequente):

```sh
cd wrapper
GOOS=linux GOARCH=arm64 go build -o opensagra-wrapper ./...   # Raspberry Pi
GOOS=linux GOARCH=amd64 go build -o opensagra-wrapper ./...   # Linux desktop/server x86_64
GOOS=darwin GOARCH=arm64 go build -o opensagra-wrapper ./...  # Mac Apple Silicon
```

`go.mod`/`go.sum` sono già nel repo. Build puro-Go (nessun cgo, `CGO_ENABLED=0`
su Windows nello script - su Linux/macOS non serve nemmeno impostarlo, nessuna
dipendenza cgo in nessuno dei file per-OS): la finestra di stato è HTTP +
browser app-mode, non una webview incorporata. Stessi passi in CI su Windows:
`.github/workflows/build-wrapper.yml` (produce l'exe come artefatto scaricabile
dalla tab Actions, non pubblica/firma nulla) - nessun equivalente CI per
Linux/macOS ancora, il binario per il Pi di test è stato incrociato a mano da
una macchina Windows con `GOOS=linux GOARCH=arm64`.

`-H=windowsgui` → nessuna console per il wrapper (solo Windows: gli altri OS
non hanno questo concetto, i log vanno comunque su file). I log finiscono in
`<eseguibile>/logs/` (`wrapper.log` + un file per figlio), su tutti gli OS.

Override utili (identici su tutti gli OS, solo il separatore di path cambia):

```
opensagra-wrapper -root /home/utente/opensagra -frankenphp /home/utente/.frankenphp/frankenphp -logdir /var/log/opensagra
```

(equivalenti: env `OPENSAGRA_ROOT`, `FRANKENPHP_BIN`)

## Non ancora fatto (dopo lo scaffold)

- [x] Finestra di stato/log — pagina HTTP loopback + browser app-mode (`--app`),
      non webview incorporata. API status/logs/action, pausa aggregata, per-processo.
      **API verificata via curl; rendering pagina + finestra app-mode da provare a video.**
- [x] "Avvia all'accensione" — Scheduled Task at-logon (Windows), `systemd
      --user` (Linux), `LaunchAgent` (macOS)
- [x] Risorse exe (Windows): `.syso` generato da `build.ps1` via `goversioninfo` (pure Go,
      niente `windres`/MinGW) — **manifest** (Common-Controls v6 → TaskDialog,
      DPI permonitorv2), **icona** (Explorer/taskbar/Alt-Tab, la stessa
      `assets/opensagra.ico` della tray), **info versione** (proprietà file,
      dal tag git esatto — vedi `packaging/Get-ReleaseVersion.ps1`). `go build`
      include il `.syso` da solo, ma va rigenerato ad ogni build (non è più
      committato): usare sempre `build.ps1`, non `go build` direttamente per
      una release.
- [x] `--kind=back` anche dopo *Riavvia tutto* (finestra e menu tray) — non
      `--kind=shutdown` prima: un riavvio è breve, non vale allarmare le casse
- [x] Rotazione dei log dei figli — `<name>.log` → `<name>.log.1` oltre 5 MiB, controllata a ogni (ri)avvio del figlio
- [x] Cross-OS reale (2026-09-29): split in file per-OS + `install.sh` per
      Debian/Raspberry Pi OS, testato dal vivo su hardware. Vedi il callout in
      cima al file e `docs/PIANO-MODIFICHE-WRAPPER.md` §5.
- [ ] Firma dell'`.exe` (SmartScreen)
- [ ] macOS mai provato su hardware reale (nessun Mac disponibile) —
      `platform_darwin.go` è scritto a tavolino secondo la documentazione
      ufficiale, da trattare come beta
- [ ] `uninstall.sh` (equivalente Linux di `uninstall.ps1`)
- [ ] `frankenphp trust` per il certificato HTTPS locale su Linux (oggi il
      primo avvio mostra l'avviso del browser, come prima che `install.ps1`
      lo automatizzasse su Windows)
