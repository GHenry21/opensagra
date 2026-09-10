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
  pagina mostra "Apri gestione DB (AdminNeo)" solo se valorizzato; azione
  `open-db` → `openURL`.

---

## 5. Altro (aperto)

- Test **end-to-end del wrapper su un client Windows** — la VM ora ci gira
  (relay attivo, config client scritta a mano 2026-09-10); manca un giro
  completo: menu/click, uscita, `--kind=back`, snapshot in pausa/ripresa al
  cambio ruolo.
- Voci "scaffold" ancora aperte in `wrapper/README.md`: icona `.syso` +
  versione nell'exe, `--kind=back` anche dopo *"Riavvia tutto"*, firma `.exe`
  (SmartScreen), tray nativa macOS/Linux.
- `install.ps1` **non testato su VM pulita** per la parte wrapper
  (`Install-Wrapper` / `Start-Wrapper` de-elevato via task INTERACTIVE una-tantum).
- Distribuzione: il pacchetto di release deve **compilare** `wrapper/opensagra-wrapper.exe`
  (nessuna build in `install.ps1`, gira su macchine senza Go).
