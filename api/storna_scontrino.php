<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/get_db_connection.php';

$idScontrRaw = $_POST['idScontr'] ?? '';
$dat = $_POST['dat'] ?? '';

if (!isset($_POST['idScontr']) || trim((string)$idScontrRaw) === '' || !isset($_POST['dat'])) {
    echo json_encode(['error' => 'Parametri non ricevuti']);
    exit;
}

$ids = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$idScontrRaw)), static function ($value) {
    return $value !== '';
}));

if (count($ids) === 0) {
    echo json_encode(['error' => 'Nessuno scontrino valido da storno']);
    exit;
}

$query = "
    SELECT v.stornato
    FROM vendite v
    WHERE v.id = ?
      AND date(v.data_ora) = ?
";

$queryUpdate = "
    UPDATE vendite v
       SET stornato = 1
     WHERE v.id = ?
       AND date(v.data_ora) = ?
";

$results = [];
$updatedCount = 0;

foreach ($ids as $idScontr) {
    $stmt = $connectionDB->prepare($query);
    if (!$stmt) {
        $results[] = ['id' => $idScontr, 'status' => 'error', 'esito' => 'Errore nella preparazione della query'];
        continue;
    }

    $stmt->bind_param('ss', $idScontr, $dat);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row === null) {
        $results[] = ['id' => $idScontr, 'status' => 'not_found', 'esito' => 'Scontrino non trovato. Impossibile stornare'];
        continue;
    }

    if ((int)($row['stornato'] ?? 0) === 1) {
        $results[] = ['id' => $idScontr, 'status' => 'already_cancelled', 'esito' => 'Lo scontrino risulta essere già stornato'];
        continue;
    }

    $updateStmt = $connectionDB->prepare($queryUpdate);
    if (!$updateStmt) {
        $results[] = ['id' => $idScontr, 'status' => 'error', 'esito' => 'Errore nell aggiornamento dello scontrino'];
        continue;
    }

    $updateStmt->bind_param('ss', $idScontr, $dat);
    $updateStmt->execute();
    $updateStmt->close();
    $updatedCount++;

    // Ripristino scorte per i prodotti dello scontrino stornato
    $dettStmt = $connectionDB->prepare("SELECT prodotto, quantita FROM dettagli_vendita WHERE vendita_id = ?");
    if ($dettStmt) {
        $dettStmt->bind_param('i', $idScontr);
        $dettStmt->execute();
        $dettRes = $dettStmt->get_result();
        while ($dettRow = $dettRes ? $dettRes->fetch_assoc() : null) {
            if (!$dettRow) continue;
            $prodName = trim((string)($dettRow['prodotto'] ?? ''));
            $prodQty = (int)($dettRow['quantita'] ?? 0);
            if ($prodQty > 0 && $prodName !== '') {
                $upStock = $connectionDB->prepare("UPDATE stock SET quantity_available = quantity_available + ? WHERE name = ? AND quantity_available IS NOT NULL");
                if ($upStock) {
                    $upStock->bind_param('is', $prodQty, $prodName);
                    $upStock->execute();
                    $upStock->close();
                }
            }
        }
        $dettStmt->close();
    }

    $results[] = ['id' => $idScontr, 'status' => 'updated', 'esito' => 'Scontrino stornato con successo'];
}

$summary = count($ids) === 1
    ? ($updatedCount === 1 ? 'Scontrino stornato con successo' : 'Scontrino già stornato o non trovato')
    : ($updatedCount > 0 ? 'Scontrini stornati: ' . $updatedCount . '/' . count($ids) : 'Nessuno scontrino stornato');

echo json_encode([
    'esito' => $summary,
    'updatedCount' => $updatedCount,
    'results' => $results
]);

