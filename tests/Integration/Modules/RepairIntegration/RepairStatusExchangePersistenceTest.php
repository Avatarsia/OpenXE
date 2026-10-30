<?php
declare(strict_types=1);

namespace Tests\Integration\Modules\RepairIntegration;

use PHPUnit\Framework\TestCase;
use Xentral\Components\Database\Adapter\MysqliAdapter;
use Xentral\Components\Database\Database;
use Xentral\Components\Database\DatabaseConfig;
use Xentral\Components\Database\SqlQuery\QueryFactory;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway;
use Xentral\Modules\RepairIntegration\Migration\RepairIntegrationMigration;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;
use Xentral\Modules\RepairIntegration\Service\RepairStatusExchangeService;
use Xentral\Modules\RepairIntegration\Service\RepairStatusService;
use Xentral\Modules\RepairIntegration\Service\RepairSyncService;
use Xentral\Modules\RepairIntegration\Gateway\RepairSyncQueueGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;
use Xentral\Modules\RepairIntegration\Hook\BelegVersendetHook;

final class RepairStatusExchangePersistenceTest extends TestCase
{
    private static Database $db;
    private RepairStatusExchangeService $exchange;

    public static function setUpBeforeClass(): void
    {
        if (getenv('REPAIR_STATUS_EXCHANGE_DATABASE') !== 'repair_status_exchange_20261020') {
            self::markTestSkipped('Requires isolated repair_status_exchange_20261020 database.');
        }
        self::$db = new Database(new MysqliAdapter(new DatabaseConfig('127.0.0.1', 'root',
            (string)getenv('REPAIR_TEST_PASSWORD'), 'repair_status_exchange_20261020', 'utf8mb4')), new QueryFactory('mysql'));
    }

    protected function setUp(): void
    {
        foreach (self::$db->fetchCol('SHOW TABLES') as $table) {
            self::$db->perform('DROP TABLE `' . str_replace('`', '``', $table) . '`');
        }
        self::$db->perform('CREATE TABLE systemconfig (`namespace` varchar(100), `key` varchar(100), `value` text, UNIQUE KEY (`namespace`, `key`))');
        self::$db->perform('CREATE TABLE ticket (id int PRIMARY KEY, schluessel varchar(255), status varchar(50), privat int DEFAULT 0) ENGINE=InnoDB');
        self::$db->perform('CREATE TABLE ticket_protokoll (id int AUTO_INCREMENT PRIMARY KEY,ticket int,zeit datetime,bearbeiter varchar(100),grund text)');
        self::$db->perform('CREATE TABLE hook (id int AUTO_INCREMENT PRIMARY KEY, name varchar(255), alias varchar(255), aktiv int, parametercount int, description text)');
        self::$db->perform('CREATE TABLE hook_register (id int AUTO_INCREMENT PRIMARY KEY, hook_action int, `function` varchar(255), aktiv int, position int, hook int, module varchar(255), module_parameter int)');
        (new RepairIntegrationMigration(self::$db))->install();
        $config = new RepairConfigService(self::$db);
        $config->set('enabled', '1');
        $config->set('sync_v2_enabled', '1');
        $config->set('wp_api_url', 'https://example.invalid');
        $this->exchange = new RepairStatusExchangeService(self::$db, $config, new RepairStatusConfigGateway(self::$db));
        self::$db->perform("INSERT INTO ticket VALUES (1, '202610200001', 'in_reparatur', 0)");
        (new RepairDetailsGateway(self::$db))->create(['ticket_id' => 1, 'ticket_schluessel' => '202610200001', 'wp_request_number' => '202610200001', 'service_type' => 'reparatur']);
    }

    private function event(string $status = 'repaired', string $time = '2026-10-20T10:00:00.000001Z'): array
    {
        return ['request_number' => '202610200001', 'status' => $status,
            'event_id' => '00112233-4455-4677-8899-aabbccddeeff', 'origin' => 'wp', 'changed_at_utc' => $time, 'revision' => 0];
    }

    public function testReceiveIsAtomicIdempotentAndReturnsCanonicalRevision(): void
    {
        $result = $this->exchange->receive($this->event());
        self::assertTrue($result['applied']);
        self::assertSame(1, $result['event']['revision']);
        self::assertSame('repariert', self::$db->fetchValue('SELECT status FROM ticket WHERE id=1'));
        $retry = $this->exchange->receive($this->event());
        self::assertFalse($retry['applied']);
        self::assertSame($result['event'], $retry['event']);
        self::assertSame(0, (int)self::$db->fetchValue('SELECT COUNT(*) FROM repair_sync_queue'));
        $older = $this->event('new', '2026-10-19T10:00:00.000001Z');
        $older['event_id'] = '10112233-4455-4677-8899-aabbccddeeff';
        self::assertSame($result['event'], $this->exchange->receive($older)['event']);
    }

    public function testLocalWriteAndOutboxRollbackTogetherThenRetryKeepsIdentity(): void
    {
        self::$db->perform("CREATE TRIGGER reject_queue BEFORE INSERT ON repair_sync_queue FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected queue failure'");
        try {
            $this->exchange->changeLocalStatus(1, 'repariert');
            self::fail('Expected atomic outbox failure');
        } catch (\mysqli_sql_exception $e) {
            self::assertStringContainsString('injected queue failure', $e->getMessage());
        }
        self::assertSame('in_reparatur', self::$db->fetchValue('SELECT status FROM ticket WHERE id=1'));
        self::assertNull($this->exchange->getSnapshot(1)['status_event']);
        self::$db->perform('DROP TRIGGER reject_queue');
        $event = $this->exchange->changeLocalStatus(1, 'repariert');
        self::assertNotNull($event);
        self::assertNull($this->exchange->captureLocalChange(1, 'repariert'));
        self::assertSame($event, json_decode(self::$db->fetchValue('SELECT payload FROM repair_sync_queue'), true));
    }

    public function testUnknownHistoryIsUnclearAndPreviewNeverWrites(): void
    {
        $remote = ['status' => 'repaired', 'status_event' => null, 'history_ambiguous' => true, 'history_status_changed_at_utc' => null];
        self::assertSame('unclear', $this->exchange->reconcile(1, $remote)['state']);
        self::assertSame(0, (int)self::$db->fetchValue('SELECT COUNT(*) FROM repair_status_exchange'));
        self::assertSame('in_reparatur', self::$db->fetchValue('SELECT status FROM ticket WHERE id=1'));
        $resolved = $this->exchange->reconcile(1, $remote, true, 'remote');
        self::assertTrue($resolved['changed']);
        self::assertSame('repaired', $resolved['event']['status']);
    }

    public function testHistoryRecoveryUsesOriginalTimeAndNeverReissuesEvent(): void
    {
        self::$db->perform("INSERT INTO ticket_protokoll (ticket,zeit,bearbeiter,grund) VALUES (1,'2026-10-19 12:00:00','staff','Ge&auml;ndert: Status ''in_reparatur''')");
        $event = $this->exchange->captureLocalChange(1);
        self::assertSame('2026-10-19T10:00:00.000000Z', $event['changed_at_utc']);
        self::assertNull($this->exchange->captureLocalChange(1));
        self::assertSame(1, (int)self::$db->fetchValue('SELECT COUNT(*) FROM repair_sync_queue'));
    }

    public function testPrivateAndUnmappedTicketsNeverExportStatus(): void
    {
        self::$db->perform('UPDATE ticket SET privat=1 WHERE id=1');
        self::assertNull($this->exchange->captureLocalChange(1, 'neu'));
        self::assertNull($this->exchange->getSnapshot(1)['status']);
        self::$db->perform("UPDATE ticket SET privat=0,status='offen' WHERE id=1");
        self::assertNull($this->exchange->captureLocalChange(1, 'neu'));
        self::assertSame('unmapped', $this->exchange->reconcile(1, ['status'=>'new'])['state']);
    }

    public function testExistingStatusServiceProducesAtomicV2EventAndQueue(): void
    {
        $service = new RepairStatusService(self::$db, new RepairStatusConfigGateway(self::$db), new RepairDetailsGateway(self::$db));
        $service->changeStatus(1, 'repariert');
        self::assertSame('repaired', $this->exchange->getSnapshot(1)['status_event']['status'] ?? null);
        self::assertSame(1, (int)self::$db->fetchValue("SELECT COUNT(*) FROM repair_sync_queue WHERE action='status_exchange'"));
    }

    public function testExistingSyncQueuesStableTargetedEventWithoutQuoteDetails(): void
    {
        $config = new RepairConfigService(self::$db);
        $service = new RepairSyncService(self::$db, new RepairSyncQueueGateway(self::$db),
            new RepairStatusConfigGateway(self::$db), new RepairDetailsGateway(self::$db), $config);
        self::assertTrue($service->checkAndQueueStatusChange(1, 'neu'));
        $payload = json_decode(self::$db->fetchValue('SELECT payload FROM repair_sync_queue'), true);
        self::assertSame('openxe', $payload['origin'] ?? null);
        self::assertArrayNotHasKey('quote_amount', $payload);
        self::assertFalse($service->checkAndQueueStatusChange(1, 'neu'));
    }

    public function testDelayedCoreWriteIsRecoveredBeforeRemoteReceiveAndKeepsHistoryTime(): void
    {
        $this->exchange->receive($this->event('in_repair', '2026-10-18T10:00:00.000001Z'));
        self::$db->perform("UPDATE ticket SET status='repariert' WHERE id=1");
        self::$db->perform("INSERT INTO ticket_protokoll (ticket,zeit,bearbeiter,grund) VALUES (1,'2026-10-19 12:00:00','staff','Ge&auml;ndert: Status ''repariert''')");
        $late = $this->event('new', '2026-10-18T11:00:00.000001Z');
        $late['event_id'] = '10112233-4455-4677-8899-aabbccddeeff';
        $result = $this->exchange->receive($late);
        self::assertFalse($result['applied']);
        self::assertSame('repaired', $result['event']['status']);
        self::assertSame('2026-10-19T10:00:00.000000Z', $result['event']['changed_at_utc']);
    }

    public function testExplicitResolutionCanResolveUnloggedCoreWriteAfterExistingEvent(): void
    {
        $this->exchange->receive($this->event('in_repair', '2026-10-18T10:00:00.000001Z'));
        self::$db->perform("UPDATE ticket SET status='repariert' WHERE id=1");
        $resolved = $this->exchange->reconcile(1, ['status' => 'new', 'status_event' => null], true, 'remote');
        self::assertTrue($resolved['changed']);
        self::assertSame('neu', self::$db->fetchValue('SELECT status FROM ticket WHERE id=1'));
        self::assertSame(2, $resolved['event']['revision']);
    }

    public function testSameNativeStatusRoundTripAfterCrashIsRecoveredFromNewHistory(): void
    {
        $this->exchange->receive($this->event('in_repair', '2026-10-18T10:00:00.000001Z'));
        self::$db->perform("INSERT INTO ticket_protokoll (ticket,zeit,bearbeiter,grund) VALUES
            (1,'2026-10-19 12:00:00','staff','Ge&auml;ndert: Status ''repariert'''),
            (1,'2026-10-19 13:00:00','staff','Ge&auml;ndert: Status ''in_reparatur''')");
        $event = $this->exchange->captureLocalChange(1);
        self::assertNotNull($event);
        self::assertSame('2026-10-19T11:00:00.000000Z', $event['changed_at_utc']);
        self::assertNull($this->exchange->captureLocalChange(1));
    }

    public function testFineNativeStatusIsPreservedAndMaintenanceMappingIsUsed(): void
    {
        self::$db->perform("INSERT INTO ticket_status_config (slug,label_de,category,sort_order,wp_status_mapping,is_active) VALUES ('special_repair','Special','repair',999,'in_repair',1)");
        self::$db->perform("UPDATE ticket SET status='special_repair' WHERE id=1");
        $this->exchange->receive($this->event('in_repair'));
        self::assertSame('special_repair', self::$db->fetchValue('SELECT status FROM ticket WHERE id=1'));
        self::$db->perform("UPDATE ticket_repair_details SET service_type='wartung' WHERE ticket_id=1");
        $event = $this->event('repaired', '2026-10-20T10:00:00.000002Z');
        $event['event_id'] = '10112233-4455-4677-8899-aabbccddeeff';
        $this->exchange->receive($event);
        self::assertSame('wartung_fertig', self::$db->fetchValue('SELECT status FROM ticket WHERE id=1'));
    }

    public function testSameSecondConflictingHistoryAndDstFoldAreUnclear(): void
    {
        self::$db->perform("INSERT INTO ticket_protokoll (ticket,zeit,bearbeiter,grund) VALUES
            (1,'2026-10-19 12:00:00','staff','Ge&auml;ndert: Status ''repariert'''),
            (1,'2026-10-19 12:00:00','staff','Ge&auml;ndert: Status ''in_reparatur''')");
        self::assertTrue($this->exchange->getSnapshot(1)['history_ambiguous']);
        self::$db->perform('DELETE FROM ticket_protokoll');
        self::$db->perform("INSERT INTO ticket_protokoll (ticket,zeit,bearbeiter,grund) VALUES (1,'2026-10-25 02:30:00','staff','Ge&auml;ndert: Status ''in_reparatur''')");
        self::assertTrue($this->exchange->getSnapshot(1)['history_ambiguous']);
    }

    public function testEqualUnknownHistoryGetsStableBaselineWithoutPretendingNewUserChange(): void
    {
        $remote = ['status'=>'in_repair', 'status_event'=>null, 'history_ambiguous'=>true];
        $first = $this->exchange->reconcile(1, $remote, true);
        self::assertSame('equal', $first['state']);
        self::assertSame('1970-01-01T00:00:00.000000Z', $first['event']['changed_at_utc']);
        $again = $this->exchange->reconcile(1, $remote, true);
        self::assertFalse($again['changed']);
        self::assertSame($first['event'], $again['event']);
    }

    public function testEventIdentityCannotBeReusedWithDifferentStatus(): void
    {
        $this->exchange->receive($this->event());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('STATUS_EVENT_CONFLICT');
        $this->exchange->receive($this->event('new'));
    }

    public function testHistoricalLocalAndCanonicalRemoteCanBeOrderedWithoutRemoteHistoryFlag(): void
    {
        self::$db->perform("INSERT INTO ticket_protokoll (ticket,zeit,bearbeiter,grund) VALUES (1,'2026-10-19 12:00:00','staff','Ge&auml;ndert: Status ''in_reparatur''')");
        $result = $this->exchange->reconcile(1, ['status'=>'repaired','status_event'=>$this->event()]);
        self::assertSame('remote_newer', $result['state']);
    }

    public function testStaleHistoryCannotRedateUnloggedCoreChangeBehindCanonicalEvent(): void
    {
        $this->exchange->receive($this->event('in_repair', '2026-10-20T10:00:00.000001Z'));
        self::$db->perform("UPDATE ticket SET status='repariert' WHERE id=1");
        self::$db->perform("INSERT INTO ticket_protokoll (ticket,zeit,bearbeiter,grund) VALUES (1,'2026-10-19 12:00:00','staff','Ge&auml;ndert: Status ''repariert''')");
        self::assertNull($this->exchange->captureLocalChange(1));
        self::assertTrue($this->exchange->getSnapshot(1)['history_ambiguous']);
        self::assertSame(1, (int)self::$db->fetchValue('SELECT revision FROM repair_status_exchange WHERE ticket_id=1'));
    }

    public function testSentDocumentAdvancesStatusWithV2EventAndNoQuoteOnlyEvent(): void
    {
        self::$db->perform('CREATE TABLE angebot (id int PRIMARY KEY,belegnr varchar(30),gesamtsumme decimal(10,2))');
        self::$db->perform("INSERT INTO angebot VALUES(1,'ANG-1',100)");
        (new RepairBelegGateway(self::$db))->link(1, '202610200001', 'angebot', 1, 'ANG-1', true, 'test');
        self::$db->perform("UPDATE ticket SET status='in_diagnose' WHERE id=1");
        $config = new RepairConfigService(self::$db);
        $sync = new RepairSyncService(self::$db, new RepairSyncQueueGateway(self::$db), new RepairStatusConfigGateway(self::$db), new RepairDetailsGateway(self::$db), $config);
        $hook = new BelegVersendetHook(self::$db, new RepairBelegGateway(self::$db), new RepairDetailsGateway(self::$db), new RepairStatusConfigGateway(self::$db), $sync);
        $hook->onBelegVersendet('angebot', 1);
        self::assertSame('quote_sent', $this->exchange->getSnapshot(1)['status_event']['status'] ?? null);
        self::assertSame(1, (int)self::$db->fetchValue('SELECT COUNT(*) FROM repair_sync_queue'));
        $before = $this->exchange->getSnapshot(1)['status_event'];
        $hook->onBelegVersendet('angebot', 1);
        self::assertSame($before, $this->exchange->getSnapshot(1)['status_event']);
        self::assertSame(1, (int)self::$db->fetchValue('SELECT COUNT(*) FROM repair_sync_queue'));
    }

    public function testWorkerAdoptsNewerCanonicalResponseAndCompletesStableOutbox(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $response = $this->event('repaired', (new \DateTimeImmutable('+1 minute', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'));
        $router = tempnam(sys_get_temp_dir(), 'repair-status-http-');
        file_put_contents($router, '<?php header("Content-Type: application/json"); echo ' . var_export(json_encode(['success'=>true,'applied'=>false,'event'=>$response]), true) . ';');
        $process = proc_open([PHP_BINARY, '-S', $address, $router], [0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
        try {
            for ($i=0;$i<100;$i++) {
                $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
                if ($ready) { fclose($ready); break; }
                usleep(10000);
            }
            self::assertTrue($i < 100, 'HTTP fixture did not start');
            $config = new RepairConfigService(self::$db);
            $config->set('wp_api_url', 'http://' . $address);
            $config->set('wp_api_key', 'test-only');
            $sync = new RepairSyncService(self::$db, new RepairSyncQueueGateway(self::$db), new RepairStatusConfigGateway(self::$db), new RepairDetailsGateway(self::$db), $config);
            $queue = new RepairSyncQueueGateway(self::$db);
            for ($legacy=10;$legacy<70;$legacy++) {
                $queue->enqueue($legacy, (string)$legacy, 'status_change', '{}', 'http://example.invalid');
            }
            self::assertTrue($sync->checkAndQueueStatusChange(1, 'neu'));
            self::assertSame(1, $sync->processQueue());
            self::assertSame('repariert', self::$db->fetchValue('SELECT status FROM ticket WHERE id=1'));
            self::assertSame('completed', self::$db->fetchValue("SELECT status FROM repair_sync_queue WHERE action='status_exchange'"));
            self::assertSame(2, $this->exchange->getSnapshot(1)['status_event']['revision']);
            self::assertSame(1, (int)self::$db->fetchValue("SELECT COUNT(*) FROM repair_sync_queue WHERE action='status_exchange'"));
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            unlink($router);
        }
    }
}
