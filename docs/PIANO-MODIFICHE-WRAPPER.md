# Piano modifiche wrapper

> Doc di lavoro. Il wrapper (`wrapper/`) compila ed è in test cross-macchina
> (host = server, VM `opensagra-test` = client). Stato base e backlog "scaffold"
> in **`wrapper/README.md`**. Qui si tracciano le modifiche **decise o da
> valutare** emerse dai test reali del 2026-09-09/10.

---

## 1. Conteggio "N casse collegate" — segnale sbagliato ✅ FATTO (2026-09-10)

`activeClientCount` in `cluster.go` ora unisce per-IP il campionamento
`SHOW PROCESSLIST` **e** gli IP remoti con una connessione TCP `ESTABLISHED`
verso la 80/443 (`webClientIPs`, da `netstat -an -p TCP`, esclusi loopback e
gli IP locali). Verificato: con la VM ferma su `billing.php` il conteggio
riporta 1 (prima 0).

**Problema.** Il conteggio usa `SHOW PROCESSLIST` sul MariaDB locale e conta gli
IP non-locali. Ma una cassa **client** ferma su `billing.php` col realtime
attivo **non tocca quasi mai** il DB del server:

- il suo `billing.php` parla con l'**hub Mercure locale del client**, non con
  quello del server;
- verso il server tiene aperta **una sola** connessione: quella del **relay**
  (SSE persistente);
- il polling DB del client rallenta a 60s quando l'SSE è sano, e ogni
  connessione dura ~20ms → il campionamento (4× in 1,5s) la becca ~1 volta su
  mille.

Risultato: il conteggio vede una cassa solo se sta facendo un checkout/poll in
quell'istante. L'utente su `billing.php` non compare mai.

**Fix deciso.** `activeClientCount` = **unione** di due sorgenti:

1. campionamento `SHOW PROCESSLIST` (com'è ora);
2. **connessioni TCP `ESTABLISHED` in ingresso su 80/443 da IP non-locali** —
   il relay di ogni client tiene una connessione SSE persistente verso
   `https://<server>/.well-known/mercure` (stabile da quando c'è
   `heartbeat 20s` nel Caddyfile del server), più eventuali tablet che
   navigano l'app del server direttamente. Una per macchina client.

**Come.** In Go, da `netstat -an -p TCP` (sempre presente su Windows,
`hideWindow`), parse delle righe `ESTABLISHED` con porta locale 80/443, IP
remoto distinto, esclusi loopback e gli IP di questa macchina
(`net.InterfaceAddrs()`). Best-effort: qualunque errore → si ignora quella
sorgente. Nessuna modifica all'app.

**File toccati (quando sbloccato):** `wrapper/cluster.go` (rifattorizzare
`activeClientCount` per unire per-IP le due sorgenti); i chiamanti
(`status_http.go`, `tray.go`) non cambiano.

---

## 2. Istanza singola più tollerante ✅ FATTO (2026-09-10)

**Sintomo.** Il wrapper ha rifiutato l'avvio con *"un'altra istanza del wrapper
è già in esecuzione, esco."* pur **senza nessun processo wrapper vivo** —
capitato 2 volte (VM 2026-09-09 20:57, host 2026-09-10 00:58). Era una corsa con
la chiusura dell'istanza precedente ancora in corso, o un doppio avvio
ravvicinato.

`wrapper/lock.go` (nuovo): all'avvio riuscito scrive `<logdir>/wrapper.lock`
= `PID\n<URL finestra di stato>`. `acquireSingleInstance` ora ritorna `fresh`
(= mutex creato da noi). Se `!fresh`, `main.go` legge il lock file:
`processAlive(pid)` vero → `openURL(statusURL)` (mostra l'istanza esistente) ed
esce; PID morto/assente → mutex stantìo, **prosegue** (log). `quit` +
`defer removeLock`. `processAlive` in `platform_windows.go`
(`OpenProcess` + `GetExitCodeProcess == 259`) e stub Unix (`Signal(0)`).
Verificato: secondo avvio → "un'altra istanza e' gia' viva - apro la sua
finestra".

---

## 3. "Ferma server" e MariaDB ✅ FATTO (2026-09-10): non lo controlla, solo status

**Domanda (utente):** "Ferma server" dovrebbe fermare/riavviare anche MariaDB?
(MariaDB parte comunque come servizio all'avvio.)

**Fatto.**

- **"Ferma server" NON tocca MariaDB** — mette offline *l'app*, non il DB.
- `wrapper/mariadb.go` (nuovo): `mariadbUp()` = ping read-only sulla 3306,
  cache 5s. Campo `mariadb_up` in `/api/status`.
- `status_page.html`: riga read-only **MariaDB: attivo (servizio) / fermo** nei
  Dettagli avanzati; se `!mariadb_up` il riquadro grande dice **"Database fermo"**
  (prima di `anyErr`/`avvio`).

**Analisi (perché non controllarlo).**

| | Contro il controllo di MariaDB dal wrapper |
|---|---|
| Privilegi | Il wrapper gira **non elevato** (è il punto del modello). `Stop-Service` / `sc stop` danno *"Accesso negato"* senza admin — stesso muro visto con `frankenphp`. Servirebbe che l'installer conceda esplicitamente allo user i diritti stop/start sul servizio via `sc sdset` (SDDL) → complessità + superficie in più. |
| Rischio | MariaDB è lo **stato durevole condiviso**. Un motore DB non è qualcosa da accendere/spegnere con un bottone di UI: invita errori. |
| Auto-guarigione | È `Automatic`: se resta "in pausa" e si riavvia il PC, riparte da solo. "Mi sono dimenticato di riavviarlo" si risolve al boot. |
| Riavvio | "Avvia server" dovrebbe far ripartire MariaDB **per prima**, aspettare che la 3306 accetti, **poi** riprendere i figli → step di readiness in più. |
| Manutenzione vera | Se serve davvero fermare MariaDB (backup a freddo, spostare il disco) è un'azione admin deliberata da `services.msc`, giustamente più attritosa. |

---

## 4. Tasto "Gestione DB" — AdminNeo su `/db` (localhost-only) ✅ FATTO (2026-09-10)

**Contesto.** La sidebar ha un link "Gestione Database" → `/phpmyadmin` che su
un'installazione pulita 404a (l'installer non instrada phpMyAdmin, e phpMyAdmin
non c'è). Serve uno strumento DB leggero, e un tasto nella finestra di stato del
wrapper.

**Valutazione (2026-09-10).**

- phpMyAdmin pieno: scartato — ~8–30 MB, `config.inc.php` da generare, componente
  terzo security-sensitive da tenere aggiornato (la migrazione ha *tolto* roba
  così, vedi QZ Tray).
- HeidiSQL: scartato — non è davvero "già spedito" (assente su questo PC anche
  con MariaDB da winget), Windows-only, UI non gradita.
- **AdminNeo** (fork Adminer, attivo, Vrána fra i contributori) **oppure Adminer
  classico 6.0.2** — entrambi mantenuti, single-file, PHP 8.5 ok, MySQL/MariaDB
  ok, licenza Apache-2.0/GPL-2. Scelto **AdminNeo** per il *build configuratore*
  (driver + lingue + tema scelti prima del download → un file su misura, solo
  MySQL, niente driver Mongo/Elastic che non servono) e i temi migliori.

**Fatto.**

- **`tools/adminer.php`** vendorizzato = **AdminNeo 5.7.1**, build
  `mysql_en.it_default` (384 KB). Provenienza + istruzioni bump in
  `tools/README.md`. `Copy-AppFiles` lo porta in `C:\opensagra\tools\` (la
  cartella `tools` non è in `ExcludeFromCopy`).
- `install.ps1` `New-CaddyConfig` `$dbRoute` (in entrambi i blocchi di sito),
  validato con `frankenphp adapt` — `handle` + matcher `path`/`remote_ip`
  (`handle_path` accetta un solo pattern, non "/db /db/*"):
  ```
  @dbpath path /db /db/*
  @dblocal { path /db /db/*; remote_ip 127.0.0.1 ::1 }
  handle @dblocal { rewrite * /adminer.php; root * <InstallPath>\tools; php_server }
  handle @dbpath  { respond "... solo dal PC server." 403 }
  ```
  Verificato live: `GET localhost/db` → 200 `<title>Login - AdminNeo</title>`;
  `GET <IP-LAN>/db` → 403.
- `includes/sidebar.php`: link "Gestione Database" ora `/db` (relativo,
  same-origin); rimosse `$_hEnv`/`$_hDbHost` diventate morte.
- **Wrapper**: `db_tool_url` in `/api/status` — `GET <AppURL>/db`, 2xx/3xx **e**
  corpo che contiene "adminneo" (un'app SPA risponde 200 anche senza route: il
  marker evita il falso positivo). Cache 30s, TLS-skip per `tls internal`. La
  sulla **riga MariaDB** compare un bottone-icona **⇗** (stile dei ⏹/⟳ dei
  processi) solo se `db_tool_url` è valorizzato; azione `open-db` → `openURL`.
  Niente stop/restart di MariaDB dal wrapper (vedi §3).

---

## 5. Pannello per server desktop macOS / Linux — ✅ FATTO (2026-09-29), analisi originale 2026-09-10 sotto

**Domanda:** un'installazione **indipendente / server** su un desktop Linux o
Mac, gestita da un utente che non usa il terminale — serve il wrapper? Se no,
come si avvia / ferma / si vede se relay-bridge vanno?

**Con solo systemd/launchd** (l'approccio "nativo"):

| | systemd/launchd puro | |
|---|---|---|
| avvio | automatico al boot/login | ✅ ok |
| **stop con un bottone** | no (`systemctl stop` / terminale) | ❌ scoperto |
| **stato di relay/bridge/…** | no (`systemctl status` / `journalctl`) | ❌ scoperto |

Per un utente non tecnico i due ❌ pesano. Ma la parte "pannello" del wrapper
— **`status_http.go` + `status_page.html`** (server HTTP locale + finestra
browser con stato dei figli, Ferma/Avvia, log) — **è già Go puro, cross-OS**.
Windows-only sono solo: tray, dialogo Esci (TaskDialog), kill-anti-orfani
(Job Object), autostart (chiave Run).

**Percorso, se servirà davvero:**

- il wrapper gira come **servizio `systemd --user`** (Linux) / **LaunchAgent**
  (macOS) → dà supervisione + avvio al login, al posto di Job Object + Run key
- **niente tray**: un'icona sul desktop (`.desktop` / mini `.app`) apre la
  **finestra del pannello** (la stessa di Windows)
- Linux-specifici da scrivere: `flock` per l'istanza singola,
  `zenity`/`kdialog` per la conferma Esci, kill del **process-group** per gli
  orfani, un `install.sh` che scrive l'unità + il `.desktop`. macOS: `launchd`
  plist + `osascript` per la conferma + un `.app`/`.command`.
- stima ~1 gg Linux (grosso = riuso del pannello esistente), simile macOS.

**Decisione originale (2026-09-10): non farlo ora.** Nessun server desktop
macOS/Linux era nei piani (server = Windows col wrapper; Pi = client headless
con systemd, nessuna interazione operatore).

**Aggiornamento 2026-09-29: decisione ribaltata, fatto.** Il percorso
tratteggiato sopra è esattamente quello implementato:

- Wrapper diviso in file per-OS (`main_unix.go`/`main_windows.go`,
  `platform_linux.go`/`platform_darwin.go`/`platform_unix.go` al posto dello
  stub `platform_other.go`) - `flock` per l'istanza singola, zenity/kdialog/
  osascript per la conferma Esci (fallback stdin), `Setpgid`+`Pdeathsig`
  (Linux) o solo `Setpgid` (macOS, nessun equivalente di Pdeathsig - limite
  noto) per gli orfani, autostart `systemd --user`/`LaunchAgent`. Nuovo flag
  `-quit` per fermare un'istanza viva da un lanciatore desktop.
- **`install.sh`** (Debian/Raspberry Pi OS): equivalente di `install.ps1` -
  FrankenPHP (binario statico ufficiale, non pacchettizzato da Debian),
  `setcap cap_net_bind_service` (il wrapper gira sempre non-root, serve
  esplicitamente per legarsi alle porte 80/443), MariaDB via apt, file app,
  Caddyfile, provisioning DB (utente di bootstrap temporaneo via `sudo
  mariadb`, perché l'utente `root@localhost` di un mariadb-server apt usa
  `unix_socket`, non una password TCP come su XAMPP/Windows), migrazioni,
  wrapper + `systemd --user` + `.desktop`.
- **Testato dal vivo** sul Pi 192.168.88.32 (ripulito dal vecchio setup
  manuale a servizi systemd separati per frankenphp/relay/snapshot, sostituito
  da questo modello a processi-figli del wrapper): install da zero + 3
  rilanci idempotenti, `loginctl enable-linger` per la persistenza al boot,
  HTTP/HTTPS/AdminNeo (`/db`, bloccato da LAN) tutti verificati. Ancora
  attivo e stabile a distanza di giorni (uptime senza riavvii del wrapper).
- **macOS**: vedi l'aggiornamento 2026-10-01 qui sotto.
- ~~Resta da fare: `uninstall.sh`, `frankenphp trust` per il certificato
  HTTPS locale su Linux~~ - fatti entrambi (commit `3678cbb`, `6747ecd`).

**Aggiornamento 2026-10-01: macOS testato su Mac reale (runner GitHub).**

Nessun Mac fisico disponibile; scartate la VM VMware locale (macOS ospite
richiede di spegnere Hyper-V/VBS sul PC di sviluppo, e la licenza Apple
consente la virtualizzazione solo su hardware Apple) e il noleggio Scaleway
(verifica d'identità troppo onerosa, minimo 24h). Soluzione adottata: i
**runner macOS di GitHub Actions** - il repo è pubblico, quindi sono gratuiti e
senza limiti di minuti. Runner `macos-15`, Apple Silicon M1, macOS 15.7.

- **`install-macos.sh` / `uninstall-macos.sh`**: script separati da
  `install.sh`, perché su macOS cambia quasi ogni passo: Homebrew per MariaDB
  (`brew services`, utente admin via `unix_socket` dell'utente macOS, niente
  sudo), binario FrankenPHP `frankenphp-mac-*`, nessun `setcap` (da macOS 10.14
  le porte <1024 sono libere per i non-root), rimozione dell'attributo di
  quarantena di Gatekeeper, autorizzazione nel firewall applicativo se acceso,
  CA locale nel portachiavi di Sistema (`frankenphp trust`), mini-app
  `~/Applications/OpenSagra.app` con alias sulla Scrivania. bash 3.2 e
  strumenti BSD (niente `grep -P`, `sed -i` GNU, `ip`, `timeout`).
- **`.github/workflows/test-macos.yml`** (ogni push al branch): installa,
  verifica (LaunchAgent attivo, HTTPS anche *senza* `-k`, DB, app, versione),
  `kill -9` del wrapper → deve ripartire con **un solo** frankenphp figlio del
  nuovo wrapper, verifica `PHPRC`, rilancio idempotente, disinstallazione
  completa con backup del DB. **Verde.**
- Sessioni interattive, attivate da una parola chiave nel messaggio di
  commit: `[debug-mac]` apre VS Code nel browser (secret `MAC_DEBUG_TOKEN`;
  l'URL stampato contiene `tkn=root`, da *sostituire* col token);
  `[desktop-mac]` apre il desktop macOS nel browser (noVNC + tunnel Cloudflare
  quick, login utente `opensagra` + secret `MAC_VNC_PASSWORD`). Per il desktop
  servono un utente admin dedicato (la password di `runner` non è modificabile
  su macOS 15, nemmeno da root o con `sysadminctl` + admin) e il permesso TCC
  "Registrazione schermo" scritto nel `TCC.db` (SIP è spento sui runner):
  senza, schermo nero e disconnessione dopo ~10 s. Attenzione: noVNC mostra la
  sessione di `opensagra`, mentre l'app è installata per `runner`. Le prove a
  video su `runner` si fanno via SSH (tunnel `cloudflared` verso la porta 22)
  con `sudo launchctl asuser <uid> … screencapture` e clic simulati via System
  Events. Gli appunti di noVNC non funzionano con il server VNC di Apple.

**Difetti trovati dal vivo e corretti:**

1. **FrankenPHP orfano dopo un crash del wrapper.** Senza Pdeathsig, dopo un
   `kill -9` il frankenphp figlio restava vivo (padre = launchd) tenendo
   `mercure.db` bloccato, e il frankenphp del wrapper riavviato falliva in
   loop. Il primo test "crash → riparte" passava per un **falso positivo**
   (il sito rispondeva, servito dall'orfano). Ora il wrapper annota i PID dei
   figli in `logs/children.pids` e all'avvio chiude i superstiti, solo se
   eseguono ancora lo stesso binario (`reapStaleChildren`). Vale anche per
   Linux; su Windows non fa nulla (c'è il Job Object).
2. **`php.ini` non letto.** La build statica di FrankenPHP per macOS non
   cerca il file accanto al binario (`upload_max_filesize` restava 2M). Ora
   `PHPRC` viene passato ai figli (`childEnv`) e ai `php-cli` dell'installer,
   che fallisce se il file non viene letto.
3. **LaunchAgent in loop.** `KeepAlive=true` avrebbe riavviato anche
   un'uscita pulita, compreso "Esci". Ora `KeepAlive/SuccessfulExit=false`,
   come `Restart=on-failure` di systemd. `bootstrap`/`enable` al posto di
   `load`/`unload` (deprecati), e mai `bootout` da `setAutostart`, che
   chiuderebbe il wrapper stesso.
4. **Doppio clic dopo "Esci" fuori da launchd.** Il lanciatore dell'app ora
   fa `launchctl kickstart` del LaunchAgent e poi apre solo la finestra di
   stato.
5. **"Welcome to Google Chrome" sopra la finestra di stato** al primo avvio
   (profilo dedicato): aggiunti `--no-first-run --no-default-browser-check`
   anche su macOS/Linux (Windows li aveva già). Inoltre la pagina di stato è
   marcata `notranslate`, contro il popup "Traduci" di un Chrome in altra
   lingua.

**Verificati ok senza modifiche:** alias sulla Scrivania; doppio clic con
istanza viva → finestra di stato (Chrome in modalità app); finestra di stato
("Server attivo", pulsanti, "Avvia all'accensione" spuntato); dialogo Esci
nativo (`osascript`): Annulla lascia OpenSagra attivo, Esci lo ferma con
codice 0 e launchd non lo riavvia; certificato HTTPS fidato dal sistema.

**Resta da fare (macOS):**

- prove su un **Mac fisico** per quello che il runner non copre: stampante
  ESC/POS e telefoni in LAN, login automatico + avvio al boot, Safari da un
  altro dispositivo, Mac Intel (il wrapper `darwin/amd64` compila ma non è mai
  stato eseguito);
- **pacchetto di release** per macOS (archivio con app, `vendor/` e i due
  wrapper darwin) generato da `release.yml`;
- **icona** dell'app `.app` (oggi generica);
- unione del branch in `main`: **bloccata lato Windows**. Il branch porta
  anche la migrazione di Mercure al modern mode (codice condiviso:
  `config/mercure.php`, Caddyfile di `install.ps1`), e a oggi la build "latest"
  di FrankenPHP per Windows **ignora** `issuer`/`resource_identifier` (vedi
  `PIANO-MIGRAZIONE-FRANKENPHP.md`, Fase 4, "Migrazione a Mercure modern
  mode", punto *Aperto*). Prima dell'unione servono: una build Windows che li
  capisca, poi un test su VM sia dell'installazione da zero sia
  dell'**aggiornamento leggero** sopra un'installazione esistente (vecchio
  Caddyfile e vecchio FrankenPHP con il nuovo `mercure.php`: il realtime
  rischia di rompersi, se serve l'aggiornamento dovrà rigenerare il Caddyfile).
  `main` è già stato unito nel branch (commit `cef6d5c`, nessun conflitto
  reale), quindi l'unione inversa resterà semplice.

**Stato CI a fine giornata (2026-10-01):** `test-macos.yml` verde su tutti i
commit del branch, compresi `8c15d40` (lanciatore + finestra di stato) e
`112b72d` (questo aggiornamento del piano).

---

## 6. Altro (aperto)

- Test **end-to-end del wrapper su un client Windows** — la VM ora ci gira
  (relay attivo, config client scritta a mano 2026-09-10); manca un giro
  completo: menu/click, uscita, `--kind=back`, snapshot in pausa/ripresa al
  cambio ruolo.
- Risorse exe **fatte**: `.syso` con manifest (`63fc9b9`) + **icona exe** e
  **info versione** (`e9b46cd`). `--kind=back` dopo *"Riavvia tutto"* **fatto**.
- **Firma `.exe` (SmartScreen): decisa NO** (2026-09-10). Costo non giustificato
  per poche installazioni note. Si documenta lo *"Esegui comunque"*. Se un
  domani la distribuzione si allarga → Azure Trusted Signing (~€10/mese,
  attivabile a lotti: firma con timestamp = valida per sempre anche dopo la
  disdetta).
- `install.ps1` **non testato su VM pulita** per la parte wrapper
  (`Install-Wrapper` / `Start-Wrapper` de-elevato via task INTERACTIVE una-tantum).
- **Compilazione per la release ✅ FATTO (2026-09-11):** `wrapper/build.ps1`
  (vet + `go build -H=windowsgui`, `CGO_ENABLED=0`, girato per davvero: exe
  12 MB, versione letta correttamente) + `.github/workflows/build-wrapper.yml`
  equivalente in CI (stessi passi, artefatto scaricabile) — **inerte finché il
  repo non ha un remote GitHub**, pronto per quando ci sarà. `install.ps1`
  resta senza build: si aspetta l'exe già pronto (gira su macchine senza Go).
