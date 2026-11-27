<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
fclose($socket);
$log = tmpfile();
$server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/wordpress-router.php'], [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes);
if (!is_resource($server)) { throw new RuntimeException('Cannot start WordPress API fixture'); }
register_shutdown_function(static function () use ($server, $log) { proc_terminate($server); proc_close($server); fclose($log); });
$ready = false;
for ($attempt = 0; $attempt < 40; $attempt++) {
    $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
    if ($probe !== false) { fclose($probe); $ready = true; break; }
    usleep(50000);
}
check($ready, 'WordPress fixture did not start');

$connector = (new ReflectionClass(Shopimporter_Woocommerce::class))->newInstanceWithoutConstructor();
$connector->url = 'http://' . $address . '/store/';
$connector->logger = new class { public array $entries = []; public function debug($message, $data) { $this->entries[] = $data; } };

runTests([
    'real WordPress HTTP request uses the shop URL and encodes query exactly once' => static function () use ($connector) {
        $result = $connector->wordpress_request('media', getdata: ['search' => 'shirt & red.png']);
        same(true, $result['status']);
        same('/store/wp-json/wp/v2/media', $result['response']['path']);
        same('shirt & red.png', $result['response']['search']);
    },
    'real WordPress POST returns media identity without warnings or body logs' => static function () use ($connector) {
        $result = $connector->wordpress_request('media', postdata: 'image-content', method: 'POST', content_disposition: 'attachment; filename="image.png"', content_type: 'image/png', debug: true);
        same(true, $result['status']); same(901, $result['response']['id']); same(13, $result['response']['bytes']);
        check(!isset($connector->logger->entries[0]['curl_postdata_500_bytes']));
        check(!isset($connector->logger->entries[0]['curl_getinfo']['request_header']));
    },
]);
