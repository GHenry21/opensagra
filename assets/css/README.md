# Convenzioni CSS OpenSagra

## Obiettivo

Mantenere una sola implementazione per ogni componente condiviso e rendere prevedibile la cascata.

## Regole

- Definire ogni token di colore, tema, spazio, raggio e ombra una sola volta.
- Usare classi per lo stile; riservare gli ID a JavaScript e accessibilita.
- Non aggiungere nuove regole globali per `button`, `table`, `input`, `label` o ID generici come `#top` e `#container`.
- Un componente condiviso deve avere una sola definizione base e varianti esplicite, per esempio `.button--primary` o `.button--danger`.
- Gli stili specifici di una pagina devono essere limitati al relativo ambito, per esempio `.billing-page .cart`.
- Evitare nuovi blocchi `<style>` nei file PHP. Spostare gli stili statici nei CSS della pagina.
- Usare le media query in un solo punto per ogni componente, evitando copie dello stesso selettore nello stesso breakpoint.
- Non usare `!important` per risolvere conflitti di cascata senza prima correggere ambito e ordine dei selettori.
- Prima di rimuovere una regola condivisa, verificare tema chiaro, tema scuro, desktop e mobile.

## Ordine dei fogli

Le pagine devono caricare i fogli in questo ordine logico:

1. token e tema;
2. base/reset;
3. componenti condivisi;
4. layout condiviso;
5. stile specifico della pagina.

Finche la migrazione non e completata, `pos-redesign.css` resta compatibile con i fogli esistenti. Le modifiche devono essere incrementali e verificabili.

I fogli pagina-specifici attivi sono caricati dopo `pos-redesign.css`:

- `home.css` per layout e azioni della dashboard iniziale;
- `billing.css` per layout del catalogo e della pagina cassa;
- `add_product.css` per inserimento e gestione prodotti;
- `conf_scontrino.css` per la configurazione dello scontrino;
- `stat_vendite.css` per le statistiche di vendita;
- `storni.css` per la gestione degli storni;
- `conf_casse.css` per la configurazione delle casse.

I token correnti sono definiti in `pos-redesign.css`.

Questi fogli contengono solo layout e componenti propri della pagina. Le regole condivise restano in `pos-redesign.css`.

## Pulizia prima del merge

- Cercare definizioni duplicate del selettore modificato.
- Controllare parentesi graffe e sintassi CSS.
- Verificare le pagine interessate in entrambi i temi e almeno una viewport mobile.
- Non accorpare regole solo per ridurre il numero di righe se cambia la responsabilita del componente.
