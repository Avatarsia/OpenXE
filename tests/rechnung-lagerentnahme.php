<?php

// Run against an empty, disposable database named openxe_invoice_test.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../phpwf/plugins/class.mysql.php';
require __DIR__ . '/../www/lib/class.erpapi.php';

class InvoiceStockTestErp extends erpAPI
{
    public bool $quickRelease = false;

    public function GetTMP()
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    }

    public function GetNextNummer($type, $projekt = '', $data = '', $nummernsperre = null)
    {
        return 'R-' . $data;
    }

    public function SetXMLRechnung($id) {}
    public function LieferscheinProtokoll($id, $text) {}
    public function SeriennummernCheckLieferscheinWarnung(int $lieferschein_id) {}
    public function Verbrauchslagervorhanden() { return false; }
    public function Firmendaten($field, $projekt = '', $clearcache = false)
    {
        return $field === 'schnellanlegen_ohnefreigabe' && $this->quickRelease ? 1 : 0;
    }

    public function RunHook($name, $parametercount = 0, &$parameter1 = null, &$parameter2 = null, &$parameter3 = null,
        &$parameter4 = null, &$parameter5 = null, &$parameter6 = null, &$parameter7 = null, &$parameter8 = null,
        &$parameter9 = null, &$parameter10 = null, &$parameter11 = null, &$parameter12 = null, &$parameter13 = null) {}
}

function expect($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("$message: expected $expected, got $actual");
    }
}

$port = (int)getenv('OPENXE_TEST_DB_PORT');
$password = getenv('OPENXE_TEST_DB_PASSWORD');
if (!$port || !$password) {
    throw new RuntimeException('Set OPENXE_TEST_DB_PORT and OPENXE_TEST_DB_PASSWORD for a disposable database.');
}
$db = new DB('127.0.0.1', 'openxe_invoice_test', 'root', $password, null, $port);
$app = (object)[
    'DB' => $db,
    'Container' => new class {
        public function get($name) { return new Psr\Log\NullLogger(); }
    },
];
$erp = new InvoiceStockTestErp($app);
if (($argv[1] ?? '') === '--book-delivery') {
    echo "ready\n";
    flush();
    $erp->LieferscheinAuslagern((int)$argv[2]);
    exit;
}

$db->Query('SET SESSION innodb_strict_mode = OFF');
$schema = file_get_contents(__DIR__ . '/../database/struktur.sql');
foreach (['projekt', 'adresse', 'artikel', 'auftrag', 'auftrag_position', 'rechnung', 'rechnung_position', 'lieferschein',
    'lieferschein_position', 'lager', 'lager_platz', 'lager_platz_inhalt', 'lager_bewegung', 'lager_reserviert',
    'objekt_lager_platz', 'lager_charge', 'chargen_log', 'beleg_chargesnmhd', 'lager_rechnung_lieferschein', 'sammelrechnung_position', 'rechnung_protokoll'] as $table) {
    if (!preg_match('/^CREATE TABLE `' . $table . '` \([^;]+;/m', $schema, $match)) {
        throw new RuntimeException("Missing table $table in schema");
    }
    $db->Query($match[0]);
}

// The upgrader adds this column after loading struktur.sql.
$db->Query("ALTER TABLE lager_platz ADD kommissionierlager tinyint(1) NOT NULL DEFAULT 0");

$db->Query("INSERT INTO projekt (id) VALUES (1)");
$db->Query("INSERT INTO lager (id) VALUES (1)");
$db->Query("INSERT INTO lager_platz (id, lager, kurzbezeichnung) VALUES (1, 1, 'A1')");
$db->Query("INSERT INTO artikel (id, lagerartikel, seriennummern) VALUES (1, 1, 'keine'), (2, 0, 'keine'), (3, 1, 'keine')");
$db->Query("INSERT INTO lager_platz_inhalt (lager_platz, artikel, menge) VALUES (1, 1, 20)");
$db->Query("INSERT INTO auftrag (id, adresse, projekt, belegnr) VALUES (1, 1, 1, 'A-1'), (2, 1, 1, 'A-2')");
$db->Query("INSERT INTO rechnung (id, adresse, projekt, auftragid, status)
    VALUES (1, 1, 1, 1, 'angelegt'), (2, 1, 1, 2, 'angelegt'), (3, 1, 1, 0, 'angelegt'), (4, 1, 1, 0, 'angelegt')");
$db->Query("INSERT INTO rechnung_position (rechnung, artikel, menge, sort)
    VALUES (1, 1, 1.5, 1), (1, 1, 0.5, 2), (1, 2, 2, 3), (2, 1, 3, 1), (3, 1, 1, 1), (3, 3, 1, 2), (4, 1, 2, 1)");

$stock = fn(): float => (float)$db->Select("SELECT SUM(menge) FROM lager_platz_inhalt WHERE artikel = 1");
$makeDelivery = static function (int $id, int $order, float $quantity) use ($db): void {
    $db->Query("INSERT INTO lieferschein (id, adresse, projekt, auftragid, belegnr, status)
        VALUES ($id, 1, 1, $order, 'L-$id', 'freigegeben')");
    $db->Query("INSERT INTO lieferschein_position (lieferschein, artikel, menge) VALUES ($id, 1, $quantity)");
};

$erp->BelegFreigabe('rechnung', 1);
expect(18.0, $stock(), 'Invoice release debits both stock positions by default');
expect(0, (int)$db->Select('SELECT COUNT(*) FROM lager_bewegung WHERE artikel = 2'), 'Services stay unbooked');
$erp->BelegFreigabe('rechnung', 1);
expect(18.0, $stock(), 'Release retry is idempotent');

$makeDelivery(1, 1, 1);
$erp->LieferscheinAuslagern(1);
expect(18.0, $stock(), 'A later partial delivery uses the invoice debit');
$makeDelivery(2, 1, 2);
$erp->LieferscheinAuslagern(2);
expect(17.0, $stock(), 'A second delivery debits only the quantity still open');
$erp->LieferscheinAuslagern(2);
expect(17.0, $stock(), 'Retry of a delivery credited by an invoice is idempotent');

$makeDelivery(3, 2, 3);
$erp->LieferscheinAuslagern(3);
$erp->BelegFreigabe('rechnung', 2);
expect(14.0, $stock(), 'A delivery created before release owns stock posting');

$erp->BelegFreigabe('rechnung', 3);
expect('freigegeben', $db->Select('SELECT status FROM rechnung WHERE id = 3'), 'Shortage does not block release');
expect('R-3', $db->Select('SELECT belegnr FROM rechnung WHERE id = 3'), 'Invoice receives its number despite shortage');
expect(1, (int)$db->Select('SELECT schreibschutz FROM rechnung WHERE id = 3'), 'Invoice is locked despite shortage');
expect(14.0, $stock(), 'Shortage rolls back any debit already made');
expect(true, str_contains($erp->RechnungLagerwarnung(3), 'Artikel-ID: 3'), 'Invoice records the shortage warning');
expect(true, $erp->RechnungLagerAenderungenGesperrt(3), 'Shortage prevents lifting write protection');
$erp->BelegFreigabe('rechnung', 3);
expect(1, (int)$db->Select("SELECT COUNT(*) FROM rechnung_protokoll WHERE rechnung = 3 AND grund LIKE 'Fehlbestand:%'"),
    'Repeated release does not duplicate the warning');

$db->Query('UPDATE projekt SET rechnung_lagerentnahme = 0 WHERE id = 1');
$erp->BelegFreigabe('rechnung', 4);
expect(14.0, $stock(), 'Project opt-out releases without debiting stock');
$db->Query("UPDATE projekt SET rechnung_lagerentnahme = 1 WHERE id = 1");
$db->Query("INSERT INTO rechnung (id, adresse, projekt, status) VALUES (5, 1, 1, 'angelegt')");
$erp->quickRelease = true;
$erp->SchnellFreigabe('rechnung', 5);
expect('angelegt', $db->Select('SELECT status FROM rechnung WHERE id = 5'), 'Empty invoice stays a draft');
$db->Query("INSERT INTO rechnung_position (rechnung, artikel, menge) VALUES (5, 1, 2)");
$erp->SchnellFreigabe('rechnung', 5);
expect(12.0, $stock(), 'Quick release with positions debits stock');

$makeDelivery(4, 0, 2);
$db->Query('UPDATE lieferschein SET rechnungid = 5 WHERE id = 4');
$erp->LieferscheinAuslagern(4);
expect(12.0, $stock(), 'Explicitly linked delivery does not debit a standalone invoice twice');

$makeDelivery(5, 0, 1);
$erp->LieferscheinAuslagern(5);
expect(11.0, $stock(), 'Unrelated delivery of the same article still debits stock');

$db->Query("INSERT INTO rechnung (id, adresse, projekt, status, lieferschein) VALUES (6, 1, 1, 'angelegt', 6)");
$db->Query("INSERT INTO rechnung_position (rechnung, artikel, menge) VALUES (6, 1, 1)");
$makeDelivery(6, 0, 1);
$db->Query("UPDATE lieferschein SET status = 'angelegt' WHERE id = 6");
$erp->BelegFreigabe('rechnung', 6);
expect(11.0, $stock(), 'Existing linked draft delivery owns the stock posting');
$erp->LieferscheinAuslagern(6);
expect(10.0, $stock(), 'Delivery linked from the invoice debits once');

$db->Query("INSERT INTO artikel (id, lagerartikel, seriennummern, chargenverwaltung)
    VALUES (4, 1, 'keine', 1), (5, 1, 'vomprodukteinlagern', 0)");
$db->Query("INSERT INTO lager_platz_inhalt (lager_platz, artikel, menge) VALUES (1, 4, 2), (1, 5, 2)");
$db->Query("INSERT INTO lager_charge (artikel, lager_platz, menge, charge) VALUES (4, 1, 1, 'CH-1')");
$db->Query("INSERT INTO rechnung (id, adresse, projekt, status)
    VALUES (7, 1, 1, 'angelegt'), (8, 1, 1, 'angelegt'), (9, 1, 1, 'angelegt')");
$db->Query("INSERT INTO rechnung_position (rechnung, artikel, menge) VALUES (7, 4, 1), (8, 4, 1), (9, 5, 1)");
$erp->BelegFreigabe('rechnung', 7);
expect(1.0, (float)$db->Select('SELECT SUM(menge) FROM lager_platz_inhalt WHERE artikel = 4'), 'Batch item debits base stock');
expect(0.0, (float)$db->Select('SELECT SUM(menge) FROM lager_charge WHERE artikel = 4'), 'Batch item debits the batch');
$erp->BelegFreigabe('rechnung', 8);
expect('freigegeben', $db->Select('SELECT status FROM rechnung WHERE id = 8'), 'Batch shortage does not block release');
expect(true, str_contains($erp->RechnungLagerwarnung(8), 'Artikel-ID: 4'), 'Batch shortage is recorded');
foreach ([9] as $invoice) {
    try {
        $erp->BelegFreigabe('rechnung', $invoice);
        throw new LogicException('Invoice release accepted incomplete tracking information');
    } catch (DomainException $e) {
        expect('angelegt', $db->Select("SELECT status FROM rechnung WHERE id = $invoice"), 'Tracking failure leaves invoice as draft');
    }
}
expect(1.0, (float)$db->Select('SELECT SUM(menge) FROM lager_platz_inhalt WHERE artikel = 4'), 'Missing batch rolls back physical debit');
expect(2.0, (float)$db->Select('SELECT SUM(menge) FROM lager_platz_inhalt WHERE artikel = 5'), 'Serial tracked stock is retained');
$db->Query("INSERT INTO rechnung (id, adresse, projekt, status) VALUES (10, 1, 1, 'angelegt')");
$db->Query("INSERT INTO rechnung_position (rechnung, artikel, menge) VALUES (10, 1, 2)");
$erp->BelegFreigabe('rechnung', 10);
$makeDelivery(7, 0, 2);
$makeDelivery(8, 0, 2);
$db->Query('UPDATE rechnung SET lieferschein = 7 WHERE id = 10');
$db->Query('UPDATE lieferschein SET rechnungid = 10 WHERE id = 8');
$erp->LieferscheinAuslagern(7);
$erp->LieferscheinAuslagern(8);
expect(6.0, $stock(), 'Two delivery links can only use one invoice debit');
$erp->LieferscheinAuslagern(8);
expect(6.0, $stock(), 'Delivery still cannot debit twice after its credit pool is exhausted');

$db->Query("INSERT INTO rechnung (id, adresse, projekt, status) VALUES (11, 1, 1, 'angelegt')");
$db->Query("INSERT INTO rechnung_position (rechnung, artikel, menge) VALUES (11, 1, 3)");
$pos = $db->GetInsertID();
$db->Query("INSERT INTO sammelrechnung_position (rechnung, rechnung_position_id, lieferschein_position_id, menge)
    SELECT 11, $pos, id, 1 FROM lieferschein_position WHERE lieferschein = 5");
$erp->BelegFreigabe('rechnung', 11);
expect(4.0, $stock(), 'Collective invoice debits only the part not linked to a delivery');
expect(1, (int)$db->Select('SELECT schreibschutz FROM rechnung WHERE id = 11'), 'Stock posting protects invoice quantities');
$db->Query("INSERT INTO auftrag (id, adresse, projekt, belegnr) VALUES (3, 1, 1, 'A-3')");
$db->Query("INSERT INTO rechnung (id, adresse, projekt, auftragid, status) VALUES (12, 1, 1, 3, 'angelegt')");
$db->Query("INSERT INTO rechnung_position (rechnung, artikel, menge) VALUES (12, 1, 1)");
$db->Query('START TRANSACTION');
$erp->BelegFreigabe('rechnung', 12);

$deliveryDb = $db->getClone();
$deliveryDb->Query("INSERT INTO lieferschein (id, rechnungid, adresse, projekt, belegnr, status)
    VALUES (9, 12, 1, 1, 'L-9', 'freigegeben')");
$deliveryDb->Query('INSERT INTO lieferschein_position (lieferschein, artikel, menge) VALUES (9, 1, 1)');
$worker = proc_open([PHP_BINARY, __FILE__, '--book-delivery', '9'], [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);
expect("ready\n", str_replace("\r", '', fgets($pipes[1])), 'Concurrent delivery worker starts');
$deadline = microtime(true) + 5;
do {
    $lockOwner = $deliveryDb->Select("SELECT IS_USED_LOCK(SHA2(CONCAT(DATABASE(), ':beleg-lagerentnahme'), 256))");
    if ($lockOwner) {
        break;
    }
    usleep(10000);
} while (microtime(true) < $deadline);
$db->Query('COMMIT');
$error = stream_get_contents($pipes[2]);
foreach ($pipes as $pipe) {
    fclose($pipe);
}
expect(0, proc_close($worker), 'Concurrent delivery completes ' . $error);
expect(true, (bool)$lockOwner, 'Delivery reached its lock before the invoice committed');
expect(3.0, $stock(), 'Delivery sees the committed invoice debit and does not debit again');

try {
    $erp->LieferscheinAuslagern(3, belegtyp: 'auftrag');
    throw new LogicException('Pre-picking accepted an already debited invoice');
} catch (DomainException $e) {
    expect(3.0, $stock(), 'Pre-picking does not debit an already posted invoice');
}

echo "Invoice stock integration checks passed.\n";
