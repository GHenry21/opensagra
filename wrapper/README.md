# opensagra-wrapper

Tray-app che supervisiona i processi di OpenSagra su una postazione. Sostituisce
i servizi Windows per FrankenPHP e i processi per-client (relay, bridge di
stampa). Deciso nel piano di migrazione, **sezione 3g** (revisione 2026-09-08).

> **Stato: compila e passa uno smoke test.** Go 1.27 + WinLibs mingw (per il
> cgo futuro della webview) installati 2026-09-09; `go vet` + `go build
> -ldflags "-H=windowsgui"` puliti. Smoke test con root fasullo verificato:
> supervisore che parte, log per figlio, riavvio con backoff 1→2→…→30s sui
> figli che falliscono l'avvio, Job Object + mutex istanza-singola ok, nessun
> panic. **Non ancora provato** end-to-end con lo stack reale (frankenphp/relay/
> bridge veri, menu tray, sequenza di uscita, announce, autostart).

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

## Comportamento finestra / tray

- **X = vai in tray** (la finestra di stato arriverà dopo — vedi *Non ancora fatto*).
- **Esci** solo dal menu tray, con **conferma** (`MessageBox`).
  Se questa macchina è il server e ha **connessioni client attive**
  (`SHOW PROCESSLIST`, host non-locali) → avviso rinforzato *"N casse collegate
  perderanno il database"*.
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
| `supervisor.go` | `Child` + loop Start→Wait→backoff/idle-recheck, log per figlio |
| `children.go` | definizione dei 3 tipi di figlio + policy di riavvio |
| `tray.go` | menu, stato live (tick 1s), conferma uscita |
| `cluster.go` | `SHOW PROCESSLIST` + `announce --kind=shutdown`/`--kind=back` |
| `platform_windows.go` | job object, mutex, `MessageBoxW`, hide-window, apri URL/cartella |
| `platform_other.go` | stub Linux/macOS (compila e gira degradato per lo sviluppo) |
| `util.go` | parsing `.env`, autorilevamento path, helper vari |

## Build

```sh
cd wrapper
go build -ldflags "-H=windowsgui" -o opensagra-wrapper.exe ./...
```

`go.mod`/`go.sum` sono già nel repo. La webview (quando arriva) userà cgo →
serve un gcc sul PATH: `winget install BrechtSanders.WinLibs.POSIX.UCRT`.

`-H=windowsgui` → nessuna console per il wrapper. I log finiscono in
`<eseguibile>/logs/` (`wrapper.log` + un file per figlio).

Override utili:

```
opensagra-wrapper.exe -root C:\opensagra -frankenphp C:\Users\me\.frankenphp\frankenphp.exe -logdir C:\ProgramData\opensagra\logs
```

(equivalenti: env `OPENSAGRA_ROOT`, `FRANKENPHP_BIN`)

## Non ancora fatto (dopo lo scaffold)

- [ ] Finestra di stato/log con `webview` — backend nativo per OS: WebView2
      (Edge/Chromium, già su Win11) / WebKitGTK su Linux / WKWebView su macOS
- [x] "Avvia all'accensione" (Scheduled Task at-logon), spunta nel menu — Windows
- [ ] Icona `.ico` (vedi `assets/README.md`) + `.syso` con manifest/versione
- [ ] `--kind=back` anche dopo un *Riavvia tutto* (ora solo all'avvio del wrapper)
- [ ] Rotazione dei log dei figli (ora append infinito)
- [ ] Firma dell'`.exe` (SmartScreen)
- [ ] macOS: `NSStatusItem` / Linux: fallback X-chiude se manca `StatusNotifierItem`
