<?php
// cronjobs/repair_retention.php — DSGVO-Anonymisierung nach Aufbewahrungsfrist

$parameter = 'repair_retention';
$mutex = $app->DB->SelectArr("SELECT mutex FROM prozessstarter WHERE parameter = '{$parameter}'");
if (!empty($mutex[0]['mutex']) && $mutex[0]['mutex'] == 1) {
    return;
}
$app->DB->Update("UPDATE prozessstarter SET mutex = 1 WHERE parameter = '{$parameter}'");

// Schreibt in die `logfile`-Tabelle - dieselbe Ablage wie das in OpenXE nicht
// vorhandene $app->erp->LogFile() (Muster: RepairSyncService::logWarning()).
// Alle NOT-NULL-Spalten werden belegt, sonst schlaegt der INSERT unter
// STRICT_TRANS_TABLES fehl. Logfehler duerfen den Cronlauf nie abbrechen.
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
            $logRepair("Anonymized Ticket #{$ticket['ticket_schluessel']}");
        } catch (\Throwable $e) {
            $db->rollBack();
            $logRepair("Error anonymizing #{$ticket['ticket_schluessel']}: " . $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    $logRepair('Error: ' . $e->getMessage());
} finally {
    $app->DB->Update("UPDATE prozessstarter SET mutex = 0, letzteausfuerhung = NOW() WHERE parameter = '{$parameter}'");
}
