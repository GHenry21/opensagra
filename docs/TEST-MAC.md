# Test di OpenSagra su un Mac vero

Prova su un Mac fisico di `install-macos.sh` / `uninstall-macos.sh` e del
Mac come **server** per altre casse. Finora macOS è stato provato solo sui
runner di GitHub Actions (`.github/workflows/test-macos.yml`, Apple Silicon e
Intel): quello che serve è ciò che il runner **non** può mostrare, cioè la
grafica, il riavvio vero, il firewall con le sue richieste e una cassa client
vera sulla stessa rete.

Branch da provare: **`macos-server`**. Tempo: ~1 ora (di cui 10-20 minuti
d'attesa per Homebrew, se manca).

---

## 0. Cosa serve

- Un **Mac Intel** (questa guida usa il pacchetto `x86_64`; su Apple Silicon
  è lo stesso con `arm64`) con un **utente amministratore** e Internet.
- macOS recente: Homebrew supporta le ultime tre versioni. Annota la versione
  (` → Informazioni su questo Mac`).
- **Nessun MySQL** installato con Homebrew (l'installer si ferma se lo trova:
  conflitto sulla porta 3306).
- Per la parte server (§4): **un secondo computer sulla stessa rete** con
  OpenSagra (un PC Windows con `opensagra-installer.exe`, o un Linux con
  `install.sh`). Senza, salta §4.
- Facoltativo: una stampante termica ESC/POS di rete.

Il Mac **non** deve essere pulito, ma l'installer installa Homebrew (se manca)
e MariaDB con Homebrew. Se il Mac è di uso personale, la disinstallazione (§6)
rimuove MariaDB e tutto quello che ha aggiunto OpenSagra.

## 1. Scaricare il pacchetto

Insieme a questa guida hai ricevuto il file
**`opensagra-0.0.0-dev-macos-x86_64.tar.gz`** (~90 MB). Mettilo in
`~/Downloads`, poi in Terminale:

```bash
mkdir -p ~/opensagra-test && cd ~/opensagra-test
tar -xzf ~/Downloads/opensagra-*-macos-x86_64.tar.gz
cd opensagra-*/
ls    # install-macos.sh, uninstall-macos.sh, frankenphp/, wrapper/, ...
```

Usa `tar` da Terminale, non il doppio clic sul file: è lo stesso, ma così i
comandi qui sotto trovano la cartella dove se l'aspettano. L'attributo di
"quarantena" che macOS mette sui file ricevuti (browser, AirDrop, chat) lo
toglie l'installer da solo.

*In alternativa*, dal repository (servono PHP + Composer e Go):
`git checkout macos-server && composer install --no-dev && bash packaging/make-unix-package.sh macos x86_64`.

## 2. Installazione

```bash
bash install-macos.sh
```

- **Non** con `sudo`: lo script la chiede da solo quando serve.
- Se manca Homebrew, lo installa (chiede la password e scarica gli strumenti
  da riga di comando di Xcode: è la parte lunga).
- Può comparire la richiesta della password per rendere fidato il
  **certificato HTTPS** locale: è normale, accetta.
- Se il **firewall di macOS** è acceso, l'installer autorizza FrankenPHP e
  MariaDB. Se compare comunque "Consentire a … di accettare connessioni in
  entrata?", **annotalo** e scegli *Consenti*.

Alla fine il log dice `OpenSagra avviato (LaunchAgent com.opensagra.wrapper ...)`.
Il log completo è in `/tmp/opensagra-install.log`.

## 3. Checklist sul Mac da solo

Segna ✅ / ❌ e, se ❌, cosa vedi (meglio con uno screenshot).

| # | Prova | Atteso |
|---|---|---|
| 3.1 | Fine installazione | OpenSagra è già attivo (`https://localhost` risponde), ma la finestra di stato **non** si apre da sola |
| 3.2 | **OpenSagra.app** in `~/Applications` (anche alias sulla Scrivania, Spotlight, Launchpad) | Si apre la **finestra di stato**: una finestra senza barre di Chrome / Edge / Brave / Chromium se ce n'è uno in `/Applications`, altrimenti una scheda del browser predefinito |
| 3.3 | "Apri OpenSagra" | `https://localhost` si apre **senza avviso di sicurezza** in Safari e Chrome. Se hai **Firefox**, provalo e annota se mostra l'avviso: ha un archivio certificati suo, e non è ancora stato verificato se su macOS legge anche quello di sistema |
| 3.4 | Login nell'app, una vendita di prova | La vendita si registra |
| 3.5 | Pagina **Configurazione Rete** | Mostra l'**IP di rete** del Mac e il QR per i telefoni (se l'IP è vuoto: ❌) |
| 3.6 | Finestra di stato → **Riavvia tutto** | Tutto torna verde in pochi secondi |
| 3.7 | Finestra di stato → **Ferma server**, poi **Avvia server** | I servizi si fermano (`https://localhost` non risponde) e ripartono |
| 3.8 | Chiudere OpenSagra del tutto: su macOS **non c'è** una voce "Esci" nella grafica (su Windows è nel menu dell'area di notifica). Da Terminale: `~/opensagra/opensagra-wrapper -quit` | Dialogo di macOS con *Annulla* / *Esci*, **Annulla** predefinito (Invio non chiude). Con *Esci* si ferma tutto e **non** riparte da solo |
| 3.8b | Riapri OpenSagra.app dopo l'uscita | Riparte tutto (attraverso launchd) |
| 3.9 | Chiudi la sola finestra di stato | OpenSagra resta attivo (`https://localhost` risponde) |
| 3.10 | **Riavvia il Mac** e rientra con il tuo utente | OpenSagra riparte da solo dopo il login (avvio automatico) |
| 3.11 | Facoltativo: accesso automatico all'avvio | Se il Mac entra da solo nell'utente, OpenSagra parte senza che nessuno tocchi niente. **Con FileVault attivo** macOS non permette l'accesso automatico: annotalo |
| 3.12 | Rilancia `bash install-macos.sh` | Finisce senza errori, l'app resta com'era (dati compresi) |

Comandi utili, se qualcosa non va:

```bash
launchctl print gui/$(id -u)/com.opensagra.wrapper | grep -E 'state|pid|last exit'
tail -50 ~/opensagra/logs/wrapper.log
tail -50 ~/opensagra/logs/launchd.log
lsof -nP -iTCP -sTCP:LISTEN | grep -E ':(80|443|3306) '
```

## 4. Il Mac come server per un'altra cassa

Serve il secondo computer della stessa rete (§0). Il Mac resta in modalità
**Indipendente**: diventa "centrale" da solo quando una cassa lo sceglie.

| # | Prova | Atteso |
|---|---|---|
| 4.1 | Sul Mac, **Configurazione Rete**: annota l'IP del Mac | es. `192.168.1.20` |
| 4.2 | Sull'altro computer, **Configurazione Rete → Client**, indirizzo del Mac | La cassa si collega e mostra il catalogo del **Mac** |
| 4.3 | Vendita dall'altro computer | Compare nelle statistiche del Mac; la disponibilità dei prodotti si aggiorna **subito** su entrambi (realtime) |
| 4.4 | Vendita dal Mac | Si aggiorna subito anche sull'altro |
| 4.5 | Sul Mac: `~/opensagra/opensagra-wrapper -quit` → *Esci* | L'altro computer, dopo al massimo ~30 s, passa da solo a lavorare in locale (messaggio a video), senza perdere vendite |
| 4.6 | Riapri OpenSagra sul Mac, poi sull'altro **Configurazione Rete → Client** con lo stesso indirizzo | Torna collegato |
| 4.7 | Firewall di macOS **acceso** (Impostazioni → Rete → Firewall) e ripeti 4.2 | Si collega senza dover cliccare nulla sul Mac. Se compare una richiesta di connessioni in entrata, annotalo |
| 4.8 | Facoltativo: stampante di rete configurata sul Mac (**Configurazione Casse**), vendita dall'altro computer | Lo scontrino esce |

Per chi deve capire un ❌ di questa sezione, dal Mac:

```bash
lsof -nP -iTCP:3306 -sTCP:LISTEN          # atteso: *:3306, non 127.0.0.1:3306
mariadb -N -e "SELECT CONCAT(User,'@',Host), JSON_VALUE(Priv,'$.account_locked') FROM mysql.global_priv"
grep -i 'accesso dalla rete' ~/opensagra/logs/wrapper.log | tail
/usr/libexec/ApplicationFirewall/socketfilterfw --listapps
```

## 5. Cosa rimandare indietro

- La tabella di §3 e §4 compilata, con modello del Mac e versione di macOS.
- Screenshot di ogni ❌ e di ogni richiesta o avviso di macOS non previsto
  dalla guida (Gatekeeper, firewall, portachiavi, permessi).
- Impressioni da utente: cosa non era chiaro, cosa ti aspettavi di trovare
  (es. come chiudere OpenSagra senza Terminale).
- I log, in un unico archivio:

```bash
tar -czf ~/Desktop/opensagra-log-mac.tgz /tmp/opensagra-install.log ~/opensagra/logs 2>/dev/null
```

## 6. Disinstallazione

```bash
bash ~/opensagra/uninstall-macos.sh
```

Fa prima una copia del database in `~/opensagra-backup-<data>.sql`, poi
rimuove OpenSagra, l'avvio automatico, FrankenPHP, il certificato locale,
MariaDB (Homebrew resta) e le regole del firewall. Verifica:

- `~/opensagra` e `~/Applications/OpenSagra.app` non ci sono più;
- in **Accesso Portachiavi → Sistema** non c'è più "Caddy Local Authority";
- `brew list | grep mariadb` non trova nulla.

Il log è in `/tmp/opensagra-uninstall.log` (aggiungilo all'archivio di §5 se
qualcosa non torna).
