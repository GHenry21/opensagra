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

## Cosa NON gestisce

- **MariaDB** — resta un servizio Windows, installato dall'MSI. È lo stato
  condiviso: se questo PC fa da server, N casse dipendono dal suo DB, non si
  può legarne la vita a "c'è una finestra aperta".

## Processi supervisionati

| nome | comando | note |
|---|---|---|
| `frankenphp` | `frankenphp run --config <root>/Caddyfile` | il web server |
| `relay` | `frankenphp php-cli bin/opensagra-realtime-relay.php` | inoltro Mercure server→client, solo in modalità rete |
| `bridge:<cassa>` | `frankenphp php-cli bin/opensagra-print-bridge.php --cassa=<cassa>` | uno per id in `PRINT_BRIDGE_CASSE` |

Il wrapper **non tocca JWT/segreti Mercure**: sono i processi PHP a leggersi
`config/variabili.env`. Del `.env` al wrapper interessa solo `PRINT_BRIDGE_CASSE`
(quante istanze del bridge) e `DB_POS_*` (check "N casse collegate" all'uscita).

## Contratto exit-code (il punto delicato)

`supervisor.go` decide il riavvio in base al codice di uscita:

- **`frankenphp`** → `AlwaysRestart`: qualsiasi uscita = crash, riavvio con
  backoff `1s → 2s → … → 30s` (reset a 1s se era su da > 60s).
- **`relay` / `bridge`** fanno `exit(0)` quando *non c'è niente da fare*:
  - relay: `DB_POS_HOST` è locale → non è un client;
  - bridge: nessuna cassa, **oppure segreto Mercure non ancora sincronizzato da
    `conf_rete`**.
  `exit(0)` → **non martellare**: ricontrollo lento (`IdleRecheck`, 30–60 s), così
  quando `conf_rete` scrive il segreto nel `.env` il bridge riparte da solo.
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
- **Esci** solo dal menu tray, con **conferma** (`MessageBox`).
  Se questa macchina è il server e ha **connessioni client attive**
  (`SHOW PROCESSLIST`, host non-locali) → avviso rinforzato *"N casse collegate
  perderanno il database"*.
- **"in pausa"** (`Supervisor`): un figlio in pausa è fermo e **non** viene
  riavviato finché non lo si riprende; il wrapper e la tray restano vivi. È lo
  stato dietro il bottone "Ferma server".
- All'uscita, se server: `bin/opensagra-announce.php --kind=shutdown` (riusa il
  CLI PHP, niente firma JWT in Go) **prima** di fermare FrankenPHP; all'avvio,
  quando FrankenPHP è su, `--kind=back` (best-effort, qualche tentativo) per
  pulire il banner "server giù" sui client.
- **Avvia all'accensione**: voce di menu con spunta → Scheduled Task at-logon
  per l'utente corrente (via PowerShell, nessuna elevazione richiesta).
- **Job Object** con `KILL_ON_JOB_CLOSE`: un crash del wrapper non lascia
  `frankenphp.exe` orfano sulla porta 80.
- **Istanza singola**: named mutex `Global\opensagra-wrapper`.

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
| `platform_windows.go` | job object, mutex, `MessageBoxW`, hide-window, autostart, apri URL/cartella/finestra-app |
| `platform_other.go` | stub Linux/macOS (compila e gira degradato per lo sviluppo) |
| `util.go` | parsing `.env`, autorilevamento path, `tailFile`, helper vari |

## Build

```sh
cd wrapper
go build -ldflags "-H=windowsgui" -o opensagra-wrapper.exe ./...
```

`go.mod`/`go.sum` sono già nel repo. Build puro-Go (nessun cgo): la finestra di
stato è HTTP + browser app-mode, non una webview incorporata.

`-H=windowsgui` → nessuna console per il wrapper. I log finiscono in
`<eseguibile>/logs/` (`wrapper.log` + un file per figlio).

Override utili:

```
opensagra-wrapper.exe -root C:\opensagra -frankenphp C:\Users\me\.frankenphp\frankenphp.exe -logdir C:\ProgramData\opensagra\logs
```

(equivalenti: env `OPENSAGRA_ROOT`, `FRANKENPHP_BIN`)

## Non ancora fatto (dopo lo scaffold)

- [x] Finestra di stato/log — pagina HTTP loopback + browser app-mode (`--app`),
      non webview incorporata. API status/logs/action, pausa aggregata, per-processo.
      **API verificata via curl; rendering pagina + finestra app-mode da provare a video.**
- [x] "Avvia all'accensione" (Scheduled Task at-logon), spunta nel menu — Windows
- [x] Risorse exe: `.syso` (`rsrc.rc` + `opensagra.manifest` → `windres`) —
      **manifest** (Common-Controls v6 → TaskDialog, DPI permonitorv2), **icona**
      (Explorer/taskbar/Alt-Tab, la stessa `assets/opensagra.ico` della tray),
      **info versione** (proprietà file). `go build` include il `.syso` da solo.
- [x] `--kind=back` anche dopo *Riavvia tutto* (finestra e menu tray) — non
      `--kind=shutdown` prima: un riavvio è breve, non vale allarmare le casse
- [x] Rotazione dei log dei figli — `<name>.log` → `<name>.log.1` oltre 5 MiB, controllata a ogni (ri)avvio del figlio
- [ ] Firma dell'`.exe` (SmartScreen)
- [ ] macOS: `NSStatusItem` / Linux: fallback X-chiude se manca `StatusNotifierItem`
