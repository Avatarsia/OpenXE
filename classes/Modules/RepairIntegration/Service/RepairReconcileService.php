<?php
declare(strict_types=1);
namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;

/** Resumable full scans plus targeted upload recovery. Preview never changes business data. */
final class RepairReconcileService
{
    public function __construct(private readonly Database $db, private readonly RepairConfigService $config,
        private readonly RepairStatusExchangeService $statuses, private readonly RepairImageExchangeService $images,
        private readonly RepairExchangeTransport $transport) {}

    public function enabled(): bool { return $this->config->isEnabled() && $this->config->get('sync_v2_enabled','0')==='1'; }

    public static function imageDelta(array $local,array $remote): array
    {
        $byOrigin=[];
        foreach (array_merge($local,$remote) as $image) {
            if (!isset($image['origin_id'],$image['sha256']) || !is_string($image['origin_id']) || !preg_match('/^[a-f0-9]{64}$/D',(string)$image['sha256'])) throw new \InvalidArgumentException('INVALID_IMAGE_MANIFEST');
            if (isset($byOrigin[$image['origin_id']]) && $byOrigin[$image['origin_id']] !== $image['sha256']) throw new \InvalidArgumentException('IMAGE_ORIGIN_HASH_CONFLICT');
            $byOrigin[$image['origin_id']]=$image['sha256'];
        }
        $localHashes=array_column($local,null,'sha256');$remoteHashes=array_column($remote,null,'sha256');
        return ['to_local'=>array_values(array_diff_key(array_column($remote,null,'sha256'),$localHashes)),
            'to_remote'=>array_values(array_diff_key(array_column($local,null,'sha256'),$remoteHashes))];
    }

    public function start(string $mode): int
    {
        if (!$this->enabled()) throw new \RuntimeException('EXCHANGE_DISABLED');
        if (!in_array($mode,['preview','apply'],true)) throw new \InvalidArgumentException('INVALID_MODE');
        $this->db->perform('INSERT INTO repair_exchange_jobs(mode) VALUES(:m)',['m'=>$mode]);
        return (int)$this->db->lastInsertId();
    }

    public function jobs(): array
    {
        return $this->db->fetchAll('SELECT j.*,(SELECT COUNT(*) FROM repair_exchange_results r WHERE r.job_id=j.id) checked,
            (SELECT COUNT(*) FROM repair_exchange_results r WHERE r.job_id=j.id AND r.state IN (\'error\',\'unclear\',\'unlinked\',\'waiting_creation\')) issues
            FROM repair_exchange_jobs j ORDER BY id DESC LIMIT 10');
    }

    public function results(int $job): array
    {
        $rows=$this->db->fetchAll('SELECT * FROM repair_exchange_results WHERE job_id=:j ORDER BY id DESC LIMIT 200',['j'=>$job]);
        foreach ($rows as &$row) $row['result']=json_decode($row['result_json'],true);
        return $rows;
    }

    public function tick(int $jobId,int $maxRecords=20,float $seconds=30.0): void
    {
        if (!$this->enabled()) return;
        $lock='repair_exchange:'.sha1((string)$this->db->fetchValue('SELECT DATABASE()'));
        if ((int)$this->db->fetchValue('SELECT GET_LOCK(:n,0)',['n'=>$lock])!==1) return;
        $deadline=microtime(true)+$seconds;
        try {
            $job=$this->db->fetchRow('SELECT * FROM repair_exchange_jobs WHERE id=:id',['id'=>$jobId]);
            if (!$job || $job['state']!=='running') return;
            for ($i=0;$i<$maxRecords && microtime(true)<$deadline;$i++) {
                if ($job['phase']==='remote') {
                    $page=$this->transport->json('GET','requests?'.http_build_query(['cursor'=>$job['remote_cursor'],'limit'=>1]));
                    $rows=$page['requests'] ?? null;
                    if (!is_array($rows) || count($rows)>1 || !array_key_exists('has_more',$page)) throw new \RuntimeException('INVALID_REMOTE_PAGE');
                    if ($rows===[]) {
                        if (!empty($page['has_more'])) throw new \RuntimeException('REMOTE_CURSOR_STALLED');
                        $job['phase']='local';$this->saveJob($job);continue;
                    }
                    $remote=$rows[0];
                    $number=(string)($remote['request_number'] ?? '');
                    $remoteId=(int)($remote['id'] ?? 0);
                    if ($remoteId<=(int)$job['remote_cursor']) throw new \RuntimeException('REMOTE_CURSOR_STALLED');
                    $result=$this->safeReconcile($remote,$job['mode']==='apply',null,$deadline);
                    $this->record($jobId,$number,$result);
                    if ($result['state']==='pending_images') break;
                    $job['remote_cursor']=$remoteId;
                    if (empty($page['has_more'])) $job['phase']='local';
                } else {
                    $row=$this->db->fetchRow('SELECT r.ticket_id,r.wp_request_number FROM ticket_repair_details r
                        WHERE r.ticket_id>:cursor ORDER BY r.ticket_id LIMIT 1',['cursor'=>(int)$job['local_cursor']]);
                    if (!$row) {
                        $issues=(int)$this->db->fetchValue("SELECT COUNT(*) FROM repair_exchange_results WHERE job_id=:j AND state IN ('error','unclear','unlinked','waiting_creation')",['j'=>$jobId]);
                        $job['state']=$issues>0?'attention':'complete';$this->saveJob($job);break;
                    }
                    $job['local_cursor']=(int)$row['ticket_id'];
                    $number=trim((string)$row['wp_request_number']);
                    if ($number==='' || $this->db->fetchValue("SELECT id FROM repair_exchange_results WHERE job_id=:j AND request_number=:n AND state<>'pending_images'",['j'=>$jobId,'n'=>$number])) {
                        // Unlinked local/private tickets are reported, never published as WP customer requests.
                        if ($number==='') $this->record($jobId,'local-'.$row['ticket_id'],['state'=>'unlinked','ticket_id'=>(int)$row['ticket_id'],'error'=>'Keine eindeutige WP-Verknuepfung']);
                        $this->saveJob($job);continue;
                    }
                    try {
                        $remote=$this->transport->json('GET','manifest?'.http_build_query(['request_number'=>$number]))['request'] ?? [];
                        $result=$this->safeReconcile($remote,$job['mode']==='apply',null,$deadline);
                    } catch (\Throwable $e) { $result=['state'=>'error','ticket_id'=>(int)$row['ticket_id'],'error'=>$e->getMessage()]; }
                    $this->record($jobId,$number,$result);
                    if ($result['state']==='pending_images') $job['local_cursor']=(int)$row['ticket_id']-1;
                }
                $this->saveJob($job);
                if (($result['state'] ?? '')==='pending_images') break;
            }
            $this->saveJob($job);
        } catch (\Throwable $e) {
            $this->db->perform('UPDATE repair_exchange_jobs SET last_error=:e,updated_at=UTC_TIMESTAMP() WHERE id=:id',['e'=>$e->getMessage(),'id'=>$jobId]);
        } finally { $this->db->fetchValue('SELECT RELEASE_LOCK(:n)',['n'=>$lock]); }
    }

    public function runScheduled(): void
    {
        if (!$this->enabled()) return;
        $this->processPending(1);
        $job=$this->db->fetchRow("SELECT id FROM repair_exchange_jobs WHERE state='running' AND mode='apply' ORDER BY id LIMIT 1");
        if (!$job && time()-(int)$this->config->get('exchange_last_scan','0')>=300) {
            $id=$this->start('apply');$this->config->set('exchange_last_scan',(string)time());
        } else { $id=(int)($job['id'] ?? 0); }
        if ($id>0) $this->tick($id);
    }

    public function queueTicket(int $ticket): void
    {
        if (!$this->enabled()) return;
        $this->db->perform('INSERT INTO repair_exchange_pending(ticket_id) VALUES(:t)
            ON DUPLICATE KEY UPDATE next_retry_at=UTC_TIMESTAMP(),generation=generation+1',['t'=>$ticket]);
    }

    public function processPending(int $limit=3): void
    {
        if (!$this->enabled()) return;
        $rows=$this->db->fetchAll('SELECT * FROM repair_exchange_pending WHERE next_retry_at<=UTC_TIMESTAMP() ORDER BY ticket_id LIMIT '.max(1,min(10,$limit)));
        foreach ($rows as $row) {
            try {
                $ticket=$this->images->ticket((int)$row['ticket_id']);
                $remote=$this->transport->json('GET','manifest?'.http_build_query(['request_number'=>$ticket['wp_request_number']]))['request'] ?? [];
                $result=$this->reconcileOne($remote,true,null,microtime(true)+20);
                if (in_array($result['state'],['error','pending_images'],true)) throw new \RuntimeException(implode('; ',$result['errors'] ?? [$result['state']]));
                $this->db->perform('DELETE FROM repair_exchange_pending WHERE ticket_id=:t AND generation=:g',['t'=>$row['ticket_id'],'g'=>$row['generation']]);
            } catch (\Throwable $e) {
                $delay=min(3600,120*(2**min(5,(int)$row['attempts'])));
                $this->db->perform('UPDATE repair_exchange_pending SET attempts=attempts+1,last_error=:e,next_retry_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.$delay.' SECOND) WHERE ticket_id=:t AND generation=:g',['e'=>$e->getMessage(),'t'=>$row['ticket_id'],'g'=>$row['generation']]);
            }
        }
    }

    public function resolve(string $number,string $choice): array
    {
        if (!$this->enabled() || !in_array($choice,['local','remote'],true)) throw new \InvalidArgumentException('INVALID_RESOLUTION');
        $remote=$this->transport->json('GET','manifest?'.http_build_query(['request_number'=>$number]))['request'] ?? [];
        return $this->reconcileOne($remote,true,$choice,microtime(true)+25);
    }

    public function reconcileOne(array $remote,bool $apply=false,?string $choice=null,?float $deadline=null): array
    {
        $number=(string)($remote['request_number'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{1,20}$/D',$number)) throw new \InvalidArgumentException('INVALID_REQUEST_NUMBER');
        $id=$this->resolveTicket($number,$apply);
        if ($id===null) {
            if ($apply) $this->transport->json('POST','enqueue-initial',['request_number'=>$number]);
            return ['state'=>$apply?'waiting_creation':'unlinked','ticket_id'=>null,'error'=>'ERP-Anlage oder Verknuepfung fehlt'];
        }
        $result=['ticket_id'=>$id,'state'=>'equal','errors'=>[],'added_local'=>0,'added_remote'=>0,
            'local_status'=>$this->statuses->getSnapshot($id)['status'],'remote_status'=>$remote['status'] ?? null];
        try {
            $status=$this->statuses->reconcile($id,$remote,$apply,$choice);
            $result['status']=$status;$result['state']=$status['state'];
            if ($apply && !empty($status['event']) && $status['state']!=='unclear' && $status['state']!=='unmapped') {
                $current=$status['event'];$other=$remote['status_event'] ?? null;
                if ($other===null || $other['event_id']!==$current['event_id'] || (int)($other['revision'] ?? 0)!==(int)$current['revision']) {
                    $answer=$this->transport->json('POST','status',$current);
                    if (!empty($answer['event'])) $this->statuses->receive($answer['event']);
                }
            }
        } catch (\Throwable $e) { $result['errors'][]='Status: '.$e->getMessage(); }
        $local=$this->images->manifest($id);
        foreach ($this->images->getErrors() as $error) $result['errors'][]='Bild '.$error['file_id'].': '.$error['error'];
        foreach (($remote['image_errors'] ?? []) as $error) $result['errors'][]='WP-Bild: '.(is_array($error)?json_encode($error):$error);
        $delta=self::imageDelta($local,is_array($remote['images'] ?? null)?$remote['images']:[]);
        $result['missing_local']=count($delta['to_local']);$result['missing_remote']=count($delta['to_remote']);
        if ($apply) {
            $count=0;
            $failures=[];
            foreach ($this->db->fetchAll('SELECT * FROM repair_media_failures WHERE ticket_id=:t',['t'=>$id]) as $failure) $failures[$failure['direction'].':'.$failure['origin_id']]=$failure;
            foreach (['to_local','to_remote'] as $direction) {
                usort($delta[$direction],static function(array $a,array $b) use ($failures,$direction): int {
                    return strcmp($failures[$direction.':'.$a['origin_id']]['last_attempt'] ?? '',$failures[$direction.':'.$b['origin_id']]['last_attempt'] ?? '');
                });
            }
            foreach (['to_local','to_remote'] as $direction) foreach ($delta[$direction] as $image) {
                if ($count>=8 || ($deadline!==null && microtime(true)>$deadline-2)) break 2;
                $failure=$failures[$direction.':'.$image['origin_id']] ?? null;
                if ($failure && $failure['next_retry_at']>gmdate('Y-m-d H:i:s')) {
                    $result['errors'][]='Bild '.$image['origin_id'].': '.$failure['last_error'];continue;
                }
                try {
                    if ($direction==='to_local') {
                        $this->images->import($id,$image,$this->transport->download($number,$image));$result['added_local']++;
                    } else {
                        $bytes=$this->images->export($id,$image['origin_id'])['content'];$this->transport->upload($number,$image,$bytes);$result['added_remote']++;
                    }
                    $this->db->perform('DELETE FROM repair_media_failures WHERE ticket_id=:t AND origin_id=:o AND direction=:d',['t'=>$id,'o'=>$image['origin_id'],'d'=>$direction]);
                } catch (\Throwable $e) {
                    $result['errors'][]='Bild '.$image['origin_id'].': '.$e->getMessage();
                    $attempts=(int)($failure['attempts'] ?? 0)+1;$delay=min(3600,120*(2**min(5,$attempts-1)));
                    $this->db->perform('INSERT INTO repair_media_failures(ticket_id,origin_id,direction,attempts,last_attempt,next_retry_at,last_error)
                        VALUES(:t,:o,:d,:a,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.$delay.' SECOND),:e)
                        ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),last_attempt=VALUES(last_attempt),next_retry_at=VALUES(next_retry_at),last_error=VALUES(last_error)',
                        ['t'=>$id,'o'=>$image['origin_id'],'d'=>$direction,'a'=>$attempts,'e'=>$e->getMessage()]);
                }
                $count++;
            }
            $missingOrigins=[];
            foreach (['to_local','to_remote'] as $direction) foreach ($delta[$direction] as $image) $missingOrigins[$direction.':'.$image['origin_id']]=true;
            foreach ($this->db->fetchAll('SELECT * FROM repair_media_failures WHERE ticket_id=:t',['t'=>$id]) as $failure) {
                if (isset($missingOrigins[$failure['direction'].':'.$failure['origin_id']])) $result['errors'][]='Bild '.$failure['origin_id'].': '.$failure['last_error'];
            }
            $result['errors']=array_values(array_unique($result['errors']));
            if (!$result['errors'] && $result['added_local']+$result['added_remote']<$result['missing_local']+$result['missing_remote']) $result['state']='pending_images';
        }
        if ($result['errors']) $result['state']='error';
        return $result;
    }

    private function safeReconcile(array $remote,bool $apply,?string $choice,float $deadline): array
    {
        try { return $this->reconcileOne($remote,$apply,$choice,$deadline); }
        catch (\Throwable $e) { return ['state'=>'error','error'=>$e->getMessage()]; }
    }

    private function resolveTicket(string $number,bool $apply): ?int
    {
        $rows=$this->db->fetchAll("SELECT r.ticket_id,r.wp_request_number FROM ticket_repair_details r JOIN ticket t ON t.id=r.ticket_id
            WHERE r.wp_request_number=:n OR (r.wp_request_number IS NULL AND r.ticket_schluessel=:k AND t.schluessel=:s
                AND t.quelle='api' AND EXISTS(SELECT 1 FROM ticket_nachricht m WHERE m.ticket=t.schluessel AND m.medium='api'))", ['n'=>$number,'k'=>$number,'s'=>$number]);
        if (count($rows)>1) throw new \RuntimeException('AMBIGUOUS_TICKET');
        if ($rows===[]) {
            if ((int)$this->db->fetchValue('SELECT COUNT(*) FROM ticket WHERE schluessel=:n',['n'=>$number])>0) throw new \RuntimeException('UNLINKED_TICKET_KEY_COLLISION');
            return null;
        }
        if (empty($rows[0]['wp_request_number'])) {
            if (!$apply) return null;
            $this->db->perform('UPDATE ticket_repair_details SET wp_request_number=:n WHERE ticket_id=:id AND wp_request_number IS NULL',['n'=>$number,'id'=>$rows[0]['ticket_id']]);
        }
        $id=(int)$rows[0]['ticket_id'];$this->images->ticket($id);return $id;
    }

    private function saveJob(array $job): void
    {
        $this->db->perform('UPDATE repair_exchange_jobs SET state=:s,remote_cursor=:r,local_cursor=:l,phase=:p,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id',
            ['s'=>$job['state'],'r'=>$job['remote_cursor'],'l'=>$job['local_cursor'],'p'=>$job['phase'],'id'=>$job['id']]);
    }

    private function record(int $job,string $number,array $result): void
    {
        $this->db->perform('INSERT INTO repair_exchange_results(job_id,request_number,ticket_id,state,result_json) VALUES(:j,:n,:t,:s,:r)
            ON DUPLICATE KEY UPDATE ticket_id=VALUES(ticket_id),state=VALUES(state),result_json=VALUES(result_json)',
            ['j'=>$job,'n'=>$number,'t'=>$result['ticket_id'] ?? null,'s'=>$result['state'],'r'=>json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)]);
    }
}
