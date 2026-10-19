# Piano delle prossime versioni

Lavori successivi alla v1.1.1 (19 ottobre 2026). La migrazione a FrankenPHP e Mercure è conclusa: storia, decisioni e verifiche restano in [PIANO-MIGRAZIONE-FRANKENPHP.md](PIANO-MIGRAZIONE-FRANKENPHP.md), a cui rimandano i riferimenti tipo "5e" o "6b" qui sotto.

## Regola per ogni release

Decisione dell'utente (2026-10-18): **nessuna release con difetti noti.** Prima del tag:

- tutti i difetti noti corretti;
- verifica completa dal vivo:
  - Windows da zero e in aggiornamento dall'ultima release, sulla VM;
  - Linux come centrale (Pi) con una cassa client vera: vendite, uscita della centrale, fallback, ritorno, realtime, stampa via ponte;
  - CI completa (Ubuntu, Fedora, macOS Apple Silicon e Intel, installer grafici, schema);
- le modifiche grafiche viste e approvate dall'utente prima della pubblicazione;
- il testo per gli utenti in `docs/NOTE-RILASCIO.md`, copiato nella release su GitHub (la CI genera solo l'elenco dei commit).

Esempio completo: la v1.1.1, in PIANO-MIGRAZIONE-FRANKENPHP.md, sezione "v1.1.1 — tutti i difetti noti della v1.1.0 corretti e verificati".

## Da fare

- [ ] **Interfaccia in inglese** (proposta 2026-10-19, da decidere).
  - **Testi da tradurre** (conteggio approssimativo):
    - pagine dell'app: ~250 righe (8 file);
    - messaggi delle API: ~170 (31 file);
    - testi stampati (scontrino, tagliandini, statistiche): ~90;
    - JS e parti comuni: ~25;
    - finestra di stato: ~45;
    - installer grafico Linux/Mac: ~150, più installer e disinstallatore Windows;
    - guida: 840 righe.
  - **Approccio proposto**, senza librerie come il resto del progetto: `lang/it.php` + `lang/en.php` e una funzione `t('chiave')`. Le pagine Vue ricevono lo stesso dizionario; le API restituiscono i messaggi già tradotti.
  - **Lingue:** quella dell'interfaccia si sceglie in Configurazione. Quella degli **scontrini è separata** (volontari stranieri, clienti italiani).
  - **Ordine:** app → pannello e installer → guida.
  - **Verifica:** giro completo in inglese con schermate di ogni pagina, più un controllo automatico delle chiavi mancanti.
  - **Da decidere dall'utente:** lingua per installazione o per dispositivo; guida subito o dopo.
  - **Stima:** ~2-3 giorni l'app, ~1 il pannello e gli installer, ~1 la guida, più le verifiche.
- [ ] **Aggiornamento leggero vero** (6b). Il primo meccanismo è stato provato dal vivo il 2026-09-16 (v1.0.0 → v1.0.1). Quello attuale (manifesto, backup e ripristino, download dalla centrale in LAN) è stato verificato solo con pacchetti simulati. Va provato end-to-end dal pulsante della finestra di stato con la prima release che cambia solo l'app, o con una release di prova non pubblica.
- [ ] **Mac su un computer vero** (5e). Da provare:
  - Mac come centrale con una cassa client vera;
  - Mac come cassa client;
  - stampa;
  - telefoni e tablet in LAN;
  - avvio all'accensione;
  - Firefox e il certificato.

  Serve un Mac in rete raggiungibile via SSH; finora solo i Mac virtuali della CI.
- [ ] **Annuncio pubblico** (5b): decisione dell'utente.

## Facoltativi

- [ ] Installer Windows con la stessa pagina HTML di Linux e Mac (WebView2), al posto della finestra XAML: una sola grafica da mantenere (5h).
- [ ] Worker mode di FrankenPHP (PIANO-MIGRAZIONE-FRANKENPHP.md, Appendice B).
- [ ] Blocco delle casse troppo vecchie (`SCHEMA_MIN_APP_VERSION`, 6c E): solo se una release dovesse cambiare il database in modo incompatibile. Finora non è servito.

## Sito (segue la sessione del sito)

- [ ] Cookie tramite sito apposito.
- [ ] Dominio opensagra.com (Ionos) e tunnel Cloudflare per sito e demo.
