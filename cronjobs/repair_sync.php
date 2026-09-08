<?php
// cronjobs/repair_sync.php — Sync-Queue an WordPress abarbeiten

$parameter = 'repair_sync';
$mutex = $app->DB->SelectArr("SELECT mutex FROM prozessstarter WHERE parameter = '{$parameter}'");
if (!empty($mutex[0]['mutex']) && $mutex[0]['mutex'] == 1) {
    return;
}
$app->DB->Update("UPDATE prozessstarter SET mutex = 1 WHERE parameter = '{$parameter}'");

try {
    $syncService = $app->Container->get('RepairSyncService');
    $processed = $syncService->processQueue();
    if ($processed > 0) {
        $app->erp->LogFile('repair_sync', "Processed {$processed} sync queue entries");
    }
} catch (Exception $e) {
    $app->erp->LogFile('repair_sync', 'Error: ' . $e->getMessage());
} finally {
    $app->DB->Update("UPDATE prozessstarter SET mutex = 0, letzteausfuerhung = NOW() WHERE parameter = '{$parameter}'");
}
