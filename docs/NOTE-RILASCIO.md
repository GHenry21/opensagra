# Note di rilascio

## v1.1.1 (19 ottobre 2026)

Versione di correzioni: risolve tutti i difetti noti della v1.1.0, compresi
due trovati durante le verifiche di questa versione.

### ⚠️ Prima di aggiornare

- **Serve reinstallare, su tutte le casse**, come per la v1.1.0: scarica
  l'installer e lancialo sopra l'installazione esistente. **Vendite, catalogo,
  casse, stampanti e impostazioni restano.**
- **Ordine: prima il PC centrale, poi le casse**, e non durante il servizio.
- **Chiudi OpenSagra prima di reinstallare su Windows** (icona vicino
  all'orologio → Esci): l'installer si ferma se lo trova aperto.

### Correzioni

- **Cassa che torna Indipendente dopo un'uscita della centrale**: ritrova il
  proprio catalogo. Prima poteva ritrovarsi con i prodotti e le casse della
  centrale, e il catalogo giusto non si recuperava dalle pagine.
- **Tagliandini per la cucina durante un'uscita della centrale**: la cassa
  stampa con la configurazione dello scontrino della centrale. Prima, se sulla
  centrale nessuno aveva mai salvato *Configura Scontrino*, la cassa usava la
  sua vecchia configurazione e poteva stampare senza tagliandini.
- **Tempo reale e stampa tramite ponte** non si scollegano più ogni 45 secondi
  con la centrale accesa (le casse si ricollegavano di continuo).
- **Vendita non registrata per un problema momentaneo del database**: ora la
  cassa ritenta da sola invece di mostrare subito "Errore durante la
  registrazione della vendita"; se l'errore resta, il messaggio dice il motivo,
  che finisce anche nel registro di OpenSagra.
- **"Scontrino NON stampato"** compare come avviso arancione, non come semplice
  informazione azzurra.
- La cassa collegata a una centrale ne mostra il nome.
- Pannello di OpenSagra: sulla centrale non resta acceso un processo che serve
  solo alle casse; l'accesso delle casse al database della centrale si
  riallinea entro 15 secondi.
- **Linux e Mac**: l'installazione mostrava `encode: command not found` e
  poteva eseguire per errore un file della cartella da cui si lanciava
  l'installer.
- **Installer Windows**:
  - lettere accentate al posto degli apostrofi;
  - la finestra si allunga man mano che procede, la barra arriva in fondo e
    l'elenco dei passi non è più tagliato;
  - alla fine dice "OpenSagra è pronta" e apre il pannello di OpenSagra;
  - non lascia più file nella cartella temporanea;
  - disinstallazione: stessi miglioramenti, e gli errori si leggono per intero.

### Problemi noti

- **Firefox su Mac**: non ancora verificato se riconosce da solo il
  certificato locale; se mostra l'avviso, va accettato una volta.
- Su Mac il primo avvio richiede *Apri comunque* (app non firmata da Apple).

## v1.1.0 (18 ottobre 2026)

Prima versione dopo la v1.0.0 (29 settembre 2026, solo Windows).

> **Se hai scaricato la v1.0.0 dopo il 3 ottobre e non funziona** (la pagina
> non si apre): la v1.0.0 scaricava l'ultima versione di FrankenPHP, che da
> ottobre non è più compatibile. Installa questa versione sopra: ora FrankenPHP
> è incluso nell'installer e non cambia più da solo.

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
- Installer Windows:
  - FrankenPHP è incluso e fissato: l'installer non scarica più "l'ultima
    versione", che dal 4 ottobre rendeva inutilizzabili le nuove installazioni
    della v1.0.0;
  - si ferma subito, con un messaggio chiaro, se OpenSagra è aperto, invece di
    fallire a metà (su una cassa centrale poteva chiudere il database alle
    altre casse per qualche minuto);
  - una finestrella compare appena lo lanci, invece di quasi un minuto senza
    niente a video;
  - non resta più bloccato su "Avvio... 0%" su un PC lento all'accensione;
  - gli errori si leggono per intero.

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
