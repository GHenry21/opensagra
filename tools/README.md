# tools/

## adminer.php — AdminNeo (gestore DB, un file)

Fork attivo di Adminer, single-file. Servito da FrankenPHP su **`/db`**
(route **solo-localhost** generato da `install.ps1` / nel `Caddyfile`), a cui
puntano il link "Gestione Database" della sidebar e il tasto "Apri gestione DB"
della finestra di stato del wrapper.

**Versione vendorizzata:** AdminNeo **5.7.1**
**Build:** driver `mysql`, lingue `en.it`, tema `default`
**Scaricato da:**
`https://www.adminneo.org/files/5.7.1/mysql_en.it_default/adminneo-5.7.1.php`

### Aggiornare
Cambia `<version>` nell'URL sopra (pattern:
`https://www.adminneo.org/files/<version>/<drivers>_<languages>_<themes>/adminneo-<version>.php`),
scarica, sostituisci `adminer.php`, aggiorna la versione qui. Niente da
ricompilare.

### Perché non phpMyAdmin / HeidiSQL
Vedi `docs/PIANO-MODIFICHE-WRAPPER.md` §4: phpMyAdmin pieno = troppo peso +
config + superficie da patchare; HeidiSQL = non davvero pre-installato,
Windows-only. AdminNeo: un file, configurabile al minimo (solo MySQL), PHP 8.5 ok.
