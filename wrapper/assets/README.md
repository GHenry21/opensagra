# assets/

Metti qui `opensagra.ico` (icona della tray su Windows — deve essere un vero
`.ico`, non un PNG rinominato). È volutamente **fuori dal repo** (`.gitignore`):
è un binario, va aggiunto in fase di packaging.

Senza il file la tray parte comunque, con l'icona di default del sistema.

Percorsi cercati, in ordine:
1. `opensagra.ico` accanto all'eseguibile
2. `<root-app>/wrapper/assets/opensagra.ico`
