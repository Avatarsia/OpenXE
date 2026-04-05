<?php
// cronjobs/repair_reminders.php — Erinnerungsmails fuer Tickets im Status "neu"

$parameter = 'repair_reminders';
$mutex = $app->DB->SelectArr("SELECT mutex FROM prozessstarter WHERE parameter = '{$parameter}'");
if (!empty($mutex[0]['mutex']) && $mutex[0]['mutex'] == 1) {
    return;
}
$app->DB->Update("UPDATE prozessstarter SET mutex = 1 WHERE parameter = '{$parameter}'");

try {
    $db = $app->Container->get('Database');
    $configService = $app->Container->get('RepairConfigService');

    // Find repair tickets in status 'neu' older than 21 days
    // that haven't received a reminder yet
    $cutoffDate = date('Y-m-d H:i:s', strtotime('-21 days'));
    $tickets = $db->fetchAll(
        "SELECT t.id, t.schluessel, t.mailadresse, t.kunde
         FROM `ticket` t
         INNER JOIN `ticket_repair_details` rd ON rd.ticket_id = t.id
         WHERE t.status = 'neu'
           AND t.zeit < :cutoff
           AND rd.service_delivery_type = 'einsendung'
           AND NOT EXISTS (
               SELECT 1 FROM `ticket_protokoll` tp
               WHERE tp.ticket = t.id AND tp.grund LIKE '%Erinnerung gesendet%'
           )",
        ['cutoff' => $cutoffDate]
    );

    foreach ($tickets as $ticket) {
        $app->erp->TicketProtokoll($ticket['id'], 'Erinnerung gesendet (21 Tage ohne Geraeteingang)');
        $app->erp->LogFile('repair_reminders', "Reminder for Ticket #{$ticket['schluessel']}");
    }
} catch (Exception $e) {
    $app->erp->LogFile('repair_reminders', 'Error: ' . $e->getMessage());
} finally {
    $app->DB->Update("UPDATE prozessstarter SET mutex = 0, letzteausfuehrung = NOW() WHERE parameter = '{$parameter}'");
}
