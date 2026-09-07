# Piano di migrazione — polling condizionale + XAMPP → FrankenPHP

> Documento di lavoro. Ogni fase ha una checklist e un **criterio di accettazione**.
> Ordine consigliato: **Fase 0 → 1 → 2 → 3**. Fase 4 solo quando serve.

---

## Contesto e decisioni prese

| Tema | Decisione |
|---|---|
| **Polling prodotti in `billing.php`** | Strategia A — polling *condizionale*: un endpoint leggerissimo dice "è cambiato qualcosa?", la lista completa si scarica solo sul cambio. Niente SSE per ora. |
| **Bug indipendente da tutto** | `api/get_products.php` esegue `ALTER TABLE` / `UPDATE` a ogni richiesta → va in una migrazione una-tantum. |
| **Infrastruttura** | Da XAMPP → **FrankenPHP in _classic mode_ + MariaDB nativa**, distribuiti con uno script d'installazione per-OS. **Niente Docker** (accesso stampante USB). **Niente worker mode** per ora (nessun refactoring, non serve alla scala attuale). |
| **Packaging / distribuzione** | **Nessun binario embed.** Lo script d'installazione **copia i file** di opensagra nelle cartelle di destinazione. Il codice sorgente resta aperto e ispezionabile (progetto open source). `vendor/` incluso nel pacchetto di release (niente `composer install` sul target). |
| **Cosa varia in base alle risposte** | Il codice di opensagra è **sempre spedito completo e identico**. Il wizard automatizza solo due passi oggi manuali: (1) generare `config/variabili.env` dalle risposte, (2) creare DB/tabelle/utente. Vedi **Fase 3a**. |
| **HTTPS** | **Aggiornato dopo i test reali (2026-09-06): HTTP e HTTPS coesistono stabilmente, non è un aut-aut.** Il problema di mixed-content su Firefox/WebKit riguarda **solo** il metodo di stampa `bridge_qz` (un QZ Tray condiviso raggiunto via IP di LAN); la stampa diretta (`WIN_USB`/`LINUX_USB`/`RETE`, gestita interamente dal server PHP senza WebSocket lato browser) **funziona identica su HTTPS**, verificato con test reale. Regola pratica per la Fase 3: cassa con stampante diretta → HTTPS ok; cassa che usa un bridge QZ condiviso → HTTP. Il bridge condiviso è il caso meno comune, quindi un avviso mirato nella guida/wizard basta, senza sacrificare HTTP/2/3 e il lucchetto per tutti gli altri. Dettagli in **Appendice C**. |
| **Realtime (Mercure/SSE)** | Rimandato. L'hub è già dentro il binario FrankenPHP: si attiva quando/se serve (Fase 4). |
| **Worker mode** | Ottimizzazione futura opzionale. Scope e stima in Appendice B. |
| **QZ Tray** | Stampa da stampanti USB via browser. La procedura certificati/firma attuale (openssl + override + `sign-message.php`) **resta invariata** in questa migrazione. Con Caddy/HTTPS vanno però verificati alcuni punti di mixed-content: vedi **Appendice C**. Nessuna modifica al codice QZ ora. |
| **Wizard d'installazione** | Lo script pone domande in linguaggio semplice (architettura, QZ, HTTPS) e configura di conseguenza: vedi **Fase 3a**. ⚠️ Le domande attuali sono solo una bozza, da riformulare al momento della Fase 3. |
| **Versionamento** | Ogni modifica va committata su git (repo locale, branch `main`). |

### Perché FrankenPHP classic + MariaDB nativa (oltre a HTTPS automatico)

- HTTP/2 + HTTP/3 (QUIC): meno stalli su wifi da sagra — **solo sulle casse in HTTPS** (richiedono TLS, nessun browser li fa girare in chiaro). Le casse che stampano tramite bridge QZ condiviso restano su HTTP/1.1 (vedi riga HTTPS sotto e Appendice C) — non è una rinuncia per tutta l'app, solo per quel sottoinsieme di postazioni.
- Un binario + un `Caddyfile` (~10 righe) invece di Apache (httpd.conf, moduli, vhost, `.htaccess`) + PHP separato.
- Parità cross-OS reale: stesso binario e stesso Caddyfile su Windows/Mac/Linux.
- Static file serviti dal layer Go (non passano dall'interprete PHP).
- Compressione (gzip/zstd/brotli), redirect HTTP→HTTPS, HSTS, TLS moderno: tutto default.
- Log strutturati JSON + metriche Prometheus out of the box.
- `frankenphp reload`: modifiche di config senza downtime.
- Mercure già nel binario per il realtime futuro.
- MariaDB nativa: versione e aggiornamenti controllati, parte come servizio di sistema, config versionabile.

### Capacità stimata (per dimensionamento)

Classic mode usa un **pool di thread** (`num_threads`), non un processo per richiesta. Un thread è occupato solo mentre una richiesta PHP è in esecuzione.

- Carico realistico per cassa: ~0,4 richieste/s (1 poll ogni ~5–8 s + checkout sporadici).
- `thread necessari ≈ richieste/s × durata media richiesta` ≈ `0,4 × 0,02 s` ≈ **0,008 thread per cassa**.
- Colli di bottiglia reali, in ordine: il DDL a ogni poll (Fase 0 lo elimina) → connessioni MariaDB (`max_connections` default 151) → RAM per richiesta (8–32 MB × richieste concorrenti).

Su hardware datato (4 core ~2013, 4–8 GB RAM), dopo la Fase 0: **50–100+ casse comodamente**; senza Fase 1, solo con Fase 0: **30–50 casse**. Una sagra reale ha 3–15 casse → 1–2 ordini di grandezza di margine. Il limite pratico è il wifi della LAN, non il server.

---

## Fase 0 — Fix DB (indipendente, da fare per prima) ✅ COMPLETATA (2026-09-05)

Vale qualunque sia il web server. Sblocca il collo di bottiglia attuale.

- [x] Creata `config/migrations/001_stock_updated_at.php`, eseguita **una sola volta** (idempotente: verifica via `information_schema` prima di ogni passo, rilanciabile senza effetti):
  - [x] `ALTER TABLE stock ADD COLUMN IF NOT EXISTS quantity_available INT NULL DEFAULT NULL AFTER price;`
  - [x] `ALTER TABLE stock ADD COLUMN IF NOT EXISTS item_sort INT NULL AFTER image_path;`
  - [x] `UPDATE stock SET item_sort = id WHERE item_sort IS NULL;`
  - [x] `ALTER TABLE stock ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp();`
  - [x] backfill: `UPDATE stock SET updated_at = COALESCE(created_at, NOW());`
  - [x] indice per il polling: `ALTER TABLE stock ADD INDEX idx_stock_updated_at (updated_at);`
- [x] Rimosse le righe 6–8 di `api/get_products.php` (le tre query DDL/UPDATE).
- [x] Aggiornato `config/pos.sql` con la nuova colonna `updated_at` e l'indice (schema per installazioni nuove).
- [x] Verificato che l'`UPDATE` su una riga di `stock` aggiorna da solo `updated_at` grazie a `ON UPDATE` (testato direttamente sul DB).

**Verifiche eseguite:**
- `get_products.php` via Apache/XAMPP → HTTP 200, JSON identico a prima.
- Migrazione rilanciata due volte: la seconda salta tutti i passi già applicati (nessun errore, nessuna riscrittura di `updated_at`).
- `SELECT MAX(updated_at)` + `SHOW INDEX` confermano colonna e indice presenti; un `UPDATE` di prova su un prodotto sposta `updated_at` da `2025-07-14` a "adesso".

**Nota:** `config/pos.sql` ha altre modifiche non correlate già in corso (non committate qui, lasciate a chi sta lavorando su quel file da VSCode Source Control).

**Accettazione:** `api/get_products.php` risponde identico a prima; nel general query log di MariaDB non compaiono più `ALTER TABLE` a ogni chiamata; `SELECT MAX(updated_at) FROM stock` cambia solo dopo una modifica prodotto. **→ Verificato.**

---

## Fase 1 — Polling condizionale (Strategia A) in `billing.php` ✅ COMPLETATA (2026-09-05)

### 1a. Endpoint "versione"

- [x] Nuovo `api/products_version.php`:
  - `SELECT COALESCE(UNIX_TIMESTAMP(MAX(updated_at)), 0) AS version, COUNT(*) AS count FROM stock WHERE is_active = 1;`
  - risposta: `{ "version": <int>, "count": <int> }` — nessun payload prodotti.
  - `Content-Type: application/json`; `Cache-Control: no-store`.

### 1b. Refactor del loop in `billing.php`

- [x] Sostituito il `setInterval` con un loop auto-pianificante (`setTimeout` ricorsivo via `scheduleProductsPoll`): non parte mai un nuovo giro finché il precedente non è concluso, niente richieste accodate su rete lenta.
- [x] `pollProductsVersion()`: chiama `products_version.php`, confronta `version` **e** `count` con `_lastProductsVersion`/`_lastProductsCount`, chiama `loadProducts()` solo se differiscono (o al primo giro, se `null`).
- [x] `initProductsPolling()` (nuovo, non previsto nella bozza originale): all'avvio legge subito la versione "di partenza" senza rifare un `loadProducts()` ridondante (quello iniziale in `mounted()` resta separato), poi avvia il loop — così fin dal primo giro il regime è quello "a riposo" descritto nell'accettazione.
- [x] Intervallo normale: `PRODUCTS_POLL_NORMAL_MS = 6000`.
- [x] **Guardia "richiesta in corso"**: `_pollInFlight`.
- [x] **Pausa a tab nascosto**: listener `visibilitychange` in `mounted()`; a `hidden` il tick esce subito; a `visible` annulla il timer in attesa e ricontrolla subito.
- [x] **Backoff su errore**: `5s → 10s → 20s → 40s → 60s` (raddoppio, cap 60s); reset a `null` al primo successo.
- [x] `mergeProducts()` (nuovo metodo): aggiorna in place (via `Object.assign`) i prodotti già presenti e sostituisce l'array con `splice` invece di riassegnarlo — Vue non ricrea gli oggetti prodotto quando non sono cambiati.
- [x] Inizializzazione di `categoryFilterMode`/`selectedCustomCategories`/`saveCategoryPreference()` spostata dietro il flag `_categoriesInitialized`: gira una sola volta. L'elenco `allProductCategories` invece si ricalcola ad ogni caricamento (una categoria nuova deve comparire subito).
- [x] `beforeUnmount` pulisce anche `_productsPollTimer` e il listener `visibilitychange`.

**Verifiche eseguite:**
- `products_version.php` via Apache → risponde `{"version":...,"count":29}`; rilanciato subito dopo → stessi valori (nessuna modifica nel frattempo).
- `UPDATE` che **non cambia realmente il valore** (es. `quantity_available = quantity_available`) → `updated_at` **non** si aggiorna (comportamento nativo di MariaDB: una riga "invariata" non viene riscritta, quindi `ON UPDATE` non scatta). Un `UPDATE` con un valore effettivamente diverso invece fa avanzare `version` immediatamente. Da tenere a mente: un "salva" che riscrive gli stessi valori non farà scattare il polling — non è un problema per l'uso reale (le modifiche prodotto cambiano sempre qualcosa), ma spiega perché un test superficiale può sembrare "non funzionare".
- Sintassi JS del file estratta e validata con `node --check` (nessun errore).

**Accettazione — ✅ verificata end-to-end in browser reale (Chromium via Playwright, 2026-09-05):**
- Con prodotti fermi: in 27 s di osservazione, un solo `get_products.php` (carico iniziale) + `products_version.php` ogni ~6 s (t=0.3, 6.4, 12.4, 18.4, 24.4) — nessuna ripetizione di `get_products.php`. **Verificato.**
- Cambio filtro categoria (`Tutte → Solo Cucina`): il valore resta `cucina` anche dopo 16 s di polling in background (3 cicli) — il flag `_categoriesInitialized` funziona, il polling non sovrascrive la scelta dell'utente. **Verificato.**
- Tab nascosta (`document.visibilityState = 'hidden'` + evento `visibilitychange`) per 15 s: **zero richieste** — la pausa funziona. **Verificato.**
- Ritorno a `visible`: entro 3 s parte subito una `products_version.php` (non si aspetta il prossimo giro schedulato) — il listener funziona. **Verificato.**
- Nessun errore in console durante l'intero test.
- Screenshot della pagina: UI renderizzata correttamente con prodotti/prezzi/categorie reali dal DB (non una schermata vuota).
- Non ripetuto in questa sessione (richiede una modifica prodotto in concomitanza, già verificato a parte in Fase 0/1a a livello di endpoint): "modificando un prodotto da un'altra postazione, la griglia si aggiorna entro un ciclo" — il meccanismo (version bump → `loadProducts()`) è lo stesso già testato, non ripetuto qui per ridondanza.

---

## Fase 2 — FrankenPHP classic mode sul PC di sviluppo ✅ COMPLETATA (2026-09-06)

Obiettivo: far girare l'app identica a XAMPP, su FrankenPHP + MariaDB, come servizio senza terminale aperto, prima di toccare la produzione. Raggiunto — con la precisazione emersa durante il percorso che il target reale è `http://localhost:8080` (non HTTPS, vedi Appendice C e la nota in 2e).

### 2a. Prerequisiti

- [x] `frankenphp version` risponde — v1.12.7, PHP 8.5.10, Caddy v2.11.4 (installato via `irm https://frankenphp.dev/install.ps1 | iex` in `C:\Users\enrig\.frankenphp`, aggiunto al PATH utente).
- **Correzione (2026-09-06)**: la riga precedente parlava di una "MariaDB nativa" installata separatamente — non era così *a quella data*. Poi è stata davvero messa in piedi ed è ora quella in uso — vedi il nuovo cutover qui sotto.
- [x] DB `opensagra_pos` presente e popolato — **ora sulla MariaDB nativa** (cutover completato 2026-09-07, vedi sotto). `config/variabili.env` non ha richiesto nessuna modifica.

## Cutover manuale (2026-09-07): XAMPP fermato, MariaDB nativa in produzione ✅

Prima di questo passo: verificato che il backup manuale (`opensagra_pos.sql` da phpMyAdmin, sul Desktop) fosse identico al DB live **byte per byte** (checksum MD5 sull'intera tabella `stock`, confronto riga per riga sull'ultima vendita) — e che `config/pos.sql` producesse lo schema corretto (vedi i tre fix in "Bug trovati in `pos.sql`" più sotto) prima di fidarcene per un'installazione da zero. Solo dopo queste due verifiche si è proceduto.

**Sequenza eseguita** (utente in PowerShell da amministratore per l'installazione del servizio, il resto da Claude):

1. Fermati Apache e MySQL di XAMPP dal pannello di controllo.
2. Registrata la MariaDB nativa (già installata via winget, mai avviata prima d'ora — vedi nota Fase 2a originale) come servizio Windows:
   ```powershell
   cd "C:\Program Files\MariaDB 12.3\bin"
   .\mariadbd.exe --install MariaDB --defaults-file="C:\Program Files\MariaDB 12.3\data\my.ini"
   Start-Service MariaDB
   ```
   (il `my.ini` esistente aveva già `port=3306`, libera perché XAMPP l'ha appena rilasciata — nessuna modifica di porta necessaria)
3. Creato il database e **importato il backup verificato** (non `pos.sql`, che è deliberatamente vergine per installazioni nuove — qui serviva mantenere i dati reali):
   ```php
   $conn = new mysqli("127.0.0.1", "root", "", "", 3306);
   $conn->query("CREATE DATABASE IF NOT EXISTS opensagra_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
   $conn->select_db("opensagra_pos");
   $conn->multi_query(file_get_contents("C:/Users/enrig/Desktop/opensagra_pos.sql"));
   ```
4. Creato l'utente applicativo `pos_own` (stesse credenziali già in `config/variabili.env`, nessuna modifica al file):
   ```php
   foreach (["localhost", "127.0.0.1", "%"] as $host) {
       $conn->query("CREATE USER IF NOT EXISTS 'pos_own'@'$host' IDENTIFIED BY 'pos_own1'");
       $conn->query("GRANT ALL PRIVILEGES ON opensagra_pos.* TO 'pos_own'@'$host'");
   }
   $conn->query("FLUSH PRIVILEGES");
   ```

**Verificato dopo il cutover:**
- Connessione come `pos_own` (non solo root) riuscita.
- Conteggi righe identici a XAMPP su tutte e 5 le tabelle (30/133/168/5/1).
- Schema completo: `stock.updated_at` + indice, `casse_stampanti.fondo_cassa` — tutte le funzioni recenti presenti.
- **L'app vera, attraverso il servizio FrankenPHP, funziona su HTTP e HTTPS con zero modifiche a `config/variabili.env`** — la stessa identica stringa di connessione (`127.0.0.1:3306`) ora arriva alla MariaDB nativa invece che a XAMPP, in modo completamente trasparente per il codice.
- Entrambi i servizi (`frankenphp`, `MariaDB`) confermati `StartMode: Auto` — l'intero stack riparte da solo al boot, senza XAMPP.

**Ripetuto a mano dall'utente (2026-09-07), stesso risultato**: per pratica, il DB è stato droppato e ricreato da zero interamente via **phpMyAdmin** (creazione database, import del backup, creazione utente `pos_own` sui 3 host) — nessun aiuto da riga di comando. Conteggi e connettività identici, app funzionante. Conferma che il percorso "phpMyAdmin su FrankenPHP" (vedi sotto) è pienamente utilizzabile per la gestione reale del database, non solo raggiungibile.

**Effetto collaterale scoperto**: con XAMPP fermo, la porta 80 si è liberata e **Caddy ha automaticamente iniziato a rispondere lì** con un redirect HTTP→HTTPS (`308` verso `:8443`) — è il server `remaining_auto_https_redirects` che FrankenPHP genera sempre quando un sito nel Caddyfile usa HTTPS automatico; era configurato fin dall'inizio ma non riusciva a legarsi alla porta 80 finché Apache la occupava. Non un problema, anzi una conferma utile in vista del prossimo passo (porte 8080/8443 → 80/443).

**Bug trovati in `config/pos.sql` durante questa verifica** (commit `911bb93`, `c2f43ae`):
1. Una `INSERT INTO casse_stampanti` senza `VALUES` né `;` corrompeva il parsing di tutto il resto del file — un'importazione pulita creava **1 tabella su 5**. Rimossa (la tabella nasce vuota comunque).
2. `casse_stampanti.tipo_stampante` e `.nome_indirizzo` erano rimasti a `varchar(20)`/`varchar(100)` nel file contro `varchar(50)`/`varchar(255)` nel DB reale (drift da modifiche fatte a mano via phpMyAdmin, mai riportate nel file).
3. Il default di `receipt_config` portava il nome e il logo di un evento passato specifico ("Festa Cavalleri e Fumeri 25/26 Luglio 2026") — rimosso, l'app ha già un fallback generico (`api/get_receipt_config.php`).

Tutti e tre corretti e riverificati con un'importazione pulita di prova prima di procedere con questo cutover.

### phpMyAdmin sotto FrankenPHP (link "Gestione Database" della sidebar)

Fermando Apache si è rotto il link `http://<host>/phpmyadmin` di `includes/sidebar.php` (puntava alla cartella di XAMPP, servita solo da Apache). **Fix**: phpMyAdmin è puro PHP, non serve Apache — instradato con `handle_path /phpmyadmin` e `handle_path /phpmyadmin/*` (due blocchi separati: `handle_path` accetta un solo pattern, non una lista) verso `C:\xampp\phpMyAdmin` nello stesso Caddyfile, sia sul blocco HTTP che HTTPS. Nessuna modifica al codice opensagra né a `phpMyAdmin/config.inc.php` (puntava già a `127.0.0.1`, risolve da solo alla MariaDB nativa).

**Difetto cosmetico noto, non bloccante**: la home di phpMyAdmin mostra un errore AJAX ("Codice errore: 200, OK (rejected)") perché phpMyAdmin non sa di essere montato sotto `/phpmyadmin` — alcuni suoi widget costruiscono URL assoluti senza quel prefisso, che finiscono per sbaglio sull'app opensagra. Non impedisce l'uso reale (verificato: import completo funzionante). Fix pulito se si vuole toglierlo: `$cfg['PmaAbsoluteUri']` in `phpMyAdmin/config.inc.php`, non ancora applicato (rimandato, non urgente).

**Per la Fase 3**: se l'installer generico prevede di offrire phpMyAdmin, questa stessa ricetta (`handle_path` verso la cartella phpMyAdmin, nessuna modifica al suo config) si applica identica su qualunque installazione — vale la pena includerla come opzione dello script.

### Passaggio alle porte standard 80/443 (2026-09-07) ✅

Ultimo step della migrazione: con XAMPP fermo le porte 80/443 sono libere per davvero — l'app non richiede più di specificare la porta nel browser, come con XAMPP.

**Conflitto trovato e risolto — `auto_https disable_redirects`.** Appena cambiate le porte, `http://192.168.88.224/` (e qualunque altro host elencato nel blocco HTTPS) ha iniziato a rispondere con un redirect automatico verso HTTPS invece di servire l'HTTP diretto — esattamente il comportamento che per le casse col bridge QZ **non vogliamo** (tutta la scelta "HTTP per il bridge" fatta in Appendice C si basa sul restare in chiaro). Causa: quando un sito HTTPS del Caddyfile elenca un nome/IP, Caddy genera **da solo** una regola di redirect per quel nome su HTTP→HTTPS — con `localhost:8443`/`8080` non si vedeva perché le due porte non si sovrapponevano mai sullo stesso nome, ma passando entrambe alle porte standard il conflitto è emerso. **Fix**: `auto_https disable_redirects` nel blocco di opzioni globali — disattiva solo il redirect automatico, non la gestione automatica dei certificati. Verificato: HTTP diretto e HTTPS tornano entrambi a funzionare indipendentemente sullo stesso host/IP.

**`PmaAbsoluteUri` di phpMyAdmin aggiornato** di conseguenza (`http://localhost/phpmyadmin/`, senza più `:8080`).

**Firewall — chiarito il meccanismo, non solo "funziona".** Cambiando porta, l'accesso da telefono ha continuato a funzionare **senza** ricreare la regola esplicita — non perché le porte 80/443 siano esenti dal firewall, ma perché Windows aveva già creato in automatico una regola **per programma** (`frankenphp.exe`, `Port=Any`, `Profile=Private`) la prima volta che l'eseguibile si è messo in ascolto: copre qualunque porta usi, non solo quelle di allora. La stessa identica cosa succedeva con XAMPP (`Apache HTTP Server | Port=Any | Profile=Private`, trovata nell'elenco regole) — da qui il ricordo "con XAMPP non serviva". **Il limite reale**: quella regola auto-creata è solo per rete **Privata**. Se Windows riclassifica la rete come Pubblica (rischio reale, già discusso), la regola automatica non si applica e si ripresenta lo stesso identico blocco già riprodotto e risolto in precedenza. **Per la Fase 3**: includere comunque la regola esplicita, ora sulle porte reali:

```powershell
New-NetFirewallRule -DisplayName "FrankenPHP opensagra (HTTP/HTTPS)" -Direction Inbound -Protocol TCP -LocalPort 80,443 -Action Allow -Profile Private,Public
```

Non è ridondante nonostante l'auto-regola di Windows: quella copre solo Private, questa aggiunge la resilienza su Public che serve davvero.

### 2b. Estensioni PHP ✅ FATTO (2026-09-06)

Lista chiusa, ricavata dal codice app + `require` dei vendor (dettaglio e snippet in **Appendice A**):

`mysqli`, `mbstring`, `gd`, `zip`, `intl`, `curl`, `openssl`, `iconv`, `dom`, `fileinfo` + `opcache` (perf).

**Insidia #1 — `frankenphp php-cli -m` NON funziona come previsto.** In questa versione (1.12.7) `php-cli` non fa da proxy trasparente ai flag di `php.exe`: tratta `-m`/`--help` come nome di uno script da eseguire (`Failed opening required '-m'`), non come opzione. Non esiste modo diretto di chiedere "elenco moduli" o "--help" a `php-cli`. **Soluzione**: scrivere un piccolo script PHP diagnostico e farlo eseguire a `php-cli` come file:

```powershell
@'
<?php
echo "PHP: " . PHP_VERSION . PHP_EOL;
echo "php.ini caricato: " . (php_ini_loaded_file() ?: "(nessuno)") . PHP_EOL;
$mods = get_loaded_extensions(); sort($mods);
foreach ($mods as $m) { echo "  - $m" . PHP_EOL; }
'@ | Out-File -FilePath "$env:USERPROFILE\check-php.php" -Encoding utf8

frankenphp php-cli "$env:USERPROFILE\check-php.php"
```

(la sequenza di caratteri strani all'inizio dell'output, tipo `´╗┐`, è solo il BOM che `Out-File -Encoding utf8` scrive di default su Windows PowerShell 5.1 — cosmetico, non un errore).

**Insidia #2 — nessun `php.ini` caricato di default.** Risultato del check qui sopra su una installazione fresca: `php.ini caricato: (nessuno)`, e delle 11 estensioni richieste **8 mancavano** (tutte tranne `dom`, `iconv`, OPcache — già built-in). **Buona notizia**: l'installer `irm install.ps1` scarica la distribuzione PHP Windows **completa**, non un binario statico — la cartella `ext\` accanto a `frankenphp.exe` contiene già tutte le DLL necessarie (`php_mysqli.dll`, `php_mbstring.dll`, `php_gd.dll`, `php_zip.dll`, `php_intl.dll`, `php_curl.dll`, `php_openssl.dll`, `php_fileinfo.dll`), insieme ai template `php.ini-development`/`php.ini-production`. Basta creare il `php.ini` e abilitarle:

```powershell
Copy-Item "$env:USERPROFILE\.frankenphp\php.ini-development" "$env:USERPROFILE\.frankenphp\php.ini" -Force

Add-Content "$env:USERPROFILE\.frankenphp\php.ini" @"

; --- Aggiunte per opensagra (Fase 2 del piano) ---
extension_dir = "ext"
extension=mysqli
extension=mbstring
extension=gd
extension=zip
extension=intl
extension=curl
extension=openssl
extension=fileinfo

; Valori replicati da XAMPP (config/php.ini), non i default di PHP:
; upload da telefono spesso 3-8MB (default PHP 2M li rifiuterebbe silenziosamente),
; date.timezone e' correttezza (non impostato = UTC, orari sbagliati su scontrini/report)
memory_limit = 512M
upload_max_filesize = 40M
post_max_size = 40M
max_execution_time = 120
date.timezone = Europe/Berlin
"@
```

PHP.ini usato: `php.ini-development` (mostra errori a video, comodo in questa fase). **In Fase 3, per l'installazione reale, usare `php.ini-production`** (nasconde gli errori all'utente finale) con le stesse aggiunte sopra.

**Nota**: `max_execution_time` letto via `php-cli` (CLI SAPI) torna sempre `0` indipendentemente dal php.ini — è comportamento normale di PHP in CLI, **non** un segno che l'impostazione non ha effetto: sotto il server web (`php_server`) viene rispettato normalmente.

- [x] **Gate di verifica**: rieseguito lo script diagnostico — tutte le 11 estensioni richieste presenti (`mysqli, mbstring, gd, zip, intl, curl, openssl, fileinfo, dom, iconv` + `Zend OPcache`), `php.ini caricato: C:\Users\enrig\.frankenphp\php.ini`. Valori numerici confermati: `memory_limit=512M`, `upload_max_filesize=40M`, `post_max_size=40M`, `date.timezone=Europe/Berlin` (`max_execution_time` non verificabile da CLI, vedi nota sopra).
- [x] **Aggiornamento (Fase 2d, 2026-09-06)**: `display_errors` portato da `On` a `Off` (con `log_errors=On` già attivo). Causa: un `Deprecated` di PHP 8.5 dentro `escpos-php` finiva scritto nella risposta JSON di `print_receipt.php`, rompendola. Vedi **Appendice C** per i dettagli — è anche la controprova pratica di perché la Fase 3 deve partire da `php.ini-production`, non da `-development`.

### 2c. Configurazione app ✅ FATTO (2026-09-06)

- [x] `Caddyfile` nella root del progetto (`C:\xampp\htdocs\opensagra\Caddyfile`). **Porte 8080/8443, non 80/443**: XAMPP resta acceso e serve già su 80/443, queste porte alternative evitano il conflitto durante i test in parallelo — si torna alle porte standard solo al cutover reale (Fase 3), quando XAMPP verrà fermato. **Aggiornato (2026-09-06)**: aggiunto un blocco HTTP — è quello che si userà per davvero (vedi decisione in **Appendice C**), il blocco HTTPS resta per riferimento/test futuri:

  ```
  {
  	frankenphp
  }

  http://:8080 {
  	root * C:\xampp\htdocs\opensagra
  	encode zstd gzip
  	php_server
  }

  https://localhost:8443, https://192.168.88.224:8443 {
  	root * C:\xampp\htdocs\opensagra
  	encode zstd gzip
  	php_server
  	tls internal
  }
  ```
  (creato con `Out-File -Encoding ascii`, non `utf8`, per evitare il BOM visto sopra)

  **⚠️ Versione finale, dopo il passaggio alle porte 80/443 (2026-09-07) — vedi "Passaggio alle porte standard" più sotto per il perché di ogni riga:**

  ```
  {
  	frankenphp
  	auto_https disable_redirects
  }

  http://:80 {
  	handle_path /phpmyadmin {
  		root * C:\xampp\phpMyAdmin
  		php_server
  	}
  	handle_path /phpmyadmin/* {
  		root * C:\xampp\phpMyAdmin
  		php_server
  	}
  	root * C:\xampp\htdocs\opensagra
  	encode zstd gzip
  	php_server
  }

  https://localhost, https://192.168.88.224, https://opensagra.local {
  	handle_path /phpmyadmin {
  		root * C:\xampp\phpMyAdmin
  		php_server
  	}
  	handle_path /phpmyadmin/* {
  		root * C:\xampp\phpMyAdmin
  		php_server
  	}
  	root * C:\xampp\htdocs\opensagra
  	encode zstd gzip
  	php_server
  	tls internal
  }
  ```

  **⚠️ Insidia #6 — CRITICA per la Fase 3, scoperta testando da telefono (2026-09-06): un sito legato a un solo hostname non risponde da altri dispositivi.** La prima versione era `http://localhost:8080` / `https://localhost:8443` — funzionava perfettamente per tutti i nostri test **perché tutti fatti dalla stessa macchina**, ma un telefono/tablet che si collega via IP di LAN (`http://192.168.88.224:8080`) riceveva **200 con corpo vuoto, nessun errore, nessun redirect** — riproducibile su ogni path (radice, API, pagine), non un caso isolato. Causa: Caddy lega un sito definito con un hostname esplicito (`localhost`) **solo** a richieste con quell'Host header esatto; qualunque altro Host non trova un sito e ottiene una risposta vuota invece di un errore parlante — il sintomo più ingannevole possibile, perché sembra "quasi funzionare".

  **Fix**: `http://:8080` (nessun hostname legato → risponde a qualunque Host in arrivo sulla porta) per l'HTTP; per l'HTTPS, elencare esplicitamente tutti i nomi/IP con cui l'app verrà raggiunta (`https://localhost:8443, https://192.168.88.224:8443`), così Caddy emette un certificato valido per tutti. **Per la Fase 3**: lo script d'installazione deve generare il `Caddyfile` con questa forma fin dall'inizio — mai un singolo hostname statico tipo `localhost`, altrimenti ogni cassa/tablet diverso dalla macchina server sperimenterà pagine bianche silenziose. Se l'IP del server cambia (DHCP), il blocco HTTPS smette di coprire quel nome — motivo in più per un IP statico o un hostname LAN stabile sul server (già notato in Appendice C per lo stesso motivo, a proposito di `wss://`).

  **Serviva anche una regola firewall** (mai creata prima d'ora — nessuna richiesta esterna arrivava proprio a livello di rete): da amministratore,
  ```powershell
  New-NetFirewallRule -DisplayName "FrankenPHP opensagra (HTTP/HTTPS)" -Direction Inbound -Protocol TCP -LocalPort 8080,8443 -Action Allow -Profile Private,Public
  ```
  **Necessità verificata con una prova pulita** (2026-09-06): disattivata la regola con `Disable-NetFirewallRule`, il telefono smette immediatamente di collegarsi; riattivata, torna a funzionare. Non è un artefatto di qualcos'altro (es. il bug del Caddyfile nel frattempo risolto) — la regola serve davvero.

  **Estesa anche al profilo "Pubblico"** (`-Profile Private,Public` invece di solo `Private`): Windows a volte classifica una rete come Pubblica anche quando è la propria rete LAN controllata (es. router senza segnali chiari di rete "domestica"), e le regole scoperte già presenti (`frankenphp.exe`, create automaticamente da Windows) erano scoped solo su `Private`. Testato forzando la rete a Pubblica (`Set-NetConnectionProfile -NetworkCategory Public`): con la regola estesa, HTTP e HTTPS funzionano comunque, sia da questa macchina sia dal telefono.

  **⚠️ Aggiornamento e correzione importante (2026-09-07), dopo il passaggio alle porte definitive 80/443 e un test con un dispositivo davvero esterno (non questa macchina):** la verifica del 2026-09-06 sopra ("con la regola estesa, funziona anche dal telefono su Pubblico") **era vera per quella regola su quelle porte (8080/8443)**, ma quella regola specifica è rimasta **disabilitata e sulle porte vecchie** dopo lo switch a 80/443 — mai aggiornata. Rifacendo il test da zero con le porte reali di produzione, sulla rete forzata a Pubblico, con un **secondo PC Linux** collegato via SSH (non questa macchina — un self-test verso il proprio IP di LAN può bypassare il firewall reale via un percorso di loopback interno, **non è una prova valida**, lezione riappresa anche qui):

  - Porte 80, 443 (FrankenPHP) e 3306 (MariaDB): tutte **bloccate** dal dispositivo esterno, confermato con `netsh advfirewall set publicprofile logging droppedconnections enable` + lettura di `pfirewall.log` → righe `DROP TCP` per tutte e 3, mentre un semplice `ping` (livello IP, non filtrato da regole per programma/porta) funzionava perfettamente — quindi non era un problema di routing/isolamento di rete, ma di regole firewall specifiche.
  - **Causa**: sia la regola auto-generata `frankenphp.exe` (Program-based, scoped solo `Private`) sia la regola equivalente di MariaDB (`MariaDB 12.3 (x64)`, Program-based su `mysqld.exe`, che pure *dichiara* di coprire tutti i profili incluso Pubblico) **non vengono effettivamente applicate su Pubblico per un processo che gira come servizio Windows** — nonostante il campo "Profili" dica il contrario. Non è mai stato verificabile prima con un test reale su Pubblico da un dispositivo esterno; le verifiche precedenti su questo punto erano o su `Private`, o self-test dalla stessa macchina (entrambi inconcludenti, con il senno di poi).
  - **Fix verificato**: una regola **per-porta generica**, senza vincolo di programma, funziona sempre:
    ```powershell
    New-NetFirewallRule -DisplayName "opensagra HTTP/HTTPS" -Direction Inbound -Protocol TCP -LocalPort 80,443 -Action Allow -Profile Domain,Private,Public
    New-NetFirewallRule -DisplayName "opensagra MariaDB" -Direction Inbound -Protocol TCP -LocalPort 3306 -Action Allow -Profile Domain,Private,Public
    ```
    Riverificato dal dispositivo esterno dopo la creazione di queste regole: tutte e 3 le porte aperte, `curl` su HTTP e HTTPS entrambi rispondono (302 reale, non solo handshake TCP riuscito).
  - **Per `install.ps1` (Fase 3)**: creare **sempre** queste due regole esplicite per-porta, su tutti e 3 i profili, indipendentemente dalla risposta alla Domanda 1 (coerente con "ogni installazione può già fare da server", vedi sopra) — **non fare affidamento** sulle regole Program-based auto-generate da Windows o dagli installer di MariaDB/FrankenPHP, anche quando sembrano già coprire tutto. **Metodologia da riapplicare sempre**: qualunque verifica di raggiungibilità di rete va fatta da un dispositivo fisicamente diverso dalla macchina server (mai un self-test verso il proprio IP), idealmente con la rete forzata su Pubblico almeno una volta, perché è lo scenario più restrittivo e il più probabile errore di classificazione automatica di Windows su reti "anonime".

  **Esplorato e concluso (2026-09-06): niente cert "valido per tutti gli IP", niente mDNS come meccanismo primario.** Un SAN di tipo IP in un certificato dev'essere un indirizzo esatto — X.509 non supporta wildcard sugli IP, solo sui nomi DNS. Provato **mDNS** (`opensagra.local`, un responder Node di test) come alternativa indipendente dall'IP: **funziona su Windows** (risoluzione confermata via `ping`, HTTP e HTTPS entrambi raggiungibili col nome), **ma fallisce su Android** (Pixel 7 Pro, testato su Firefox, Chrome e l'app FullyKiosk — `ERR_NAME_NOT_RESOLVED` ovunque). È un limite noto dell'ecosistema: la risoluzione `.local` via multicast per un URL digitato non è affidabile su Android/iOS, anche se il device supporta mDNS a livello di API per le app. Dato che le casse reali saranno soprattutto tablet Android, **mDNS non è utilizzabile come meccanismo primario**.

  **Soluzione adottata per questo deployment**: una voce **DNS statica sul router** (DNS unicast normale, non mDNS — es. `opensagra.local` → IP del server, abbinata a una riserva DHCP sul MAC del server) funziona ovunque, confermato dall'utente che già usa questo pattern per altri dispositivi (`muro.local`). Il nome `opensagra.local` resta comunque nell'elenco del blocco HTTPS del Caddyfile (non fa danno se non risolve, ed è pronto a funzionare non appena la voce DNS viene aggiunta sul router).

  **Per la Fase 3 (installazioni generiche, router non sempre personalizzabili)**: mDNS resta un'opzione *bonus* a costo zero da annunciare comunque (aiuta laptop Windows/macOS/Linux che si collegano per amministrazione), ma **non va promesso come soluzione per i tablet**. La gerarchia realistica: (1) voce DNS statica sul router, se personalizzabile — la più robusta; (2) IP statico/riserva DHCP con IP scritto a mano nel Caddyfile — sempre funzionante, va aggiornato se cambia; (3) mDNS — comodo extra, non affidabile su mobile.

  **Chiusura definitiva su "possiamo implementare DNS-SD completo come Chromecast?"**: no, non risolverebbe il problema. Chromecast/Bravia sono trovabili perché **app dedicate** (Google Home, l'SDK di Cast) cercano attivamente un servizio tramite le API di discovery del sistema (NSD su Android) — non perché qualcuno scrive il loro indirizzo in Chrome. Il nostro caso d'uso (FullyKiosk/browser puntato su un URL) passa dal **resolver DNS di sistema** per risolvere l'hostname digitato, che su Android **non consulta mai mDNS**, indipendentemente da quanto sia completo l'annuncio del servizio (PTR/SRV/TXT inclusi). Un'implementazione DNS-SD completa ci renderebbe scopribili solo da un'app scritta apposta per cercarci via NSD — non da un browser generico. Sproporzionato rispetto al bisogno reale: resta la voce DNS sul router.

- [x] `composer install` — non necessario in questa fase: `vendor/` è già presente e tracciato nella working copy usata da FrankenPHP (stessa identica cartella di XAMPP, nessuna copia separata).
- [x] `config/variabili.env`: nessuna modifica — punta già a `127.0.0.1` (XAMPP), FrankenPHP la legge tale e quale perché è **lo stesso identico codice PHP**, nella stessa cartella. FrankenPHP non "sa" nulla del database: esegue il file `.php` richiesto esattamente come faceva `mod_php`, ed è quel file (`config/get_db_connection.php`) a leggere `variabili.env` e aprire la connessione — la scelta del server web è indipendente dalla logica applicativa.
- [x] `frankenphp run --config Caddyfile` avviato con successo: PHP 8.5.10, 24 thread, HTTP/1+2+3 su `:8443`, certificato TLS locale ottenuto e **installato in automatico nel trust store di Windows** all'avvio (non serve girare `frankenphp trust` a parte, anche se rilanciarlo dopo conferma "already trusted" senza fare danni).

**Insidia #3 — `frankenphp trust` va lanciato mentre il server è già acceso.** Il comando chiede l'informazione sulla CA all'**admin API del processo in esecuzione** (`localhost:2019`); lanciato prima di `frankenphp run` fallisce con `dial tcp [::1]:2019: ... Rifiuto persistente`. Ordine corretto: prima `frankenphp run` (finestra 1, resta bloccante), poi eventualmente `frankenphp trust` da una seconda finestra.

**Insidia #4 — `curl.exe` su Windows rifiuta il certificato della CA locale.** Errore: `schannel: next InitializeSecurityContext failed: CRYPT_E_NO_REVOCATION_CHECK`. Causa: una CA locale di sviluppo (quella di Caddy) non pubblica una CRL (lista di revoca) — non avrebbe senso che lo facesse — ma `curl.exe` su Windows (backend schannel) tratta l'assenza di CRL come errore bloccante, a differenza dei browser che sono più tolleranti su questo. **Soluzione per i test da terminale**: `curl.exe --ssl-no-revoke <url>`. Non serve nei browser né rilevante per l'uso reale dell'app.

- [x] Primo smoke test manuale: `curl.exe --ssl-no-revoke https://localhost:8443/api/get_products.php` e `.../api/products_version.php` → entrambi rispondono con i dati reali (lista prodotti, `{"version":...,"count":...}"`), confermando l'intera catena FrankenPHP → PHP 8.5 → mysqli → MariaDB (XAMPP) funzionante end-to-end.

### 2d. Smoke test completo (parità con XAMPP)

**Insidia #5 — Firefox non si fida della CA locale, Chromium (Brave/Chrome/Edge) sì.** Verificato (2026-09-06): Brave ha accettato `https://localhost:8443` senza alcun avviso (usa lo store certificati di Windows, dove `frankenphp run`/`trust` installa la CA in automatico). Firefox invece ha mostrato "Avanzate → accetta il rischio" perché usa un **proprio store NSS indipendente da Windows** — coerente col log di avvio (`note: NSS support is not available on your platform`). **Fix**: importare manualmente `%APPDATA%\Caddy\pki\authorities\local\root.crt` in Firefox → `about:preferences#privacy` → Certificati → Visualizza certificati → Autorità → Importa → spunta "considera attendibile per identificare siti web". Da tenere a mente per la **Fase 3h**: se qualche postazione userà Firefox (o altri browser NSS-based), il certificato va installato **anche lì**, non basta il trust store di sistema.

**Correzioni al checklist originale, emerse testando (2026-09-06):** "Ricerca prodotti" non esiste come funzione in `billing.php` (nessun campo di ricerca testo — solo filtro categoria + click diretto sulla card). `session_start()` non compare **in nessun file** del progetto: l'app non usa sessioni PHP, lo stato (`cassa_id`) vive in `localStorage` lato client. Voce rimossa dal checklist.

**Nota sul DB condiviso**: questa macchina di sviluppo usa lo stesso database che a volte viene testato manualmente in parallelo (vedi memoria `concurrent-live-testing`). I test automatici sotto sono stati progettati per non scrivere vendite reali: niente checkout automatico, e l'unica scrittura di test (`chiudi_cassa.php`) ha usato un `cassa_id` palesemente fittizio (`TEST-CLAUDE-FASE2-DELETE-ME`), rimosso subito dopo la verifica.

Script usati (mantenuti in `e2e/*.manual.js`, non wired a `npx playwright test` — sono verifiche una tantum, non una suite CI):

- [x] `pages/billing.php`: caricamento prodotti con foto, categorie — visivamente identico a XAMPP (Brave e Firefox, dopo il fix CA).
- [x] Upload immagini prodotto (cartella `uploads/`) — creazione nuovo prodotto testata manualmente, riuscita (conferma `gd`/`fileinfo` oltre a `mysqli`).
- [x] Filtri categoria, aggiunta al carrello, sconti riga/totale — automatizzato (`e2e/fase2d_smoke.manual.js`): click su prodotto aggiorna il totale, click su sconto preimpostato lo ricalcola, cambio filtro categoria nasconde le sezioni non pertinenti. Nessun errore console.
- [x] **Checkout completo (contanti) → stampa scontrino via bridge QZ** — inizialmente rimandato, poi eseguito per davvero su richiesta esplicita dell'utente (stampante reale USB+LAN collegata, QZ Tray aperto), **su Chromium, Firefox e WebKit**: solo Chromium stampa con successo, Firefox e WebKit falliscono alla connessione QZ per mixed-content — vedi dettagli, causa e gravità reale in **Appendice C**. 4 vendite di test registrate (id 113-116, cassa `henry`).
- [ ] Stampa scontrino **ESC/POS USB diretta** (senza QZ, tipo `WIN_USB`/`LINUX_USB` lato server) — non testata: lo scenario testato è passato dal bridge QZ (tipo `BRIDGE`), non dalla stampa diretta server→USB.
- [x] Stampa via **bridge QZ** (`printBridgeViaQz`, cassa tipo `BRIDGE`) — testata con stampante reale, `qz.print` completato senza errori, sia per lo scontrino di vendita sia per il report statistiche.
- [x] **`api/sign-message.php`** (firma `openssl`) — automatizzato: HTTP 200, firma base64 di 344 caratteri; confermato anche indirettamente dall'assenza di popup di consenso QZ durante i test con stampante reale.
- [x] **QZ Tray sotto HTTPS** — testato con QZ Tray reale in esecuzione: popup di consenso mai comparsi, stampa riuscita nonostante l'host non-loopback (`192.168.88.224`) generi un warning di mixed-content non bloccante. Dettagli completi in **Appendice C**.
- [x] **PDF dompdf** (`print/print_stat_pdf.php`) — automatizzato (`e2e/fase2d_pdf_download.manual.js`): **non tramite chiamata HTTP diretta** (curl e l'API di richieste di Playwright falliscono entrambi in modo innocuo su risposte `Content-Disposition: attachment`, 0 byte scaricati — limite dello strumento, non dell'app), ma pilotando il vero flusso utente (click su "Scarica PDF" in `stat_vendite.php`, che sottomette un form HTML classico) con `page.waitForEvent('download')`: PDF valido ricevuto, 2767 byte, header `%PDF-1.7`.
- [x] Statistiche vendite (`api/statistiche_vendite.php`) — automatizzato, HTTP 200, risposta coerente (nessuna vendita odierna).
- [x] Statistiche storni (`api/statistiche_storni.php`) — verificato via curl POST, HTTP 200, `{"storni":[]}`.
- [x] Config casse e scontrini — `pages/conf_casse.php` (rinominata da `conf_stampanti.php`, commit `a5620da`) e `api/get_receipt_config.php` verificati **in sola lettura** (GET, nessun salvataggio testato per non toccare la config reale delle casse).
- [x] Apertura cassetto (`api/open_drawer.php`) — automatizzato: **atteso e ottenuto** un errore pulito HTTP 500 (nessuna stampante reale collegata: l'IP di rete hardcoded nel file non risponde) — conferma che `escpos-php` carica ed esegue correttamente sotto FrankenPHP, il fallimento è solo per assenza di hardware, non un errore di piattaforma.
- [x] Chiusura cassa (`api/chiudi_cassa.php`) — automatizzato con `cassa_id` di test fittizio, riga creata e **rimossa subito dopo** (`DELETE FROM casse_stampanti WHERE cassa_id = 'TEST-CLAUDE-FASE2-DELETE-ME'`, 1 riga cancellata, verificato).
- [x] ~~Sessioni PHP~~ — non applicabile, vedi nota sopra.

### 2e. Servizio Windows ✅ COMPLETATA (2026-09-06)

Obiettivo raggiunto: FrankenPHP gira in background come servizio, non serve più tenere una finestra PowerShell aperta con `frankenphp run` — sopravvive a logout e riparte da solo al boot (`StartMode: Auto`, verificato).

- [x] Scaricato WinSW (`WinSW-x64.exe`, rinominato `frankenphp-service.exe`) accanto a `frankenphp.exe` in `C:\Users\enrig\.frankenphp\`.
- [x] `frankenphp-service.xml` — **nota**: il `Caddyfile` vive nel progetto (`C:\xampp\htdocs\opensagra`), non nella cartella di FrankenPHP, quindi `--config` usa un percorso assoluto invece di `%BASE%`:

  ```xml
  <service>
    <id>frankenphp</id>
    <name>FrankenPHP opensagra</name>
    <description>Server FrankenPHP per l'app opensagra</description>
    <executable>%BASE%\frankenphp.exe</executable>
    <arguments>run --config "C:\xampp\htdocs\opensagra\Caddyfile"</arguments>
    <workingdirectory>C:\xampp\htdocs\opensagra</workingdirectory>
    <log mode="roll-by-time"><pattern>yyyy-MM-dd</pattern></log>
  </service>
  ```
- [x] `.\frankenphp-service.exe install` && `.\frankenphp-service.exe start` — **richiede PowerShell da amministratore** (a differenza degli altri passi della Fase 2, fatti da utente normale).
- [x] Verificato: `Get-Service frankenphp` → `Running`; `Get-CimInstance Win32_Service` → `StartMode: Auto`.
- [x] **HTTP funziona correttamente attraverso il servizio** (`http://localhost:8080` → HTTP 200) — questo è il percorso reale scelto per l'app, pienamente operativo senza finestre aperte.
- [x] **HTTPS attraverso il servizio — inizialmente rotto, poi risolto (2026-09-06).** Il servizio gira come **LocalSystem**, un account diverso dall'utente interattivo. Dal log (`frankenphp-service_*.err.log`): Caddy genera una **CA locale diversa** (storage sotto `C:\WINDOWS\system32\config\systemprofile\...`, non sotto il profilo di `enrig`) e **fallisce** ad installarla nel trust store di Windows (`"failed to install root certificate", "error":"add cert failed: ... Richiesta non supportata"`) — LocalSystem non può scrivere nei trust store come farebbe una sessione utente interattiva.

  **Fix applicato** (richiede PowerShell da amministratore — l'unico passo di tutta la Fase 2 che lo richiede): copiare la CA generata dal servizio fuori dalla cartella protetta e importarla nello store "Macchina locale" (condiviso da tutti gli utenti/browser Chromium della macchina):

  ```powershell
  Copy-Item "C:\WINDOWS\system32\config\systemprofile\AppData\Roaming\Caddy\pki\authorities\local\root.crt" "$env:TEMP\frankenphp-service-root.crt"
  Import-Certificate -FilePath "$env:TEMP\frankenphp-service-root.crt" -CertStoreLocation Cert:\LocalMachine\Root
  ```

  **Verificato**: `https://localhost:8443` via servizio → HTTP 200. **Nota**: Firefox ha un proprio store NSS separato per-utente (non tocca "Macchina locale") — se una postazione userà Firefox con una cassa a stampa diretta su HTTPS, va ripetuto anche lì l'import manuale del certificato (stessa procedura già vista in Fase 2d, Insidia #5), non serve amministratore per quello essendo per-utente.
- [x] **Test di riavvio effettivo della macchina — eseguito il 2026-09-07** (per abilitare Hyper-V e mettere in piedi una VM di test per `install.ps1`) — e la "garanzia standard di Windows" ipotizzata sopra **non bastava**: emersi due problemi reali, non prevedibili senza il riavvio vero.

  **⚠️ Insidia #7 — i servizi XAMPP (`Apache2.4`, `mysql`) erano rimasti `StartType: Automatic`.** Fermarli dal pannello di controllo XAMPP durante il cutover (Fase precedente) ferma il *processo*, non cambia il tipo di avvio del *servizio Windows* sottostante — al primo riavvio reale da allora, entrambi sono ripartiti da soli. `httpd.exe` ha vinto la corsa alla porta 80 su `frankenphp.exe` (`netstat` mostrava due processi in `LISTENING` sulla stessa porta — Windows non impedisce il doppio bind se nessuno dei due chiede l'esclusiva — ma solo uno riceve davvero il traffico: verificato che fosse Apache leggendo la firma del 404 restituito). **Verificato per fortuna innocuo lato dati**: `mysqld.exe` (XAMPP) e `mariadbd.exe` erano ugualmente entrambi in `LISTENING` su 3306, ma una connessione reale (`new mysqli(...)`) ha confermato che a rispondere era comunque `12.3.3-MariaDB` (quella vera), con dati coerenti — nessuna vendita finita nel DB XAMPP sbagliato nella finestra tra riavvio e scoperta. **Fix**: `Stop-Service` + `Set-Service -StartupType Disabled` su entrambi (non bastava fermarli, andava disabilitato l'avvio), poi `Restart-Service frankenphp` per liberare la porta. **Lezione per `install.ps1`**: se rileva XAMPP installato, deve disabilitare esplicitamente l'avvio automatico dei suoi servizi (non solo fermarli), altrimenti un riavvio futuro della macchina può silenziosamente far ripartire il vecchio stack accanto al nuovo.

  **⚠️ Insidia #8 — `detectLocalLanIp()` (pagina Configurazione Rete) rotta dall'aver abilitato Hyper-V.** Hyper-V aggiunge un adattatore `vEthernet (Default Switch)`; dopo il riavvio, `gethostbyname(gethostname())` ha iniziato a risolvere sul suo IP (`172.31.128.1`, non instradabile) invece che sulla vera scheda LAN (`192.168.88.224`) — Windows non garantisce un ordine stabile tra le schede di rete, e l'euristica precedente non teneva conto di adattatori virtuali. **Fix** (commit `522bf5f`): usare `Get-NetIPConfiguration` via PowerShell filtrando per `IPv4DefaultGateway` non nullo — l'adattatore con un gateway predefinito è quello davvero collegato alla LAN, esclude naturalmente switch virtuali/Tailscale/Bluetooth PAN che di norma non ne hanno uno. Aggiunta una cache su file (TTL 5 minuti): lanciare PowerShell costa fino a qualche secondo, e l'endpoint viene interrogato ogni 15-20s dalla pillola di stato. **Lezione**: qualunque euristica di rete va riverificata ogni volta che cambia qualcosa nell'ambiente (qui: l'installazione di un hypervisor) — non è un fatto acquisito una volta per tutte.

**Accettazione:** i flussi HTTP e HTTPS di Fase 2d funzionano identici attraverso il servizio, senza terminale aperto — **entrambi verificati**.

---

## Fase 3 — Script d'installazione per-OS

Un solo entry point (`install.ps1` su Windows, `install.sh` su macOS/Linux, o un unico script che rileva l'OS) che porta una macchina pulita ad app funzionante in HTTPS.

**Modello di distribuzione:** niente binario embed. Il pacchetto di release contiene i file di opensagra (con `vendor/`) + questo script. Lo script installa i prerequisiti (FrankenPHP, MariaDB), **copia i file** nella cartella di destinazione, genera i config e registra il servizio. Il codice resta in chiaro e modificabile sul posto (open source).

> L'embed di FrankenPHP è stato valutato e **scartato**: dato che lo script d'installazione serve comunque (MariaDB, QZ, domande, servizio), il guadagno del binario unico è marginale, mentre la build cross-OS — soprattutto Windows — aggiunge una pipeline da mantenere. La copia dei file è più semplice e coerente con "codice aperto e ispezionabile".

### 3a. Wizard: cosa automatizza (scope ridotto) ✅ finalizzato 2026-09-07, semplificato ulteriormente il 07-09

**Principio:** il codice di opensagra è **sempre spedito completo e identico**, tutte le funzioni presenti. Il wizard **non** abilita/disabilita funzionalità. Sostituisce solo due passi oggi manuali:

1. **Generazione di `config/variabili.env`** (oggi: creato/editato a mano copiando `variabili.env.example`).
2. **Provisioning del DB**: creare database, tabelle e utente (oggi: si lancia `config/crea_dbtable_and_user.php`, ora anche da CLI — vedi 3e).

Il file `config/variabili.env` resta in ogni caso un **file di testo normale, accessibile e modificabile** dopo l'installazione (l'app lo rilegge a ogni richiesta via `config/get_db_connection.php`). Il wizard lo scrive, non lo "nasconde". **In più**, da oggi, non serve nemmeno riaprirlo a mano per cambiare `DB_POS_HOST`: la pagina **Configurazione Rete** dentro l'app lo fa da interfaccia grafica (vedi sotto).

Serve anche una modalità non interattiva (parametri) per reinstallazioni ripetibili.

#### ⚠️ Aggiornamento (2026-09-07): il nucleo dell'installazione è a ZERO domande

La "Domanda 1" (indipendente vs server centrale), l'unica rimasta dopo aver tolto QZ e HTTPS, è stata **rimossa anche lei**. Motivo, notato discutendo l'implementazione: guardando la tabella "Effetto sullo script" (sotto), Indipendente e Server centrale producono **esattamente le stesse identiche azioni** — stesso `DB_POS_HOST=127.0.0.1`, stesso provisioning locale. La risposta non cambiava mai nulla di quello che lo script fa davvero. Il caso realmente diverso (Cassa client) era già gestito **fuori dal wizard**, dopo l'installazione, dalla pagina Configurazione Rete.

Quindi: **`install.ps1` non fa più nessuna domanda di architettura**. Installa sempre e comunque una configurazione "indipendente" funzionante (che è anche l'esatta base per fare da server, essendo la stessa cosa). Se una postazione deve diventare client di un'altra, lo si decide **dopo**, in qualsiasi momento — subito o mesi dopo, nessuna differenza — dalla pagina Configurazione Rete già costruita e testata (sotto). Non riguarda solo la Fase 3: è la conferma che quella pagina non era "un passo intermedio prima dell'installer", era già **la** soluzione per questa parte.

**Semplificazione di fondo dietro tutto questo:** ogni installazione, anche indipendente, fa già girare una sua MariaDB — la differenza tra "indipendente" e "server" non è *se* installare un database server, ma solo *se altre postazioni lo raggiungono in rete*. Diventare "il server" per altre casse non è un passo separato da eseguire in anticipo: è solo il risultato del fatto che altre postazioni scelgono di puntare a questa — **ma questo richiede comunque una regola firewall esplicita e corretta, vedi correzione sotto e Insidia #6.**

> ⚠️ **Correzione (2026-09-07, dopo test con un dispositivo davvero esterno): la frase originale qui sotto era sbagliata.** Diceva che bind-address e firewall di MariaDB "vanno sempre bene così, nessuna azione manuale necessaria" basandosi su: (1) `netstat` che mostra `0.0.0.0:3306` in ascolto, (2) la regola `MariaDB 12.3 (x64)` che *dichiara* di coprire Dominio/Privato/Pubblico, (3) un self-test riuscito dalla stessa macchina verso il proprio IP di LAN. **Tutti e tre inducevano in errore.** Un test reale da un secondo PC (Linux, via SSH), con la rete forzata su Pubblico, ha mostrato **le porte 80/443/3306 tutte bloccate** nonostante quanto sopra — il self-test "funzionava" solo perché una connessione verso il proprio IP dalla stessa macchina può bypassare il filtro reale del firewall (percorso di loopback interno), non prova nulla su un dispositivo esterno. Il log del firewall (`pfirewall.log`, con `RegistraConnessioniEliminate` attivato per la diagnosi) ha confermato `DROP TCP` su tutte e 3 le porte. **Causa reale**: le regole *legate al programma* (`mysqld.exe`, `frankenphp.exe`) create dagli installer non vengono applicate in modo affidabile su Pubblico per un processo che gira come **servizio Windows**, anche quando il loro campo "Profili" dichiara di coprire tutti e tre. Una regola *per-porta generica* (`New-NetFirewallRule -LocalPort 3306 -Profile Public`, senza vincolo di programma) invece **funziona sempre**, verificato con lo stesso dispositivo esterno. **Fix applicato**: sostituite le regole fragili con due regole esplicite per-porta, tutti i profili: `opensagra HTTP/HTTPS` (TCP 80,443) e `opensagra MariaDB` (TCP 3306) — vedi Insidia #6 per i dettagli e il comando esatto. **Lezione per la Fase 3**: `install.ps1` deve creare *sempre* queste due regole per-porta esplicite, senza fare affidamento sulle regole auto-generate dagli installer di MariaDB/FrankenPHP (per quanto sembrino corrette a leggerle) — e ogni volta che si verifica il comportamento di rete, farlo con un **dispositivo fisicamente diverso**, mai un self-test verso il proprio IP.

Cosa fa lo script, senza rami condizionali (uguale sempre):

| Passo | Comportamento |
|---|---|
| `config/variabili.env` | Sempre `DB_POS_HOST=127.0.0.1`, credenziali generate/di default |
| Provisioning DB | Sempre eseguito in locale su questa macchina |
| Diventare client di un altro server | **Non è compito di `install.ps1`** — si fa dopo, in qualsiasi momento, dalla pagina Configurazione Rete (sotto) |

- Password DB: default noto per `127.0.0.1`; se in futuro serve generarne una casuale per l'accesso via LAN, lo si fa comunque nello stesso provisioning, senza rami condizionali diversi.
- ⚠️ Resta valida la nota su **centralizzato + stampanti USB sui tablet**: la pagina in HTTPS da un host diverso blocca `ws://` verso un QZ non-loopback su Firefox/WebKit. Vedi **Appendice C**.

#### QZ Tray: incluso in `install.ps1`, HTTPS non è più una domanda

- **HTTPS**: HTTP e HTTPS girano già **sempre in parallelo** di default (deciso in Fase 2, vedi Appendice C) con la CA locale generata automaticamente da Caddy (`tls internal`) — non c'è nulla da chiedere all'installazione. Il certificato è già lì; se e come fidarsi di lui su altri dispositivi (tablet) è materiale da spiegare **dopo**, come guida separata, non come domanda bloccante del wizard.
- **QZ Tray** — decisione 2026-09-07, poi ulteriormente semplificata: va **incluso nello stesso `install.ps1`** (non uno script separato da scoprire a parte) e **installato sempre, senza nessuna domanda** — coerente col nucleo a zero domande (vedi sopra). "Deve funzionare tutto subito; chi scarica, se decide che QZ Tray non gli serve, lo disinstalla come un programma qualsiasi" — non tocca né rompe il resto di opensagra (**da scrivere esplicitamente anche nella guida finale**, insieme alla spiegazione di quando serve davvero, sotto).
  - La domanda "quando serve QZ" **non è più chiesta durante l'installazione** — resta comunque documentata (tabella sotto) per la guida, perché è materiale utile a chi deve poi configurare `conf_casse.php` per una cassa specifica. ⚠️ Da tenere a mente per quella guida: la domanda giusta **non è** "hai una stampante USB collegata a questo PC?" (quel caso, `WIN_USB`, stampa diretta, **non** ha bisogno di QZ) ma **"la stampante di questo PC deve essere raggiunta anche da altre postazioni, non solo da questo?"** — solo lì (il PC diventa un "ponte") QZ serve davvero, e va installato **solo sul PC col cavo USB**, mai sulle postazioni che ne usufruiscono da remoto.
  - Tabella completa degli scenari, verificata leggendo il codice di instradamento (`config/get_printer.php`, `print/print_receipt.php::routingStampa()`) — utile anche come base per la guida finale:

    | # | Scenario | Come stampa | Serve QZ? |
    |---|---|---|---|
    | 1 | Stampante di **rete** (IP proprio) | Socket diretto dal server all'IP | ❌ Mai |
    | 2 | Stampante **USB sullo stesso PC** che stampa (cassa indipendente tipica) | `WIN_USB`, condivisione locale | ❌ No — nessun ponte necessario |
    | 3 | Stampante **USB su un PC, ma altre postazioni** devono stamparci sopra | Browser remoto → QZ Tray sul PC col cavo USB, via websocket | ✅ **Sì, unico caso reale** — QZ solo sul PC "ponte" |
    | 4 | **Bluetooth** (appaiata a un telefono/tablet) | Intent Android → RawBT | ❌ No, meccanismo separato |
    | 5 | Nessuno scontrino fisico (solo PDF/schermo) | — | ❌ No |

#### Aspetto grafico di `install.ps1`: WPF via PowerShell ✅ deciso (2026-09-07)

Con il nucleo ridotto a zero domande (sopra), non serve più costruire un vero "wizard" a più schermate — resta solo il problema di **come si presenta** un'installazione automatica, dato l'obiettivo esplicito di **evitare finestre di terminale** ("un utente medio si spaventa quando lo vede").

**Opzioni valutate:**
- **`.ps1` puro** — riusa 1:1 tutto il lavoro già scritto/testato in questo piano (WinSW, regole firewall, installazione silenziosa MariaDB), ma mostra una console PowerShell visibile con output grezzo — esattamente l'effetto da evitare.
- **Eseguibile "vero" via FrankenPHP** (installer come mini sito web, impacchettato con l'embedding) — scartata: stessa pipeline di build cross-piattaforma già scartata per l'app stessa (vedi introduzione a questa fase), e sotto il cofano dovrebbe comunque lanciare gli stessi comandi PowerShell/`sc.exe`/`netsh` per servizi e firewall — non elimina la console, la nasconde dietro un altro livello con un pezzo in più che può fallire ad avviarsi (il mini-server locale).
- **PowerShell + interfaccia grafica nativa (scelta 2026-09-07)** — stesso motore/comandi già testati, ma con una finestra al posto della console.

**WinForms vs WPF, confrontati con mockup reali** (non solo in teoria — costruiti ed eseguiti su questa macchina, screenshot autentici):
- **WinForms**: libertà grafica bassa, resta visibilmente "una finestra di Windows anni 2000" (cornice nativa, font di sistema) anche personalizzando colori e testo.
- **WPF**: libertà alta — bordi personalizzati, angoli arrotondati, ombre, colori/tipografia a piacere, praticamente il livello di controllo estetico di una pagina web pur restando un eseguibile nativo compilabile in `.exe`. Costruito un mockup con barra del titolo dorata coerente col tema dell'app, logo, checklist con indicatori di stato colorati — nessuna delle limitazioni delle due opzioni scartate sopra.

**Decisione**: **WPF via PowerShell**, compilato in `.exe` con `ps2exe` (nasconde la console, aggiunge un'icona propria) — stesso codice/comandi di sistema già pianificati in questo documento, presentati con una finestra invece che con testo che scorre in un terminale. Nessuna domanda da mostrare (l'installazione è automatica end-to-end): la finestra serve solo a mostrare avanzamento/stato, si chiude da sola a fine installazione.

#### Pagina "Configurazione Rete" ✅ implementata e testata (2026-09-07)

Passo intermedio sviluppato e verificato prima di scrivere l'installer vero e proprio, per risolvere in anticipo il problema "come si passa da indipendente a client, senza toccare file a mano".

**File aggiunti:**
- `config/env_reader.php` — lettura di `variabili.env` condivisa (estratta da `get_db_connection.php`, che ora la riusa) — non forza una connessione, a differenza di prima.
- `config/env_writer.php` — `setEnvValue()`: riscrive **una sola chiave** in `variabili.env`, preservando tutte le altre righe.
- `api/db_status.php` — ping leggero (timeout 2s) verso l'host configurato, per la pillola di stato.
- `api/set_network_config.php` — cambia `DB_POS_HOST`: **verifica prima la connessione** con le credenziali correnti contro il nuovo host, e scrive il file solo se riesce. Non scrive mai un host che romperebbe l'app al giro successivo.
- `pages/conf_rete.php` — pagina con due opzioni ("Indipendente" / "Client verso un server"), stato live, salvataggio via `fetch()`.
- Pillola di stato rete in `includes/sidebar.php` (visibile su ogni pagina, polling ogni 20s) + voce di navigazione "Configurazione Rete".

**Perché funziona senza riavvii:** `get_db_connection.php` rilegge `variabili.env` **a ogni richiesta**, senza cache — cambiare `DB_POS_HOST` ha effetto immediato sulla richiesta successiva, nessun riavvio di FrankenPHP o di MariaDB necessario.

**Verificato con Playwright** (`e2e/conf_rete_test.manual.js`):
- Stato iniziale: indipendente, `127.0.0.1`, online — radio "Indipendente" preselezionato.
- Host non valido (`10.0.0.250`, non instradabile su questa rete): toast di errore, **`variabili.env` non modificato** (verificato leggendo `db_status.php` subito dopo: host ancora `127.0.0.1`).
- Switch a un host valido raggiungibile in LAN (`192.168.88.224`, il proprio IP di rete — stesso DB, indirizzo diverso): toast di successo, `db_status.php` riflette subito il nuovo host, `api/get_products.php` continua a rispondere 200 (l'app non si rompe).
- Ripristino a "Indipendente": tornato a `127.0.0.1` correttamente.

**Rafforzamento successivo — avviso basato su connessioni reali**: `api/db_connections.php` interroga `SHOW PROCESSLIST` sul database locale (richiede il privilegio `PROCESS`, aggiunto al provisioning) e conta le connessioni davvero attive da altri indirizzi in questo momento; il dialogo di conferma le mostra per nome host invece di un avviso generico sempre uguale.

⚠️ **Da testare con una macchina Linux vera quando ne mettiamo in piedi una come cassa permanente** (2026-09-07): finora verificato solo con connessioni auto-aperte dalla stessa macchina Windows (via IP di LAN invece di loopback, per non farle contare come locali da MariaDB) e con il PC Linux usato per il debug del firewall via SSH — mai con un'installazione opensagra reale e stabile su Linux che si connette normalmente. La meccanica (`SHOW PROCESSLIST` filtrato per host non-locale) non dovrebbe cambiare comportamento in base al sistema operativo del client, ma va comunque confermato con un caso d'uso reale prima di considerarlo definitivamente verificato.

**Nota su MariaDB nativa** (verificato su questa macchina, Windows, MariaDB 12.3): bind-address già su tutte le interfacce (`netstat` conferma `0.0.0.0:3306`). ~~Regole firewall già presenti e create dall'installer MSI ufficiale, nessuna azione manuale necessaria~~ — **smentito da un test reale, vedi la correzione qui sopra e Insidia #6**: quelle regole non bastano su rete Pubblica per un dispositivo davvero esterno. `install.ps1` deve creare esplicitamente le due regole per-porta descritte in Insidia #6, non fare affidamento su quelle degli installer.

### 3b. Rilevamento

- [x] **Deciso Windows-only** (2026-09-07, vedi 3a): nessun rilevamento OS/package manager multi-piattaforma, `install.ps1` richiede Windows esplicitamente (`Test-Prerequisites`). macOS/Linux restano fuori scope per ora.

### 3c. Installazione software

- [x] **FrankenPHP** (Windows): `Install-FrankenPHP` in `install.ps1`, usa lo script ufficiale (`Invoke-Expression (Invoke-RestMethod 'https://frankenphp.dev/install.ps1')`), idempotente. macOS/Linux non applicabile (Windows-only).
- [x] **MariaDB** (Windows): `Install-MariaDBEngine`, `winget install --id MariaDB.Server -e --silent`. macOS/Linux non applicabile.
- [x] **Composer**: confermato non necessario sul target, `vendor/` incluso nel pacchetto (`Copy-AppFiles` copia tutto il repo incluso `vendor/`, escluso solo ciò che serve allo sviluppo — vedi `ExcludeFromCopy`).

### 3d. Estensioni PHP

- [x] `Set-PhpExtensions` in `install.ps1` — applica lo script idempotente al `php.ini` in uso. **Bug reale trovato testando su VM pulita**: il controllo "già configurato" matchava anche le righe commentate del template di default di FrankenPHP, lasciando le estensioni vere disattivate — corretto con una regex ancorata a inizio riga.
- [x] **Gate**: verificato (messaggio reale visto in test: "Estensioni PHP mancanti dopo la configurazione: ...") — abort con elenco leggibile delle estensioni mancanti.
- [x] **Caveat binario statico Linux/Mac**: non applicabile, Windows-only.

### 3e. Database — riusa lo script esistente

**Script:** `config/crea_dbtable_and_user.php`. Fa:
- legge `config/variabili.env` (via `config/env_reader.php` — **non** più via `get_db_connection.php`, che apre subito una connessione come utente applicativo: bug reale trovato testando su VM pulita, quell'utente non esiste ancora su un'istanza vergine, essendo proprio questo script a doverlo creare);
- si connette come `root` e crea il database `opensagra_pos` se manca;
- importa `config/pos.sql` **solo se non ci sono già tabelle** (idempotente);
- crea l'utente app per gli host `localhost`, `127.0.0.1` e `%` con `GRANT ALL` sul db + `FLUSH PRIVILEGES`.

**Adattamenti per il wizard/installer:**
- [x] Eseguibile da **CLI** (`php config/crea_dbtable_and_user.php [opzioni]`), non solo via browser — `getopt`, `fail()` su stderr + `exit(1)`.
- [x] Connessione root parametrizzata: `--root-host`/`--root-user`/`--root-pass` (default: host di `variabili.env`, `root`, vuota — comportamento storico invariato per l'uso da browser).
- [x] **Architettura indipendente/centralizzato**: non più una domanda del wizard (rimossa, vedi 3a) — ogni installazione parte come cassa singola, l'eventuale passaggio a centralizzato si fa dopo, dalla pagina **Configurazione Rete**. Questo bullet e i due seguenti (utente `%`, `bind-address` solo LAN) sono superati da quella decisione: l'installer non distingue più i due casi a monte.
- [x] Migrazioni eseguite dopo l'import schema — `Invoke-Migrations` in `install.ps1`, gira ogni file di `config/migrations/*.php` in ordine.
- [x] Nome del database: **deciso fisso** (`opensagra_pos`, non parametrizzato) — vedi commento in `config/env_reader.php`. `DB_POS_SID` (variabile ridondante) rimossa da `variabili.env.example`.

### 3f. File e config

- [x] Percorso fisso `C:\opensagra` — `$Script:InstallPath`, `Copy-AppFiles` in `install.ps1`, `vendor/` incluso (non escluso da `ExcludeFromCopy`).
- [x] `Caddyfile` generato (`New-CaddyConfig`) — HTTP+HTTPS sempre in parallelo, `tls internal`. **Verificato** (2026-09-07): pur specificando solo `https://localhost` come site address (non un IP di rete esplicito), Caddy risponde comunque su `https://<IP-LAN>` (testato con successo, 302) — non serve un hostname aggiuntivo esplicito nel blocco, il caso dell'Insidia #6 (rilevamento IP) riguardava solo `detectLocalLanIp()` lato app, non il `Caddyfile`.
- [x] `config/variabili.env` generato (`New-EnvFile`) — solo se non esiste già (idempotente, non sovrascrive mai una configurazione esistente su reinstall/riparazione).
- [x] Permessi `uploads/`: **non serve un passo installer dedicato** — il bug ACL (LocalSystem scrive file illeggibili ad altri account) è già chiuso a livello di codice (`copy()` in `config/store_uploaded_file.php`, vedi [[frankenphp_localsystem_upload_acl]]), funziona indipendentemente dall'account con cui gira il servizio.
- [x] `upload_tmp_dir` + ACL — `Set-PhpExtensions` crea `C:\ProgramData\opensagra\php_upload_tmp` con `icacls` ereditabili (`Authenticated Users:Modify`, `SYSTEM:Full`).

### 3g. Servizi

- [x] Windows: WinSW — `Register-FrankenPHPService` in `install.ps1`. **Bug reale trovato testando su VM pulita**: `frankenphp-service.exe` (= WinSW rinominato) non veniva mai scaricato automaticamente, andava installato a mano in Fase 2 — aggiunta `Install-WinSW`, scarica l'ultima release da GitHub (`winsw/winsw`).
- [ ] macOS/Linux: non applicabile (Windows-only).
- [ ] **Soluzione B (da valutare, non urgente)**: account a bassi privilegi invece di LocalSystem — non necessario ora, il bug ACL è chiuso lato codice (Soluzione A). Dettagli in Appendice A.

### 3h. HTTPS / certificati

- [x] `tls internal` genera la CA locale — verificato funzionante (Fase 2, confermato di nuovo nei test VM di Fase 3).
- [x] Fidarsi della CA sulla macchina server — non necessario un passo esplicito: FrankenPHP/Caddy gestisce il proprio store di fiducia lato server; il problema reale era l'import nello store "Macchina locale" per i **client** (vedi Fase 2e), non il server.
- [ ] Produrre `root-CA.crt` + procedura documentata per installarlo sui **tablet** — **ancora da fare**, materiale per la guida finale (non uno step di `install.ps1`, è per-dispositivo lato client).
- [x] **Interazione con QZ Tray**: analizzata a fondo (Appendice C), nessuna modifica al codice QZ — si resta su HTTP in parallelo per il bridge, HTTPS funziona per tutti gli altri metodi di stampa.

### 3i. QZ Tray — incluso in `install.ps1`, installato sempre (vedi 3a)

Non un ramo condizionato da una domanda (il nucleo è a zero domande, vedi 3a): è un passo dello stesso script, eseguito **sempre**, su ogni installazione. Se sulla postazione risulta poi inutile, si disinstalla QZ Tray come un programma qualsiasi.

- [x] Installare QZ Tray sul PC/tablet a cui è collegata la stampante USB — `Install-QZTray` in `install.ps1`, risolve l'ultima release da GitHub (bug reale: l'URL fisso storico `download.qz.io` non esiste più).
- [x] Copiare `cert/cert.pem` come certificato di **override** nella cartella di QZ Tray (soppressione popup) — **due bug reali distinti qui**: (1) la cartella dati di QZ Tray non esiste ancora su un'installazione fresca, il vecchio controllo la saltava sempre; (2) più a fondo, la cartella giusta **non è affatto** `%APPDATA%\qz` ma la cartella di installazione di QZ Tray stessa (`C:\Program Files\QZ Tray\override.crt`) — verificato leggendo il codice sorgente di QZ Tray (`qz.auth.Certificate`, usa `SystemUtilities.getJarParentPath()` + `Constants.OVERRIDE_CERT`), dopo che il popup di conferma continuava a comparire nonostante un `override.crt` presente e byte-per-byte corretto, solo nel posto sbagliato.
- [x] ~~Posizionare la chiave privata in `../../../private/key.pem`~~ **Ridisegnato due volte (2026-09-07)**: quel percorso era calcolato per la vecchia struttura `xampp/htdocs/opensagra` e non aveva senso con il percorso fisso `C:\opensagra` di Fase 3 — il codice che avrebbe dovuto posizionare la chiave, per giunta, non copiava mai nulla da nessuna parte (bug reale, "failed to sign request" da QZ Tray).
  - **Primo tentativo**: generare una coppia chiave/certificato unica per ogni installazione (`openssl_*` di PHP) invece di condividere lo stesso `cert.pem` committato in git — vedi discussione su licenza QZ Tray (LGPL 2.1) più sotto: nessun impedimento legale, il meccanismo di firma/override è una funzionalità ufficiale di QZ. Tecnicamente più corretto (nessun segreto condiviso tra installazioni indipendenti) ma **scartato dopo un problema pratico**: testando con una VM e la macchina reale nello stesso scenario (entrambe che parlano allo stesso QZ Tray fisico), le due installazioni avevano certificati diversi e non si fidavano a vicenda senza un passo di sincronizzazione manuale — non zero-touch come richiesto.
  - **Decisione finale**: si distribuisce la **stessa** coppia chiave/certificato (quella dell'autore) con ogni installazione, bundlata in una cartella `private/key.pem` accanto a `install.ps1` (mai in git) e copiata al punto giusto da `config/installa_certificati_qz.php` (rinominato da `genera_certificati_qz.php`, ora copia soltanto, non genera). Percorso di destinazione centralizzato in `config/qz_key_path.php` (`C:\ProgramData\opensagra\private\key.pem`), usato sia da questo script sia da `api/sign-message.php` — prima erano due calcoli indipendenti, causa profonda del disallineamento originale. `install.ps1` esclude esplicitamente la cartella `private/` dalla copia verso la webroot (`Copy-AppFiles`/`ExcludeFromCopy`): la chiave deve restare fuori dalla webroot per non essere mai raggiungibile via browser.
- [x] Ricezione da altri dispositivi LAN: **verificato non serve alcuna modifica a `qz-tray.properties`** — QZ Tray 2.2.6 ascolta di default su tutte le interfacce (`::`, confermato con `Get-NetTCPConnection`), non solo loopback. Verificato con un vero test incrociato VM↔macchina reale sulla stessa LAN.
- [x] Riavviare QZ Tray e verificare (test di **Appendice C**) — verificato in VM con stampante reale via bridge dopo i fix sopra.
- [x] **Non toccare** le chiamate `qz.websocket.connect`/`qz.print` né il meccanismo di certificati in sé: solo il *percorso* della chiave è cambiato (`qz-helper.js` invariato), non il protocollo o la logica di firma.

**Nota legale (chiesta esplicitamente, verificata 2026-09-07)**: QZ Tray è LGPL 2.1. `install.ps1` scarica l'installer ufficiale non modificato dalle release GitHub e comunica con QZ Tray solo via WebSocket (nessun codice QZ incorporato/linkato) — è "mere aggregation"/IPC secondo le stesse FAQ della FSF sulla LGPL, non genera un'opera derivata, nessun obbligo di rilasciare il codice di opensagra. Il meccanismo di firma/override per sopprimere il popup è una funzionalità ufficiale di QZ, non un aggiramento. Attenzione solo se in futuro si volesse *rebrandizzare* QZ Tray (nome/icona propri): lì servirebbe una licenza commerciale di white-labeling — non il caso qui.

**Esperimento futuro, da valutare (annotato su richiesta esplicita, non pianificato) — bridge di stampa nativo via Mercure**

Discusso il 2026-09-07, affinato in una seconda conversazione dopo aver osservato che la Fase 4 (Mercure) userebbe comunque FrankenPHP come trasporto push: invece di reimplementare da zero l'accesso USB come fa QZ Tray, l'idea è **riusare il codice di stampa diretta già scritto e testato** (`config/get_printer.php`, casi `WIN_USB`/`LINUX_USB`, libreria `escpos-php`) spostandolo su un piccolo processo residente sul PC collegato alla stampante, invece che sul server centrale:

```
Browser (HTTPS, checkout normale) → Server centrale (PHP)
Server centrale → costruisce i byte ESC/POS (buildEscposRawReceipt, già esistente)
                → li pubblica su un topic Mercure (server-to-server)
PC col bridge   → un piccolo script PHP si iscrive al topic (SSE, come EventSource ma da CLI)
                → alla ricezione, chiama WindowsPrintConnector (stesso codice di WIN_USB oggi)
```

**Risolve da solo il problema HTTPS/mixed-content di Appendice C**: oggi Firefox/WebKit bloccano `ws://` verso QZ non-loopback da una pagina `https://` perché il *browser* apre quella connessione, e il mixed-content è una policy del browser. Nel nuovo schema il browser non partecipa più allo step di stampa — è un processo PHP in CLI a parlare con Mercure via HTTP(S), e un client da riga di comando non ha un'origine da proteggere: nessuna policy di mixed-content si applica. Si potrebbe usare HTTPS ovunque senza il compromesso attuale (tenere HTTP in parallelo apposta per QZ).

**I due pezzi realmente nuovi da scrivere** (il resto è riuso):
- **Script sottoscrittore**: processo persistente sul PC bridge, connessione streaming verso `https://server/.well-known/mercure?topic=print/cassa/{id}` (token JWT nell'header `Authorization`), parsing riga-per-riga del flusso `text/event-stream` (nessuna libreria necessaria, `curl` con `CURLOPT_WRITEFUNCTION` o `fopen()`/`stream_get_line()`), riconnessione automatica (Mercure supporta `Last-Event-ID` per non perdere eventi). Da far girare come servizio persistente — riusabile lo stesso meccanismo WinSW già costruito e testato in Fase 3 per `frankenphp`.
- **Autorizzazione JWT per topic**: Mercure richiede un JWT firmato sia per pubblicare che per sottoscrivere, con claim che elencano esplicitamente i topic permessi (non un token generico). Il server centrale userebbe un JWT "publisher" (`{"mercure":{"publish":["print/cassa/*"]}}`), ogni PC bridge un JWT "subscriber" **scoped alla sola sua cassa** (`{"mercure":{"subscribe":["print/cassa/henry"]}}`) — stessa logica di isolamento di oggi (un bridge non deve poter vedere gli scontrini di un'altra cassa). Si aggancerebbe bene allo schema esistente: una colonna tipo `bridge_token` in `casse_stampanti`, generata quando si configura una cassa con un nuovo tipo (es. `BRIDGE_NATIVE`), analogo a come oggi si configurano `qz_host`/`nome_indirizzo` per il tipo `BRIDGE`.

**Vantaggi**: elimina del tutto QZ Tray (un componente di terze parti in meno da installare/scaricare/tenere aggiornato — oggi causa di 3 dei bug reali trovati in Fase 3: URL di download morto, cartella override mai creata, chiave di firma mai posizionata), nessun popup/certificato di override da gestire, risolve l'HTTPS su tutti i browser per costruzione, non solo Chromium.

**Pacchetto sul PC bridge**: solo `vendor/mike42/escpos-php`, `config/get_printer.php` e il nuovo script sottoscrittore — **non l'app intera**: niente MariaDB inutilizzato sempre acceso, niente regola firewall per una porta 3306 che nessuno userà lì, niente superficie in più su un PC che deve solo stampare.

**Costi/rischi non stimati** (nessuna stima di tempo fatta finora, da fare se/quando si decide di affrontarlo davvero): lo script sottoscrittore e lo schema di autorizzazione JWT sono comunque codice nuovo da scrivere e testare, anche se il pezzo difficile (parlare con l'hardware) è già pronto. Andrebbe anche rifatto lato server l'instradamento in `routingStampa()` (nuovo caso `BRIDGE_NATIVE` accanto a `BRIDGE`/QZ, non necessariamente in sostituzione — potrebbero coesistere). Non prioritario: QZ Tray funziona (verificato in Fase 3), questa resta un'idea per il futuro.

### 3j. Pulizia

- [x] Cartella `docker/` rimossa dal repo (verificato: non esiste più).
- [x] **Open source**: `config/variabili.env` non è più tracciato (verificato: `git ls-files` non lo elenca), `.gitignore` lo esclude esplicitamente, solo `variabili.env.example` è tracciato.
- [x] README scritto (`README.md`, vedi sotto).

**Accettazione:** su una VM/macchina pulita, un comando porta a: DB popolato, app raggiungibile in HTTPS, servizi attivi al boot, smoke test 2d verde.

---

## Fase 4 — Realtime via Mercure (opzionale, futuro)

Da fare **solo se** cresce il numero di casse o si vuole il realtime anche sugli ordini. Nessun componente nuovo da installare: l'hub è nel binario FrankenPHP.

- [ ] Abilitare l'hub Mercure nel `Caddyfile` (route `/.well-known/mercure`, chiavi JWT publisher/subscriber).
- [ ] Su ogni mutazione rilevante (`update_product.php`, `insert_product.php`, `soft_delete_product.php`, `update_category.php`, checkout, storni): `POST` di un update all'hub con topic `products` / `orders`.
- [ ] `billing.php`: sottoscrizione `EventSource` sul topic `products`; su messaggio → `loadProducts()` (o applica il delta dal payload).
- [ ] **Mantenere la Strategia A come fallback** se `EventSource` non è disponibile / la connessione cade.
- [ ] Estendere ad `orders` per far comparire nuovi ordini sulle casse senza refresh.

**Accettazione:** una modifica prodotto si riflette sulle altre casse in < 1 s senza polling; staccando l'hub, l'app continua a funzionare col polling condizionale.

### Pro e contro (valutazione 2026-09-07, prima di decidere se promuoverla)

Analisi fatta passando in rassegna il codice reale (non solo in teoria) per capire dove il realtime cambierebbe davvero qualcosa.

**Cosa ha davvero un consumatore oggi:**

| Pagina | Aggiornamento oggi | Beneficio reale da Mercure |
|---|---|---|
| `billing.php` (cassa, sempre aperta) | Polling condizionale ~6s (Fase 1) | Solo estetico/percepito, vedi sotto |
| `add_product.php` | Nessun polling (pagina da admin) | Nessuno |
| `storni.php` | Nessun polling, lookup manuale a bottone | Nessuno |
| `stat_vendite.php` | Nessun polling, snapshot al caricamento | Solo se si vuole una dashboard live — funzione **nuova**, non esiste oggi |

**Contro / motivi per NON farla ora:**
- **Non è un problema di correttezza dei dati, solo di percezione**: verificato in `print/print_receipt.php` — il checkout rivalida sempre lo stock a livello DB dentro una transazione (`SELECT ... FOR UPDATE`) prima di scalare la quantità. Due casse che vedono per 6s lo stesso prodotto "disponibile" non possono mai generare una vendita doppia di un articolo esaurito: la seconda riceve un errore pulito al checkout, non un dato corrotto. Mercure chiuderebbe un gap percepito (il pulsante si disabilita prima), non un bug reale.
- **"Realtime sugli ordini" non ha oggi nessuna funzione che lo consumerebbe** — non esiste una bacheca ordini condivisa tra casse. Sarebbe una funzionalità totalmente nuova da progettare, non un potenziamento di qualcosa che già esiste.
- **Complessità permanente aggiunta all'installer di Fase 3**: andrebbe generata/gestita la chiave JWT e la route dell'hub nel wizard — un pezzo in più in uno script che abbiamo tenuto deliberatamente semplice (una sola domanda).
- **Terreno nuovo per questo progetto**: a differenza di QZ/MariaDB/firewall (ormai ben conosciuti, insidie mappate), Mercure/JWT/`EventSource` non sono mai stati toccati qui — rischio concreto di sorprese scoperte solo testando, come già successo più volte in questa migrazione (firewall, HTTPS, porte).
- **Stima se la facessi (Claude) io stesso, implementazione + test**: 6-10 ore di lavoro effettivo, con margine di incertezza reale per il punto sopra. Scomposizione: 1-2h config hub+JWT nel Caddyfile, 1-2h pubblicazione dai 5-6 endpoint di mutazione (senza mai rallentare/bloccare il checkout se l'hub è giù), 1-2h `EventSource` + fallback in `billing.php`, 1-2h test multi-browser del realtime (fattibile in autonomia, senza bisogno di hardware dell'utente), ~1h non-regressione sugli endpoint toccati, 1-3h di margine imprevisti.

**Pro / motivi per farla comunque, in futuro:**
- Se il numero di casse cresce davvero, il gap percepito (pulsante che si disabilita con qualche secondo di ritardo) diventa più visibile e fastidioso proporzionalmente al traffico.
- Se nasce un bisogno concreto di una dashboard vendite live durante l'evento (non solo "sarebbe carino"), Mercure è la via naturale per farla bene.
- L'hub è già dentro il binario FrankenPHP: nessuna nuova dipendenza esterna da installare, "solo" configurazione e codice applicativo.
- Se si fa comunque per i motivi sopra, l'avviso "il server sta per fermarsi" (vedi sotto) diventerebbe praticamente gratis — un topic in più sull'infrastruttura già in piedi, invece di un meccanismo a parte.

**Decisione (2026-09-07): resta opzionale/futura, non promossa.** Da rivalutare solo se cambia concretamente uno dei motivi "pro" sopra — non per il solo avviso di disconnessione, che ha una via più economica (sotto).

### Idea rimandata: avviso "il server sta per fermarsi" alle altre postazioni

Emersa discutendo la pagina Configurazione Rete (2026-09-07): oggi non esiste alcun canale da un'installazione opensagra alle altre — ognuna fa solo polling verso il DB condiviso, nessuno "spinge" nulla (nessun push instantaneo possibile senza l'hub Mercure di questa fase). Un avviso reale è comunque realizzabile **senza** aspettare la Fase 4, riusando quello che già c'è: questo PC scrive un "avviso" in una riga condivisa nel DB; le altre postazioni (che già fanno polling periodico, stesso pattern di `products_version.php`) lo notano entro pochi secondi e mostrano un banner "Il server sta per fermarsi, salva il lavoro in corso". Non implementata ora su richiesta esplicita dell'utente ("non ora, rimandiamo") — da riprendere se/quando serve davvero, eventualmente insieme o al posto della Fase 4.

---

## Appendice A — Estensioni PHP: lista e abilitazione

### Lista verificata (scansione codice app + `require` dei vendor in `composer.lock`)

| Estensione | Perché | Fonte |
|---|---|---|
| **mysqli** | tutto l'accesso DB (`new mysqli` in 21 file) | codice app |
| **mbstring** | `mb_substr` nel codice; `require` di dompdf, php-css-parser, php-svg-lib, php-font-lib | app + vendor |
| **gd** | dompdf `require` `ext-gd: *` (non opzionale in 3.x) | vendor |
| **zip** | dompdf `require` `ext-zip: *` | vendor |
| **intl** | escpos-php `require` `ext-intl: *` | vendor |
| **curl** | `curl_init`/`curl_exec` in `print/print_receipt.php`, `print_receipt_bridge.php`, `print_stat_receipt.php` | codice app |
| **openssl** | `openssl_sign` / `openssl_get_privatekey` in `api/sign-message.php` + HTTPS in curl | codice app |
| **iconv** | php-css-parser `require` `ext-iconv: *` | vendor |
| **dom** | dompdf + masterminds/html5 `require` `ext-dom` | vendor |
| **zlib** | escpos-php `require` `ext-zlib`; compressione PDF dompdf | vendor |
| **fileinfo** | MIME immagini in dompdf; costo nullo | prudenza |
| **opcache** | dimezza l'overhead di bootstrap per richiesta in classic mode | performance |

**Non abilitare** (solo `suggest`, mai chiamate dal codice app): `imagick`, `gmagick`, `pdo_mysql`, `bcmath`, `gmp`, `sodium`, `exif`, `soap`.
Nel core PHP 8.x, nessuna riga: `json`, `session`, `hash`, `random`, `pcre`, `filter`, `spl`.

Riferimento: XAMPP abilita esattamente questo set di default → l'app ha sempre funzionato con queste.

### ⚠️ `frankenphp php-cli -m` / `--ini` non funzionano

Verificato sul campo (Fase 2b, 2026-09-06, v1.12.7): `php-cli` non fa da proxy trasparente ai flag di `php.exe` — tratta qualunque cosa cominci per `-` come nome di file da eseguire, non come opzione (`Failed opening required '-m'`). Niente panico, non è un bug nostro: **si aggira scrivendo un piccolo script PHP e facendolo eseguire come file**, che è l'uso per cui `php-cli` è pensato ("Runs a PHP command").

### Snippet verificato (Windows / PowerShell)

```powershell
$frankenDir = "$env:USERPROFILE\.frankenphp"   # dove atterra con `irm install.ps1`; con l'archivio ZIP e' dove lo estrai
$required = @('mysqli','mbstring','gd','zip','intl','curl','openssl','iconv','dom','fileinfo')

# 1. php.ini: development in Fase 2 (mostra errori mentre testiamo),
#    production nell'installer reale di Fase 3 (li nasconde all'utente finale)
Copy-Item "$frankenDir\php.ini-development" "$frankenDir\php.ini" -Force
Add-Content "$frankenDir\php.ini" @"

extension_dir = "ext"
extension=mysqli
extension=mbstring
extension=gd
extension=zip
extension=intl
extension=curl
extension=openssl
extension=fileinfo
memory_limit = 512M
upload_max_filesize = 40M
post_max_size = 40M
max_execution_time = 120
date.timezone = Europe/Berlin
upload_tmp_dir = "C:\ProgramData\opensagra\php_upload_tmp"
"@
# iconv, dom e OPcache sono gia' compilati dentro questa distribuzione: nessuna riga necessaria.

# 1b. Cartella temp upload con ACL ereditabili (vedi "upload_tmp_dir e ACL degli upload").
#     Senza, con il servizio come LocalSystem gli upload finiscono illeggibili in uploads/.
$phpTmp = "C:\ProgramData\opensagra\php_upload_tmp"
New-Item -ItemType Directory -Force -Path $phpTmp | Out-Null
icacls $phpTmp /grant "*S-1-5-11:(OI)(CI)M" /grant "*S-1-5-18:(OI)(CI)F"   # Authenticated Users:Modify, SYSTEM:Full

# 2. Gate di verifica — via script, non via -m
$checkScript = "$env:TEMP\check-php.php"
@'
<?php
$loaded = get_loaded_extensions();
echo implode(",", $loaded);
'@ | Out-File -FilePath $checkScript -Encoding ascii -Force

$loaded = (frankenphp php-cli $checkScript) -split ','
$missing = $required | Where-Object { $loaded -notcontains $_ }
if ($missing) { throw "Estensioni PHP mancanti: $($missing -join ', ')" }
```

Nota: `-Encoding utf8` di PowerShell 5.1 scrive un BOM che finisce nell'output PHP (cosmetico, non rompe la logica, ma usare `ascii` per gli script diagnostici lo evita del tutto).

### `upload_tmp_dir` e ACL degli upload (problema scoperto 2026-09-06)

**Sintomo.** Un logo caricato dalla pagina di configurazione scontrino veniva stampato come rumore e, guardando il file, `uploads/receipt_logo_global_*.jpg` risultava **illeggibile** anche all'utente interattivo — cosa che con XAMPP non accadeva. (Il logo troppo largo per la testina era un secondo bug, indipendente, risolto in `print/print_receipt.php` con un ridimensionamento a 560 punti / 70 mm.)

**Causa.** Il servizio FrankenPHP gira come **LocalSystem** (Fase 2e). Il suo `sys_temp_dir` è `C:\Windows\Temp`, leggibile solo da SYSTEM/Administrators. Su Windows `move_uploaded_file()` usa `MoveFile()`, che **conserva la ACL del file temporaneo** invece di rifarla ereditare dalla cartella di destinazione: il file spostato in `uploads/` si portava dietro quella ACL restrittiva. I placeholder SVG (`file_put_contents`) non ne soffrivano perché il file nuovo eredita normalmente le ACE della cartella. Con XAMPP il problema non c'era perché Apache girava come l'utente interattivo (temp con ACL permissive).

**Soluzione A — applicata ora (basso sforzo).**
1. `config/store_uploaded_file.php`: helper `is_uploaded_file()` + `copy()` + `unlink()` al posto di `move_uploaded_file()` in `api/save_receipt_config.php`, `api/insert_product.php`, `api/update_product.php`. `copy()` crea il file a destinazione → eredita le ACE di `uploads/` (`Authenticated Users:Modify`). **Chiude il bug a prescindere dall'account del servizio.**
2. `upload_tmp_dir` esplicito nel `php.ini` → `C:\ProgramData\opensagra\php_upload_tmp`, cartella con ACE ereditabili (vedi snippet sopra, passo 1b). Tiene i temp di PHP fuori da `C:\Windows\Temp` — utile anche per gli upload grandi. Dopo la modifica del `php.ini` serve **riavviare il servizio** (`Restart-Service frankenphp`, da amministratore).

**Soluzione B — rimandata a Fase 3 (da valutare).** Non far girare il web server come **LocalSystem** ma come account locale a bassi privilegi (o `LOCAL SERVICE`). È l'igiene corretta per un servizio di rete, ma:
- va garantita in modo uniforme su Win 10/11 (creazione account/gruppo, `Log on as a service`), e va testato l'equivalente su Linux (unit `systemd` con `User=`/`DynamicUser=`) e macOS (LaunchDaemon con `UserName`);
- il workaround CA di Caddy della Fase 2e (import manuale della root di LocalSystem nello store Macchina locale) andrebbe rifatto/adattato: con un account dedicato lo storage Caddy si sposta e il trust va rigestito;
- vanno riassegnate con `icacls` le ACL di `uploads/`, dei log e dello storage Caddy all'account scelto.
Con la Soluzione A in piedi, B resta un miglioramento di sicurezza, non un requisito per il funzionamento.

### macOS / Linux

- `php.ini` in `/opt/homebrew/etc/php/*/` (brew) o `/etc/php/*/`.
- Estensioni brew: `extension="mysqli.so"`, ecc.; nei build statici FrankenPHP molte sono già compilate dentro.
- **Stesso limite di `php-cli -m`** atteso anche qui (è il wrapper Go, non qualcosa di Windows-specifico): usare lo stesso script diagnostico via file, non il flag. Verificarle sempre, non darle per scontate.

---

## Appendice B — Worker mode (ottimizzazione futura)

### Cosa darebbe

- Niente bootstrap per richiesta (autoload/`require`/init eseguiti una volta).
- Connessione DB persistente per worker: niente handshake TCP + auth a ogni richiesta.
- Stato caro riutilizzabile (config, template, connettori stampante, dompdf).
- Più throughput per core, latenza più bassa e costante.

### Quando conviene

Solo se: casse da una manciata a decine/centinaia, funzioni pesanti sempre attive (es. dashboard statistiche live su tutte le casse), o spremere hardware molto datato. Per 3–15 casse a ~0,4 req/s l'una, classic mode ha 1–2 ordini di grandezza di margine → **non necessario**.

### Scope del refactoring (stato attuale del codice)

- 43 file PHP entry-point, **nessun front controller**.
- 21 file creano un `$connectionDB` globale via `config/get_db_connection.php`; **57** chiamate `$connectionDB->close()`.
- **66** `exit`/`die` in 21 file di `api/` e `print/`.
- `session_start()` usato nell'app.
- `api/get_products.php` esegue DDL a ogni richiesta (risolto in Fase 0).

| Intervento | Portata | Rischio |
|---|---|---|
| Wrapper worker (loop `frankenphp_handle_request`) | ~20 righe di `worker.php` generico che resetta stato e `require` dello script richiesto | basso |
| `exit`/`die` → `return` | incapsulare il corpo di ~43 script in `function handle() {...}`; convertire 66 occorrenze | basso, ripetitivo |
| Connessione DB a livello worker | aprire una volta per worker, riusare, guardia ping/reconnect per *"MySQL gone away"*; eliminare i 57 `close()` | medio |
| Sessioni | `session_write_close()` per richiesta, nessun leak in globali | basso |
| Sweep risorse | connettori stampante ESC/POS in `print/*` devono chiudere gli handle per richiesta | medio (audit) |
| Stato globale | audit di `setlocale`/`ini_set`/timezone → spostare nel bootstrap del worker | basso |
| Gestione fatal | try/catch nel loop worker + `register_shutdown_function` | basso |

Superglobali (`$_GET/$_POST/...`): FrankenPHP le resetta da solo → nessun lavoro.

**Stima:** ~3–4 giorni per chi conosce il codice (2 di lavoro meccanico, 1–2 di audit/hardening/load-test per verificare assenza di leak su worker longevi) + periodo di rodaggio a traffico basso prima di un evento.

---

## Appendice C — QZ Tray, certificati e HTTPS ✅ TESTATA CON STAMPANTE REALE (2026-09-06)

> Decisione originale: non toccare il codice QZ in questa migrazione. **Aggiornamento**: durante il test reale è emerso e corretto un bug indipendente in `billing.php` (vedi in fondo) — non nel codice QZ stesso, che infatti funziona invariato.

### Stato attuale del codice (riferimento)

- `assets/js/qz-helper.js` → `setupQzSecurity()`: certificate promise su `../cert/cert.pem`, signature promise su `../api/sign-message.php` (SHA512).
- `api/sign-message.php`: `openssl_sign(...)` con chiave privata in `../../../private/key.pem` (fuori dalla webroot).
- `cert/cert.pem` + `cert/cert.cer`: certificato pubblico self-signed (generato con openssl), caricato come **override** in QZ Tray.
- Connessione: `qz.websocket.connect({ host, usingSecure: false, port: { secure: [], insecure: [8182] } })` → **WS in chiaro su porta 8182** (`pages/billing.php` ~557, `pages/conf_stampanti.php` ~368).
- `pages/conf_stampanti.php` ~315 carica `qz-tray.js` da `http://localhost:8182/qz-tray.js`.
- `qz_host` per-cassa salvato in DB (`api/stampanti.php`, `config/get_printer.php`) → già supporta "QZ locale" e "QZ su bridge".

### Concetto chiave: due "certificati" diversi, da non confondere

| | A cosa serve | Lo tocca Caddy/HTTPS? |
|---|---|---|
| **Certificato di firma QZ** (`cert.pem` + override + `sign-message.php`) | Dire a QZ Tray "questo sito è autorizzato" → **elimina i popup di consenso** | **No.** È il livello di fiducia interno di QZ, indipendente dal trasporto web. Resta identico. |
| **Certificato di trasporto QZ** (TLS del websocket `wss://` su 8181) | Cifrare il canale browser ↔ QZ Tray | **Indirettamente sì** (mixed-content, sotto) |

→ **Passare a Caddy/HTTPS non semplifica né sostituisce la procedura di soppressione dei popup.** Quella (openssl + override + firma) va mantenuta com'è.

### Cosa HTTPS rompe/non rompe — risultato reale, non più un'ipotesi

1. **Mixed content sul websocket verso un host non-loopback → dipende radicalmente dal motore browser.** Testato per davvero su tutti e tre i motori (2026-09-06), stesso scenario esatto: pagina `https://localhost:8443`, QZ Tray configurato con `qz_host=192.168.88.224` (IP di rete, non `localhost`) — lo scenario "bridge su un'altra macchina LAN".

   | Motore | Risultato |
   |---|---|
   | **Chromium** (Brave/Chrome/Edge) | ⚠️ Warning `Mixed Content: ... Insecure access is deprecated` **ma la connessione riesce comunque** e la stampa va a buon fine. |
   | **Firefox** | ❌ **Bloccato**: `Firefox can't establish a connection to the server at ws://192.168.88.224:8182/` — errore JS, stampa non parte. |
   | **WebKit** (motore di Safari) | ❌ **Bloccato esplicitamente**: `[blocked] ... requested insecure content from ws://... This content was blocked and must be served over HTTPS.` |

   **La previsione originale del piano ("si rompe") era corretta — è la tolleranza di Chromium ad essere l'eccezione, non la regola.** Su una sagra reale con casse/tablet non tutti sullo stesso browser, questo scenario (QZ raggiunto via IP di LAN da una pagina HTTPS) **si rompe su 2 browser su 3**. Non è un dettaglio da rimandare: se anche una sola postazione usa Firefox o Safari con un `qz_host` non-loopback, la stampa smette di funzionare non appena si passa a HTTPS. Va risolto (es. `wss://` con certificato valido, o QZ sempre in loopback rispetto alla pagina) **prima** di considerare il passaggio a HTTPS completo in produzione, non lasciato come rischio "da monitorare".

   In tutti e tre i casi, il record di vendita viene comunque scritto sul DB da `print_receipt.php` **prima** che il client tenti la connessione QZ: un fallimento di stampa lato browser non impedisce la registrazione della vendita (utile a saperlo: la cassa non deve ripetere l'ordine pensando che "non sia passato", ma la ricevuta va ristampata a mano se la stampa fallisce).
2. **Script `qz-tray.js` da `http://localhost:8182`** in `pages/conf_casse.php` (rinominata da `conf_stampanti.php`) → non testato in questo giro (il test reale ha usato `billing.php`/`stat_vendite.php`, che caricano QZ diversamente). Resta da verificare quando si testerà la pagina di configurazione stampanti sotto HTTPS.
3. **`usingSecure: false` hardcoded** → confermato ancora presente e funzionante nello scenario testato (connessione `ws://`, non `wss://`).

### Test eseguiti in Fase 2 con stampante reale (USB + LAN) e QZ Tray attivo

- [x] **Popup di consenso QZ**: **non sono mai comparsi**, nemmeno sui browser dove la stampa poi falliva (Firefox/WebKit) — la procedura di firma/override esistente (`cert.pem` + `sign-message.php`) continua a sopprimerli identica sotto HTTPS, indipendentemente dal motore browser.
- [x] **Checkout reale → stampa scontrino via bridge QZ** (`pages/billing.php`, cassa `henry`, tipo `BRIDGE`, printer `POS-80C`, `qz_host=192.168.88.224`) — **testato su Chromium, Firefox e WebKit**: solo Chromium completa la stampa, Firefox e WebKit falliscono alla connessione QZ (vedi tabella sopra). 4 vendite di test scritte sul DB in questo giro: id 113/114 (Chromium, prima e dopo il fix `display_errors`), 115 (Firefox), 116 (WebKit) — tutte €6, cassa `henry`, lasciate nel DB su autorizzazione esplicita dell'utente.
- [x] **Confermata fisicamente dall'utente**: uno scontrino "Antipasto Piem 1×6€" è uscito davvero dalla stampante (corrisponde al test Chromium riuscito).
- [x] **Stampa report statistiche via bridge QZ** (`pages/stat_vendite.php`, bottone "Stampa Scontrino Vendite") — testato solo su Chromium: stesso esito positivo, log conferma `qz.print completato senza errori`. Non ripetuto su Firefox/WebKit (stesso identico meccanismo di connessione QZ del checkout, già dimostrato fallire lì per motivi indipendenti dal tipo di stampa).
- [ ] Scenario "tablet separato dal bridge" (browser su un dispositivo diverso dalla macchina che ospita QZ Tray) — non testato: qui QZ e il browser condividevano la stessa macchina fisica, solo indirizzata con un IP di rete invece di `localhost`.
- **Edge**: non testato direttamente (non installato su questa macchina, l'installazione via Playwright richiede privilegi di amministratore non disponibili qui). Per inferenza tecnica — stesso motore Blink/Chromium di Chrome/Brave, stessa implementazione della policy mixed-content — **molto probabile** si comporti identico a Chromium (warning ma connessione riuscita). Non verificato con un test reale: se serve la controprova, va fatto su una macchina con Edge installato o con privilegi elevati.

### Decisione finale (2026-09-06): si resta su HTTP

L'architettura reale prevista (confermata dall'utente) è **un bridge QZ condiviso tra più casse** — esattamente lo scenario che su HTTPS si rompe su Firefox e WebKit. Le due strade per tenere comunque HTTPS erano:
- **QZ sempre in loopback per-cassa** — scartata, incompatibile con l'architettura a bridge condiviso.
- **`wss://` con certificato dalla CA locale di Caddy** — tecnicamente valida (vedi sotto per lo scope), ma un giorno di lavoro concentrato (conversione keystore Java, gestione IP/hostname stabile del bridge, rinnovo certificati, modifiche JS duplicate in più pagine) per un beneficio (il lucchetto, HTTP/2+3) non essenziale su un'app solo-LAN senza login.

**✅ Confermato con test reale**: stesso identico scenario (bridge QZ su `192.168.88.224`, stampante fisica) ripetuto su `http://localhost:8080` sui tre motori Chromium/Firefox/WebKit — **connessione pulita su tutti e tre, zero warning di mixed-content** (`Established connection with QZ Tray` su ognuno). La decisione è validata coi dati, non solo con la teoria. 7 vendite di test totali accumulate in questa sessione di verifica (id 113–119, cassa `henry`, €6 ciascuna) — lasciate nel DB su autorizzazione esplicita dell'utente.

**Scelta**: restare su HTTP puro per l'uso reale (`Caddyfile` ha un blocco `http://localhost:8080` accanto a quello HTTPS, che resta per riferimento/test). Elimina il problema alla radice — `ws://` da pagina HTTP non è mai mixed-content, su nessun motore — con zero modifiche al codice QZ, identico a come funziona oggi con XAMPP. Si rinuncia anche a HTTP/2/HTTP/3 (richiedono TLS), non solo al lucchetto.

### Precisazione importante: il problema è solo del bridge QZ, non di tutta l'app

**Verificato con test reale (2026-09-06)**: checkout su cassa `poop` (tipo `RETE`, stampante di rete raggiungibile direttamente, `192.168.88.22:9100`) eseguito su **HTTPS** (`https://localhost:8443`) → `{"success":true,"method":"direct"}`, nessuna menzione di QZ/websocket in console, nessun mixed-content. **Funziona perfettamente su HTTPS.**

Il motivo è strutturale: per i tipi `WIN_USB`/`LINUX_USB`/`RETE` (metodo `"direct"` in `checkout()`), è **il server PHP** a parlare con la stampante (via `escpos-php`, socket diretto), non il browser — nessun WebSocket lato client, quindi il mixed-content (che è una restrizione solo sulle connessioni che *il browser* apre da sé) non si applica mai. **Solo il metodo `"bridge_qz"` (bridge condiviso via QZ Tray) è toccato dal problema.**

Conclusione pratica, adottata: **tenere HTTP e HTTPS in parallelo stabilmente** (non solo come ripiego temporaneo), con questa indicazione per la guida/wizard di installazione (Fase 3):
- **Cassa con stampante diretta USB o di rete propria** (nessun bridge condiviso) → HTTPS va benissimo, nessuna limitazione.
- **Cassa che stampa tramite un bridge QZ condiviso con altre casse** → usare **HTTP**, altrimenti su Firefox/WebKit la stampa non parte.

Il bridge condiviso è comunque il caso meno comune (una stampante per più casse) — un avviso mirato nella documentazione/wizard risolve il problema per la minoranza di installazioni che lo usano, senza sacrificare HTTP/2/3 e il lucchetto per tutti gli altri.

### HTTPS + CA locale su Android reale: confermato end-to-end (2026-09-06)

Copiato il certificato CA generato dal servizio (`C:\WINDOWS\...\Caddy\pki\authorities\local\root.crt`) in `cert/caddy-root-ca.crt` — già scaricabile via browser con MIME type corretto (`application/x-x509-ca-cert`, riconosciuto automaticamente da Caddy per estensione).

**L'installazione automatica al tocco non funziona più sulle versioni recenti di Android** (limite voluto di Google, non un problema nostro) — serve il percorso manuale: Impostazioni → Sicurezza e privacy → Crittografia e credenziali → Installa certificato → Certificato CA → sfoglia fino al file scaricato. Dopo l'installazione Android mostra un avviso permanente ("rete monitorata" o simile) — normale, non un errore.

**Testato sul dispositivo reale (Pixel 7 Pro) dopo l'installazione manuale:**
- ✅ **Chrome**: funziona perfettamente, nessun avviso.
- ✅ **FullyKiosk** (l'app target per le casse): funziona perfettamente — risolve il dubbio aperto in Fase 2d sulle app WebView che potrebbero ignorare le CA installate dall'utente (Android 7+). Non lo fa: FullyKiosk rispetta la CA installata manualmente.
- ⚠️ **Firefox mobile**: mostra un avviso ("continua/mi fido") prima di procedere, poi funziona comunque — coerente con Firefox che (come su desktop) usa un proprio store certificati NSS invece di quello di sistema/Android.

**Conclusione**: HTTPS con CA locale è una strada pienamente percorribile per le casse a stampa diretta, verificata sull'app reale che userete (FullyKiosk), non solo in teoria. L'unico costo è l'installazione manuale del certificato una tantum per dispositivo (nessuna automazione possibile per il tap-to-install su Android moderno).

**Scope di un'eventuale integrazione `wss://` futura** (documentato per riferimento, non implementato):
- *Lato QZ Tray*: certificato in formato keystore Java (conversione da PEM via `keytool`/`openssl pkcs12`), valido per l'hostname/IP esatto del bridge (serve un IP statico o hostname LAN fisso, altrimenti si invalida ad ogni rinnovo DHCP), riavvio di QZ Tray per ricaricarlo, rinnovo manuale prima della scadenza.
- *Lato codice*: `usingSecure: false` → `true` con `port.secure:[8181]` invece di `port.insecure:[8182]`, duplicato in ogni pagina che chiama `printBridgeViaQz` (nessun modulo QZ condiviso oggi — occasione per accorparlo). Va sistemato anche il caricamento di `qz-tray.js` da `http://localhost:8182` in `conf_casse.php` (già rotto sotto HTTPS, indipendente da `wss`).
- *Lato processo*: da scriptare per N macchine-bridge se ce ne fosse più di una — diventerebbe un pezzo dello script d'installazione di Fase 3.

### Scoperta collaterale durante il test (non QZ, ma trovata testando QZ)

**PHP 8.5 (FrankenPHP) vs `escpos-php`**: `Mike42\Escpos\EscposImage::loadImageData()` genera un `Deprecated` (parametro nullable implicito, deprecato da PHP 8.4) che con `display_errors=On` finiva scritto nella risposta HTTP di `print_receipt.php`, rompendo il JSON atteso dal browser. Non succedeva su XAMPP (PHP 8.2, deprecazione non ancora esistente). **Fix applicato**: `display_errors = Off` nel php.ini di FrankenPHP (con `log_errors = On` già attivo) — conferma sul campo perché la Fase 3 userà `php.ini-production`, non `-development`, per l'installazione reale. Il bug resta latente in `escpos-php`: da considerare un aggiornamento della libreria in futuro (fuori scope ora).

Questo ha anche smascherato un **bug indipendente e preesistente** in `pages/billing.php`: il ramo `.fail()` del checkout referenziava una variabile mai dichiarata (`msg` invece di `errorMessage`, più un refuso `errorMsg`/`errorMessage`) — invisibile finché quel ramo non veniva mai raggiunto. Corretto (vedi commit di questa sessione): senza il fix, un checkout fallito per *qualsiasi* motivo avrebbe mostrato un crash JS invece di un messaggio d'errore leggibile alla cassa.

### Quando serve davvero QZ Tray (riferimento per README/guida, non più una domanda del wizard — vedi 3a)

Verificato leggendo il codice di instradamento (`config/get_printer.php`, `print/print_receipt.php::routingStampa()`), non solo per teoria:

| Scenario | Come stampa | Serve QZ? |
|---|---|---|
| **Stampante di rete** (IP proprio, Ethernet o WiFi integrato) — `tipo_stampante = RETE` | Il server manda i comandi ESC/POS via socket direttamente all'IP della stampante | ❌ Mai, indipendentemente da dove gira l'app o da chi stampa |
| **Stampante USB collegata allo stesso PC che fa girare il server** (caso tipico di "cassa indipendente") — `tipo_stampante = WIN_USB`/`LINUX_USB` | PHP scrive direttamente sulla condivisione stampante locale (es. `smb://127.0.0.1/POS-80C`, o un device path) | ❌ No, il server "vede" la USB da sé, non serve un ponte |
| **Stampante USB collegata a un PC diverso da quello che deve stampare** (server centralizzato con tablet, o una cassa che condivide la sua stampante con le altre) — `tipo_stampante = BRIDGE` | Il browser del dispositivo che stampa manda il comando via websocket a QZ Tray installato sul PC collegato fisicamente alla USB | ✅ **Sì, unico caso reale** — metodo `bridge_qz`, già testato (cassa `henry`, sopra) |
| **Bluetooth** (stampante appaiata a un telefono/tablet Android) — `tipo_stampante = BLUETOOTH` | Intent Android intercettato da RawBT | ❌ No, meccanismo separato (`genera_scontrino_bluetooth.php`) |

Nota per chi è più esperto: `WIN_USB` accetta anche un target `smb://<altro-pc>/<condivisione>` (non solo `127.0.0.1`) — tecnicamente una stampante USB su un PC diverso potrebbe essere raggiunta via condivisione stampanti nativa di Windows invece che via QZ. Non proposta come alternativa standard: richiede configurare la condivisione file/stampanti di Windows tra macchine (credenziali di rete, regole firewall dedicate), più fragile da spiegare a un organizzatore non tecnico rispetto a QZ Tray.

Fuori scope attuale: instradamento della stampa per reparto/categoria (es. "i primi in cucina, i dolci al banco") su stampanti diverse in base al prodotto — oggi ogni cassa ha **una sola** stampante configurata in `casse_stampanti`, non esiste il concetto di reparto. Non è una questione di QZ, sarebbe una funzionalità applicativa nuova.

---

## Appendice D — Git: versionamento delle modifiche

Repo locale, branch `main`, nessun remoto. Ogni fase chiude con un commit dedicato.

- [ ] Fase 0 → commit `db: migrazione DDL fuori da get_products + stock.updated_at`
- [ ] Fase 1 → commit `billing: polling condizionale con endpoint versione, backoff, pausa tab`
- [ ] Fase 2 → commit `infra: Caddyfile + note estensioni/servizio FrankenPHP (dev)`
- [ ] Fase 3 → commit `infra: script installazione per-OS (copia file) + wizard env/DB`
- [ ] Fase 3 → commit separato `chore: variabili.env fuori da git, solo .example tracciato`
- [ ] Fase 4 → commit `realtime: hub Mercure + EventSource con fallback polling`
- [ ] Il documento di piano stesso e i suoi aggiornamenti vanno committati man mano.

---

## Appendice E — Elevazione dei permessi nello script d'installazione (Windows/macOS/Linux)

Nata da una domanda legittima durante la Fase 2e: se l'installazione richiede passaggi da amministratore (installare un servizio, scrivere nel trust store di sistema), lo script di Fase 3 potrà farlo da solo, o serve rifare tutto a mano come abbiamo fatto qui passo-passo?

**Risposta: sì, uno script può farlo, su tutti e tre gli OS.** Il motivo per cui qui abbiamo proceduto un comando alla volta è che stavamo scoprendo cosa serve (es. la CA di LocalSystem, mai vista prima), non un limite di Windows/Linux/macOS. Un installer vero chiede l'elevazione **una volta sola**, poi l'intero script gira con i permessi necessari — esattamente come installare Chrome, Office, o la stessa XAMPP.

### Windows (verificato oggi)

- **Meccanismo**: un manifest che richiede `requireAdministrator`, oppure un wrapper che fa `Start-Process powershell -Verb RunAs` — un solo popup UAC, poi l'intero script gira elevato.
- **Richiede elevazione**: installare servizi Windows (FrankenPHP, MariaDB), scrivere nel trust store "Macchina locale" (`Cert:\LocalMachine\Root` — fatto oggi per il fix HTTPS del servizio), aprire porte firewall.
- **Non la richiede**: copiare i file app in una cartella scrivibile dall'utente; l'import del certificato in **Firefox** (store NSS, per-utente, non per-macchina); spesso `winget install` gestisce l'elevazione da sé.

### Linux

- **Meccanismo**: `sudo ./install.sh` (l'utente lancia lo script preceduto da `sudo`), oppure lo script si ri-esegue da solo elevato se non lo è già (`if [ "$EUID" -ne 0 ]; then exec sudo "$0" "$@"; fi` in testa allo script) — un solo prompt password, poi tutto lo script gira elevato. Per un flusso grafico, `pkexec` (PolicyKit) dà un popup simile a UAC.
- **Richiede elevazione**: pacchetti via `apt`/`dnf`/`pacman`, creare unit `systemd` in `/etc/systemd/system/`, il certificato di sistema (`update-ca-certificates` su Debian/Ubuntu, `update-ca-trust` su Fedora/RHEL scrivono in percorsi protetti), bind su porte privilegiate (<1024) salvo `setcap`, regole firewall (`ufw`/`firewalld`).
- **Non la richiede**: copiare i file nella home dell'utente; girare FrankenPHP su una porta >1024 come utente normale; un **systemd user service** (`systemctl --user`) è possibile senza root, con il limite che potrebbe non partire prima del login a meno di abilitare il "lingering" (`loginctl enable-linger`, che a sua volta può servire root a seconda della distro/policy). Firefox: stesso discorso di Windows, store NSS per-utente.

### macOS

- **Meccanismo**: `sudo ./install.sh` da terminale, oppure `osascript -e 'do shell script "..." with administrator privileges'` per un popup grafico nativo (password amministratore) — stesso pattern "un prompt, poi tutto elevato". Un vero pacchetto `.pkg` (costruito con `pkgbuild`/`productbuild`) può dichiarare i passi che richiedono privilegi e far comparire il prompt di `Installer.app` una volta sola — è l'equivalente macOS più nativo del wrapper UAC.
- **Particolarità gradita**: **Homebrew non richiede `sudo`** per la sua installazione né per la maggior parte delle formule (`brew install mariadb`, `brew install frankenphp`) — gestisce una propria cartella (`/usr/local` o `/opt/homebrew`) di proprietà dell'utente. Se l'installer usa Homebrew per FrankenPHP e MariaDB, **gran parte dello script può girare senza elevazione affatto**.
- **Richiede elevazione**: un **LaunchDaemon** di sistema (`/Library/LaunchDaemons/`, avviato per tutti gli utenti anche senza login, via `launchctl load`) e l'inserimento del certificato nel keychain **di Sistema** (`sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ...`). Un **LaunchAgent** per-utente (quello che usa di default `brew services start`, parte al login di quell'utente) non richiede root, così come il keychain di **login** (per-utente).
- Firefox: stesso store NSS per-utente delle altre due piattaforme, nessuna elevazione.

### Nota comune a tutti e tre

Su ogni OS, **Firefox tiene un proprio store di certificati separato e per-utente** — mai toccato dall'elevazione a livello di sistema, va sempre gestito a parte (stessa procedura di import manuale vista in Fase 2d, Insidia #5). È l'unico passo dell'intera installazione che, per sua natura, **non può essere automatizzato una volta per tutte**: va ripetuto per ogni profilo Firefox su ogni postazione che lo userà.

## Appendice F — Verifica finale su produzione (2026-09-07): porte 80/443 + MariaDB nativa

Dopo il cutover (XAMPP fermo, MariaDB nativa come servizio, Caddy su 80/443) e lo switch di porta, ripetuta tutta la suite di test con Playwright sulla configurazione reale finale, registrando vendite vere e stampando davvero dove serviva (autorizzato).

Script: `e2e/fase_final_verification.manual.js` (smoke test completo) + `e2e/fase_final_remaining.manual.js` (rilancio mirato dei due casi che richiedevano un fix).

### Risultati

- ✅ Carrello, filtro categorie, `sign-message.php`, `statistiche_vendite.php`, `statistiche_storni.php`, `conf_casse.php`, `get_receipt_config.php`, `open_drawer.php`, `chiudi_cassa.php` — tutti OK su HTTP, nessun errore console.
- ✅ Stampa diretta (cassa di rete, no QZ) su HTTPS.
- ✅ Stampa bridge QZ (cassa con stampante condivisa) su HTTP, sui 3 motori (Chromium, Firefox, WebKit) — conferma finale che la scelta architetturale "HTTP per le casse bridge_qz" elimina davvero il mixed content su tutti i browser, non solo su Chromium.
- ❌→✅ **Download PDF statistiche**: falliva ("Nessun contenuto ricevuto") nel flusso di download reale via Playwright. Bug vero trovato e corretto, vedi sotto.

### Bug reale trovato: PDF statistiche vuoto/rotto (non un limite di curl/Playwright)

**Sintomo**: cliccando "Scarica PDF" in `stat_vendite.php`, il file scaricato era il testo `Nessun contenuto ricevuto` (25 byte) invece del PDF.

**Causa**: `print/print_stat_pdf.php` faceva `echo "OK";` **dopo** `$dompdf->stream(...)`. `stream()` invia già una risposta HTTP completa (header `Content-Length` calcolato sui soli byte del PDF, poi il corpo). Il testo extra veniva accodato al corpo, quindi i byte effettivi superavano `Content-Length` dichiarato. I client rigorosi trattano questo come risposta malformata:
- `fetch()` del browser: `net::ERR_CONTENT_LENGTH_MISMATCH`, la promise va in reject.
- `curl`: `transfer closed with N bytes remaining to read` — **questo è lo stesso fenomeno già osservato ed erroneamente attribuito, durante il debug della Fase 2d, a un limite di curl con risposte `Content-Disposition: attachment`.** Non era un limite di curl: era questo bug, e curl lo segnalava correttamente.

Diagnosticato mettendo un log lato server (`var_export($_POST, ...)` su file) dentro `print_stat_pdf.php` e osservando, durante il click reale via Playwright, **due richieste POST**: la prima con `$_POST['htmlContent']` popolato (quella genera il PDF vero e invia la risposta tagliata), la seconda con `$_POST` vuoto — quest'ultima è il risultato del download-manager di Chrome che, di fronte a una risposta POST con `Content-Disposition: attachment` percepita come incompleta/malformata, ripete la richiesta per "materializzare" il salvataggio; la ripetizione non porta con sé il body originale.

**Fix applicato (commit `f80764a`)**:
1. Rimossa la `echo "OK";` superflua in `print_stat_pdf.php` — `stream()` chiude già la risposta.
2. `exportPdf()` in `stat_vendite.php` migrato da form-submit nativo (POST + navigazione del browser, con `<form id="pdfForm">` e hidden input ora inutili e rimossi) a `fetch()` + `blob()` + `<a download>` sintetico: la richiesta è unica, gestita interamente lato client, e non dipende più dal comportamento (a volte fragile, e ora comunque corretto a monte) del download-manager del browser su risposte POST.

**Lezione**: un bug del genere può restare invisibile per anni con XAMPP/Apache se il client di test (o l'utente) non nota mai la discrepanza di pochi byte — betrayed solo testando il *download reale* end-to-end (Playwright con `waitForEvent('download')` + lettura dei byte scaricati), non con richieste HTTP dirette che ignorano l'esatta corrispondenza di `Content-Length`. Motivo in più per preferire questo tipo di test rispetto a un semplice controllo "risponde 200".



- [x] **Fase 0** ✅ — migrazione DDL + `stock.updated_at` + indice; rimosse le query DDL da `get_products.php`; `pos.sql` aggiornato. Commit `ca260d0`.
- [x] **Fase 1** ✅ — `api/products_version.php`; loop condizionale in `billing.php` con guardia in-flight, pausa a tab nascosto, backoff, merge array, init categorie una-tantum. **Verificato end-to-end in Chromium reale**: cadenza 6s a riposo, filtro categoria non sovrascritto, pausa a tab nascosta, ripresa immediata al ritorno, nessun errore console.
- [x] **Fase 2** ✅ — FrankenPHP classic sul PC dev: estensioni + gate, `Caddyfile` (HTTP+HTTPS in parallelo), smoke test 2d (incluso test di stampa reale su 3 browser), servizio WinSW (HTTP e HTTPS via servizio entrambi verificati — HTTPS richiedeva l'import della CA di LocalSystem nello store Macchina locale, vedi 2e).
- [x] **Cutover + verifica finale** ✅ (2026-09-07) — XAMPP fermo, MariaDB nativa in produzione, porte standard 80/443, phpMyAdmin servito da Caddy. Suite Playwright completa ripetuta sulla configurazione reale (vendite e stampe reali): smoke test, stampa diretta HTTPS, stampa bridge QZ HTTP sui 3 motori, export PDF — tutto ✅. Trovato e corretto un bug reale preesistente (non introdotto dalla migrazione): PDF statistiche vuoto per un `echo` di troppo dopo `dompdf->stream()`, vedi Appendice F.
- [~] **Fase 3** *(in corso)* — script d'installazione per-OS (solo Windows per ora) che **copia i file** (niente binario). Il nucleo dell'installazione è a **zero domande** (indipendente/server erano la stessa identica installazione, la domanda è stata tolta — vedi 3a): `install.ps1` installa sempre la stessa base funzionante, l'architettura (diventare client di un altro server) si decide dopo, in qualsiasi momento, dalla pagina Configurazione Rete. QZ Tray incluso nello stesso script e installato **sempre**, nessuna domanda (chi non ne ha bisogno lo disinstalla); HTTPS sempre in parallelo di default, nessuna domanda. Percorso d'installazione fisso di default (`C:\opensagra`). Aspetto grafico deciso: **WPF via PowerShell**, compilato in `.exe` con `ps2exe` per nascondere la console (vedi sotto) — nessuna domanda da porre nemmeno lì, essendo un'installazione automatica end-to-end. Fatto finora: pulizia repo (`docker/` rimossa, `variabili.env` fuori da git, `DB_POS_SID` residuo rimosso), `crea_dbtable_and_user.php` eseguibile da CLI con exit code corretto (incluso privilegio `PROCESS` per il rafforzamento in Rete), pagina "Configurazione Rete" in-app per cambiare `DB_POS_HOST` senza toccare file, con avviso basato su connessioni reali (testata con Playwright, da riconfermare con una macchina Linux vera). **`install.ps1` — prima bozza scritta e in parte verificata (2026-09-07, commit `c85d760`)**: tutti i passi presenti (FrankenPHP+MariaDB, estensioni+gate, migrazioni, `Caddyfile`, le due regole firewall esplicite dell'Insidia #6, servizi, QZ sempre incluso, finestra WPF in thread separato). Verificato isolando le funzioni (senza eseguire l'orchestrazione sulla macchina di produzione): meccanismo GUI a thread separato con aggiornamento reale nel tempo (screenshot autentici), generazione `Caddyfile`/`variabili.env` corretta e idempotente, riconoscimento di installazioni/servizi già presenti, regole firewall idempotenti (confermato in sessione elevata: una sola regola, non duplicata). **Non verificabile in questa sessione** (nessuna macchina pulita/VM disponibile): il ramo di installazione "da zero" di FrankenPHP/MariaDB/WinSW/QZ Tray — da ritestare per intero su una macchina davvero vergine prima del rilascio. Resta da fare: packaging con `ps2exe`, README.
- [ ] **Fase 4** *(opzionale)* — hub Mercure nel `Caddyfile`, `POST` degli update su mutazioni, `EventSource` in `billing.php` con fallback al polling.
- [ ] **QZ / HTTPS** *(Appendice C, solo test in Fase 2)* — verificare che i popup non riappaiano; mappare i casi mixed-content; nessuna modifica al codice QZ ora.
- [ ] **Git** *(Appendice D)* — un commit per fase; il piano si committa man mano.
