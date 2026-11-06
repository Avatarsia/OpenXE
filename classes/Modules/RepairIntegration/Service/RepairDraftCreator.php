<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Modules\RepairIntegration\Exception\RepairIntegrationException;

/** Keeps document, position and ticket link on the core ERP's DB connection. */
final class RepairDraftCreator
{
    public static function create($app, array $prepared, string $createdBy): int
    {
        $type = $prepared['beleg_typ'];
        if (!in_array($type, ['angebot', 'auftrag', 'rechnung'], true)) {
            throw new RepairIntegrationException('Unbekannte Belegart.');
        }
        $db = $app->DB;
        self::query($db, 'START TRANSACTION');
        try {
            $create = 'Create' . ucfirst($type);
            $load = 'Load' . ucfirst($type) . 'Standardwerte';
            $id = (int)$app->erp->$create((int)$prepared['adresse_id']);
            if ($id <= 0) {
                throw new RepairIntegrationException('Beleg konnte nicht angelegt werden.');
            }
            $app->erp->$load($id, (int)$prepared['adresse_id']);
            $document = $db->SelectRow("SELECT id, waehrung FROM `$type` WHERE id = $id LIMIT 1");
            if ((int)($document['id'] ?? 0) !== $id) {
                throw new RepairIntegrationException('Beleg konnte nicht gelesen werden.');
            }
            $currency = trim((string)($document['waehrung'] ?? ''));
            if ($currency === '') {
                $currency = (string)$app->erp->Firmendaten('waehrung') ?: 'EUR';
            }
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new RepairIntegrationException('Ungueltige Belegwaehrung.');
            }
            $position = $prepared['position'];
            if (($position['gross_amount'] !== null || $position['net_amount'] !== null) && $currency !== 'EUR') {
                throw new RepairIntegrationException('Formular-/Positionspreis ist in EUR. Fuer Fremdwaehrung zuerst den Artikelpreis im Beleg verwenden.');
            }
            $subject = $db->real_escape_string((string)$prepared['betreff']);
            self::query($db, "UPDATE `$type` SET betreff = '$subject' WHERE id = $id LIMIT 1");
            $description = RepairPositionTemplate::toDocumentHtml($position['description']);
            if (strlen($description) > 60000) {
                throw new RepairIntegrationException('Produkttext ist zu lang fuer die Belegposition.');
            }
            // AddPositionManuell interpolates these two arguments into SQL.
            $positionId = (int)$app->erp->AddPositionManuell(
                $type, $id, (int)$position['article_id'], 1,
                $db->real_escape_string($position['name']), $db->real_escape_string($description), $currency
            );
            $saved = $positionId > 0 ? $db->SelectRow(
                "SELECT id, artikel, `$type` AS beleg_id, preis FROM `{$type}_position` WHERE id = $positionId LIMIT 1"
            ) : [];
            if ((int)($saved['artikel'] ?? 0) !== (int)$position['article_id'] || (int)($saved['beleg_id'] ?? 0) !== $id) {
                throw new RepairIntegrationException('Reparaturposition konnte nicht angelegt werden.');
            }
            $net = $position['net_amount'];
            if ($position['gross_amount'] !== null) {
                $taxMethod = ucfirst($type) . 'MitUmsatzeuer';
                $rate = $app->erp->$taxMethod($id) ? $app->erp->GetSteuersatzBelegpos($type, $id, $positionId) : 0;
                if (!is_numeric($rate)) {
                    throw new RepairIntegrationException('ERP-Steuersatz konnte nicht ermittelt werden.');
                }
                $net = RepairPositionTemplate::grossToNet($position['gross_amount'], (float)$rate);
            }
            if ($net !== null) {
                if (!preg_match('/^\d+(?:\.\d{1,6})?$/', $net)) {
                    throw new RepairIntegrationException('Ungueltiger Positionspreis.');
                }
                self::query($db, "UPDATE `{$type}_position` SET preis = '$net' WHERE id = $positionId AND `$type` = $id LIMIT 1");
                $saved = $db->SelectRow("SELECT preis FROM `{$type}_position` WHERE id = $positionId LIMIT 1");
                if (!isset($saved['preis']) || abs((float)$saved['preis'] - (float)$net) > 0.0001) {
                    throw new RepairIntegrationException('Positionspreis konnte nicht gespeichert werden.');
                }
            }
            if ($position['gross_amount'] !== null) {
                // The agreed diagnosis fee must not inherit customer discounts.
                self::query($db, "UPDATE `{$type}_position`
                    SET keinrabatterlaubt = 1, rabattsync = 1, rabatt = 0, grundrabatt = 0,
                        rabatt1 = 0, rabatt2 = 0, rabatt3 = 0, rabatt4 = 0, rabatt5 = 0
                    WHERE id = $positionId AND `$type` = $id LIMIT 1");
            }
            $recalculate = ucfirst($type) . 'Neuberechnen';
            $app->erp->$recalculate($id);
            $ticketId = (int)$prepared['ticket_id'];
            $key = $db->real_escape_string((string)$prepared['ticket_schluessel']);
            $user = $db->real_escape_string($createdBy);
            // Modern Database uses another connection, so this INSERT must use
            // the legacy connection to roll back together with the core draft.
            self::query($db, "INSERT INTO repair_ticket_beleg
                (ticket_id, ticket_schluessel, beleg_typ, beleg_id, beleg_nr, erstellt_aus_ticket, created_by)
                VALUES ($ticketId, '$key', '$type', $id, NULL, 1, '$user')");
            self::query($db, 'COMMIT');
            return $id;
        } catch (\Throwable $e) {
            $db->Query('ROLLBACK');
            throw $e;
        }
    }

    private static function query($db, string $sql): void
    {
        if ($db->Query($sql) !== true) {
            throw new RepairIntegrationException('Beleg konnte nicht vollstaendig gespeichert werden. Bitte erneut versuchen.');
        }
    }
}
