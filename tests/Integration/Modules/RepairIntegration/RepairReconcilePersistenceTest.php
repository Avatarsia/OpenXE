<?php
declare(strict_types=1);
namespace Tests\Integration\Modules\RepairIntegration;
use PHPUnit\Framework\TestCase;
use Xentral\Components\Database\{Database,DatabaseConfig};
use Xentral\Components\Database\Adapter\MysqliAdapter;
use Xentral\Components\Database\SqlQuery\QueryFactory;
use Xentral\Modules\RepairIntegration\Service\{RepairConfigService,RepairImageExchangeService,RepairStatusExchangeService,RepairExchangeTransport,RepairReconcileService};
use Xentral\Modules\RepairIntegration\Gateway\{RepairDetailsGateway,RepairStatusConfigGateway};
use Xentral\Modules\RepairIntegration\Migration\RepairIntegrationMigration;
final class RepairReconcilePersistenceTest extends TestCase
{
    private Database $db;
    private RepairReconcileService $service;
    private string $root;
    private array $remote;
    private array $bytes=[];
    private array $calls=[];
    private int $badImages=0;
    private bool $listEmpty=false;
    private bool $enqueueDuringRead=false;
    private const PNG='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jK1kAAAAASUVORK5CYII=';
    protected function setUp():void
    {
        if(getenv('REPAIR_RECONCILE_DATABASE')!=='repair_reconcile_exchange_20261020') self::markTestSkipped('Explicit isolated reconcile DB required');
        $this->db=new Database(new MysqliAdapter(new DatabaseConfig('127.0.0.1','root',(string)getenv('REPAIR_TEST_PASSWORD'),'repair_reconcile_exchange_20261020','utf8mb4')),new QueryFactory('mysql'));
        foreach($this->db->fetchCol('SHOW TABLES') as $t) $this->db->perform('DROP TABLE `'.str_replace('`','``',$t).'`');
        foreach([
          'CREATE TABLE systemconfig (`namespace` varchar(100),`key` varchar(100),`value` text,UNIQUE KEY (`namespace`,`key`))',
          'CREATE TABLE ticket (id int PRIMARY KEY,schluessel varchar(255),status varchar(50),privat int DEFAULT 0,quelle varchar(30) DEFAULT NULL,adresse int DEFAULT 0)',
          'CREATE TABLE ticket_protokoll (id int AUTO_INCREMENT PRIMARY KEY,ticket int,zeit datetime,bearbeiter varchar(100),grund text)',
          'CREATE TABLE hook (id int AUTO_INCREMENT PRIMARY KEY,name varchar(255),alias varchar(255),aktiv int,parametercount int,description text)',
          'CREATE TABLE hook_register (id int AUTO_INCREMENT PRIMARY KEY,hook_action int,`function` varchar(255),aktiv int,position int,hook int,module varchar(255),module_parameter int)',
          'CREATE TABLE ticket_nachricht (id int AUTO_INCREMENT PRIMARY KEY,ticket varchar(255),medium varchar(50),status varchar(50),versendet varchar(50),zeitausgang datetime DEFAULT NULL)',
          'CREATE TABLE datei (id int AUTO_INCREMENT PRIMARY KEY,titel varchar(255),beschreibung text,nummer varchar(255),geloescht int,firma int,geschuetzt int NOT NULL)',
          'CREATE TABLE datei_version (id int AUTO_INCREMENT PRIMARY KEY,datei int,ersteller varchar(255),datum date,version int,dateiname varchar(255),bemerkung varchar(255),size varchar(255))',
          'CREATE TABLE datei_stichwoerter (id int AUTO_INCREMENT PRIMARY KEY,datei int,subjekt varchar(255),objekt varchar(255),parameter varchar(255),sort int,parameter2 int,objekt2 varchar(255))',
        ] as $sql) $this->db->perform($sql);
        (new RepairIntegrationMigration($this->db))->install();
        $config=new RepairConfigService($this->db);
        foreach(['enabled'=>'1','sync_v2_enabled'=>'1','wp_api_url'=>'https://example.invalid','wp_api_key'=>'test-only'] as $k=>$v) $config->set($k,$v);
        $this->db->perform("INSERT INTO ticket(id,schluessel,status,privat) VALUES(1,'202610200001','in_reparatur',0)");
        (new RepairDetailsGateway($this->db))->create(['ticket_id'=>1,'wp_request_number'=>'202610200001','ticket_schluessel'=>'202610200001','service_type'=>'reparatur']);
        $this->db->perform("INSERT INTO ticket_nachricht VALUES(1,'202610200001','api','neu','0',NULL)");
        $this->root=sys_get_temp_dir().'/repair-reconcile-test-'.bin2hex(random_bytes(8));mkdir($this->root,0700,true);
        $this->remote=['id'=>1,'request_number'=>'202610200001','service_type'=>'reparatur','status'=>'repaired','status_event'=>['request_number'=>'202610200001','status'=>'repaired','event_id'=>'11111111-1111-4111-8111-111111111111','origin'=>'wp','changed_at_utc'=>'2026-10-20T10:00:00.000001Z','revision'=>0], 'history_ambiguous'=>false,'history_status_changed_at_utc'=>'2026-10-20T10:00:00.000001Z','images'=>[]];
        $this->db->perform("INSERT INTO ticket_protokoll(ticket,zeit,bearbeiter,grund) VALUES(1,'2026-10-20 11:00:00','test','Ge&auml;ndert: Status \'In Reparatur\'')");
        $transport=new RepairExchangeTransport($config,function($method,$path,$body){
            $this->calls[]=[$method,$path];
            if($this->enqueueDuringRead){$this->enqueueDuringRead=false;$this->service->queueTicket(1);}
            if(str_starts_with($path,'requests?')) return json_encode(['success'=>true,'requests'=>$this->listEmpty?[]:[$this->remote],'next_cursor'=>1,'has_more'=>false]);
            if(str_starts_with($path,'manifest?')) return json_encode(['success'=>true,'request'=>$this->remote]);
            if($path==='enqueue-initial') return json_encode(['success'=>true,'queued'=>true]);
            if($path==='status'){$event=json_decode($body,true);$this->remote['status_event']=$event;$this->remote['status']=$event['status'];return json_encode(['success'=>true,'applied'=>true,'event'=>$event]);}
            if(str_starts_with($path,'image?')){parse_str(parse_url($path,PHP_URL_QUERY),$q);$id=$q['origin_id'];if((int)substr($id,strrpos($id,':')+1)<=$this->badImages) throw new \RuntimeException('TEST_IMAGE_404');return $this->bytes[$id];}
            if($path==='image' && is_array($body))return json_encode(['success'=>true,'created'=>true,'image'=>['sha256'=>$body['sha256']]]);
            throw new \RuntimeException('Unexpected test request: '.$path);
        });
        $this->service=new RepairReconcileService($this->db,$config,new RepairStatusExchangeService($this->db,$config,new RepairStatusConfigGateway($this->db)),new RepairImageExchangeService($this->db,$config,$this->root),$transport);
    }
    protected function tearDown():void
    {
        if(!isset($this->root))return;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $p)$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());rmdir($this->root);
    }
    private function photos(int $count):void
    {
        for($i=1;$i<=$count;$i++){$id='wp:22222222-2222-4222-8222-222222222222:'.$i;$bytes=base64_decode(self::PNG).$i;$this->bytes[$id]=$bytes;
            $this->remote['images'][]=['origin_id'=>$id,'sha256'=>hash('sha256',$bytes),'mime_type'=>'image/png','file_name'=>'photo.png','uploaded_at_utc'=>'2026-10-20T10:00:00.000000Z'];}
    }
    public function testPreviewReadsActualDivergenceWithoutBusinessWrites():void
    {
        $this->photos(1);$r=$this->service->reconcileOne($this->remote);
        self::assertSame('remote_newer',$r['state']);self::assertSame(1,$r['missing_local']);
        self::assertSame('in_reparatur',$this->db->fetchValue('SELECT status FROM ticket WHERE id=1'));
        self::assertSame(0,(int)$this->db->fetchValue('SELECT COUNT(*) FROM repair_status_exchange'));
        self::assertSame(0,(int)$this->db->fetchValue('SELECT COUNT(*) FROM datei'));self::assertSame([],$this->calls);
    }
    public function testApplyOverTwentyImagesThenTwoRepeatsAreIdempotent():void
    {
        $this->photos(27);
        for($i=0;$i<4;$i++)$this->service->reconcileOne($this->remote,true);
        self::assertSame('repariert',$this->db->fetchValue('SELECT status FROM ticket WHERE id=1'));
        self::assertSame(27,(int)$this->db->fetchValue('SELECT COUNT(*) FROM datei'));
        $events=(int)$this->db->fetchValue('SELECT COUNT(*) FROM repair_status_exchange_events');
        for($i=0;$i<2;$i++){ $r=$this->service->reconcileOne($this->remote,true);self::assertSame('equal',$r['state']);self::assertSame(0,$r['missing_local']);}
        self::assertSame(27,(int)$this->db->fetchValue('SELECT COUNT(*) FROM datei'));
        self::assertSame($events,(int)$this->db->fetchValue('SELECT COUNT(*) FROM repair_status_exchange_events'));
    }
    public function testFailedFirstImagesDoNotStarveLaterOnesAndStayVisible():void
    {
        $this->photos(24);$this->badImages=8;
        for($i=0;$i<3;$i++)$r=$this->service->reconcileOne($this->remote,true);
        self::assertSame(16,(int)$this->db->fetchValue('SELECT COUNT(*) FROM datei'));
        self::assertSame('error',$r['state']);self::assertCount(8,$r['errors']);
        self::assertSame(8,(int)$this->db->fetchValue('SELECT COUNT(*) FROM repair_media_failures'));
    }
    public function testLocalScanResumesPendingImageRecordRatherThanSkippingIt():void
    {
        $this->photos(27);$this->listEmpty=true;$job=$this->service->start('apply');
        for($i=0;$i<8;$i++)$this->service->tick($job,1);
        self::assertSame('complete',$this->db->fetchValue('SELECT state FROM repair_exchange_jobs WHERE id=:id',['id'=>$job]));
        self::assertSame(27,(int)$this->db->fetchValue('SELECT COUNT(*) FROM datei'));
    }
    public function testUnlinkedNativeTicketKeyIsNotReusedForInitialImport():void
    {
        $this->db->perform('DELETE FROM ticket_repair_details WHERE ticket_id=1');
        $this->expectException(\RuntimeException::class);
        try{$this->service->reconcileOne($this->remote,true);}finally{self::assertSame([],$this->calls);}
    }
    public function testInitialRetryNeverOverwritesExistingRepairDetails():void
    {
        $this->db->perform("UPDATE ticket_repair_details SET manufacturer='Existing',repair_notes='private' WHERE ticket_id=1");
        $config=new RepairConfigService($this->db);
        $controller=new \Xentral\Modules\RepairIntegration\Api\RepairApiController($this->db,new \Xentral\Modules\RepairIntegration\Api\RepairApiAuth(),$config,new RepairDetailsGateway($this->db),new RepairStatusConfigGateway($this->db));
        $method=new \ReflectionMethod($controller,'processPushDetails');$method->setAccessible(true);
        $method->invoke($controller,['request_number'=>'202610200001','service_type'=>'reparatur','exchange_initial'=>true,'device'=>['manufacturer'=>'Wrong']]);
        self::assertSame('Existing',$this->db->fetchValue('SELECT manufacturer FROM ticket_repair_details WHERE ticket_id=1'));
        self::assertSame('private',$this->db->fetchValue('SELECT repair_notes FROM ticket_repair_details WHERE ticket_id=1'));
    }
    public function testNewUploadWhileWorkerReadsCannotBeDequeuedByOlderGeneration():void
    {
        $this->service->queueTicket(1);$this->enqueueDuringRead=true;$this->service->processPending(1);
        self::assertSame(1,(int)$this->db->fetchValue('SELECT COUNT(*) FROM repair_exchange_pending'));
        $this->service->processPending(1);self::assertSame(0,(int)$this->db->fetchValue('SELECT COUNT(*) FROM repair_exchange_pending'));
    }
    public function testFailedInitialBindingRollsBackTicketAndMessageBeforeRetry():void
    {
        $this->db->perform('ALTER TABLE ticket MODIFY id int AUTO_INCREMENT, ADD zeit datetime, ADD projekt int, ADD kunde text, ADD mailadresse text, ADD prio int, ADD betreff text, ADD firma int, ADD notiz text, ADD bearbeiter text, ADD warteschlange text, ADD zugewiesen int, ADD inbearbeitung int, ADD inbearbeitung_user text, ADD kommentar text, ADD tags text, ADD nachrichten_anz int');
        $this->db->perform('ALTER TABLE ticket_nachricht ADD zeit datetime, ADD text text, ADD betreff text, ADD verfasser text, ADD mail text, ADD bearbeiter text, ADD textausgang text, ADD bemerkung text, ADD mail_cc text, ADD verfasser_replyto text, ADD mail_replyto text');
        $controller=new \Xentral\Modules\RepairIntegration\Api\RepairApiController($this->db,new \Xentral\Modules\RepairIntegration\Api\RepairApiAuth(),new RepairConfigService($this->db),new RepairDetailsGateway($this->db),new RepairStatusConfigGateway($this->db));
        $method=new \ReflectionMethod($controller,'processPushDetails');$method->setAccessible(true);
        $payload=['request_number'=>'202610200002','service_type'=>'reparatur','exchange_initial'=>true];
        $this->db->perform("CREATE TRIGGER fail_initial_binding BEFORE INSERT ON ticket_repair_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture binding failure'");
        try {
            try {$method->invoke($controller,$payload);self::fail('Expected binding persistence failure');}
            catch (\mysqli_sql_exception $error) { self::assertStringContainsString('fixture binding failure',$error->getMessage()); }
        } finally { $this->db->perform('DROP TRIGGER fail_initial_binding'); }
        self::assertSame(0,(int)$this->db->fetchValue("SELECT COUNT(*) FROM ticket WHERE schluessel='202610200002'"));
        self::assertSame(0,(int)$this->db->fetchValue("SELECT COUNT(*) FROM ticket_nachricht WHERE ticket='202610200002'"));
        $method->invoke($controller,$payload);
        self::assertSame(1,(int)$this->db->fetchValue("SELECT COUNT(*) FROM ticket WHERE schluessel='202610200002'"));
        self::assertSame(1,(int)$this->db->fetchValue("SELECT COUNT(*) FROM ticket_repair_details WHERE wp_request_number='202610200002'"));
    }
}
