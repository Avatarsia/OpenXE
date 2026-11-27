<?php
declare(strict_types=1);
namespace Xentral\Modules\RepairIntegration\Api;

use Xentral\Modules\RepairIntegration\Service\{RepairConfigService,RepairImageExchangeService,RepairStatusExchangeService};
use Xentral\Modules\RepairIntegration\Exception\{AuthenticationException,ForbiddenException};

final class RepairExchangeApiController
{
    public function __construct(private readonly RepairApiController $guard, private readonly RepairConfigService $config,
        private readonly RepairStatusExchangeService $statuses, private readonly RepairImageExchangeService $images) {}

    public function dispatch(string $path): void
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $type = $_SERVER['CONTENT_TYPE'] ?? '';
            $raw = '';
            if ($path === 'image' && $method === 'POST' && str_starts_with($type,'multipart/form-data')
                && ($_SERVER['HTTP_X_REPAIR_SIGNATURE_MODE'] ?? '') === 'image-v1') {
                $metadata=[];
                foreach (['request_number','origin_id','sha256','uploaded_at_utc'] as $field) {
                    if (!is_string($_POST[$field] ?? null)) throw new \InvalidArgumentException('INVALID_IMAGE_METADATA');
                    $metadata[$field]=$_POST[$field];
                }
                $raw="repair-image-v1\n".json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            }
            if ($method === 'POST' && !str_starts_with($type,'multipart/form-data')) {
                $raw = (string)file_get_contents('php://input',false,null,0,1048577);
                if (strlen($raw)>1048576) throw new \InvalidArgumentException('BODY_TOO_LARGE');
            }
            $this->guard->authenticateExchange($raw);
            if (!$this->config->isEnabled() || $this->config->get('sync_v2_enabled','0') !== '1') {
                $this->respond(503,['success'=>false,'error'=>'EXCHANGE_DISABLED']); return;
            }
            if ($path === 'status' && $method === 'POST') {
                if (!str_starts_with($type,'application/json')) throw new \InvalidArgumentException('JSON_REQUIRED');
                $event = json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($event)) throw new \InvalidArgumentException('INVALID_EVENT');
                $this->respond(200,(new RepairStatusExchangeApiController($this->statuses))->handle($event)); return;
            }
            $number = (string)($method==='POST'?($_POST['request_number'] ?? ''):($_GET['request_number'] ?? ''));
            $ticketId = $this->images->ticketId($number);
            if ($path === 'manifest' && $method === 'GET') {
                $ticket = $this->images->ticket($ticketId);
                $manifest = $this->statuses->getSnapshot($ticketId);
                $manifest += ['id'=>$ticketId,'request_number'=>$number,'service_type'=>$ticket['service_type']];
                $manifest['images'] = $this->images->manifest($ticketId);
                $manifest['image_errors'] = $this->images->getErrors();
                $this->respond(200,['success'=>true,'request'=>$manifest]); return;
            }
            if ($path === 'image' && $method === 'GET') {
                $image = $this->images->export($ticketId,(string)($_GET['origin_id'] ?? ''));
                header('Content-Type: '.$image['mime_type']);
                header('Content-Length: '.strlen($image['content']));
                header('Cache-Control: private, no-store');
                header('X-Content-Type-Options: nosniff');
                echo $image['content']; return;
            }
            if ($path === 'image' && $method === 'POST') {
                $file = $_FILES['file'] ?? null;
                if (!is_array($file) || ($file['error'] ?? -1)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])
                    || filesize($file['tmp_name'])>RepairImageExchangeService::MAX_BYTES) throw new \InvalidArgumentException('INVALID_IMAGE_UPLOAD');
                $input = ['origin_id'=>(string)($_POST['origin_id'] ?? ''),'sha256'=>(string)($_POST['sha256'] ?? ''),'file_name'=>(string)$file['name']];
                if (isset($_POST['uploaded_at_utc'])) $input['uploaded_at_utc']=(string)$_POST['uploaded_at_utc'];
                $this->respond(200,$this->images->import($ticketId,$input,(string)file_get_contents($file['tmp_name']))); return;
            }
            $this->respond(405,['success'=>false,'error'=>'METHOD_NOT_ALLOWED']);
        } catch (AuthenticationException $e) { $this->respond(401,['success'=>false,'error'=>$e->getMessage()]);
        } catch (ForbiddenException $e) { $this->respond(403,['success'=>false,'error'=>$e->getMessage()]);
        } catch (\InvalidArgumentException|\JsonException $e) { $this->respond(422,['success'=>false,'error'=>$e->getMessage()]);
        } catch (\Throwable $e) {
            $notFound = in_array($e->getMessage(),['TICKET_NOT_FOUND','IMAGE_NOT_FOUND'],true);
            error_log('Repair exchange: '.get_class($e).': '.$e->getMessage());
            $this->respond($notFound?404:500,['success'=>false,'error'=>$notFound?$e->getMessage():'EXCHANGE_FAILED']);
        }
    }

    private function respond(int $code,array $data): void
    {
        http_response_code($code);header('Content-Type: application/json');header('Cache-Control: no-store');
        echo json_encode($data,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
