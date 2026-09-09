<?php
// cronjobs/repair_sync.php — Sync-Queue an WordPress abarbeiten

$parameter = 'repair_sync';
$mutex = $app->DB->SelectArr("SELECT mutex FROM prozessstarter WHERE parameter = '{$parameter}'");
if (!empty($mutex[0]['mutex']) && $mutex[0]['mutex'] == 1) {
    return;
}
$app->DB->Update("UPDATE prozessstarter SET mutex = 1 WHERE parameter = '{$parameter}'");

// Schreibt in die `logfile`-Tabelle - $app->erp->LogFile() existiert in
// OpenXE nicht. Alle NOT-NULL-Spalten werden belegt, sonst schlaegt der
// INSERT unter STRICT_TRANS_TABLES fehl. Logfehler duerfen den Cronlauf
// nie abbrechen.
$logRepair = static function (string $message) use ($app, $parameter): void {
    try {
        $app->Container->get('Database')->perform(
            "INSERT INTO `logfile`
             (`meldung`, `dump`, `module`, `action`, `bearbeiter`, `funktionsname`, `datum`)
             VALUES (:msg, '', 'repair_integration', :action, '', '', NOW())",
            ['msg' => $message, 'action' => $parameter]
        );
    } catch (\Throwable $logError) {
        // bewusst geschluckt
    }
};

try {
    $syncService = $app->Container->get('RepairSyncService');
    try {
        $syncService->backfillAndQueueCurrentStatuses();
    } catch (\Throwable $e) {
        $logRepair('Backfill deferred: ' . $e->getMessage());
    }
    $processed = $syncService->processQueue();
    if ($processed > 0) {
        $logRepair("Processed {$processed} sync queue entries");
    }
} catch (\Throwable $e) {
    $logRepair('Error: ' . $e->getMessage());
} finally {
    $app->DB->Update("UPDATE prozessstarter SET mutex = 0, letzteausfuerhung = NOW() WHERE parameter = '{$parameter}'");
}
