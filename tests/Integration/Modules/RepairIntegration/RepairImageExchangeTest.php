<?php
declare(strict_types=1);
namespace Tests\Integration\Modules\RepairIntegration;
use PHPUnit\Framework\TestCase;
use Xentral\Components\Database\{Database,DatabaseConfig};
use Xentral\Components\Database\Adapter\MysqliAdapter;
use Xentral\Components\Database\SqlQuery\QueryFactory;
use Xentral\Modules\RepairIntegration\Service\{RepairConfigService,RepairImageExchangeService};
final class RepairImageExchangeTest extends TestCase
{
    private Database $db;
    private RepairImageExchangeService $images;
    private string $root;
    private const PNG='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jK1kAAAAASUVORK5CYII=';
    protected function setUp(): void
    {
        if (getenv('REPAIR_MEDIA_TEST_DATABASE') !== 'repair_media_exchange_20261020') self::markTestSkipped('Explicit isolated media DB required.');
        $this->db=new Database(new MysqliAdapter(new DatabaseConfig('127.0.0.1','root',(string)getenv('REPAIR_TEST_PASSWORD'),'repair_media_exchange_20261020','utf8mb4')),new QueryFactory('mysql'));
        foreach($this->db->fetchCol('SHOW TABLES') as $t) $this->db->perform('DROP TABLE `'.str_replace('`','``',$t).'`');
        foreach([
            'CREATE TABLE systemconfig (`namespace` varchar(100),`key` varchar(100),`value` text,UNIQUE KEY (`namespace`,`key`))',
            'CREATE TABLE ticket (id int PRIMARY KEY,schluessel varchar(255),status varchar(50),privat int DEFAULT 0)',
            'CREATE TABLE ticket_repair_details (ticket_id int,wp_request_number varchar(20),ticket_schluessel varchar(255),service_type varchar(50))',
            'CREATE TABLE ticket_nachricht (id int AUTO_INCREMENT PRIMARY KEY,ticket varchar(255),medium varchar(50),status varchar(50),versendet varchar(50),zeitausgang datetime DEFAULT NULL)',
            'CREATE TABLE datei (id int AUTO_INCREMENT PRIMARY KEY,titel varchar(255),beschreibung text,nummer varchar(255),geloescht int,firma int,geschuetzt int NOT NULL)',
            'CREATE TABLE datei_version (id int AUTO_INCREMENT PRIMARY KEY,datei int,ersteller varchar(255),datum date,version int,dateiname varchar(255),bemerkung varchar(255),size varchar(255))',
            'CREATE TABLE datei_stichwoerter (id int AUTO_INCREMENT PRIMARY KEY,datei int,subjekt varchar(255),objekt varchar(255),parameter varchar(255),sort int,parameter2 int,objekt2 varchar(255))',
        ] as $sql) $this->db->perform($sql);
        $sql=file_get_contents(__DIR__.'/../../../../classes/Modules/RepairIntegration/Migration/sql/010_media_exchange.sql');
        foreach(explode(';',$sql) as $statement) if(trim($statement)!=='') $this->db->perform($statement);
        $this->db->perform("INSERT INTO ticket VALUES(1,'202610200001','neu',0)");
        $this->db->perform("INSERT INTO ticket_repair_details VALUES(1,'202610200001','202610200001','reparatur')");
        $this->db->perform("INSERT INTO ticket_nachricht VALUES(1,'202610200001','api','neu','0',NULL)");
        $this->root=sys_get_temp_dir().'/repair-media-test-'.bin2hex(random_bytes(8));
        mkdir($this->root,0700,true);
        $config=new RepairConfigService($this->db);$config->set('sync_instance_id','11111111-1111-4111-8111-111111111111');
        $this->images=new RepairImageExchangeService($this->db,$config,$this->root);
    }
    protected function tearDown():void
    {
        if(!isset($this->root)) return;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $p) $p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());
        rmdir($this->root);
    }
    private function input(string $origin='wp:22222222-2222-4222-8222-222222222222:1'):array
    {
        return ['origin_id'=>$origin,'sha256'=>hash('sha256',base64_decode(self::PNG)),'file_name'=>'photo.png','mime_type'=>'image/png','uploaded_at_utc'=>'2026-10-20T10:00:00.000000Z'];
    }
    public function testImportIsPhysicalIdempotentAndVisibleAtNativeTicket():void
    {
        $first=$this->images->import(1,$this->input(),base64_decode(self::PNG));
        $again=$this->images->import(1,$this->input(),base64_decode(self::PNG));
        self::assertTrue($first['created']);self::assertFalse($again['created']);
        self::assertSame(1,(int)$this->db->fetchValue('SELECT COUNT(*) FROM datei'));
        $list=$this->images->manifest(1);self::assertCount(1,$list);
        self::assertSame($this->input()['origin_id'],$list[0]['origin_id']);
        self::assertSame(base64_decode(self::PNG),$this->images->export(1,$list[0]['origin_id'])['content']);
        self::assertSame('1',$this->db->fetchValue("SELECT parameter FROM datei_stichwoerter WHERE objekt='Ticket'"));
    }
    public function testDifferentOriginSameBytesDoesNotDuplicateWithinTicket():void
    {
        $this->images->import(1,$this->input(),base64_decode(self::PNG));
        $this->images->import(1,$this->input('openxe:33333333-3333-4333-8333-333333333333:9:10'),base64_decode(self::PNG));
        self::assertSame(1,(int)$this->db->fetchValue('SELECT COUNT(*) FROM datei'));
    }
    public function testBadHashNeverCreatesAnAttachment():void
    {
        $data=$this->input();$data['sha256']=str_repeat('0',64);
        try{$this->images->import(1,$data,base64_decode(self::PNG));self::fail('Hash mismatch accepted');}catch(\InvalidArgumentException $e){self::assertStringContainsString('HASH',$e->getMessage());}
        self::assertSame(0,(int)$this->db->fetchValue('SELECT COUNT(*) FROM datei'));
    }
    public function testPreviewManifestDoesNotWriteAndPrivateTicketIsNotExported():void
    {
        $this->images->import(1,$this->input(),base64_decode(self::PNG));
        $before=$this->db->fetchAll('SELECT * FROM repair_media_exchange');
        $this->images->manifest(1);self::assertSame($before,$this->db->fetchAll('SELECT * FROM repair_media_exchange'));
        $this->db->perform('UPDATE ticket SET privat=1 WHERE id=1');
        $this->expectException(\RuntimeException::class);$this->images->manifest(1);
    }
    public function testAllImagesBeyondTwentyAreListedAndMissingBytesAreReported():void
    {
        for($i=1;$i<=23;$i++) {
            $bytes=base64_decode(self::PNG).(string)$i;
            $input=$this->input('wp:22222222-2222-4222-8222-222222222222:'.$i);
            $input['sha256']=hash('sha256',$bytes);
            $this->images->import(1,$input,$bytes);
        }
        self::assertCount(23,$this->images->manifest(1));
        unlink($this->root.'/d23/23');
        self::assertCount(22,$this->images->manifest(1));
        self::assertCount(1,$this->images->getErrors());
    }
    public function testDraftAndMismatchedTicketAreNotExported():void
    {
        $this->images->import(1,$this->input(),base64_decode(self::PNG));
        $this->db->perform("UPDATE ticket_nachricht SET medium='email',versendet='1',zeitausgang=NULL WHERE id=1");
        self::assertSame([],$this->images->manifest(1));
        $this->db->perform("UPDATE ticket_repair_details SET ticket_schluessel='other' WHERE ticket_id=1");
        $this->expectException(\RuntimeException::class);$this->images->manifest(1);
    }
    public function testSameOriginCannotBeAssignedToAnotherCustomerRequest():void
    {
        $this->images->import(1,$this->input(),base64_decode(self::PNG));
        $this->db->perform("INSERT INTO ticket VALUES(2,'202610200002','neu',0)");
        $this->db->perform("INSERT INTO ticket_repair_details VALUES(2,'202610200002','202610200002','reparatur')");
        $this->expectException(\InvalidArgumentException::class);
        $this->images->import(2,$this->input(),base64_decode(self::PNG));
    }
    public function testNativeLegacyFlatAndDirectDmsPathsRemainReadable():void
    {
        $this->images->import(1,$this->input(),base64_decode(self::PNG));
        rename($this->root.'/d1/1',$this->root.'/1');
        self::assertCount(1,$this->images->manifest(1));
        $direct=$this->root.'/legacy-photo.png';rename($this->root.'/1',$direct);
        $this->db->perform('UPDATE datei_version SET dateiname=:n WHERE id=1',['n'=>$direct]);
        $list=$this->images->manifest(1);self::assertCount(1,$list);self::assertSame('legacy-photo.png',$list[0]['file_name']);
        self::assertSame(base64_decode(self::PNG),$this->images->export(1,$this->input()['origin_id'])['content']);
    }
    public function testRetryRestoresMissingMappedBytesWithoutDuplicateDmsRows():void
    {
        $this->images->import(1,$this->input(),base64_decode(self::PNG));unlink($this->root.'/d1/1');
        $this->images->import(1,$this->input(),base64_decode(self::PNG));
        self::assertSame(1,(int)$this->db->fetchValue('SELECT COUNT(*) FROM datei'));
        self::assertCount(1,$this->images->manifest(1));self::assertSame([],$this->images->getErrors());
    }
    public function testOriginCannotPointToChangedBytes():void
    {
        $this->images->import(1,$this->input(),base64_decode(self::PNG));
        $data=$this->input();$bytes=base64_decode(self::PNG).'extra';$data['sha256']=hash('sha256',$bytes);
        $this->expectException(\InvalidArgumentException::class);$this->images->import(1,$data,$bytes);
    }
}
