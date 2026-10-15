# Migrazioni del database

Ogni file `NNN_descrizione.php` porta il database da una versione alla
successiva. Installer e aggiornamento leggero li eseguono **tutti, in ordine,
a ogni installazione e aggiornamento**, sempre sul MariaDB della macchina
stessa (`OPENSAGRA_DB_HOST=127.0.0.1`): ognuno deve quindi controllare da sé se
il proprio passo è già stato applicato (idempotente).

## Regola: solo aggiunte

Per qualche minuto, o per una serata se una cassa non viene aggiornata, la
cassa centrale ha la versione nuova e le casse collegate quella vecchia, **sullo
stesso database**. Il codice vecchio deve continuare a funzionare con lo schema
nuovo. Quindi, sia qui sia negli `ALTER` fatti a runtime dal codice (funzioni
`ensure*`):

- **sì**: tabelle nuove, colonne nuove con un `DEFAULT` (o `NULL`), indici nuovi;
- **no**: `DROP`, `RENAME`, `CHANGE`/`MODIFY` di una colonna, `TRUNCATE`,
  colonne `NOT NULL` senza `DEFAULT`.

Per togliere o rinominare qualcosa servono due release: nella prima il codice
smette di usare la cosa vecchia, nella seconda (quando nessuna cassa ha più la
versione precedente) la si toglie.

Il controllo è automatico in CI (`.github/workflows/schema-check.yml`,
`wrapper/cmd/schemacheck`). In locale:

```
cd wrapper && go run ./cmd/schemacheck -root ..
```

Un'eccezione davvero sicura (per esempio tabelle di appoggio sul database
locale) va motivata nel codice con un commento `schema-compat: ok - <motivo>`
sulla stessa riga dell'istruzione, o su una riga di solo commento appena sopra.
