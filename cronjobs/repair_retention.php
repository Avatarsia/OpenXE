<?php
// cronjobs/repair_retention.php — DSGVO-Anonymisierung nach Aufbewahrungsfrist

$parameter = 'repair_retention';
$mutex = $app->DB->SelectArr("SELECT mutex FROM prozessstarter WHERE parameter = '{$parameter}'");
if (!empty($mutex[0]['mutex']) && $mutex[0]['mutex'] == 1) {
    return;
}
$app->DB->Update("UPDATE prozessstarter SET mutex = 1 WHERE parameter = '{$parameter}'");

try {
    $db = $app->Container->get('Database');
    $configService = $app->Container->get('RepairConfigService');
    $detailsGateway = $app->Container->get('RepairDetailsGateway');

    $anonymizeAfterYears = $configService->getRetentionAnonymizeYears();
    $cutoffDate = date('Y-m-d', strtotime("-{$anonymizeAfterYears} years"));

    $expired = $detailsGateway->getUnanonymizedExpired($cutoffDate, 100);

    foreach ($expired as $ticket) {
        $db->beginTransaction();
        try {
            // Anonymize personal data in ticket
            $db->perform(
                "UPDATE `ticket` SET
                    `kunde` = 'anonymisiert',
                    `mailadresse` = '',
                    `adresse` = 0,
                    `notiz` = ''
                 WHERE `id` = :id",
                ['id' => $ticket['ticket_id']]
            );

            // Anonymize messages
            $db->perform(
                "UPDATE `ticket_nachricht` SET
                    `verfasser` = 'anonymisiert',
                    `mail` = '',
                    `text` = '[Anonymisiert nach Aufbewahrungsfrist]',
                    `textausgang` = '[Anonymisiert nach Aufbewahrungsfrist]',
                    `verfasser_replyto` = '',
                    `mail_replyto` = '',
                    `mail_cc` = ''
                 WHERE `ticket` = :key",
                ['key' => $ticket['ticket_schluessel']]
            );

            // Mark repair details as anonymized (keeps service data)
            $detailsGateway->markAnonymized($ticket['id']);

            // Protocol
            $app->erp->TicketProtokoll(
                $ticket['ticket_id'],
                'Personendaten anonymisiert (Aufbewahrungsfrist abgelaufen)'
            );

            $db->commit();
            $app->erp->LogFile('repair_retention', "Anonymized Ticket #{$ticket['ticket_schluessel']}");
        } catch (Exception $e) {
            $db->rollBack();
            $app->erp->LogFile('repair_retention', "Error anonymizing #{$ticket['ticket_schluessel']}: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    $app->erp->LogFile('repair_retention', 'Error: ' . $e->getMessage());
} finally {
    $app->DB->Update("UPDATE prozessstarter SET mutex = 0, letzteausfuehrung = NOW() WHERE parameter = '{$parameter}'");
}
