# OpenSagra

App di cassa (POS) open source per sagre e feste paesane italiane: gestione prodotti/categorie, vendita a banco, scontrini termici (USB, rete, bridge di stampa nativo via Mercure, Bluetooth), statistiche vendite, gestione multi-cassa.

Basata su **FrankenPHP** (server web + PHP in un unico eseguibile, con HTTPS automatico) e **MariaDB**. Attualmente **solo Windows** è supportato dall'installer automatico; l'app in sé (PHP puro + MariaDB) non ha dipendenze specifiche da Windows.

## Installazione (utente finale, Windows)

1. Scarica **`opensagra-installer.exe`** — un unico file, contiene già tutto il necessario (non serve scaricare nient'altro a parte quello che l'installer stesso scaricherà al volo: FrankenPHP, MariaDB).
2. Fai doppio click. Windows chiederà conferma un paio di volte (UAC + eventuali popup di sicurezza): accetta sempre.
3. Da lì in poi non c'è più nulla da fare: una finestra mostra l'avanzamento mentre l'installer scarica e configura da solo FrankenPHP, MariaDB, le estensioni PHP necessarie, crea il database, registra il servizio MariaDB, avvia il wrapper (che gestisce FrankenPHP) e apre le regole firewall. Ci vogliono alcuni minuti, dipende dalla connessione internet.
4. A fine installazione l'app è già raggiungibile su `http://localhost` e `https://localhost`.
5. Per configurare l'architettura di rete (cassa singola o centralizzata con più postazioni), apri la pagina **Configurazione Rete** dall'app dopo il primo avvio.

Prerequisiti: Windows 10/11, connessione a internet (per scaricare FrankenPHP/MariaDB al primo avvio).

Per chi lavora sul codice invece che installare l'app: l'installer è generato da `packaging/make-installer.ps1` a partire da `install.ps1`, che resta eseguibile anche da solo (`.\install.ps1` da PowerShell come amministratore) — utile per debug o per rigenerare un'installazione senza ripassare dall'`.exe`.

Guida utente per chi userà le casse, con screenshot reali dell'app: [`docs/GUIDA-UTENTE.md`](docs/GUIDA-UTENTE.md).

## Sviluppo / contribuire

Serve un ambiente FrankenPHP "classic mode" (PHP eseguito da FrankenPHP, non serve Apache/nginx):

```powershell
irm https://frankenphp.dev/install.ps1 | iex
```

Poi, dalla cartella del progetto:

```powershell
# Estensioni PHP necessarie (vedi config/ e composer.lock): mysqli, mbstring, gd,
# zip, intl, curl, openssl, iconv, dom, fileinfo — verifica con:
frankenphp php-cli -m

# Dipendenze PHP (vendor/ è incluso nei pacchetti di release, ma per sviluppo
# rigeneralo con Composer se modifichi composer.json)
composer install

# Database: crea/aggiorna schema e utente applicativo (root MariaDB in locale)
php config/crea_dbtable_and_user.php --root-host=127.0.0.1 --root-user=root --root-pass=

# Migrazioni (schema aggiuntivo dopo il primo import)
php config/migrations/001_stock_updated_at.php

# Avvio
frankenphp run --config Caddyfile
```

Copia `config/variabili.env.example` in `config/variabili.env` e imposta le credenziali del database (non è tracciato in git — contiene una password).

## Aggiornare un'installazione esistente

```powershell
git pull
composer install --no-dev --optimize-autoloader   # solo se non usi il pacchetto con vendor/ già incluso
```
Poi esegui le eventuali nuove migrazioni in `config/migrations/` ed esegui `frankenphp reload` (o riavvia il servizio Windows `frankenphp`).

## Struttura del progetto

- `pages/` — le pagine dell'app (cassa, configurazione, statistiche…)
- `api/` — endpoint chiamati via AJAX dalle pagine
- `print/` — logica di stampa scontrini (diretta USB/rete, bridge, Bluetooth)
- `config/` — connessione DB, migrazioni, script di provisioning
- `assets/` — CSS/JS/immagini statiche
- `includes/` — componenti PHP condivisi (es. registro icone)
- `cert/` — certificato CA locale (`caddy-root-ca.crt`) da installare sui dispositivi client per l'HTTPS, vedi [`docs/GUIDA-UTENTE.md`](docs/GUIDA-UTENTE.md#11-la-connessione-è-sempre-protetta-https)
- `docs/PIANO-MIGRAZIONE-FRANKENPHP.md` — piano/diario tecnico dettagliato della migrazione da XAMPP a FrankenPHP, con tutti i problemi reali incontrati e come sono stati risolti
- `e2e/` — test end-to-end Playwright (manuali, non in CI)

## Stampa

L'app supporta più modalità di stampa (`config/get_printer.php`): stampante di rete, USB diretta (Windows/Linux), Bluetooth (Android via RawBT) e **bridge di stampa nativo** (`BRIDGE_NATIVE`, via Mercure) — quest'ultima serve solo quando una stampante USB deve essere condivisa da più postazioni diverse da quella a cui è collegata fisicamente. Dettagli e tabella degli scenari in [`docs/GUIDA-UTENTE.md`](docs/GUIDA-UTENTE.md#6-configurazione-casse-e-stampanti).

## Licenza

Vedi il file di licenza del progetto.
