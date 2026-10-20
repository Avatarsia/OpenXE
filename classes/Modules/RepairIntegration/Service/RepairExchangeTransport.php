<?php
declare(strict_types=1);
namespace Xentral\Modules\RepairIntegration\Service;

/** Authenticated WP exchange; redirects and unbounded responses are forbidden. */
final class RepairExchangeTransport
{
    public function __construct(private readonly RepairConfigService $config, private readonly ?\Closure $transport = null) {}

    public function json(string $method, string $path, ?array $data = null): array
    {
        $body = $data === null ? null : json_encode($data,JSON_THROW_ON_ERROR);
        $result = $this->request($method,$path,$body,4194304);
        $decoded = json_decode($result,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || empty($decoded['success'])) throw new \RuntimeException('WP_EXCHANGE_UNSUCCESSFUL');
        return $decoded;
    }

    public function download(string $number, array $image): string
    {
        $content = $this->request('GET','image?'.http_build_query(['request_number'=>$number,'origin_id'=>$image['origin_id']]),null,RepairImageExchangeService::MAX_BYTES);
        if (!hash_equals((string)$image['sha256'],hash('sha256',$content))) throw new \RuntimeException('WP_IMAGE_HASH_MISMATCH');
        return $content;
    }

    public function upload(string $number, array $image, string $bytes): array
    {
        $temp = tempnam(sys_get_temp_dir(),'repair-exchange-');
        if ($temp === false) throw new \RuntimeException('IMAGE_TEMP_FAILED');
        try {
            if (file_put_contents($temp,$bytes) !== strlen($bytes)) throw new \RuntimeException('IMAGE_TEMP_FAILED');
            $data = ['request_number'=>$number,'origin_id'=>$image['origin_id'],'sha256'=>$image['sha256'],
                'uploaded_at_utc'=>$image['uploaded_at_utc'],'file'=>new \CURLFile($temp,$image['mime_type'],$image['file_name'])];
            $decoded = json_decode($this->request('POST','image',$data,1048576),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || empty($decoded['success']) || ($decoded['image']['sha256'] ?? '') !== $image['sha256']) throw new \RuntimeException('WP_IMAGE_NOT_CONFIRMED');
            return $decoded;
        } finally { if (is_file($temp)) unlink($temp); }
    }

    private function request(string $method,string $path,$body,int $max): string
    {
        $base = $this->config->getWpApiUrl();
        $parts = parse_url($base);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new \RuntimeException('INVALID_WP_EXCHANGE_URL');
        $scheme = strtolower($parts['scheme'] ?? '');
        $local = getenv('REPAIR_EXCHANGE_ALLOW_LOCAL_HTTP') === '1'
            && ($parts['host'] === getenv('REPAIR_EXCHANGE_LOCAL_HOST') || in_array($parts['host'],['127.0.0.1','localhost'],true));
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) throw new \RuntimeException('WP_EXCHANGE_REQUIRES_HTTPS');
        if ($this->config->getWpApiKey() === '') throw new \RuntimeException('WP_API_KEY_NOT_CONFIGURED');
        $url = rtrim($base,'/').'/wp-json/p3d/v1/erp-sync/'.$path;
        if ($this->transport !== null) return ($this->transport)($method,$path,$body,$max);
        $handle = curl_init($url);
        if ($handle === false) throw new \RuntimeException('WP_CURL_FAILED');
        $received = '';
        $headers = ['Authorization: Bearer '.$this->config->getWpApiKey(),'X-Repair-Source: openxe'];
        if (is_string($body)) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($handle,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_PROTOCOLS=>$scheme==='https'?CURLPROTO_HTTPS:CURLPROTO_HTTP,
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$received,$max): int {
                if (strlen($received)+strlen($chunk)>$max) return 0;
                $received.=$chunk; return strlen($chunk);
            }]);
        if ($body !== null) curl_setopt($handle,CURLOPT_POSTFIELDS,$body);
        $ok = curl_exec($handle);
        $code = (int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($ok === false || $code<200 || $code>=300) throw new \RuntimeException('WP_EXCHANGE_HTTP_'.$code);
        return $received;
    }
}
