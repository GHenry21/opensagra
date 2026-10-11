# Note di rilascio

## Prossima versione (bozza, numero da decidere)

Prima versione dopo la v1.0.0 (29 settembre 2026, solo Windows).

### ⚠️ Prima di aggiornare

- **Serve reinstallare, su tutte le casse.** Questa versione cambia i
  componenti di base (server web, gestione degli aggiornamenti in tempo
  reale), quindi il pulsante di aggiornamento non basta: scarica l'installer
  e lancialo sopra l'installazione esistente. **Vendite, catalogo, casse,
  stampanti e impostazioni restano.**
- **Ordine: prima il PC centrale, poi le casse, prima del servizio.** Finché
  casse e centrale non sono alla stessa versione le vendite funzionano, ma la
  **stampa tramite ponte** (stampante collegata a un altro PC) non esce e il
  catalogo si aggiorna fra le casse con qualche secondo di ritardo. Vedi la
  guida, §10.2.
- **Chiudi OpenSagra prima di reinstallare su Windows** (icona vicino
  all'orologio → Esci): l'installer si ferma se lo trova aperto.

### Novità

- **Linux e macOS.** OpenSagra si installa anche su Linux (Debian, Ubuntu,
  Raspberry Pi OS, Fedora) e su Mac (Apple Silicon e Intel), con lo stesso
  pannello di controllo e un installer grafico. Su Mac l'app non è firmata da
  Apple: al primo avvio va aperta da *Impostazioni di Sistema → Privacy e
  sicurezza → Apri comunque* (guida, §1 "Su Mac").
- **Qualunque PC può fare da cassa centrale**, anche un Mac o un Linux. Il
  database della centrale si apre alle altre casse solo quando una cassa la
  sceglie e solo mentre OpenSagra è aperto; quello di una cassa indipendente
  resta chiuso.
- **Aggiornamenti delle casse dal PC centrale**, attraverso la rete della
  sagra: le casse non hanno bisogno di Internet. Una cassa non va mai oltre la
  versione del centrale, e un avviso segnala le casse da aggiornare.
- **Indirizzo del PC che cambia**: OpenSagra si adatta da solo se il router
  assegna un altro indirizzo al PC (resta consigliato un indirizzo fisso per
  la centrale, guida §3.2).
- **Stampa tramite ponte**: se non parte perché il PC con la stampante ha
  un'altra versione, la cassa lo dice chiaramente invece di "ponte non
  raggiungibile".
- **Mac e Linux: "Esci da OpenSagra"** nella finestra di stato.

### Cambiamenti di comportamento

- **Solo HTTPS.** `http://` porta automaticamente alla versione protetta.
  Tablet e telefoni devono avere installato il certificato (QR in
  *Configurazione Rete*, guida §1.2), come già serviva per l'app.
- **Windows: nessuna finestra di conferma per il certificato** durante
  l'installazione (prima poteva finire dietro l'installer e bloccarla), e il
  dialogo *Esci* ha **No** come scelta predefinita.

### Correzioni principali

- I file interni dell'app (configurazione con le password, log, script) non
  sono più raggiungibili dal browser.
- Le casse si collegano alla centrale senza attese anche dove la rete non ha
  un DNS (sagra senza Internet).
- Windows: la disinstallazione da *Impostazioni → App* funziona, anche con il
  pannello di controllo aperto.
- Copie di sicurezza del database con nomi sempre diversi.

### Componenti

- **FrankenPHP 1.13.x** (server web + PHP 8.5) incluso nei pacchetti, la
  stessa versione su tutti i sistemi; **Mercure 1.x** per il tempo reale
  (token RFC 9068).
- **MariaDB**: Windows con l'installer ufficiale (12.3), Mac con Homebrew,
  Linux con i pacchetti della distribuzione.

### Problemi noti

- **Firefox su Mac**: non ancora verificato se riconosce da solo il
  certificato locale (su Windows e Linux sì); se mostra l'avviso, va accettato
  una volta.
- Su Mac il primo avvio richiede *Apri comunque* (app non firmata da Apple).
