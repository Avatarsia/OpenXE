<?php
declare(strict_types=1);
namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;

/** Native Ticket/DMS images; manifests are read-only, imports keep their origin. */
final class RepairImageExchangeService
{
    public const MAX_BYTES = 26214400;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private array $errors = [];

    public function __construct(private readonly Database $db, private readonly RepairConfigService $config, private readonly ?string $dmsRoot = null) {}

    public function ticket(int $id): array
    {
        $row = $this->db->fetchRow('SELECT t.id,t.schluessel,t.privat,r.wp_request_number,r.ticket_schluessel,r.service_type
            FROM ticket t JOIN ticket_repair_details r ON r.ticket_id=t.id WHERE t.id=:id', ['id'=>$id]);
        if (!$row || (int)$row['privat'] !== 0 || empty($row['wp_request_number']) || $row['ticket_schluessel'] !== $row['schluessel']) {
            throw new \RuntimeException('TICKET_NOT_EXPORTABLE');
        }
        $matches = (int)$this->db->fetchValue('SELECT COUNT(*) FROM ticket_repair_details WHERE wp_request_number=:number', ['number'=>$row['wp_request_number']]);
        $tickets = (int)$this->db->fetchValue('SELECT COUNT(*) FROM ticket WHERE schluessel=:key', ['key'=>$row['schluessel']]);
        if ($matches !== 1 || $tickets !== 1) throw new \RuntimeException('AMBIGUOUS_TICKET');
        return $row;
    }

    public function ticketId(string $number): int
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,20}$/D', $number)) throw new \InvalidArgumentException('INVALID_REQUEST_NUMBER');
        $rows = $this->db->fetchAll('SELECT ticket_id FROM ticket_repair_details WHERE wp_request_number=:n', ['n'=>$number]);
        if (count($rows) !== 1) throw new \RuntimeException(count($rows) ? 'AMBIGUOUS_TICKET' : 'TICKET_NOT_FOUND');
        $id = (int)$rows[0]['ticket_id'];
        $this->ticket($id);
        return $id;
    }

    public function getErrors(): array { return $this->errors; }

    public function manifest(int $ticketId): array
    {
        $ticket = $this->ticket($ticketId);
        $this->errors = [];
        $images = [];
        foreach ($this->files($ticket) as $file) {
            try {
                $path = $this->readPath($file);
                if (!is_file($path)) throw new \RuntimeException('IMAGE_FILE_MISSING');
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                if (!isset(self::TYPES[$mime])) continue;
                if (filesize($path) > self::MAX_BYTES) throw new \RuntimeException('IMAGE_TOO_LARGE');
                $hash = hash_file('sha256', $path);
                $alias = $this->db->fetchRow('SELECT origin_id,uploaded_at_utc FROM repair_media_exchange
                    WHERE ticket_id=:t AND file_id=:f AND version_id=:v AND sha256=:h ORDER BY id LIMIT 1',
                    ['t'=>$ticketId,'f'=>$file['file_id'],'v'=>$file['version_id'],'h'=>$hash]);
                $origin = $alias ? $alias['origin_id'] : 'openxe:'.$this->instanceId().':'.$file['file_id'].':'.$file['version_id'];
                $images[] = ['origin_id'=>$origin,'sha256'=>$hash,'mime_type'=>$mime,'file_name'=>basename(str_replace('\\','/',$file['dateiname'])),
                    'uploaded_at_utc'=>$alias['uploaded_at_utc'] ?? $this->date((string)$file['datum'])];
            } catch (\Throwable $e) {
                $this->errors[] = ['file_id'=>(int)$file['file_id'],'error'=>$e->getMessage()];
            }
        }
        return $images;
    }

    public function export(int $ticketId, string $origin): array
    {
        self::validateOrigin($origin);
        $images = $this->manifest($ticketId);
        foreach ($images as $image) {
            if ($image['origin_id'] !== $origin) continue;
            foreach ($this->files($this->ticket($ticketId)) as $file) {
                $path = $this->readPath($file);
                if (is_file($path) && hash_file('sha256',$path) === $image['sha256']) {
                    $content = file_get_contents($path);
                    if ($content === false || hash('sha256',$content) !== $image['sha256']) throw new \RuntimeException('IMAGE_CHANGED_DURING_READ');
                    return $image + ['content'=>$content];
                }
            }
        }
        throw new \RuntimeException('IMAGE_NOT_FOUND');
    }

    public function import(int $ticketId, array $image, string $content): array
    {
        $ticket = $this->ticket($ticketId);
        self::validateOrigin((string)($image['origin_id'] ?? ''));
        $hash = (string)($image['sha256'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash) || !hash_equals($hash,hash('sha256',$content))) throw new \InvalidArgumentException('IMAGE_HASH_MISMATCH');
        if ($content === '' || strlen($content)>self::MAX_BYTES) throw new \InvalidArgumentException('IMAGE_SIZE_INVALID');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!isset(self::TYPES[$mime]) || @getimagesizefromstring($content) === false) throw new \InvalidArgumentException('IMAGE_TYPE_INVALID');
        if (isset($image['mime_type']) && $image['mime_type'] !== $mime) throw new \InvalidArgumentException('IMAGE_TYPE_MISMATCH');
        $originLock='repair_media_origin:'.sha1((string)$this->db->fetchValue('SELECT DATABASE()').':'.$image['origin_id']);
        if ((int)$this->db->fetchValue('SELECT GET_LOCK(:n,10)',['n'=>$originLock]) !== 1) throw new \RuntimeException('IMAGE_ORIGIN_LOCK_TIMEOUT');
        $lock = 'repair_media:'.sha1((string)$this->db->fetchValue('SELECT DATABASE()').':'.$ticketId);
        if ((int)$this->db->fetchValue('SELECT GET_LOCK(:n,10)',['n'=>$lock]) !== 1) {
            $this->db->fetchValue('SELECT RELEASE_LOCK(:n)',['n'=>$originLock]);throw new \RuntimeException('IMAGE_LOCK_TIMEOUT');
        }
        $written = null;
        try {
            $owner=$this->db->fetchValue('SELECT ticket_id FROM repair_media_exchange WHERE origin_id=:o',['o'=>$image['origin_id']]);
            if ($owner !== false && $owner !== null && (int)$owner !== $ticketId) throw new \InvalidArgumentException('IMAGE_ORIGIN_WRONG_REQUEST');
            $existing = $this->db->fetchRow('SELECT * FROM repair_media_exchange WHERE ticket_id=:t AND origin_id=:o', ['t'=>$ticketId,'o'=>$image['origin_id']]);
            if ($existing && $existing['sha256'] !== $hash) throw new \InvalidArgumentException('IMAGE_ORIGIN_HASH_CONFLICT');
            $match = null;$restore=false;
            foreach ($this->files($ticket) as $file) {
                $path = $this->readPath($file);
                if (is_file($path) && hash_file('sha256',$path) === $hash) { $match=$file; break; }
                if ($existing && (int)$existing['file_id']===(int)$file['file_id'] && (int)$existing['version_id']===(int)$file['version_id']) {
                    if (is_file($path)) throw new \InvalidArgumentException('IMAGE_ORIGIN_CONTENT_CHANGED');
                    $match=$file;$restore=true;break;
                }
            }
            $created = $match === null;
            $this->db->beginTransaction();
            if ($match === null) {
                $name = basename(str_replace('\\','/',(string)($image['file_name'] ?? 'photo')));
                $name = substr(preg_replace('/[\x00-\x1f\x7f]/','',$name),0,180);
                $name = ($name === '' ? 'photo' : pathinfo($name,PATHINFO_FILENAME)).'.'.self::TYPES[$mime];
                $protected=(int)$this->db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='datei' AND COLUMN_NAME='geschuetzt'")>0;
                $this->db->perform("INSERT INTO datei(titel,beschreibung,nummer,geloescht,firma".($protected?',geschuetzt':'').") VALUES(:n,'Reparaturbild-Sync',:o,0,1".($protected?',0':'').")",['n'=>$name,'o'=>'REPAIR-SYNC-'.hash('sha256',$image['origin_id'])]);
                $fileId = (int)$this->db->lastInsertId();
                $this->db->perform("INSERT INTO datei_version(datei,ersteller,datum,version,dateiname,bemerkung,size) VALUES(:f,'Repair-Sync',UTC_DATE(),1,:n,'Initiale Version',:s)",['f'=>$fileId,'n'=>$name,'s'=>(string)strlen($content)]);
                $versionId = (int)$this->db->lastInsertId();
                $written = $this->path($versionId);
                if (!is_dir(dirname($written)) && !mkdir(dirname($written),0700,true) && !is_dir(dirname($written))) throw new \RuntimeException('IMAGE_DIRECTORY_FAILED');
                if (file_put_contents($written,$content,LOCK_EX) !== strlen($content) || hash_file('sha256',$written) !== $hash) throw new \RuntimeException('IMAGE_WRITE_FAILED');
                $message = (int)$this->db->fetchValue("SELECT MIN(id) FROM ticket_nachricht WHERE ticket=:k AND medium='api'",['k'=>$ticket['schluessel']]);
                $this->db->perform("INSERT INTO datei_stichwoerter(datei,subjekt,objekt,parameter,sort,parameter2,objekt2) VALUES(:f,'Anhang',:o,:p,1,0,'')",['f'=>$fileId,'o'=>$message>0?'Ticket':'ticket_header','p'=>(string)($message>0?$message:$ticketId)]);
                $match = ['file_id'=>$fileId,'version_id'=>$versionId];
            } elseif ($restore) {
                $written=$this->path((int)$match['version_id']);
                if (!is_dir(dirname($written)) && !mkdir(dirname($written),0700,true) && !is_dir(dirname($written))) throw new \RuntimeException('IMAGE_DIRECTORY_FAILED');
                if (file_put_contents($written,$content,LOCK_EX)!==strlen($content) || hash_file('sha256',$written)!==$hash) throw new \RuntimeException('IMAGE_WRITE_FAILED');
            }
            $date = $image['uploaded_at_utc'] ?? gmdate('Y-m-d\TH:i:s.000000\Z');
            if (!is_string($date) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D',$date)) throw new \InvalidArgumentException('IMAGE_DATE_INVALID');
            $this->db->perform('INSERT INTO repair_media_exchange(ticket_id,origin_id,file_id,version_id,sha256,uploaded_at_utc)
                VALUES(:t,:o,:f,:v,:h,:d) ON DUPLICATE KEY UPDATE file_id=VALUES(file_id),version_id=VALUES(version_id)',
                ['t'=>$ticketId,'o'=>$image['origin_id'],'f'=>$match['file_id'],'v'=>$match['version_id'],'h'=>$hash,'d'=>$date]);
            $this->db->commit();
            return ['success'=>true,'created'=>$created,'image'=>['origin_id'=>$image['origin_id'],'sha256'=>$hash,'mime_type'=>$mime,'file_name'=>$image['file_name'] ?? 'photo.'.self::TYPES[$mime],'uploaded_at_utc'=>$date]];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($written !== null && is_file($written)) unlink($written);
            throw $e;
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(:n)',['n'=>$lock]);
            $this->db->fetchValue('SELECT RELEASE_LOCK(:n)',['n'=>$originLock]);
        }
    }

    public static function validateOrigin(string $id): void
    {
        if (!preg_match('/^(?:wp:[a-f0-9-]{36}:\d+|openxe:[a-f0-9-]{36}:\d+:\d+)$/D',$id) || strlen($id)>190) throw new \InvalidArgumentException('INVALID_IMAGE_ORIGIN');
    }

    private function files(array $ticket): array
    {
        return $this->db->fetchAll("SELECT d.id file_id,v.id version_id,v.dateiname,v.datum FROM datei d
            JOIN datei_version v ON v.id=(SELECT vv.id FROM datei_version vv WHERE vv.datei=d.id ORDER BY vv.version DESC,vv.id DESC LIMIT 1)
            WHERE d.geloescht=0 AND EXISTS(SELECT 1 FROM datei_stichwoerter ds WHERE ds.datei=d.id AND
              ((ds.objekt='ticket_header' AND ds.parameter=:id) OR (ds.objekt='Ticket' AND EXISTS(
                SELECT 1 FROM ticket_nachricht n WHERE n.id=ds.parameter AND n.ticket=:k
                AND (n.medium='api' OR n.versendet<>'1' OR n.zeitausgang IS NOT NULL))))) ORDER BY d.id",['id'=>(string)$ticket['id'],'k'=>$ticket['schluessel']]);
    }

    private function instanceId(): string
    {
        $id=$this->config->get('sync_instance_id');
        if (!preg_match('/^[a-f0-9-]{36}$/D',$id)) throw new \RuntimeException('SYNC_INSTANCE_NOT_CONFIGURED');
        return $id;
    }

    private function date(string $date): string
    {
        return preg_match('/^\d{4}-\d\d-\d\d/',$date) ? substr($date,0,10).'T00:00:00.000000Z' : '1970-01-01T00:00:00.000000Z';
    }

    private function path(int $version): string
    {
        if ($version<=0) throw new \InvalidArgumentException('INVALID_DMS_VERSION');
        return $this->rootDirectory().'/d'.implode('/d',str_split((string)$version,2)).'/'.$version;
    }

    private function readPath(array $file): string
    {
        $direct=(string)$file['dateiname'];
        if (!str_contains($direct,'://') && is_file($direct)) return $direct;
        $path=$this->path((int)$file['version_id']);
        if (is_file($path)) return $path;
        $flat=$this->rootDirectory().'/'.(int)$file['version_id'];
        return is_file($flat)?$flat:$path;
    }

    private function rootDirectory(): string
    {
        $root=$this->dmsRoot;
        if ($root===null) {
            $conf=class_exists('Config')?new \Config():null;
            $userdata=$conf && !empty($conf->WFuserdata)?$conf->WFuserdata:dirname(__DIR__,4).'/userdata';
            $name=(string)$this->db->fetchValue('SELECT DATABASE()');
            if ($name==='') throw new \RuntimeException('DMS_DATABASE_MISSING');
            $root=rtrim($userdata,'/\\').'/dms/'.$name;
        }
        return rtrim($root,'/\\');
    }
}
