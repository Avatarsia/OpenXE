<?php
/**
 * RepairIntegration API Entry-Point
 *
 * Eigenstaendiger Entry-Point der NICHT ueber www/index.php laeuft,
 * damit keine Session/Login-Pruefung stattfindet.
 *
 * URL: /repairapi/index.php?action=push_details
 */

ini_set('display_errors', '0');
error_reporting(E_ERROR);

// Autoloader
require dirname(dirname(__DIR__)) . '/xentral_autoloader.php';
require dirname(dirname(__DIR__)) . '/vendor/autoload.php';

// Config laden (gleicher Weg wie www/api/bootstrap.php)
if (!class_exists('Config', true)) {
    include dirname(dirname(__DIR__)) . '/conf/main.conf.php';
}

// DB-Verbindung via Config-Objekt
$conf = new Config();
$dbConfig = new \Xentral\Components\Database\DatabaseConfig(
    $conf->WFdbhost,
    $conf->WFdbuser,
    $conf->WFdbpass,
    $conf->WFdbname
);
$adapter = new \Xentral\Components\Database\Adapter\MysqliAdapter($dbConfig);
$queryFactory = new \Xentral\Components\Database\SqlQuery\QueryFactory('mysql');
$db = new \Xentral\Components\Database\Database($adapter, $queryFactory);

// Services instanziieren
$configService = new \Xentral\Modules\RepairIntegration\Service\RepairConfigService($db);
$auth = new \Xentral\Modules\RepairIntegration\Api\RepairApiAuth();
$detailsGateway = new \Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway($db);
$statusConfigGateway = new \Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway($db);
$controller = new \Xentral\Modules\RepairIntegration\Api\RepairApiController(
    $db,
    $auth,
    $configService,
    $detailsGateway,
    $statusConfigGateway
);

// Route — support both ?action=push_details and /repair-status (WP plugin format)
$action = $_GET['action'] ?? '';

// Parse path-based routing: /repairapi/index.php/repair-status
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($action === '' && $pathInfo !== '') {
    $action = match (trim($pathInfo, '/')) {
        'repair-status' => 'push_details',
        default => '',
    };
}

switch ($action) {
    case 'push_details':
        $controller->handlePushDetails();
        break;
    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'UNKNOWN_ACTION']);
        break;
}
