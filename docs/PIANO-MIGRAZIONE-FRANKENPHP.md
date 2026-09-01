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
| **HTTPS** | Automatico via FrankenPHP/Caddy. Sui tablet: installare la CA locale una volta. |
| **Realtime (Mercure/SSE)** | Rimandato. L'hub è già dentro il binario FrankenPHP: si attiva quando/se serve (Fase 4). |
| **Worker mode** | Ottimizzazione futura opzionale. Scope e stima in Appendice B. |
| **QZ Tray** | Stampa da stampanti USB via browser. La procedura certificati/firma attuale (openssl + override + `sign-message.php`) **resta invariata** in questa migrazione. Con Caddy/HTTPS vanno però verificati alcuni punti di mixed-content: vedi **Appendice C**. Nessuna modifica al codice QZ ora. |
| **Wizard d'installazione** | Lo script pone domande in linguaggio semplice (architettura, QZ, HTTPS) e configura di conseguenza: vedi **Fase 3a**. |
| **Versionamento** | Ogni modifica va committata su git (repo locale, branch `main`). |

### Perché FrankenPHP classic + MariaDB nativa (oltre a HTTPS automatico)

- HTTP/2 + HTTP/3 (QUIC): meno stalli su wifi da sagra, recupero migliore quando un tablet cambia access point.
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

## Fase 0 — Fix DB (indipendente, da fare per prima)

Vale qualunque sia il web server. Sblocca il collo di bottiglia attuale.

- [ ] Creare `config/migrations/` con uno script eseguito **una sola volta** (registro migrazioni in una tabella `schema_migrations`, o check manuale):
  - [ ] `ALTER TABLE stock ADD COLUMN IF NOT EXISTS quantity_available INT NULL DEFAULT NULL AFTER price;`
  - [ ] `ALTER TABLE stock ADD COLUMN IF NOT EXISTS item_sort INT NULL AFTER image_path;`
  - [ ] `UPDATE stock SET item_sort = id WHERE item_sort IS NULL;`
  - [ ] `ALTER TABLE stock ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp();`
  - [ ] backfill: `UPDATE stock SET updated_at = COALESCE(created_at, NOW());`
  - [ ] indice per il polling: `ALTER TABLE stock ADD INDEX idx_stock_updated_at (updated_at);`
- [ ] Rimuovere le righe 6–8 di `api/get_products.php` (le tre query DDL/UPDATE).
- [ ] Aggiornare `config/pos.sql` con la nuova colonna `updated_at`, l'indice e la rimozione del disallineamento (così una install pulita nasce già corretta).
- [ ] Verificare che gli endpoint che scrivono su `stock` (`insert_product.php`, `update_product.php`, `soft_delete_product.php`, `update_category.php`) tocchino righe di `stock` → `updated_at` si aggiorna da solo grazie a `ON UPDATE`.

**Accettazione:** `api/get_products.php` risponde identico a prima; nel general query log di MariaDB non compaiono più `ALTER TABLE` a ogni chiamata; `SELECT MAX(updated_at) FROM stock` cambia solo dopo una modifica prodotto.

---

## Fase 1 — Polling condizionale (Strategia A) in `billing.php`

### 1a. Endpoint "versione"

- [ ] Nuovo `api/products_version.php`:
  - `SELECT COALESCE(UNIX_TIMESTAMP(MAX(updated_at)), 0) AS version, COUNT(*) AS count FROM stock WHERE is_active = 1;`
  - risposta: `{ "version": <int>, "count": <int> }` — nessun payload prodotti.
  - `Content-Type: application/json`; opzionale `Cache-Control: no-store`.

### 1b. Refactor del loop in `billing.php`

Riferimenti attuali: `setInterval` a [billing.php:1725](../pages/billing.php#L1725), `loadProducts()` a [billing.php:1299](../pages/billing.php#L1299), `clearInterval` già presente in `beforeUnmount` a [billing.php:1765](../pages/billing.php#L1765).

- [ ] Il `setInterval` chiama un nuovo metodo `pollProductsVersion()`, non più `loadProducts()`.
- [ ] `pollProductsVersion()`:
  - [ ] chiama `api/products_version.php`;
  - [ ] confronta `version` **e** `count` con gli ultimi valori salvati in `data` (es. `_lastProductsVersion`, `_lastProductsCount`);
  - [ ] chiama `loadProducts()` **solo** se differiscono (o al primo giro, quando i valori sono `null`);
  - [ ] dopo un `loadProducts()` andato a buon fine, aggiorna i valori salvati.
- [ ] Intervallo da `3000` → `5000`–`8000` ms.
- [ ] **Guardia "richiesta in corso"**: flag `_pollInFlight`; se `true`, il tick corrente esce subito.
- [ ] **Pausa a tab nascosto**: listener `visibilitychange`; se `document.visibilityState === 'hidden'` il tick esce subito; al ritorno a `visible` esegue un `pollProductsVersion()` immediato.
- [ ] **Backoff su errore**: su `fail`, salta i tick successivi con ritardo crescente `5s → 10s → 20s → 40s` (cap `60s`); al primo successo torna all'intervallo normale.
- [ ] In `loadProducts()`: **merge** nell'array `this.products` invece di riassegnarlo interamente (aggiorna/aggiunge/rimuove per `id`), così Vue non ri-renderizza tutta la griglia a ogni refresh.
- [ ] Spostare l'inizializzazione di `categoryFilterMode` / `selectedCustomCategories` / la chiamata a `saveCategoryPreference()` **fuori** dal callback di `loadProducts()`: eseguirla **una volta sola** dopo il primo caricamento riuscito (flag `_categoriesInitialized`).
- [ ] Verificare che `beforeUnmount` pulisca anche il nuovo listener `visibilitychange` e gli eventuali timer di backoff.

**Accettazione:**
- Con prodotti fermi: la tab Network mostra solo `products_version.php` (~30 byte) ogni 5–8 s; nessun re-render della griglia prodotti (verificabile con Vue devtools / flash di paint in DevTools).
- Modificando un prodotto da un'altra postazione: la griglia si aggiorna entro un ciclo di polling.
- Tab in background: nessuna richiesta parte. Server spento: le richieste rallentano fino a 60 s invece di martellare ogni 5 s.
- Cambio filtro categoria da parte dell'utente: non viene sovrascritto dal polling.

---

## Fase 2 — FrankenPHP classic mode sul PC di sviluppo

Obiettivo: far girare l'app identica a XAMPP, su `https://localhost`, con FrankenPHP + MariaDB, prima di toccare la produzione.

### 2a. Prerequisiti

- [ ] `frankenphp version` risponde (già installato via `irm https://frankenphp.dev/install.ps1 | iex`).
- [ ] MariaDB locale disponibile (per ora va bene quella di XAMPP; in alternativa installazione nativa). DB `opensagra_pos` presente e popolato.

### 2b. Estensioni PHP

Lista chiusa, ricavata dal codice app + `require` dei vendor (dettaglio e snippet in **Appendice A**):

`mysqli`, `mbstring`, `gd`, `zip`, `intl`, `curl`, `openssl`, `iconv`, `dom`, `fileinfo` + `opcache` (perf).

- [ ] `frankenphp php-cli -m` → elenco estensioni attive.
- [ ] `frankenphp php-cli --ini` → individua il `php.ini` in uso (crearlo da `php.ini-production` se assente).
- [ ] Abilitare le mancanti in `php.ini` (script idempotente in Appendice A).
- [ ] **Gate di verifica**: rieseguire `frankenphp php-cli -m` e fallire se manca una required.

### 2c. Configurazione app

- [ ] `Caddyfile` minimale in root progetto:

  ```
  {
      frankenphp
      # log JSON su file, opzionale
  }

  localhost {
      root * C:\xampp\htdocs\opensagra
      encode zstd br gzip
      php_server
      tls internal
  }
  ```
- [ ] `composer install` (dipendenze: `mike42/escpos-php`, `dompdf/dompdf`).
- [ ] `config/variabili.env`: `DB_POS_HOST=127.0.0.1` (già default nel codice se il file manca — vedi `config/get_db_connection.php`).
- [ ] `frankenphp run --config Caddyfile` e primo smoke test manuale.

### 2d. Smoke test completo (parità con XAMPP)

- [ ] `pages/billing.php`: caricamento prodotti, ricerca, filtri categoria, aggiunta al carrello, sconti riga/totale.
- [ ] Checkout completo per ogni metodo pagamento abilitato (contanti/carta/Satispay).
- [ ] Stampa scontrino **ESC/POS USB** (`print/print_receipt.php`, `print/print_last_receipt.php`).
- [ ] Stampa via **bridge / rete** (`print/print_receipt_bridge.php`, `print/print_stat_receipt.php`) — usa `curl`.
- [ ] Firma popup **QZ Tray** (`api/sign-message.php`) — usa `openssl` + chiave in `../../../private/key.pem`.
- [ ] **QZ Tray sotto HTTPS**: servendo l'app da `https://localhost`, verificare che i popup di consenso QZ **non riappaiano** e che la stampa USB funzioni ancora. Eseguire i test elencati in **Appendice C** (mixed-content su `ws://`, load di `qz-tray.js` da `http://`). Non modificare nulla lato QZ: solo osservare e annotare.
- [ ] **PDF dompdf** (`print/print_stat_pdf.php`) — usa `gd`, `dom`, `mbstring`, `zip`.
- [ ] Statistiche vendite / storni (`pages/stat_vendite.php`, `api/statistiche_*.php`).
- [ ] Config stampanti e scontrini (`pages/conf_stampanti.php`, `api/save_receipt_config.php`, `api/get_receipt_config.php`).
- [ ] Apertura cassetto (`api/open_drawer.php`), chiusura cassa (`api/chiudi_cassa.php`).
- [ ] Upload immagini prodotto (cartella `uploads/`).
- [ ] Sessioni PHP (`session_start`) — login/stato cassa persistono tra richieste.

### 2e. Servizio Windows

- [ ] Scaricare WinSW come `frankenphp-service.exe` accanto a `frankenphp.exe`.
- [ ] `frankenphp-service.xml`:

  ```xml
  <service>
    <id>frankenphp</id>
    <name>FrankenPHP</name>
    <description>opensagra app server</description>
    <executable>%BASE%\frankenphp.exe</executable>
    <arguments>run --config %BASE%\Caddyfile</arguments>
    <log mode="roll-by-time"><pattern>yyyy-MM-dd</pattern></log>
  </service>
  ```
- [ ] `.\frankenphp-service.exe install` && `.\frankenphp-service.exe start`.
- [ ] Test modifica config a caldo: `.\frankenphp.exe reload --config Caddyfile` (i servizi Windows non si "reloadano").

**Accettazione:** tutti i flussi del punto 2d funzionano su `https://localhost` come sotto XAMPP; XAMPP può restare spento; il servizio riparte al boot.

---

## Fase 3 — Script d'installazione per-OS

Un solo entry point (`install.ps1` su Windows, `install.sh` su macOS/Linux, o un unico script che rileva l'OS) che porta una macchina pulita ad app funzionante in HTTPS.

### 3a. Wizard: domande all'utente

Prima di installare, lo script pone alcune domande in **linguaggio semplice**, con spiegazione inline. Le risposte determinano cosa installare e come generare i config. Deve avere anche una modalità non interattiva (`--answers file.json`) per reinstallazioni ripetibili.

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
- [ ] *Indipendente*: installa FrankenPHP **+ MariaDB locale** su ogni macchina; `DB_POS_HOST=127.0.0.1`; `Caddyfile` su `localhost`.
- [ ] *Centralizzato — server*: installa FrankenPHP **+ MariaDB**; `Caddyfile` con hostname di rete; apre la porta 443 sul firewall; MariaDB in ascolto solo sulla LAN.
- [ ] *Centralizzato — cassa*: **non installa nulla lato app**; salva l'URL del server e (se serve) installa solo QZ Tray; opzionale scorciatoia/kiosk del browser verso `https://<server>.local`.

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
- [ ] **Composer** + `composer install --no-dev --optimize-autoloader`.

### 3d. Estensioni PHP

- [ ] Applicare lo script idempotente di **Appendice A** al `php.ini` in uso (`frankenphp php-cli --ini`).
- [ ] **Gate**: `frankenphp php-cli -m` deve contenere tutte le required, altrimenti abort con messaggio chiaro.
- [ ] **Caveat binario statico Linux/Mac**: se `gd`/`intl`/`curl` non sono nel bundle statico, usare l'immagine Docker FrankenPHP **solo sul server** o un build custom. Documentare nel README dello script.

### 3e. Database

- [ ] Creare DB `opensagra_pos` + utente applicativo con password da parametro/env.
- [ ] Importare `config/pos.sql`.
- [ ] Eseguire le migrazioni di `config/migrations/` (Fase 0).
- [ ] *Solo modalità centralizzata*: `bind-address` MariaDB sulla LAN, utente app con host `%` o subnet, porta 3306 aperta solo verso la LAN.

### 3f. File e config

- [ ] Copiare i file app nel path target dell'OS (`C:\opensagra`, `/opt/opensagra`, `/usr/local/opensagra`...).
- [ ] Generare `Caddyfile` (root = path target; `localhost` o hostname di rete secondo la Domanda 1; `tls internal` o dominio secondo la Domanda 3).
- [ ] Generare `config/variabili.env` (host, utente, password DB).
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
- [ ] Aggiornare il README con: prerequisiti, comando unico di install, come aggiornare (`git pull` + `composer install` + migrazioni + `frankenphp reload`).

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

### Snippet idempotente (Windows / PowerShell)

```powershell
$required = @('mysqli','mbstring','gd','zip','intl','curl','openssl','iconv','dom','fileinfo')
$ini = (frankenphp php-cli --ini | Select-String 'Loaded Configuration File:').ToString().Split(':',2)[1].Trim()
if (-not (Test-Path $ini)) { Copy-Item "$phpDir\php.ini-production" $ini }

$content = Get-Content $ini
foreach ($ext in $required) {
    if ($content -match "^\s*extension\s*=\s*$ext(\.dll)?\s*$") { continue }
    if ($content -match "^\s*;\s*extension\s*=\s*$ext(\.dll)?\s*$") {
        $content = $content -replace "^\s*;\s*(extension\s*=\s*$ext)(\.dll)?\s*$", '$1'
    } else {
        $content += "extension=$ext"
    }
}
if (-not ($content -match '^\s*zend_extension\s*=\s*opcache')) { $content += 'zend_extension=opcache' }
Set-Content $ini $content -Encoding UTF8

# Gate di verifica
$loaded  = frankenphp php-cli -m
$missing = $required | Where-Object { $loaded -notcontains $_ }
if ($missing) { throw "Estensioni PHP mancanti nel bundle: $($missing -join ', ')" }
```

### macOS / Linux

- `php.ini` in `/opt/homebrew/etc/php/*/` (brew) o `/etc/php/*/`.
- Estensioni brew: `extension="mysqli.so"`, ecc.; nei build statici FrankenPHP molte sono già compilate dentro.
- Il gate finale è identico e resta l'unico controllo affidabile: `frankenphp php-cli -m | grep -x <ext>`. **Verificarle, non darle per scontate.**

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

## Appendice C — QZ Tray, certificati e HTTPS (da verificare, nessuna modifica ora)

> Decisione: in questa migrazione **non si tocca nulla del codice QZ**. Questa appendice raccoglie solo lo stato attuale e cosa osservare/testare. Le eventuali modifiche saranno una fase separata dopo i test della Fase 2.

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

### Cosa HTTPS può rompere — da testare

1. **Mixed content sul websocket.** Una pagina servita in `https://` non può aprire un `ws://` (in chiaro) verso un host **non-loopback**.
   - `ws://localhost:8182` da pagina HTTPS → **permesso** (eccezione loopback). Scenario "QZ sulla stessa macchina della cassa": continua a funzionare.
   - `ws://192.168.x.x:8182` da pagina HTTPS → **bloccato**. Scenario "bridge QZ centralizzato su un'altra macchina LAN": si rompe. Servirebbe `wss://` su 8181 con un certificato che il browser del tablet consideri valido per quell'host.
2. **Script `qz-tray.js` da `http://localhost:8182`** in `conf_stampanti.php` → **bloccato** come active mixed content sotto HTTPS. Rimedio (fase futura): usare la copia locale già presente in `assets/js/qz-tray.js`.
3. **`usingSecure: false` hardcoded** in `billing.php` e `conf_stampanti.php` → da rivedere per scenario quando si affronterà.

### Cosa HTTPS potrebbe semplificare — da verificare

- I tablet, per l'app, avranno **già installata la CA radice di Caddy**. Si potrebbe emettere **dallo stesso CA** un certificato `wss` per la macchina bridge (hostname LAN) e darlo a QZ Tray → `wss://bridge.local:8181` diventa fidato senza altri trucchi. Una sola CA per app + trasporto QZ. (Resta comunque separato dal certificato di *firma* che sopprime i popup.)
- Da capire se QZ Tray accetta un certificato di trasporto emesso da una CA arbitraria via `qz-tray.properties` (`wss.*`).

### Test da eseguire in Fase 2 (senza modificare nulla)

- [ ] App da FrankenPHP in `https://localhost`, QZ Tray locale, connessione `ws://localhost:8182`: **i popup di consenso NON riappaiono** e la stampa USB funziona.
- [ ] Pagina aperta da un **tablet** via `https://<ip-server>` con QZ Tray sul server: verificare cosa fallisce (il tablet non ha QZ in loopback) e cosa servirebbe (bridge? QZ sul tablet? `wss://`?).
- [ ] `conf_stampanti.php` sotto HTTPS: confermare il fallimento del load di `qz-tray.js` da `http://localhost:8182` e annotare il fix.
- [ ] `wss://` su 8181 con certificato emesso dalla CA di Caddy: QZ lo accetta? Il browser lo considera valido?

---

## Appendice D — Git: versionamento delle modifiche

Repo locale, branch `main`, nessun remoto. Ogni fase chiude con un commit dedicato.

- [ ] Fase 0 → commit `db: migrazione DDL fuori da get_products + stock.updated_at`
- [ ] Fase 1 → commit `billing: polling condizionale con endpoint versione, backoff, pausa tab`
- [ ] Fase 2 → commit `infra: Caddyfile + note estensioni/servizio FrankenPHP (dev)`
- [ ] Fase 3 → commit `infra: script installazione per-OS + wizard`
- [ ] Fase 4 → commit `realtime: hub Mercure + EventSource con fallback polling`
- [ ] Il documento di piano stesso e i suoi aggiornamenti vanno committati man mano.

---

## Riepilogo checklist di alto livello

- [ ] **Fase 0** — migrazione DDL + `stock.updated_at` + indice; rimosse le query DDL da `get_products.php`; `pos.sql` aggiornato.
- [ ] **Fase 1** — `api/products_version.php`; loop condizionale in `billing.php` con guardia in-flight, pausa a tab nascosto, backoff, merge array, init categorie una-tantum.
- [ ] **Fase 2** — FrankenPHP classic sul PC dev: estensioni + gate, `Caddyfile`, `composer install`, smoke test 2d, servizio WinSW.
- [ ] **Fase 3** — script d'installazione per-OS con **wizard** (architettura indipendente/centralizzata, QZ sì/no, HTTPS CA locale/dominio): FrankenPHP + MariaDB + Composer, estensioni + gate, DB + migrazioni, file + config, servizi, HTTPS/CA, QZ (procedura esistente), rimozione `docker/`, README.
- [ ] **Fase 4** *(opzionale)* — hub Mercure nel `Caddyfile`, `POST` degli update su mutazioni, `EventSource` in `billing.php` con fallback al polling.
- [ ] **QZ / HTTPS** *(Appendice C, solo test in Fase 2)* — verificare che i popup non riappaiano; mappare i casi mixed-content; nessuna modifica al codice QZ ora.
- [ ] **Git** *(Appendice D)* — un commit per fase; il piano si committa man mano.
