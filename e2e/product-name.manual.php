<?php
/**
 * Fase 4 punto 4 - harness per config/product_name.php (nome prodotto sempre
 * MAIUSCOLO + controllo duplicati, decisione 2026-09-12). NON automatico:
 *
 *   php e2e/product-name.manual.php
 *
 * Su uno schema usa-e-getta, non tocca ne' config/variabili.env ne' il DB
 * opensagra_pos (stesso principio degli altri *.manual.php).
 *
 * Perche' questo controllo esiste: api/push_local_sales.php decrementa lo
 * stock sul centrale cercando per NOME (lo scontrino salva il nome, non un
 * id) - due prodotti con lo stesso nome farebbero scalare per errore anche
 * quello non coinvolto nella vendita, in silenzio.
 */

require_once __DIR__ . '/../config/product_name.php';

const TEST_DB = 'opensagra_pos_prodname_test';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$fails = 0;
function check(bool $cond, string $msg): void
{
    global $fails;
    echo ($cond ? "  OK   " : "  FAIL ") . $msg . PHP_EOL;
    if (!$cond) { $fails++; }
}

function rootConn(): mysqli
{
    foreach ([['root', ''], ['root', 'root']] as [$u, $p]) {
        try {
            $c = @new mysqli('127.0.0.1', $u, $p);
            if (!$c->connect_errno) { return $c; }
        } catch (Throwable $e) { /* prova la prossima */ }
    }
    fwrite(STDERR, "Impossibile connettersi a MariaDB come root su 127.0.0.1.\n");
    exit(2);
}

echo '== normalizeProductNameForStorage ==' . PHP_EOL;
check(normalizeProductNameForStorage('panino') === 'PANINO', 'minuscolo -> MAIUSCOLO');
check(normalizeProductNameForStorage('  Panino   Salsiccia  ') === 'PANINO SALSICCIA', 'trim + spazi multipli collassati a uno');
check(normalizeProductNameForStorage('Bibità') === 'BIBITÀ', 'accenti gestiti correttamente (mb_strtoupper)');

echo '== productNameCompareKey ==' . PHP_EOL;
check(productNameCompareKey('Coca-Cola') === productNameCompareKey('Coca Cola'), 'trattino equivalente a spazio nel confronto (refusi frequenti)');
check(productNameCompareKey('Panino Salsiccia') !== productNameCompareKey('Panino Porchetta'), 'nomi diversi restano diversi - confronto sulla stringa INTERA, non un pezzo');

echo '== productNameIsDuplicate (DB reale, schema usa-e-getta) ==' . PHP_EOL;
$root = rootConn();
$root->query('DROP DATABASE IF EXISTS ' . TEST_DB);
$root->query('CREATE DATABASE ' . TEST_DB . ' CHARACTER SET utf8mb4');
$root->select_db(TEST_DB);
$root->query('CREATE TABLE stock (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
$root->query("INSERT INTO stock (name) VALUES ('PANINO SALSICCIA'), ('COCA COLA')");

check(productNameIsDuplicate($root, 'panino salsiccia') === true, '"panino salsiccia" (minuscolo) rilevato duplicato di "PANINO SALSICCIA"');
check(productNameIsDuplicate($root, 'PANINO  SALSICCIA') === true, 'spazi doppi non aggirano il controllo');
check(productNameIsDuplicate($root, 'Panino Porchetta') === false, '"Panino Porchetta" NON e\' un duplicato (nome diverso)');
check(productNameIsDuplicate($root, 'Coca-Cola') === true, '"Coca-Cola" (trattino) rilevato duplicato di "COCA COLA"');

$id = (int) $root->query("SELECT id FROM stock WHERE name = 'PANINO SALSICCIA'")->fetch_assoc()['id'];
check(productNameIsDuplicate($root, 'Panino Salsiccia', $id) === false, 'modificando lo stesso prodotto (stesso nome) non si autoblocca');
check(productNameIsDuplicate($root, 'Coca Cola', $id) === true, 'ma rinominandolo come un ALTRO prodotto gia\' esistente resta bloccato');

$root->query('DROP DATABASE IF EXISTS ' . TEST_DB);
$root->close();

echo PHP_EOL . ($fails === 0 ? 'TUTTO VERDE' : "$fails ASSERZIONI FALLITE") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
