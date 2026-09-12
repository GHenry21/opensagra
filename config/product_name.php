<?php
/**
 * Normalizzazione e controllo duplicati per il nome prodotto (Fase 4 punto 4,
 * decisione 2026-09-12): il nome viene sempre salvato in MAIUSCOLO, anche se
 * digitato in minuscolo, e non possono esistere due prodotti con lo stesso
 * nome - confronto sulla stringa INTERA, non un pezzo: "PANINO SALSICCIA" e
 * "PANINO PORCHETTA" restano prodotti distinti.
 *
 * Perche' importa: api/push_local_sales.php decrementa lo stock sul centrale
 * cercando per NOME (lo scontrino salva il nome del prodotto, non un id) -
 * due prodotti con lo stesso nome farebbero scalare per errore anche quello
 * non coinvolto nella vendita, in modo silenzioso (a differenza del vero
 * oversell tra casse, che e' visibile perche' lo stock scende sotto zero).
 */

/**
 * Nome pronto per il salvataggio: trim, spazi multipli collassati a uno,
 * tutto MAIUSCOLO (mb_ per gestire correttamente gli accenti).
 */
function normalizeProductNameForStorage(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    return mb_strtoupper($name, 'UTF-8');
}

/**
 * Chiave di confronto per il controllo duplicati: come sopra, ma con i
 * trattini equiparati a uno spazio (cosi' "Coca-Cola" e "Coca Cola" vengono
 * visti come lo stesso nome - un refuso frequente).
 */
function productNameCompareKey(string $name): string
{
    return normalizeProductNameForStorage(str_replace('-', ' ', $name));
}

/**
 * True se esiste gia' un prodotto con lo stesso nome (confronto sull'intera
 * stringa normalizzata). $excludeId esclude se stesso in una modifica.
 * Best-effort: un errore di lettura non deve mai bloccare il salvataggio,
 * quindi ritorna false (nessun duplicato trovato) invece di lanciare.
 */
function productNameIsDuplicate(mysqli $db, string $name, int $excludeId = 0): bool
{
    $compareKey = productNameCompareKey($name);
    try {
        $res = $db->query('SELECT id, name FROM stock');
    } catch (Throwable $e) {
        return false;
    }
    if (!$res) {
        return false;
    }
    while ($row = $res->fetch_assoc()) {
        if ((int) $row['id'] === $excludeId) {
            continue;
        }
        if (productNameCompareKey((string) $row['name']) === $compareKey) {
            return true;
        }
    }
    return false;
}
