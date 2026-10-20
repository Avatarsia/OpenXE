<?php
declare(strict_types=1);
// Optional real-cron worker: run once a minute; full scans start every five minutes.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root=dirname(__DIR__);
require $root.'/xentral_autoloader.php';
require $root.'/vendor/autoload.php';
set_include_path(get_include_path().PATH_SEPARATOR.$root.'/conf');
try {
    $conf=new Config();
    $db=new \Xentral\Components\Database\Database(new \Xentral\Components\Database\Adapter\MysqliAdapter(
        new \Xentral\Components\Database\DatabaseConfig($conf->WFdbhost,$conf->WFdbuser,$conf->WFdbpass,$conf->WFdbname,'utf8mb4',$conf->WFdbport ?? 3306)),new \Xentral\Components\Database\SqlQuery\QueryFactory('mysql'));
    $config=new \Xentral\Modules\RepairIntegration\Service\RepairConfigService($db);
    $statuses=new \Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway($db);
    $coordinator=new \Xentral\Modules\RepairIntegration\Service\RepairReconcileService($db,$config,
        new \Xentral\Modules\RepairIntegration\Service\RepairStatusExchangeService($db,$config,$statuses),
        new \Xentral\Modules\RepairIntegration\Service\RepairImageExchangeService($db,$config),
        new \Xentral\Modules\RepairIntegration\Service\RepairExchangeTransport($config));
    $coordinator->runScheduled();
    $sync=new \Xentral\Modules\RepairIntegration\Service\RepairSyncService($db,
        new \Xentral\Modules\RepairIntegration\Gateway\RepairSyncQueueGateway($db),$statuses,
        new \Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway($db),$config);
    $processed=$sync->processQueue();
    echo json_encode(['enabled'=>$coordinator->enabled(),'status_jobs_processed'=>$processed,'scans'=>$coordinator->jobs()],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    error_log('Repair exchange CLI: '.get_class($e).': '.$e->getMessage());
    fwrite(STDERR,"Repair exchange worker failed. See PHP error log.\n");exit(1);
}
