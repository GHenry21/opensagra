# opensagra

App di cassa (POS) open source per sagre e feste paesane italiane: gestione prodotti/categorie, vendita a banco, scontrini termici (USB, rete, bridge di stampa nativo via Mercure, Bluetooth), statistiche vendite, gestione multi-cassa.

Basata su **FrankenPHP** (server web + PHP in un unico eseguibile, con HTTPS automatico) e **MariaDB**. Attualmente **solo Windows** è supportato dall'installer automatico; l'app in sé (PHP puro + MariaDB) non ha dipendenze specifiche da Windows.

## Installazione (utente finale, Windows)

1. Scarica il pacchetto di release (contiene `install.ps1` e tutto il codice dell'app).
2. Apri PowerShell **come amministratore** nella cartella del pacchetto.
3. Esegui:
   ```powershell
   .\install.ps1
   ```
4. L'installer fa tutto da solo, senza domande: scarica e configura FrankenPHP, MariaDB, le estensioni PHP necessarie, crea il database, registra i servizi Windows e apre le regole firewall. A fine installazione l'app è raggiungibile su `http://localhost` e `https://localhost`.
5. Per configurare l'architettura di rete (cassa singola o centralizzata con più postazioni), apri la pagina **Configurazione Rete** dall'app dopo il primo avvio.

Prerequisiti: Windows 10/11, connessione a internet (per scaricare FrankenPHP/MariaDB al primo avvio), PowerShell con esecuzione script consentita (l'installer stesso richiede privilegi di amministratore per registrare servizi e regole firewall).

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
- `print/` — logica di stampa scontrini (diretta USB/rete, bridge QZ, Bluetooth)
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
