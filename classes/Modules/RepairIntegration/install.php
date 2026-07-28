<?php
// classes/Modules/RepairIntegration/install.php
// Run once to set up cronjobs and permissions.
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
} elseif ($migration->needsUpgrade()) {
    $fromVersion = (string)$migration->getCurrentVersion();
    $migration->upgrade();
    echo "Database upgraded: {$fromVersion} -> " . $migration->getTargetVersion() . ".\n";
} else {
    echo "Database already up to date (version: " . $migration->getCurrentVersion() . ").\n";
}

// 2. Tote Hook-Registrierungen entfernen (self-healing)
// Fruehere Versionen registrierten ticket_edit_after/ticket_list_after auf
// Methoden der Page-Klasse, die es nie gab und die kein Core-Code feuert.
// Der echte Sync laeuft ueber die Direkt-Instanziierung in
// www/pages/ticket_custom.php. Idempotent: auf frischen Installationen,
// auf denen nie Zeilen angelegt wurden, ein No-Op.
$db->perform(
    "DELETE hr FROM `hook_register` hr
     INNER JOIN `hook` h ON h.id = hr.hook
     WHERE h.name IN ('ticket_edit_after', 'ticket_list_after')
       AND hr.module = 'repairintegration'"
);

// 3. Register cronjobs in prozessstarter
$cronjobs = [
    ['repair_sync', 'cronjobs/repair_sync.php', 1440, 'Repair WP Sync'],
    ['repair_reminders', 'cronjobs/repair_reminders.php', 1440, 'Repair Erinnerungsmails'],
    ['repair_retention', 'cronjobs/repair_retention.php', 1440, 'Repair DSGVO Retention'],
];
foreach ($cronjobs as [$parameter, $datei, $periode, $bezeichnung]) {
    $existing = $db->fetchValue(
        "SELECT COUNT(*) FROM `prozessstarter` WHERE `parameter` = :param",
        ['param' => $parameter]
    );
    if ((int)$existing === 0) {
        // Alle NOT-NULL-Spalten ohne Default explizit setzen, sonst schlaegt
        // der INSERT unter STRICT_TRANS_TABLES fehl. Spaltenbelegung wie bei
        // den Core-Eintraegen: art = 'periodisch', typ = 'cronjob' (der
        // Runner in cronjobs/starter.php filtert auf typ = 'cronjob').
        $db->perform(
            "INSERT INTO `prozessstarter`
             (`bezeichnung`, `bedingung`, `art`, `startzeit`, `letzteausfuerhung`,
              `periode`, `typ`, `parameter`, `aktiv`, `mutex`, `mutexcounter`,
              `firma`, `art_filter`, `status`)
             VALUES (:bez, '', 'periodisch', NOW(), NOW(), :periode, 'cronjob',
                     :param, 1, 0, 0, 1, '', '')",
            ['bez' => $bezeichnung, 'param' => $parameter, 'periode' => $periode]
        );
        echo "Cronjob registered: {$parameter} (every {$periode} min)\n";
    }
}

// Self-healing fuer Bestandsinstallationen (idempotent):
// - repair_sync soll nur einmal taeglich laufen (periode 1440 statt 2)
// - durch den frueheren Spalten-Typo im finally-UPDATE der Cronjobs
//   (letzteausfuehrung statt letzteausfuerhung) blieb mutex ggf. auf 1
//   haengen und der Job lief nie wieder
// - alte INSERTs ohne typ/art wurden vom Runner (typ = 'cronjob') nie
//   gefunden
$db->perform(
    "UPDATE `prozessstarter`
     SET `art` = 'periodisch', `typ` = 'cronjob', `mutex` = 0
     WHERE `parameter` IN ('repair_sync', 'repair_reminders', 'repair_retention')"
);
$db->perform(
    "UPDATE `prozessstarter` SET `periode` = '1440' WHERE `parameter` = 'repair_sync'"
);

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
