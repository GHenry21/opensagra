# Piano modifiche wrapper

> Doc di lavoro. Il wrapper (`wrapper/`) compila ed è in test cross-macchina
> (host = server, VM `opensagra-test` = client). Stato base e backlog "scaffold"
> in **`wrapper/README.md`**. Qui si tracciano le modifiche **decise o da
> valutare** emerse dai test reali del 2026-09-09/10.

---

## 1. Conteggio "N casse collegate" — segnale sbagliato ⏳ DA FARE

**Bloccato su:** l'altra sessione ha modifiche non committate in
`wrapper/cluster.go` / `wrapper/main.go` (difese snapshot + `watchDbHost`).
`activeClientCount` sta in `cluster.go`. Riprendere quando quei file sono
committati (opzione **B** scelta dall'utente 2026-09-10).

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

## 2. Istanza singola più tollerante ⏳ DA FARE

**Sintomo.** Il wrapper ha rifiutato l'avvio con *"un'altra istanza del wrapper
è già in esecuzione, esco."* pur **senza nessun processo wrapper vivo** —
capitato 2 volte (VM 2026-09-09 20:57, host 2026-09-10 00:58). Verificato subito
dopo: il mutex `Global\opensagra-wrapper` era **libero** (test .NET lo ricrea
nuovo). Era una corsa con la chiusura dell'istanza precedente ancora in corso
(quit sequence: `announceShutdown` fino a 4s + `sup.Wait()` + `job.close()` +
`systray.Quit()`), oppure un doppio avvio ravvicinato.

**Fix.** Su `ERROR_ALREADY_EXISTS` non uscire subito:

- scrivere all'avvio riuscito un lock file `<logdir>/wrapper.lock` con **PID** +
  **URL della finestra di stato**;
- su `ERROR_ALREADY_EXISTS`, leggere il lock file:
  - PID vivo → aprire quella finestra di stato (porta l'istanza esistente in
    primo piano) ed uscire — comportamento utile invece del silenzio;
  - PID morto / file assente → mutex stantìo: **procedere** (loggare l'anomalia).

**File:** `wrapper/main.go` (`acquireSingleInstance` + gestione),
`wrapper/platform_windows.go`.

---

## 3. "Ferma server" e MariaDB — DECISO: non lo controlla, solo status

**Domanda (utente, 2026-09-10):** il pulsante **"Ferma server"** (pausa
aggregata: ferma frankenphp + relay + bridge + snapshot, wrapper/tray vivi,
`announce shutdown` ai client) dovrebbe **fermare e riavviare anche MariaDB**?
Nota: MariaDB parte comunque come servizio all'avvio.

**Deciso (2026-09-10):**

- **"Ferma server" NON tocca MariaDB.** Resta = mettere offline *l'app*, non il
  database.
- Nei **"Dettagli avanzati"**: riga **read-only** `MariaDB: attivo (servizio) /
  fermo` (ping best-effort sulla 3306, cache). Se è giù, il riquadro grande dice
  *"Database fermo"* invece di lasciare frankenphp a sbattere con errori poco
  leggibili.
- **File:** `wrapper/status_http.go` (nuovo campo `mariadb_up` in `/api/status`,
  ping in un file nuovo tipo `wrapper/mariadb.go` — NON `cluster.go`),
  `wrapper/status_page.html`. Non tocca i file bloccati → **fattibile subito**.

**Analisi (per memoria).**

| | Contro il controllo di MariaDB dal wrapper |
|---|---|
| Privilegi | Il wrapper gira **non elevato** (è il punto del modello). `Stop-Service` / `sc stop` danno *"Accesso negato"* senza admin — stesso muro visto con `frankenphp`. Servirebbe che l'installer conceda esplicitamente allo user i diritti stop/start sul servizio via `sc sdset` (SDDL) → complessità + superficie in più. |
| Rischio | MariaDB è lo **stato durevole condiviso**. Un motore DB non è qualcosa da accendere/spegnere con un bottone di UI: invita errori. |
| Auto-guarigione | È `Automatic`: se resta "in pausa" e si riavvia il PC, riparte da solo. "Mi sono dimenticato di riavviarlo" si risolve al boot. |
| Riavvio | "Avvia server" dovrebbe far ripartire MariaDB **per prima**, aspettare che la 3306 accetti, **poi** riprendere i figli → step di readiness in più. |
| Manutenzione vera | Se serve davvero fermare MariaDB (backup a freddo, spostare il disco) è un'azione admin deliberata da `services.msc`, giustamente più attritosa. |

**Proposta (da confermare):**

- **"Ferma server" NON tocca MariaDB.** Resta ciò che è: mettere offline
  *l'app*, non spegnere il database.
- **Aggiungere però la visibilità read-only**: nei "Dettagli avanzati" una riga
  **`MariaDB: attivo (servizio)` / `fermo`** (query best-effort sulla 3306 o
  `Get-Service` — senza controlli). Se MariaDB è giù, il riquadro grande lo dice
  chiaro (*"Database fermo"*) invece di lasciare i figli frankenphp a sbattere
  con errori poco leggibili.

---

## 4. Tasto "Gestione DB" — AdminNeo su `/db` (localhost-only) — DECISO

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

**Deciso.**

- **Vendorizzare** il file singolo in **`tools/adminer.php`** nel repo (come
  `vendor/`): deterministico, si vede nei diff, si aggiorna sostituendolo.
- Build AdminNeo da configurare sulla pagina download: **driver = solo MySQL**,
  **lingue = it (+ en)**, **tema = uno pulito** (scelta sulla pagina). URL del
  configuratore nella forma
  `https://www.adminneo.org/files/<version>/<drivers>_<languages>_<themes>/adminneo-<version>.php`
  → **annotare la versione + l'URL esatto** in testa a `tools/adminer.php` così
  il bump è un download + replace.
- `install.ps1`: copia `tools/adminer.php` in `C:\opensagra\tools\` +
  `New-CaddyConfig` aggiunge, in **entrambi** i blocchi di sito, un route
  **solo-localhost**:
  ```
  handle_path /db {
      @l remote_ip 127.0.0.1 ::1
      handle @l { root * C:\opensagra\tools ; rewrite * /adminer.php ; php_server }
      respond 403
  }
  ```
  (forma da rifinire — l'importante è che da IP di LAN risponda 403, non serva
  la pagina).
- `includes/sidebar.php`: link "Gestione Database" → `/db` (era `/phpmyadmin`).
- **Tasto wrapper** nei "Dettagli avanzati": "Apri gestione DB" → apre
  `<AppURL>db` nel browser. Si **nasconde** se `/db` non risponde (check di
  raggiungibilità nello status, come per `pma`), così su installazioni senza il
  route non compare.

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
