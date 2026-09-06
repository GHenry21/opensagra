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
- **Correzione (2026-09-06)**: la riga precedente parlava di una "MariaDB nativa" installata separatamente — non è così. Verificato passo passo (servizi, processi, porta 3306): `mysql`/`php` sono **XAMPP registrato come servizio Windows** (comodità di avvio automatico, non serve più aprire il pannello XAMPP), non un'installazione MariaDB indipendente. È tuttora `C:\xampp\mysql\bin\mysqld.exe` a rispondere su `127.0.0.1:3306` con tutti i dati reali. Una MariaDB davvero separata dall'albero XAMPP resta da fare **in Fase 3** (installazione da zero), non è ancora stata fatta.
- [x] DB `opensagra_pos` presente e popolato — su XAMPP (via servizio Windows), raggiungibile da `127.0.0.1:3306` come sempre. `config/variabili.env` non richiede modifiche per la Fase 2.

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

  http://localhost:8080 {
  	root * C:\xampp\htdocs\opensagra
  	encode zstd gzip
  	php_server
  }

  https://localhost:8443 {
  	root * C:\xampp\htdocs\opensagra
  	encode zstd gzip
  	php_server
  	tls internal
  }
  ```
  (creato con `Out-File -Encoding ascii`, non `utf8`, per evitare il BOM visto sopra)

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
- [ ] Test di riavvio effettivo della macchina — non eseguito (avrebbe richiesto un reboot durante la sessione di lavoro); `StartMode: Auto` è comunque la garanzia standard di Windows per l'avvio automatico dei servizi, non serve una controprova empirica per fidarsene.

**Accettazione:** i flussi HTTP e HTTPS di Fase 2d funzionano identici attraverso il servizio, senza terminale aperto — **entrambi verificati**.

---

## Fase 3 — Script d'installazione per-OS

Un solo entry point (`install.ps1` su Windows, `install.sh` su macOS/Linux, o un unico script che rileva l'OS) che porta una macchina pulita ad app funzionante in HTTPS.

**Modello di distribuzione:** niente binario embed. Il pacchetto di release contiene i file di opensagra (con `vendor/`) + questo script. Lo script installa i prerequisiti (FrankenPHP, MariaDB), **copia i file** nella cartella di destinazione, genera i config e registra il servizio. Il codice resta in chiaro e modificabile sul posto (open source).

> L'embed di FrankenPHP è stato valutato e **scartato**: dato che lo script d'installazione serve comunque (MariaDB, QZ, domande, servizio), il guadagno del binario unico è marginale, mentre la build cross-OS — soprattutto Windows — aggiunge una pipeline da mantenere. La copia dei file è più semplice e coerente con "codice aperto e ispezionabile".

### 3a. Wizard: cosa automatizza (scope ridotto)

> ⚠️ **BOZZA — da rivedere.** I testi e il numero di domande qui sotto **così non vanno bene**: primo abbozzo per fissare l'idea. Da riformulare (testo per non esperti, default, casi mancanti) quando si implementa la Fase 3.

**Principio:** il codice di opensagra è **sempre spedito completo e identico**, tutte le funzioni presenti. Il wizard **non** abilita/disabilita funzionalità. Sostituisce solo due passi oggi manuali:

1. **Generazione di `config/variabili.env`** (oggi: creato/editato a mano copiando `variabili.env.example`).
2. **Provisioning del DB**: creare database, tabelle e utente (oggi: si lancia `config/crea_dbtable_and_user.php`).

Il file `config/variabili.env` resta in ogni caso un **file di testo normale, accessibile e modificabile** dopo l'installazione (l'app lo rilegge a ogni richiesta via `config/get_db_connection.php`). Il wizard lo scrive, non lo "nasconde".

Serve anche una modalità non interattiva (`--answers file.json` o parametri) per reinstallazioni ripetibili.

#### Domanda 1 — Architettura: cassa indipendente o server centralizzato?

> **Ogni cassa indipendente.** Ogni PC/tablet cassa fa girare per conto suo l'applicazione e il suo database. Le casse non si parlano tra loro.
> - ✅ Non serve rete tra le postazioni; se una cassa si guasta o la rete cade, le altre continuano a lavorare.
> - ❌ I dati NON sono condivisi: statistiche separate per cassa, catalogo prodotti da aggiornare a mano su ogni postazione, chiusura cassa una per una.
> - 🟢 Adatto a: sagra piccola, 1–3 casse vicine con stampante propria, nessuna necessità di totali aggregati in tempo reale.
>
> **Server centralizzato.** Un solo PC fa da server (applicazione + database). Le casse sono solo schermi (tablet/PC col browser) che puntano a `https://<server>.local`.
> - ✅ Un unico catalogo prodotti, statistiche e totali aggregati in tempo reale, una sola installazione da aggiornare.
> - ❌ Se cade il server o la rete, tutte le casse si fermano. Serve una LAN affidabile (cablata dove possibile).
> - 🟢 Adatto a: sagra media/grande, più punti vendita, si vuole la vista unica delle vendite.

Effetto sullo script:

| Scenario | `config/variabili.env` | Provisioning DB | App + FrankenPHP |
|---|---|---|---|
| **Indipendente** (ogni PC a sé) | `DB_POS_HOST=127.0.0.1` + utente/password (default o generati) | eseguito **in locale** su ogni macchina | copia file + FrankenPHP + MariaDB locale; `Caddyfile` su `localhost` |
| **Centralizzato — server** | `DB_POS_HOST=127.0.0.1` (il server parla col suo DB in locale) | eseguito **una volta sul server**; utente app anche per host `%`/subnet LAN; `bind-address` MariaDB sulla LAN | copia file + FrankenPHP + MariaDB; `Caddyfile` con hostname di rete; porta 443 sul firewall |
| **Centralizzato — cassa** | *(opzionale)* solo se quella postazione esegue anche codice PHP proprio; altrimenti **nessun `.env`**: la cassa è solo un browser verso `https://<server>` | nessuno | nessuna copia app; salva l'URL del server; QZ solo se serve; opzionale kiosk del browser |

- [ ] Se *indipendente*: il wizard chiede solo conferma; scrive `DB_POS_HOST=127.0.0.1`.
- [ ] Se *centralizzato*: sul **server** scrive `127.0.0.1`; sulle **casse** (se hanno una loro app) chiede **l'IP del server** e lo scrive in `DB_POS_HOST=<ip>`. L'utente può correggerlo a mano nel file in qualsiasi momento.
- [ ] Password DB: per `127.0.0.1` va bene un default noto; per accesso via LAN il wizard **genera una password casuale** e la scrive sia nell'`.env` sia nel provisioning.

> ⚠️ Combinazione delicata: **centralizzato + stampanti USB sui tablet**. La pagina è servita in HTTPS da un altro host, quindi il browser blocca `ws://` verso un QZ non-loopback. Vedi **Appendice C** — da testare prima di prometterla.

#### Domanda 2 — Serve QZ Tray?

> QZ Tray è il programma che permette al browser di stampare sugli **scontrini** collegati **via USB** a quel PC/tablet Windows (o di pilotare alcune stampanti di rete tramite esso).

> Rispondi **Sì** se: le stampanti scontrini sono collegate **via USB** a un PC/tablet.
> Rispondi **No** se: le stampanti sono di **rete con indirizzo IP** (gestite direttamente dal server via `print/*.php`), sono **Bluetooth** (`genera_scontrino_bluetooth.php`), oppure non si stampano scontrini (solo PDF/schermo).

Effetto sullo script:
- [ ] *Sì*: scarica/installa QZ Tray; applica la procedura certificati **esistente e non modificata** (vedi **Appendice C**): copia `cert/cert.pem` come override, posiziona la chiave privata in `../../../private/key.pem`, imposta `wss.host=0.0.0.0` in `qz-tray.properties` se il PC deve accettare connessioni da altri dispositivi della LAN. Riavvia QZ Tray.
- [ ] *No*: salta del tutto la parte QZ.

#### Domanda 3 — HTTPS: solo CA locale o dominio di rete?

> Per far sparire l'avviso "sito non sicuro" sui tablet serve che il certificato sia considerato fidato.

> **CA locale (default).** Caddy genera una propria autorità; va installato **una volta** il certificato radice su ogni tablet. Semplice, offline, nessun costo.
> **Dominio + DNS di rete.** Se hai un dominio interno gestito (es. `cassa.sagra.lan` su un DNS locale), Caddy può usarlo direttamente. Più pulito ma richiede infrastruttura DNS.

Effetto sullo script:
- [ ] *CA locale*: `tls internal` nel `Caddyfile`; `caddy trust` sul server; genera un pacchetto `root-CA.crt` + istruzioni per i tablet.
- [ ] *Dominio*: chiede il nome host; lo scrive nel `Caddyfile`.

### 3b. Rilevamento

- [ ] OS + package manager: `winget`/Chocolatey (Windows), `brew` (macOS), `apt`/`dnf`/`pacman` (Linux).

### 3c. Installazione software

- [ ] **FrankenPHP**:
  - Windows: `irm https://frankenphp.dev/install.ps1 | iex` (o download archivio + PHP ufficiale Windows).
  - macOS: `brew install dunglas/frankenphp/frankenphp` o `curl https://frankenphp.dev/install.sh | sh`.
  - Linux: `curl https://frankenphp.dev/install.sh | sh`.
- [ ] **MariaDB**:
  - Windows: MSI silent (`msiexec /i ... /qn` con `SERVICENAME`, `PASSWORD`).
  - macOS: `brew install mariadb` + `brew services start mariadb`.
  - Linux: pacchetto distro + `systemctl enable --now mariadb`.
- [ ] **Composer**: non serve sul target se `vendor/` è nel pacchetto di release (default). Serve solo per rigenerare le dipendenze in fase di build del pacchetto: `composer install --no-dev --optimize-autoloader`.

### 3d. Estensioni PHP

- [ ] Applicare lo script idempotente di **Appendice A** al `php.ini` in uso (`frankenphp php-cli --ini`).
- [ ] **Gate**: `frankenphp php-cli -m` deve contenere tutte le required, altrimenti abort con messaggio chiaro.
- [ ] **Caveat binario statico Linux/Mac**: se `gd`/`intl`/`curl` non sono nel bundle statico, usare l'immagine Docker FrankenPHP **solo sul server** o un build custom. Documentare nel README dello script.

### 3e. Database — riusa lo script esistente

**Script già presente:** `config/crea_dbtable_and_user.php`. Oggi fa già:
- legge `config/variabili.env` (via `get_db_connection.php`);
- si connette come `root` e crea il database `opensagra_pos` se manca;
- importa `config/pos.sql` **solo se non ci sono già tabelle** (idempotente);
- crea l'utente app per gli host `localhost`, `127.0.0.1` e `%` con `GRANT ALL` sul db + `FLUSH PRIVILEGES`.

**Adattamenti per il wizard/installer:**
- [ ] Renderlo eseguibile anche da **CLI** (`php config/crea_dbtable_and_user.php`), non solo via browser (oggi stampa HTML `<br>`): output pulito + `exit code` ≠ 0 su errore.
- [ ] Parametrizzare la connessione root: oggi è hardcoded `new mysqli($db_host, 'root', '')` (stile XAMPP). Accettare **password di root** e host da parametro/env per i server reali.
- [ ] **Indipendente**: eseguirlo in locale su ogni macchina (root su `127.0.0.1`). L'utente `%` che già crea è innocuo in locale.
- [ ] **Centralizzato**: eseguirlo **solo sul server**; le casse non lo lanciano. Verificare che l'utente `%` (già creato) sia adeguato o restringerlo alla subnet LAN.
- [ ] Dopo l'import schema, eseguire le migrazioni di `config/migrations/` (Fase 0: `stock.updated_at`, indice, ecc.).
- [ ] *Solo centralizzato*: `bind-address` MariaDB sulla LAN, porta 3306 aperta solo verso la LAN.
- [ ] Allineare l'incoerenza minore: `variabili.env` ha `DB_POS_SID`/`DB_POS_HOST` mentre il nome DB è hardcoded `opensagra_pos` in `get_db_connection.php` — decidere se il nome DB diventa anch'esso una variabile.

### 3f. File e config

- [ ] Copiare i file di opensagra nel path target dell'OS (`C:\opensagra`, `/opt/opensagra`, `/usr/local/opensagra`...), `vendor/` incluso.
- [ ] Generare `Caddyfile` (root = path target; `localhost` o hostname di rete secondo la Domanda 1; `tls internal` o dominio secondo la Domanda 3).
- [ ] Generare `config/variabili.env` dalle risposte del wizard (`DB_POS_HOST`, `DB_POS_USER`, `DB_POS_PASS`), partendo da `config/variabili.env.example`. **Lasciarlo come file di testo leggibile/modificabile.**
- [ ] Permessi cartella `uploads/` scrivibile dal processo FrankenPHP.

### 3g. Servizi

- [ ] Windows: WinSW (`frankenphp-service.exe install/start`).
- [ ] macOS: plist launchd o `brew services`.
- [ ] Linux: unit `systemd` (`frankenphp.service`) con `Restart=on-failure`.

### 3h. HTTPS / certificati

- [ ] `frankenphp` con `tls internal` genera la CA locale.
- [ ] `caddy trust` (o equivalente) per fidarsi della CA sulla macchina server.
- [ ] Produrre `root-CA.crt` + procedura documentata per installarlo sui **tablet** (una volta per dispositivo) — oppure dominio reale + DNS di rete.
- [ ] **Interazione con QZ Tray**: annotare che una pagina in HTTPS + `ws://` verso un QZ non-loopback viene bloccata (mixed-content). Vedi **Appendice C** per i test. Nessuna modifica ora.

### 3i. QZ Tray (solo se Domanda 2 = Sì) — procedura esistente, non modificata

- [ ] Installare QZ Tray sul PC/tablet a cui è collegata la stampante USB.
- [ ] Copiare `cert/cert.pem` come certificato di **override** nella cartella di QZ Tray (soppressione popup).
- [ ] Posizionare la chiave privata in `../../../private/key.pem` (fuori dalla webroot) per `api/sign-message.php`.
- [ ] Se il PC deve ricevere connessioni da altri dispositivi LAN: `wss.host=0.0.0.0` in `qz-tray.properties`.
- [ ] Riavviare QZ Tray e verificare (test di **Appendice C**).
- [ ] **Non toccare** `qz-helper.js`, `sign-message.php`, le chiamate `qz.websocket.connect` né i certificati: eventuali adeguamenti a HTTPS sono una fase separata post-test.

### 3j. Pulizia

- [ ] Rimuovere la cartella `docker/` dal repo (strada abbandonata) e ogni riferimento.
- [ ] **Open source**: `config/variabili.env` è oggi **tracciato** con credenziali (`pos_own`/`pos_own1`). Aggiungere `config/variabili.env` al `.gitignore` (oggi ignora solo `.env`), `git rm --cached config/variabili.env`, tracciare solo `variabili.env.example`. Idem `docker/.env`.
- [ ] Aggiornare il README con: prerequisiti, comando unico di install, come aggiornare (`git pull` + migrazioni + `frankenphp reload`; `composer install` solo se non si usa il pacchetto con `vendor/`).

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
"@
# iconv, dom e OPcache sono gia' compilati dentro questa distribuzione: nessuna riga necessaria.

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

**Scope di un'eventuale integrazione `wss://` futura** (documentato per riferimento, non implementato):
- *Lato QZ Tray*: certificato in formato keystore Java (conversione da PEM via `keytool`/`openssl pkcs12`), valido per l'hostname/IP esatto del bridge (serve un IP statico o hostname LAN fisso, altrimenti si invalida ad ogni rinnovo DHCP), riavvio di QZ Tray per ricaricarlo, rinnovo manuale prima della scadenza.
- *Lato codice*: `usingSecure: false` → `true` con `port.secure:[8181]` invece di `port.insecure:[8182]`, duplicato in ogni pagina che chiama `printBridgeViaQz` (nessun modulo QZ condiviso oggi — occasione per accorparlo). Va sistemato anche il caricamento di `qz-tray.js` da `http://localhost:8182` in `conf_casse.php` (già rotto sotto HTTPS, indipendente da `wss`).
- *Lato processo*: da scriptare per N macchine-bridge se ce ne fosse più di una — diventerebbe un pezzo dello script d'installazione di Fase 3.

### Scoperta collaterale durante il test (non QZ, ma trovata testando QZ)

**PHP 8.5 (FrankenPHP) vs `escpos-php`**: `Mike42\Escpos\EscposImage::loadImageData()` genera un `Deprecated` (parametro nullable implicito, deprecato da PHP 8.4) che con `display_errors=On` finiva scritto nella risposta HTTP di `print_receipt.php`, rompendo il JSON atteso dal browser. Non succedeva su XAMPP (PHP 8.2, deprecazione non ancora esistente). **Fix applicato**: `display_errors = Off` nel php.ini di FrankenPHP (con `log_errors = On` già attivo) — conferma sul campo perché la Fase 3 userà `php.ini-production`, non `-development`, per l'installazione reale. Il bug resta latente in `escpos-php`: da considerare un aggiornamento della libreria in futuro (fuori scope ora).

Questo ha anche smascherato un **bug indipendente e preesistente** in `pages/billing.php`: il ramo `.fail()` del checkout referenziava una variabile mai dichiarata (`msg` invece di `errorMessage`, più un refuso `errorMsg`/`errorMessage`) — invisibile finché quel ramo non veniva mai raggiunto. Corretto (vedi commit di questa sessione): senza il fix, un checkout fallito per *qualsiasi* motivo avrebbe mostrato un crash JS invece di un messaggio d'errore leggibile alla cassa.

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

## Riepilogo checklist di alto livello

- [x] **Fase 0** ✅ — migrazione DDL + `stock.updated_at` + indice; rimosse le query DDL da `get_products.php`; `pos.sql` aggiornato. Commit `ca260d0`.
- [x] **Fase 1** ✅ — `api/products_version.php`; loop condizionale in `billing.php` con guardia in-flight, pausa a tab nascosto, backoff, merge array, init categorie una-tantum. **Verificato end-to-end in Chromium reale**: cadenza 6s a riposo, filtro categoria non sovrascritto, pausa a tab nascosta, ripresa immediata al ritorno, nessun errore console.
- [x] **Fase 2** ✅ — FrankenPHP classic sul PC dev: estensioni + gate, `Caddyfile` (HTTP+HTTPS in parallelo), smoke test 2d (incluso test di stampa reale su 3 browser), servizio WinSW (HTTP e HTTPS via servizio entrambi verificati — HTTPS richiedeva l'import della CA di LocalSystem nello store Macchina locale, vedi 2e).
- [ ] **Fase 3** — script d'installazione per-OS che **copia i file** (niente binario). Wizard a scope ridotto: genera `variabili.env` (architettura indipendente/centralizzata + IP server) e fa il provisioning DB riusando/adattando `config/crea_dbtable_and_user.php`; + QZ sì/no e HTTPS CA locale/dominio. Install: FrankenPHP + MariaDB, estensioni + gate, migrazioni, `Caddyfile`, servizi, HTTPS/CA, QZ (procedura esistente), rimozione `docker/`, `variabili.env` fuori da git, README.
- [ ] **Fase 4** *(opzionale)* — hub Mercure nel `Caddyfile`, `POST` degli update su mutazioni, `EventSource` in `billing.php` con fallback al polling.
- [ ] **QZ / HTTPS** *(Appendice C, solo test in Fase 2)* — verificare che i popup non riappaiano; mappare i casi mixed-content; nessuna modifica al codice QZ ora.
- [ ] **Git** *(Appendice D)* — un commit per fase; il piano si committa man mano.
