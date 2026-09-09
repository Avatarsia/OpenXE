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
use Xentral\Modules\RepairIntegration\Gateway\RepairSyncQueueGateway;
use Xentral\Modules\RepairIntegration\Migration\RepairIntegrationMigration;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;
use Xentral\Modules\RepairIntegration\Service\RepairSyncService;

final class RepairSyncPersistenceTest extends TestCase
{
    private static Database $db;
    private static Database $secondDb;
    private RepairConfigService $config;
    private RepairSyncService $service;
    private RepairSyncQueueGateway $queue;

    public static function setUpBeforeClass(): void
    {
        if (getenv('REPAIR_TEST_DATABASE') !== 'repair_sync_review_20260908') {
            self::markTestSkipped('Requires explicitly isolated repair_sync_review_20260908 database.');
        }
        $user = rtrim((string)fgets(STDIN), "\r\n");
        $password = rtrim((string)fgets(STDIN), "\r\n");
        self::$db = new Database(new MysqliAdapter(new DatabaseConfig(
            '127.0.0.1', $user, $password, 'repair_sync_review_20260908', 'utf8mb4'
        )), new QueryFactory('mysql'));
        self::$secondDb = new Database(new MysqliAdapter(new DatabaseConfig(
            '127.0.0.1', $user, $password, 'repair_sync_review_20260908', 'utf8mb4'
        )), new QueryFactory('mysql'));
    }

    protected function setUp(): void
    {
        foreach (self::$db->fetchCol('SHOW TABLES') as $table) {
            self::$db->perform('DROP TABLE `' . str_replace('`', '``', $table) . '`');
        }
        self::$db->perform('CREATE TABLE systemconfig (`namespace` varchar(100), `key` varchar(100), `value` text, UNIQUE KEY (`namespace`, `key`))');
        self::$db->perform('CREATE TABLE ticket (id int PRIMARY KEY, schluessel varchar(255), status varchar(50))');
        self::$db->perform('CREATE TABLE logfile (meldung text, dump text, module text, action text, bearbeiter text, funktionsname text, datum datetime)');
        self::$db->perform('CREATE TABLE hook (id int, name varchar(255))');
        self::$db->perform('CREATE TABLE hook_register (hook int, module varchar(255))');
        self::$db->perform('CREATE TABLE hook_navigation (module varchar(255))');
        (new RepairIntegrationMigration(self::$db))->install();
        $this->config = new RepairConfigService(self::$db);
        $this->config->set('enabled', '1');
        $this->config->set('wp_api_url', 'https://example.invalid');
        $this->config->set('wp_api_key', 'test-only');
        $this->queue = new RepairSyncQueueGateway(self::$db);
        $this->service = new RepairSyncService(self::$db, $this->queue,
            new RepairStatusConfigGateway(self::$db), new RepairDetailsGateway(self::$db), $this->config);
    }

    private function ticket(int $id = 1, string $key = '202609080001', ?string $detailsKey = null): void
    {
        self::$db->perform('INSERT INTO ticket VALUES (:id, :key, :status)', ['id' => $id, 'key' => $key, 'status' => 'in_reparatur']);
        (new RepairDetailsGateway(self::$db))->create(['ticket_id' => $id, 'ticket_schluessel' => $detailsKey ?? $key]);
    }

    public function testFailedStatusCannotBeRetriedAfterNewStatusWasQueued(): void
    {
        $this->ticket();
        $this->service->backfillAndQueueCurrentStatuses();
        $old = $this->queue->getPendingEntries()[0];
        $this->queue->markFailed((int)$old['id'], 1, '2000-01-01 00:00:00', 'offline');
        self::$db->perform("UPDATE ticket SET status = 'repariert' WHERE id = 1");
        $this->service->checkAndQueueStatusChange(1);
        $pending = $this->queue->getPendingEntries();
        self::assertCount(1, $pending);
        self::assertSame('repaired', json_decode($pending[0]['payload'], true)['status']);
    }

    public function testInconsistentDetailKeyNeverUpdatesAnotherRequest(): void
    {
        $this->ticket(1, '202609080001', '202609080002');
        self::$db->perform("INSERT INTO ticket VALUES (2, '202609080002', 'neu')");
        $this->service->backfillAndQueueCurrentStatuses();
        self::assertSame(0, (int)self::$db->fetchValue('SELECT COUNT(*) FROM repair_sync_queue'));
        self::assertNull(self::$db->fetchValue('SELECT wp_request_number FROM ticket_repair_details'));
        self::assertGreaterThan(0, (int)self::$db->fetchValue('SELECT COUNT(*) FROM logfile'));
    }

    public function testDisabledBackfillResumesAfterConfigurationAndDoesNotRepeatCompletedRows(): void
    {
        $this->ticket();
        $this->config->set('enabled', '0');
        $this->service->backfillAndQueueCurrentStatuses();
        $this->config->set('enabled', '1');
        $this->service->backfillAndQueueCurrentStatuses();
        $first = $this->queue->getPendingEntries();
        self::assertCount(1, $first);
        $this->service->backfillAndQueueCurrentStatuses();
        self::assertSame($first, $this->queue->getPendingEntries());
    }

    public function testMigrationMakesOldMappingsAvailableBeforeBackfill(): void
    {
        $this->ticket();
        $this->config->set('schema_version', '1.0.0');
        self::$db->perform("UPDATE ticket_status_config SET wp_status_mapping = NULL WHERE slug = 'in_reparatur'");
        (new RepairIntegrationMigration(self::$db))->upgrade();
        self::assertSame('pending', $this->config->get('status_backfill_state'));
        $this->service->backfillAndQueueCurrentStatuses();
        self::assertSame('in_repair', json_decode($this->queue->getPendingEntries()[0]['payload'], true)['status']);
        self::assertSame('completed', $this->config->get('status_backfill_state'));
    }

    public function testClaimRejectsStaleBatchEntryAfterSupersession(): void
    {
        $this->ticket();
        $this->service->backfillAndQueueCurrentStatuses();
        $old = $this->queue->getPendingEntries()[0];
        $this->queue->markProcessing((int)$old['id']);
        self::$db->perform("UPDATE ticket SET status = 'repariert'");
        $this->service->checkAndQueueStatusChange(1);
        // Reproduces a stale worker/reaper trying to restore an obsolete job.
        $this->queue->markFailed((int)$old['id'], 1, '2000-01-01 00:00:00', 'late failure');
        self::assertCount(1, $this->queue->getPendingEntries());
        $this->expectException(\RuntimeException::class);
        $this->queue->markProcessing((int)$old['id']);
    }

    public function testTicketLockExcludesSecondConnectionDuringDelivery(): void
    {
        $other = new RepairSyncQueueGateway(self::$secondDb);
        self::assertTrue($this->queue->lockTicket(1, 0));
        try {
            self::assertFalse($other->lockTicket(1, 0));
            self::assertTrue($other->lockTicket(2, 0));
            $other->unlockTicket(2);
        } finally {
            $this->queue->unlockTicket(1);
        }
        self::assertTrue($other->lockTicket(1, 0));
        $other->unlockTicket(1);
    }

    public function testMissingUrlDefersAndDuplicateKeysAreSkipped(): void
    {
        $this->ticket();
        $this->ticket(2, '202609080002');
        self::$db->perform("INSERT INTO ticket VALUES (3, '202609080002', 'neu')");
        $this->config->set('wp_api_url', '');
        $this->service->backfillAndQueueCurrentStatuses();
        self::assertNotSame('completed', $this->config->get('status_backfill_state'));
        self::assertCount(0, $this->queue->getPendingEntries());
        $this->config->set('wp_api_url', 'https://example.invalid');
        $this->service->backfillAndQueueCurrentStatuses();
        self::assertCount(1, $this->queue->getPendingEntries());
        self::assertNull(self::$db->fetchValue('SELECT wp_request_number FROM ticket_repair_details WHERE ticket_id = 2'));
    }

    public function testUnmappedTicketDoesNotBlockLaterValidTickets(): void
    {
        $this->ticket();
        $this->ticket(2, '202609080002');
        self::$db->perform("UPDATE ticket SET status = 'offen' WHERE id = 1");
        $this->service->backfillAndQueueCurrentStatuses();
        self::assertCount(1, $this->queue->getPendingEntries());
        self::assertSame(2, (int)$this->queue->getPendingEntries()[0]['ticket_id']);
    }

    public function testPartialBackfillResumesWithoutLosingQueuedRows(): void
    {
        $this->ticket();
        $this->ticket(2, '202609080002');
        self::$db->perform("CREATE TRIGGER fail_second BEFORE INSERT ON repair_sync_queue FOR EACH ROW BEGIN IF NEW.ticket_id = 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected insert failure'; END IF; END");
        try {
            $this->service->backfillAndQueueCurrentStatuses();
            self::fail('Expected injected insert failure');
        } catch (\mysqli_sql_exception $e) {
            self::assertStringContainsString('injected insert failure', $e->getMessage());
        }
        $firstId = (int)$this->queue->getPendingEntries()[0]['id'];
        self::$db->perform('DROP TRIGGER fail_second');
        $this->service->backfillAndQueueCurrentStatuses();
        $pending = $this->queue->getPendingEntries();
        self::assertCount(2, $pending);
        self::assertSame($firstId, (int)$pending[0]['id']);
        self::assertSame('completed', $this->config->get('status_backfill_state'));
    }

    public function testFailedReplacementInsertKeepsPreviousDelivery(): void
    {
        $this->ticket();
        $this->service->backfillAndQueueCurrentStatuses();
        $first = $this->queue->getPendingEntries();
        self::$db->perform("CREATE TRIGGER reject_queue BEFORE INSERT ON repair_sync_queue FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected insert failure'");
        try {
            $this->service->checkAndQueueStatusChange(1);
            self::fail('Expected injected insert failure');
        } catch (\mysqli_sql_exception $e) {
            self::assertStringContainsString('injected insert failure', $e->getMessage());
        }
        self::assertSame($first, $this->queue->getPendingEntries());
    }

    public function testCronCannotBackfillBeforeMappingsAreUpgraded(): void
    {
        $this->ticket();
        $this->config->set('schema_version', '1.0.0');
        $this->service->backfillAndQueueCurrentStatuses();
        self::assertCount(0, $this->queue->getPendingEntries());
        self::assertNotSame('completed', $this->config->get('status_backfill_state'));
    }

    public function testWorkerSendsOnlyLatestPayloadWhileHoldingTicketLock(): void
    {
        $this->ticket();
        $this->config->set('wp_api_url', 'repairsynctest://wordpress');
        $this->service->backfillAndQueueCurrentStatuses();
        $old = $this->queue->getPendingEntries()[0];
        $this->queue->markFailed((int)$old['id'], 1, '2000-01-01 00:00:00', 'offline');
        self::$db->perform("UPDATE ticket SET status = 'repariert'");
        $this->service->checkAndQueueStatusChange(1);
        $other = new RepairSyncQueueGateway(self::$secondDb);
        RepairHttpCapture::$requests = [];
        $acquired = null;
        RepairHttpCapture::$onRequest = static function () use ($other, &$acquired): void {
            $acquired = $other->lockTicket(1, 0);
            if ($acquired) {
                $other->unlockTicket(1);
            }
        };
        stream_wrapper_register('repairsynctest', RepairHttpCapture::class);
        try {
            $this->service->processQueue();
        } finally {
            stream_wrapper_unregister('repairsynctest');
        }
        self::assertCount(1, RepairHttpCapture::$requests);
        self::assertFalse($acquired);
        $request = RepairHttpCapture::$requests[0];
        self::assertSame('repairsynctest://wordpress/wp-json/p3d/v1/requests/status', $request['url']);
        self::assertSame('POST', $request['options']['method']);
        self::assertStringContainsString('Authorization: Bearer test-only', $request['options']['header']);
        self::assertSame(['request_number' => '202609080001', 'status' => 'repaired'], json_decode($request['options']['content'], true));
    }

    public function testSaveDeliversStatusSynchronouslyAndLeavesRetryToCron(): void
    {
        $this->ticket();
        self::$db->perform("UPDATE ticket_repair_details SET wp_request_number = '202609080001'");
        $this->config->set('wp_api_url', 'repairsynctest://wordpress');
        RepairHttpCapture::$requests = [];
        RepairHttpCapture::$onRequest = static function (): void {};
        stream_wrapper_register('repairsynctest', RepairHttpCapture::class);
        try {
            self::assertTrue($this->service->queueAndPushStatusChange(1));
        } finally {
            stream_wrapper_unregister('repairsynctest');
        }
        self::assertCount(1, RepairHttpCapture::$requests);
        $request = RepairHttpCapture::$requests[0];
        self::assertSame('repairsynctest://wordpress/wp-json/p3d/v1/requests/status', $request['url']);
        self::assertSame(8, $request['options']['timeout']);
        self::assertSame(['request_number' => '202609080001', 'status' => 'in_repair'], json_decode($request['options']['content'], true));
        // Der Test-Wrapper liefert keine HTTP-Statuszeile -> Zustellung gilt als
        // fehlgeschlagen, der Eintrag bleibt fuer den Cron-Retry stehen.
        $row = self::$db->fetchRow('SELECT status, retry_count, next_retry_at FROM repair_sync_queue');
        self::assertSame('failed', $row['status']);
        self::assertSame(1, (int)$row['retry_count']);
        self::assertNotNull($row['next_retry_at']);
        self::assertSame(1, (int)self::$db->fetchValue("SELECT COUNT(*) FROM repair_sync_log WHERE direction = 'outbound' AND success = 0"));
        self::assertTrue($this->queue->lockTicket(1, 0));
        $this->queue->unlockTicket(1);
    }

    public function testSaveWithoutMappingQueuesNothingAndSendsNothing(): void
    {
        $this->ticket();
        self::$db->perform("UPDATE ticket_repair_details SET wp_request_number = '202609080001'");
        self::$db->perform("UPDATE ticket SET status = 'offen'");
        RepairHttpCapture::$requests = [];
        self::assertFalse($this->service->queueAndPushStatusChange(1));
        self::assertCount(0, RepairHttpCapture::$requests);
        self::assertSame(0, (int)self::$db->fetchValue('SELECT COUNT(*) FROM repair_sync_queue'));
    }
}

final class RepairHttpCapture
{
    public $context;
    public static array $requests = [];
    public static ?\Closure $onRequest = null;
    private bool $read = false;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        self::$requests[] = ['url' => $path, 'options' => stream_context_get_options($this->context)['http']];
        (self::$onRequest)();
        return true;
    }

    public function stream_read($count): string
    {
        if ($this->read) {
            return '';
        }
        $this->read = true;
        return '{}';
    }

    public function stream_eof(): bool { return $this->read; }
    public function stream_stat(): array { return []; }
}
