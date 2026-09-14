<?php
/**
 * Persistenza dell'esito di stampa di una vendita (colonna `vendite.stampa_errore`).
 *
 * NULL = ultimo tentativo di stampa (checkout o ristampa) riuscito; stringa =
 * messaggio dell'errore dell'ultimo tentativo, ancora valido finche' non
 * arriva una ristampa che ha successo. Letta da api/get_ordini.php e
 * api/get_ordine_dettaglio.php per evidenziare nel pannello "Ordini" le
 * vendite registrate ma non stampate - vedi print/print_receipt.php::routingStampa,
 * che la aggiorna a ogni tentativo (checkout, replay idempotente, ristampa
 * manuale passano tutti da li').
 */
function ensureVenditeStampaColumn(mysqli $connectionDB): void
{
    $connectionDB->query("ALTER TABLE vendite ADD COLUMN IF NOT EXISTS stampa_errore VARCHAR(255) NULL");
}

function recordPrintOutcome(mysqli $connectionDB, int $id_vendita, ?string $errorMessage): void
{
    ensureVenditeStampaColumn($connectionDB);
    $stmt = $connectionDB->prepare('UPDATE vendite SET stampa_errore = ? WHERE id = ?');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('si', $errorMessage, $id_vendita);
    $stmt->execute();
    $stmt->close();
}
