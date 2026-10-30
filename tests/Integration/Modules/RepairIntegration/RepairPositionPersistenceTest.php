<?php
declare(strict_types=1);

namespace Tests\Integration\Modules\RepairIntegration;

use PHPUnit\Framework\TestCase;
use Xentral\Components\Database\Adapter\MysqliAdapter;
use Xentral\Components\Database\Database;
use Xentral\Components\Database\DatabaseConfig;
use Xentral\Components\Database\SqlQuery\QueryFactory;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;
use Xentral\Modules\RepairIntegration\Migration\RepairIntegrationMigration;
use Xentral\Modules\RepairIntegration\Service\RepairDraftCreator;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;
use Xentral\Modules\RepairIntegration\Service\RepairBelegService;

if (getenv('REPAIR_POSITION_TEST_DB') === 'repair_position_review_20261019') {
    require_once __DIR__ . '/../../../../phpwf/plugins/class.mysql.php';
    require_once __DIR__ . '/../../../../www/lib/class.erpapi.php';
    require_once __DIR__ . '/../../../../www/pages/repairintegration.php';
}

final class RepairPositionPersistenceTest extends TestCase
{
    private static Database $db;
    private \stdClass $app;

    public static function setUpBeforeClass(): void
    {
        if (getenv('REPAIR_POSITION_TEST_DB') !== 'repair_position_review_20261019') {
            self::markTestSkipped('Requires isolated repair_position_review_20261019 database.');
        }
        self::$db = new Database(new MysqliAdapter(new DatabaseConfig(
            '127.0.0.1', 'root', getenv('REPAIR_TEST_PASSWORD'), 'repair_position_review_20261019', 'utf8mb4'
        )), new QueryFactory('mysql'));
        self::$db->exec("SET SESSION sql_mode = ''");
    }

    protected function setUp(): void
    {
        foreach (self::$db->fetchCol('SHOW TABLES') as $table) self::$db->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
        self::$db->exec('CREATE TABLE hook (id int AUTO_INCREMENT PRIMARY KEY, name varchar(255), alias varchar(255), aktiv int, parametercount int, description text)');
        self::$db->exec('CREATE TABLE hook_register (id int AUTO_INCREMENT PRIMARY KEY, hook_action int, `function` varchar(255), aktiv int, position int, hook int, module varchar(255), module_parameter int)');
        (new RepairIntegrationMigration(self::$db))->install();
        self::$db->exec("CREATE TABLE artikel (id int PRIMARY KEY, nummer varchar(50), name_de text, anabregs_text text, umsatzsteuer varchar(50), projekt int, mlmpunkte decimal(10,2), mlmbonuspunkte decimal(10,2), mlmdirektpraemie decimal(10,2), geloescht int DEFAULT 0)");
        self::$db->exec("INSERT INTO artikel VALUES (17, '100003', 'Reparatur', '', 'normal', 1, 0, 0, 0, 0), (18, 'VERSAND', 'Versand', '', 'normal', 1, 0, 0, 0, 0)");
        self::$db->exec('CREATE TABLE verkaufspreise (adresse int, artikel int, kundenartikelnummer varchar(100), ab_menge decimal(10,2), gueltig_bis date)');
        foreach (['angebot','auftrag','rechnung'] as $type) {
            self::$db->exec("CREATE TABLE `$type` (id int AUTO_INCREMENT PRIMARY KEY, adresse int, betreff text, waehrung varchar(3), steuersatz_normal decimal(5,2), steuersatz_ermaessigt decimal(5,2), ust_befreit int DEFAULT 0, ustid varchar(50) DEFAULT '')");
            self::$db->exec("CREATE TABLE `{$type}_position` (id int AUTO_INCREMENT PRIMARY KEY, `$type` int, artikel int, bezeichnung text, beschreibung text, nummer varchar(50), menge decimal(14,6), preis decimal(16,6), waehrung varchar(3), sort int, lieferdatum date, umsatzsteuer varchar(50), status varchar(50), projekt int, vpe varchar(30), punkte decimal(10,2), bonuspunkte decimal(10,2), mlmdirektpraemie decimal(10,2), artikelnummerkunde varchar(100), steuersatz decimal(5,2) DEFAULT NULL, keinrabatterlaubt int DEFAULT 0, rabattsync int DEFAULT 0, rabatt decimal(10,2) DEFAULT 0, grundrabatt decimal(10,2) DEFAULT 0, rabatt1 decimal(10,2) DEFAULT 0, rabatt2 decimal(10,2) DEFAULT 0, rabatt3 decimal(10,2) DEFAULT 0, rabatt4 decimal(10,2) DEFAULT 0, rabatt5 decimal(10,2) DEFAULT 0) ENGINE=InnoDB");
        }
        $this->app = new \stdClass();
        $this->app->DB = new \DB('127.0.0.1', 'repair_position_review_20261019', 'root', getenv('REPAIR_TEST_PASSWORD'));
        $this->app->erp = new PositionNativeErp($this->app);
        self::$db->exec("CREATE TABLE adresse (id int PRIMARY KEY, kundennummer varchar(50))");
        self::$db->exec("INSERT INTO adresse VALUES (3, 'TEST-1001')");
        self::$db->exec("CREATE TABLE ticket (id int PRIMARY KEY, schluessel varchar(50), adresse int, betreff text)");
        self::$db->exec("INSERT INTO ticket VALUES (42, 'T42', 3, 'Repair')");
    }

    private function prepared(string $type = 'angebot'): array
    {
        return ['ticket_id' => 42, 'ticket_schluessel' => 'T42', 'adresse_id' => 3, 'beleg_typ' => $type,
            'betreff' => "Reparatur O'Brien", 'position' => ['article_id' => 17, 'name' => "Service O'Brien",
                'description' => "Fehler <script> & O'Brien\n- Wechsel Messer", 'net_amount' => '100.00', 'gross_amount' => null]];
    }

    public function testActualCorePositionAcceptsRepairTextAndAdditionalShippingArticle(): void
    {
        $id = RepairDraftCreator::create($this->app, $this->prepared(), "User O'Brien");
        $this->app->erp->AddPositionManuell('angebot', $id, 18, 1);
        $rows = self::$db->fetchAll('SELECT * FROM angebot_position ORDER BY sort');
        self::assertCount(2, $rows);
        self::assertSame('100003', $rows[0]['nummer']);
        self::assertSame("Service O'Brien", $rows[0]['bezeichnung']);
        self::assertSame("Fehler &lt;script&gt; &amp; O&#039;Brien<br>- Wechsel Messer", $rows[0]['beschreibung']);
        self::assertSame('100.000000', $rows[0]['preis']);
        self::assertSame('VERSAND', $rows[1]['nummer']);
        self::assertSame('7.500000', $rows[1]['preis']);
        self::assertSame($id, (int)self::$db->fetchValue('SELECT beleg_id FROM repair_ticket_beleg'));
    }

    public function testLinkFailureRollsBackRealCoreDocumentAndPosition(): void
    {
        self::$db->exec("CREATE TRIGGER reject_link BEFORE INSERT ON repair_ticket_beleg FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected link failure'");
        try {
            RepairDraftCreator::create($this->app, $this->prepared(), 'User');
            self::fail('Expected database failure');
        } catch (\Throwable $e) {
            self::assertSame(0, (int)self::$db->fetchValue('SELECT COUNT(*) FROM angebot'));
            self::assertSame(0, (int)self::$db->fetchValue('SELECT COUNT(*) FROM angebot_position'));
            self::assertSame(0, (int)self::$db->fetchValue('SELECT COUNT(*) FROM repair_ticket_beleg'));
        }
    }

    public function testSchemaInstallAndUpgradeAreIdempotent(): void
    {
        $columns = self::$db->fetchCol('SHOW COLUMNS FROM ticket_repair_details');
        foreach (['public_diagnosis','service_work','final_check','position_net_amount','diagnosis_cost_snapshot','wartung_paket_price_snapshot'] as $column) self::assertContains($column, $columns);
        $migration = new RepairIntegrationMigration(self::$db);
        (new RepairConfigService(self::$db))->set('schema_version', '1.5.0');
        $migration->upgrade(); $migration->upgrade();
        self::assertSame('1.8.0', $migration->getCurrentVersion());
        foreach (['repair_status_exchange', 'repair_media_exchange', 'repair_exchange_jobs', 'repair_media_failures'] as $table) {
            self::assertSame(1, (int)self::$db->fetchValue('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', ['table'=>$table]));
        }
    }

    public function testStaffFieldsPersistWithoutTouchingInternalNotes(): void
    {
        $gateway = new RepairDetailsGateway(self::$db);
        $gateway->create(['ticket_id' => 42]);
        $gateway->updateDiagnosisFields(42, 'internal', '500.00', null, 'private');
        $gateway->updateServiceFields(42, ['public_diagnosis' => "O'Brien", 'service_work' => "- Messer\n- TH Board", 'final_check' => '- Testdruck', 'position_net_amount' => '100.00']);
        $row = $gateway->getByTicketId(42);
        self::assertSame("O'Brien", $row['public_diagnosis']);
        self::assertSame('private', $row['repair_notes']);
        self::assertSame('500.00', $row['quote_amount']);
        self::assertSame('100.00', $row['position_net_amount']);
    }

    public function testClearedChecklistsRemainEmptyAfterSavingAndReloading(): void
    {
        $this->controller();
        $this->app->Container->get('RepairBelegService')->saveServiceFields(42, ['public_diagnosis' => '', 'final_check' => '']);
        $details = (new RepairDetailsGateway(self::$db))->getByTicketId(42);
        self::assertSame('', $details['public_diagnosis']);
        self::assertSame('', $details['final_check']);
        $values = \Xentral\Modules\RepairIntegration\Service\RepairPositionTemplate::values($details, 'T42');
        self::assertSame('', $values['eingangsdiagnose']);
        self::assertSame('', $values['endkontrolle']);
    }

    public function testHistoricalFeeCanBeBackfilledButNotOverwritten(): void
    {
        $gateway = new RepairDetailsGateway(self::$db);
        $id = $gateway->create(['ticket_id' => 42]);
        $gateway->update($id, ['diagnosis_cost_snapshot' => '49.00']);
        $gateway->update($id, ['diagnosis_cost_snapshot' => '99.00']);
        self::assertSame('49.00', $gateway->getByTicketId(42)['diagnosis_cost_snapshot']);
    }

    private function controller(): \Repairintegration
    {
        $config = new RepairConfigService(self::$db);
        $config->set('service_article_number', '100003');
        $details = new RepairDetailsGateway(self::$db);
        $details->create(['ticket_id' => 42, 'ticket_schluessel' => 'T42', 'service_type' => 'reparatur', 'manufacturer' => 'Bambu Lab', 'model' => 'X1C', 'issue_description' => 'TH Board', 'diagnosis_cost_snapshot' => '49.00']);
        $gateway = new RepairBelegGateway(self::$db);
        $service = new RepairBelegService(self::$db, $details, $gateway, $config);
        $this->app->Secure = new PositionPostBoundary();
        $this->app->Location = new class { public string $url = ''; public function execute($url): void { $this->url = $url; } };
        $this->app->User = new class { public function GetName(): string { return 'Test User'; } };
        $this->app->Container = new class(['Database' => self::$db, 'RepairBelegGateway' => $gateway, 'RepairBelegService' => $service]) {
            public function __construct(private array $services) {}
            public function get($name) {
                if ($name === 'Session') return new class { public function isCsrfTokenValid($key, $value): bool { return $key === 'repair_beleg' && $value === 'valid-test-token'; } };
                return $this->services[$name];
            }
        };
        return new \Repairintegration($this->app, true);
    }

    public function testControllerCreatesNativeOfferAndRepeatedClickPreservesAddedShipping(): void
    {
        $controller = $this->controller();
        $controller->RepairCreateBeleg();
        self::assertStringContainsString('module=angebot&action=edit&id=', $this->app->Location->url);
        self::assertStringEndsWith('#tabs-2', $this->app->Location->url);
        $id = (int)self::$db->fetchValue('SELECT id FROM angebot');
        self::assertGreaterThan(0, $id);
        $text = (string)self::$db->fetchValue('SELECT beschreibung FROM angebot_position');
        self::assertStringContainsString('- Aktuelle Diagnose', $text);
        $this->app->erp->AddPositionManuell('angebot', $id, 18, 1);
        $this->app->Secure->POST['repair_position_net_amount'] = '999.00';
        $controller->RepairCreateBeleg();
        self::assertSame(1, (int)self::$db->fetchValue('SELECT COUNT(*) FROM angebot'));
        self::assertSame('index.php?module=angebot&action=edit&id=' . $id . '#tabs-2', $this->app->Location->url);
        self::assertSame(2, (int)self::$db->fetchValue('SELECT COUNT(*) FROM angebot_position'));
        self::assertSame('100.000000', self::$db->fetchValue('SELECT preis FROM angebot_position WHERE artikel = 17'));
        self::assertSame($text, self::$db->fetchValue('SELECT beschreibung FROM angebot_position WHERE artikel = 17'));
        self::assertSame(1, (int)self::$db->fetchValue('SELECT COUNT(*) FROM repair_ticket_beleg'));
    }

    public function testDeletedOfferDoesNotBlockANewNativeDraft(): void
    {
        $controller = $this->controller();
        $controller->RepairCreateBeleg();
        $oldId = (int)self::$db->fetchValue('SELECT id FROM angebot');
        self::$db->exec('DELETE FROM angebot_position WHERE angebot = ' . $oldId);
        self::$db->exec('DELETE FROM angebot WHERE id = ' . $oldId);
        $controller->RepairCreateBeleg();
        $newId = (int)self::$db->fetchValue('SELECT id FROM angebot');
        self::assertGreaterThan($oldId, $newId);
        self::assertStringEndsWith('id=' . $newId . '#tabs-2', $this->app->Location->url);
        self::assertSame(1, (int)self::$db->fetchValue('SELECT COUNT(*) FROM angebot_position'));
    }

    public function testControllerRejectsMissingCsrfBeforeAnyDocumentWrite(): void
    {
        $controller = $this->controller();
        $this->app->Secure->POST['repair_csrf'] = '';
        $controller->RepairCreateBeleg();
        self::assertSame(0, (int)self::$db->fetchValue('SELECT COUNT(*) FROM angebot'));
        self::assertStringContainsString('module=ticket&action=edit', $this->app->Location->url);
    }

    public function testControllerFeeOnlyUsesOriginalPriceWithNativeTax(): void
    {
        $controller = $this->controller();
        $this->app->Secure->POST['repair_beleg_typ'] = 'fee';
        $controller->RepairCreateBeleg();
        self::assertStringContainsString('module=rechnung&action=edit&id=', $this->app->Location->url);
        self::assertStringEndsWith('#tabs-2', $this->app->Location->url);
        self::assertSame(1, (int)self::$db->fetchValue('SELECT COUNT(*) FROM rechnung_position'));
        self::assertSame('41.176471', self::$db->fetchValue('SELECT preis FROM rechnung_position'));
        self::assertStringContainsString('Keine Reparatur durchgefuehrt', (string)self::$db->fetchValue('SELECT beschreibung FROM rechnung_position'));
    }

    public function testFeeStaysAtOriginalGrossAmountWithNativeCustomerDiscount(): void
    {
        self::$db->exec("ALTER TABLE artikel ADD keinrabatterlaubt int DEFAULT 0, ADD porto int DEFAULT 0, ADD rabatt int DEFAULT 0, ADD rabatt_prozent decimal(10,2) DEFAULT 0");
        self::$db->exec("ALTER TABLE rechnung ADD belegnr varchar(50) DEFAULT '', ADD status varchar(50) DEFAULT 'angelegt', ADD schreibschutz int DEFAULT 0, ADD gruppe int DEFAULT 1, ADD rabatt decimal(10,2) DEFAULT 0, ADD rabatt1 decimal(10,2) DEFAULT 0, ADD rabatt2 decimal(10,2) DEFAULT 0, ADD rabatt3 decimal(10,2) DEFAULT 0, ADD rabatt4 decimal(10,2) DEFAULT 0, ADD rabatt5 decimal(10,2) DEFAULT 0, ADD realrabatt decimal(10,2) DEFAULT 0");
        self::$db->exec("CREATE TABLE gruppen (id int, art varchar(50), grundrabatt decimal(10,2), rabatt1 decimal(10,2), rabatt2 decimal(10,2), rabatt3 decimal(10,2), rabatt4 decimal(10,2), rabatt5 decimal(10,2))");
        self::$db->exec("INSERT INTO gruppen VALUES (1, 'preisgruppe', 10, 0, 0, 0, 0, 0)");
        $this->app->erp = new PositionDiscountNativeErp($this->app);
        $prepared = $this->prepared('rechnung');
        $prepared['position']['net_amount'] = null;
        $prepared['position']['gross_amount'] = '49.00';
        RepairDraftCreator::create($this->app, $prepared, 'User');
        $position = self::$db->fetchRow('SELECT preis, rabatt, keinrabatterlaubt FROM rechnung_position');
        self::assertEqualsWithDelta(49.00, (float)$position['preis'] * (1 - (float)$position['rabatt'] / 100) * 1.19, 0.005);
        self::assertSame(1, (int)$position['keinrabatterlaubt']);
        self::assertSame('0.00', $position['rabatt']);
    }

    public function testNativeContinuationCallbacksPreserveLinkWithoutDuplicates(): void
    {
        $controller = $this->controller();
        $controller->RepairCreateBeleg();
        $offer = (int)self::$db->fetchValue('SELECT id FROM angebot');
        $controller->RepairOnAngebotZuAuftrag($offer, 200);
        $controller->RepairOnAngebotZuAuftrag($offer, 200);
        $controller->RepairOnAuftragZuRechnung(200, 300);
        self::assertSame(3, (int)self::$db->fetchValue('SELECT COUNT(*) FROM repair_ticket_beleg'));
        self::assertSame(42, (int)self::$db->fetchValue("SELECT ticket_id FROM repair_ticket_beleg WHERE beleg_typ = 'rechnung'"));
    }
}

/** Keep AddPositionManuell and tax lookup real, isolate unrelated ERP setup. */
if (getenv('REPAIR_POSITION_TEST_DB') === 'repair_position_review_20261019') {
class PositionNativeErp extends \erpAPI
{
    public function __construct($app) { $this->app = $app; }
    public function CreateAngebot($adresse = '') { $this->app->DB->Query("INSERT INTO angebot (adresse,waehrung,steuersatz_normal,steuersatz_ermaessigt) VALUES (" . (int)$adresse . ",'EUR',19,7)"); return $this->app->DB->GetInsertID(); }
    public function LoadAngebotStandardwerte($id, $adresse) {}
    public function AngebotNeuberechnen($id) {}
    public function RechteVorhanden($module, $action) { return true; }
    public function CreateRechnung($adresse = '') { $this->app->DB->Query("INSERT INTO rechnung (adresse,waehrung,steuersatz_normal,steuersatz_ermaessigt) VALUES (" . (int)$adresse . ",'EUR',19,7)"); return $this->app->DB->GetInsertID(); }
    public function LoadRechnungStandardwerte($id, $adresse) {}
    public function RechnungNeuberechnen($id) {}
    public function GetVerkaufspreis($artikel, $menge, $adresse=0, $waehrung='', &$returnwaehrung='', $returnarr=false, $auchpreisegrmenge=false) { return (int)$artikel === 18 ? 7.50 : 23.50; }
    public function RunHook($name, $parametercount = 0, &...$parameters) {}
}
}

final class PositionPostBoundary
{
    public array $POST = ['repair_beleg_typ' => 'angebot', 'repair_csrf' => 'valid-test-token',
        'repair_public_diagnosis' => '- Aktuelle Diagnose', 'repair_service_work' => '- Messer',
        'repair_final_check' => '- Testdruck', 'repair_position_net_amount' => '100.00'];
    public function GetGET($name) { return $name === 'ticket' ? 42 : ''; }
    public function GetPOST($name, ...$args) { return $this->POST[$name] ?? ''; }
}


if (getenv('REPAIR_POSITION_TEST_DB') === 'repair_position_review_20261019') {
/** Exercise the real core discount synchronization, stop before unrelated totals. */
class PositionDiscountNativeErp extends PositionNativeErp
{
    public function RechnungNeuberechnen($id)
    {
        try {
            $this->ANABREGSNeuberechnen($id, 'rechnung');
        } catch (PositionDiscountStageComplete $e) {
        }
    }
    public function LoadKurs($id, $typ, $projekt = '', $force = false) {}
    public function CheckFreifelder($table, $id, $force = false) {}
    public function Firmendaten($field, $projekt = '', $clearcache = false) { return ''; }
    public function ANABREGSNeuberechnenGesamtsummeOhnePortoUndKeinRabatt($id, $art)
    {
        throw new PositionDiscountStageComplete();
    }
}
class PositionDiscountStageComplete extends \RuntimeException {}
}
