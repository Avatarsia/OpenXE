<?php
// classes/Modules/RepairIntegration/install.php
// Run once to set up hooks, cronjobs, and permissions.
// Execute via: include from OpenXE admin or manually.

if (!isset($app) || !is_object($app)) {
    die('This script must be run within the OpenXE context.');
}

$db = $app->Container->get('Database');

// 1. Run database migration
$migration = new \Xentral\Modules\RepairIntegration\Migration\RepairIntegrationMigration($db);
if ($migration->needsInstall()) {
    $migration->install();
    echo "Database migration completed.\n";
} else {
    echo "Database already up to date (version: " . $migration->getCurrentVersion() . ").\n";
}

// 2. Register hooks
$hooks = [
    ['ticket_edit_after', 'repairintegration', 'HookTicketEditAfter'],
    ['ticket_list_after', 'repairintegration', 'HookTicketListAfter'],
];
foreach ($hooks as [$hookName, $module, $function]) {
    $existing = $db->fetchValue(
        "SELECT COUNT(*) FROM `hook_register` hr
         INNER JOIN `hook` h ON h.id = hr.hook
         WHERE h.name = :name AND hr.module = :module AND hr.function = :func",
        ['name' => $hookName, 'module' => $module, 'func' => $function]
    );
    if ((int)$existing === 0) {
        $app->erp->RegisterHook($hookName, $module, $function);
        echo "Hook registered: {$hookName} -> {$module}::{$function}\n";
    }
}

// 3. Register cronjobs in prozessstarter
$cronjobs = [
    ['repair_sync', 'cronjobs/repair_sync.php', 2, 'Repair WP Sync'],
    ['repair_reminders', 'cronjobs/repair_reminders.php', 1440, 'Repair Erinnerungsmails'],
    ['repair_retention', 'cronjobs/repair_retention.php', 1440, 'Repair DSGVO Retention'],
];
foreach ($cronjobs as [$parameter, $datei, $periode, $bezeichnung]) {
    $existing = $db->fetchValue(
        "SELECT COUNT(*) FROM `prozessstarter` WHERE `parameter` = :param",
        ['param' => $parameter]
    );
    if ((int)$existing === 0) {
        $db->perform(
            "INSERT INTO `prozessstarter` (`bezeichnung`, `art`, `parameter`, `periode`, `aktiv`, `mutex`)
             VALUES (:bez, 'cronjob', :param, :periode, 1, 0)",
            ['bez' => $bezeichnung, 'param' => $parameter, 'periode' => $periode]
        );
        echo "Cronjob registered: {$parameter} (every {$periode} min)\n";
    }
}

// 4. Register permissions
// Fuer alle vorhandenen Admin-User die vier Modul-Actions in userrights anlegen.
// Idempotent: bereits vorhandene Eintraege werden uebersprungen.
$permissionActions = ['list', 'einstellungen', 'merge', 'syncstatus'];
$adminUsers = $db->fetchAll("SELECT id FROM `user` WHERE `type` = 'admin'");
foreach ($adminUsers as $adminUser) {
    $userId = (int)$adminUser['id'];
    foreach ($permissionActions as $action) {
        $existing = $db->fetchValue(
            "SELECT id FROM `userrights`
             WHERE `module` = :module AND `action` = :action AND `user` = :user_id
             LIMIT 1",
            ['module' => 'repairintegration', 'action' => $action, 'user_id' => $userId]
        );
        if ($existing === null || $existing === false) {
            $db->perform(
                "INSERT INTO `userrights` (`module`, `action`, `permission`, `user`)
                 VALUES (:module, :action, 1, :user_id)",
                ['module' => 'repairintegration', 'action' => $action, 'user_id' => $userId]
            );
            echo "Permission registered: repairintegration::{$action} -> user {$userId}\n";
        }
    }
}

echo "\nRepairIntegration install complete.\n";
