<?php
// www/pages/ticket_custom.php
// Extends the core Ticket page with RepairIntegration features.
// OpenXE's loadModule() prefers *_custom.php over *.php automatically.

class TicketCustom extends Ticket
{
    function __construct($app, $intern = false)
    {
        parent::__construct($app, $intern);
    }

    function ticket_menu($id)
    {
        // Check if this is a repair ticket — if so, link back to repair list
        try {
            $detailsGateway = $this->app->Container->get('RepairDetailsGateway');
            $details = $detailsGateway->getByTicketId((int)$id);
            if ($details !== null) {
                $this->app->erp->MenuEintrag("index.php?module=repairintegration&action=list", "Zur&uuml;ck zur Reparatur-&Uuml;bersicht");
                $this->app->erp->MenuEintrag("index.php?module=ticket&action=edit&id=$id", "Details");
                $this->app->erp->MenuEintrag("index.php?module=ticket&action=dateien&id=$id", "Dateien");
                $this->app->erp->MenuEintrag("index.php?module=ticket&action=protokoll&id=$id", "Protokoll");
                return;
            }
        } catch (\Exception $e) {
            // Module not available
        }
        parent::ticket_menu($id);
    }

    function ticket_edit()
    {
        $ticketId = (int)$this->app->Secure->GetGET('id');
        $oldStatus = '';
        if ($ticketId > 0) {
            $row = $this->app->DB->SelectRow(
                "SELECT status FROM ticket WHERE id = '" . (int)$ticketId . "' LIMIT 1"
            );
            $oldStatus = $row['status'] ?? '';
        }

        // --- Handle Beleg creation BEFORE parent (so redirect works) ---
        try {
            $submitValue = $this->app->Secure->GetPOST('submit');
            if ($ticketId > 0 && in_array($submitValue, ['repair_angebot', 'repair_auftrag', 'repair_rechnung'], true)) {
                $belegTyp = str_replace('repair_', '', $submitValue);
                $this->handleRepairAction($ticketId, $belegTyp);
            }
        } catch (\Exception $e) {
            $this->app->Tpl->Set('MESSAGE', '<div class="error">' . htmlspecialchars($e->getMessage()) . '</div>');
        }

        // --- Save diagnosis fields BEFORE parent (so they persist) ---
        try {
            $submitValue = $this->app->Secure->GetPOST('submit');
            if ($ticketId > 0 && $submitValue === 'speichern') {
                $detailsGw = $this->app->Container->get('RepairDetailsGateway');
                $existingDetails = $detailsGw->getByTicketId($ticketId);
                if ($existingDetails !== null) {
                    $detailsGw->updateDiagnosisFields(
                        $ticketId,
                        $this->app->Secure->GetPOST('repair_diagnosis_result') ?: null,
                        $this->app->Secure->GetPOST('repair_quote_amount') ?: null,
                        $this->app->Secure->GetPOST('repair_actual_cost') ?: null,
                        $this->app->Secure->GetPOST('repair_notes') ?: null,
                    );
                }
            }
        } catch (\Exception $e) {
            // Silently skip
        }

        // --- Set repair template placeholders BEFORE parent (parent calls Parse) ---
        try {
            if ($ticketId > 0) {
                $detailsGateway = $this->app->Container->get('RepairDetailsGateway');
                $details = $detailsGateway->getByTicketId($ticketId);

                if ($details !== null) {
                    $repairHtml = $this->renderRepairPanel($ticketId, $details);
                    $this->app->Tpl->Set('REPAIR_PANEL', $repairHtml);

                    $belegButtonsHtml = $this->renderBelegButtons($ticketId);
                    $this->app->Tpl->Set('REPAIR_BELEG_BUTTONS', $belegButtonsHtml);
                }
            }
        } catch (\Exception $e) {
            // Module not available
        }

        // --- Call parent (handles ticket form, status change, email, PARSES TEMPLATE) ---
        parent::ticket_edit();

        // --- Post-parent: status change hook ---
        try {
            if ($ticketId > 0 && $oldStatus !== '' && $this->app->Secure->GetPOST('status') !== '') {
                $detailsGateway = $this->app->Container->get('RepairDetailsGateway');
                if ($detailsGateway->getByTicketId($ticketId) !== null) {
                    $hook = new \Xentral\Modules\RepairIntegration\Hook\TicketStatusChangeHook(
                        $this->app->Container->get('Database'),
                        $this->app->Container->get('RepairSyncService'),
                        $this->app->Container->get('RepairEmailService'),
                    );
                    $hook->onTicketEditAfter($ticketId, $oldStatus);
                }
            }
        } catch (\Exception $e) {
            // RepairIntegration not installed — silently skip
        }
    }

    /**
     * Handle repair-specific POST actions (Beleg creation)
     */
    private function handleRepairAction(int $ticketId, string $action): void
    {
        $validTypes = ['angebot', 'auftrag', 'rechnung'];
        if (!in_array($action, $validTypes, true)) {
            return;
        }

        $ticket = $this->app->DB->SelectRow(
            "SELECT id, schluessel, adresse FROM ticket WHERE id = '" . (int)$ticketId . "' LIMIT 1"
        );
        if (!$ticket) {
            throw new \RuntimeException('Ticket nicht gefunden');
        }

        $adresseId = (int)$ticket['adresse'];
        if ($adresseId === 0) {
            throw new \RuntimeException('Ticket hat keine verknuepfte Adresse. Bitte zuerst eine Adresse zuweisen.');
        }

        // Create the Beleg using OpenXE core methods
        switch ($action) {
            case 'angebot':
                $belegId = $this->app->erp->CreateAngebot($adresseId);
                $this->app->erp->LoadAngebotStandardwerte($belegId, $adresseId);
                break;
            case 'auftrag':
                $belegId = $this->app->erp->CreateAuftrag($adresseId);
                $this->app->erp->LoadAuftragStandardwerte($belegId, $adresseId);
                break;
            case 'rechnung':
                $belegId = $this->app->erp->CreateRechnung($adresseId);
                $this->app->erp->LoadRechnungStandardwerte($belegId, $adresseId);
                break;
            default:
                return;
        }

        if (empty($belegId)) {
            throw new \RuntimeException(ucfirst($action) . ' konnte nicht erstellt werden.');
        }

        // Set Betreff from repair details
        $detailsGateway = $this->app->Container->get('RepairDetailsGateway');
        $details = $detailsGateway->getByTicketId($ticketId);
        if ($details) {
            $betreff = sprintf(
                '%s: %s %s (Ticket #%s)',
                ucfirst($details['service_type'] ?? ''),
                $details['manufacturer'] ?? '',
                $details['model'] ?? '',
                $ticket['schluessel']
            );
            $this->app->DB->Update(
                "UPDATE " . $action . " SET freitext = '" . $this->app->DB->real_escape_string($betreff) . "' WHERE id = '" . (int)$belegId . "'"
            );
        }

        // Get Belegnummer
        $belegNr = $this->app->DB->Select(
            "SELECT belegnr FROM " . $action . " WHERE id = '" . (int)$belegId . "'"
        );

        // Link in repair_ticket_beleg
        $belegService = $this->app->Container->get('RepairBelegService');
        $belegService->linkBelegToTicket(
            $ticketId,
            $ticket['schluessel'],
            $action,
            (int)$belegId,
            $belegNr ?: null,
            $this->app->User->GetName(),
        );

        // Bidirectional protocol entries
        $this->app->erp->TicketProtokoll(
            $ticketId,
            ucfirst($action) . ' <a href="index.php?module=' . $action . '&action=edit&id=' . (int)$belegId . '">#' . htmlspecialchars($belegNr ?: (string)$belegId) . '</a> erstellt'
        );

        $protokollMethode = ucfirst($action) . 'Protokoll';
        if (method_exists($this->app->erp, $protokollMethode)) {
            $this->app->erp->$protokollMethode(
                $belegId,
                'Erstellt aus Ticket <a href="index.php?module=ticket&action=edit&id=' . $ticketId . '">#' . htmlspecialchars($ticket['schluessel']) . '</a>'
            );
        }

        // Redirect to the Beleg edit page (normal OpenXE workflow)
        header("Location: index.php?module={$action}&action=edit&id={$belegId}");
        exit;
    }

    /**
     * Render repair detail panel HTML (inside the form)
     */
    private function renderRepairPanel(int $ticketId, array $details): string
    {
        $html = '<div style="margin-top:15px; padding:10px; border:1px solid #ddd; background:#f9f9f9; border-radius:4px;">';
        $html .= '<h3 style="margin-top:0;">Reparatur-Details</h3>';

        // Info table
        $html .= '<table class="mkTableFormular" style="width:100%">';
        $html .= '<tr><td width="150"><strong>Service-Typ:</strong></td><td>' . htmlspecialchars(ucfirst($details['service_type'] ?? '')) . '</td>';
        $html .= '<td width="150"><strong>Hersteller:</strong></td><td>' . htmlspecialchars($details['manufacturer'] ?? '') . '</td></tr>';
        $html .= '<tr><td><strong>Modell:</strong></td><td>' . htmlspecialchars($details['model'] ?? '') . '</td>';
        $html .= '<td><strong>Seriennummer:</strong></td><td>' . htmlspecialchars($details['serial_number'] ?? '') . '</td></tr>';
        $html .= '<tr><td><strong>Fehlerkategorie:</strong></td><td>' . htmlspecialchars($details['issue_category'] ?? '') . '</td>';
        $html .= '<td><strong>Garantie:</strong></td><td>' . htmlspecialchars($details['warranty_status'] ?? '') . '</td></tr>';
        $html .= '<tr><td><strong>Kostenrahmen:</strong></td><td>' . htmlspecialchars($details['cost_limit'] ?? '') . '</td>';
        $html .= '<td><strong>Express:</strong></td><td>' . ($details['is_express'] ? '<span style="color:#FFB800;font-size:16px">&#9733;</span> Ja' : 'Nein') . '</td></tr>';
        if (!empty($details['issue_description'])) {
            $html .= '<tr><td colspan="4"><strong>Beschreibung:</strong><br>' . nl2br(htmlspecialchars($details['issue_description'])) . '</td></tr>';
        }
        $html .= '</table>';

        // Diagnosis fields (editable, saved on "Speichern")
        $html .= '<h3 style="margin-top:15px;">Diagnose &amp; Kosten (intern)</h3>';
        $html .= '<table class="mkTableFormular" style="width:100%">';
        $html .= '<tr><td width="150">Diagnose-Ergebnis:</td><td><textarea name="repair_diagnosis_result" rows="3" style="width:100%">' . htmlspecialchars($details['diagnosis_result'] ?? '') . '</textarea></td></tr>';
        $html .= '<tr><td>KV-Betrag (EUR):</td><td><input type="text" name="repair_quote_amount" value="' . htmlspecialchars($details['quote_amount'] ?? '') . '" size="10"></td></tr>';
        $html .= '<tr><td>Tats. Kosten (EUR):</td><td><input type="text" name="repair_actual_cost" value="' . htmlspecialchars($details['actual_cost'] ?? '') . '" size="10"></td></tr>';
        $html .= '<tr><td>Reparatur-Notizen:</td><td><textarea name="repair_notes" rows="3" style="width:100%">' . htmlspecialchars($details['repair_notes'] ?? '') . '</textarea></td></tr>';
        $html .= '</table>';

        // Linked Belege
        $belegGateway = $this->app->Container->get('RepairBelegGateway');
        $belege = $belegGateway->getByTicketId($ticketId);
        if (!empty($belege)) {
            $html .= '<h3 style="margin-top:15px;">Verknuepfte Belege</h3>';
            $html .= '<table class="mkTable" style="width:100%">';
            $html .= '<thead><tr><th>Typ</th><th>Nr.</th><th>Datum</th></tr></thead><tbody>';
            foreach ($belege as $beleg) {
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars(ucfirst($beleg['beleg_typ'])) . '</td>';
                $html .= '<td><a href="index.php?module=' . htmlspecialchars($beleg['beleg_typ']) . '&action=edit&id=' . (int)$beleg['beleg_id'] . '">#' . htmlspecialchars($beleg['beleg_nr'] ?? (string)$beleg['beleg_id']) . '</a></td>';
                $html .= '<td>' . htmlspecialchars($beleg['created_at'] ?? '') . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render Beleg creation buttons for the action column
     */
    private function renderBelegButtons(int $ticketId): string
    {
        $html = '';
        $html .= '<td><button name="submit" value="repair_angebot" class="ui-button-icon" style="width:100%;">Angebot erstellen</button></td></tr>';
        $html .= '<td><button name="submit" value="repair_auftrag" class="ui-button-icon" style="width:100%;">Auftrag erstellen</button></td></tr>';
        $html .= '<td><button name="submit" value="repair_rechnung" class="ui-button-icon" style="width:100%;">Rechnung erstellen</button></td></tr>';
        return $html;
    }
}
