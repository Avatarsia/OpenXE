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

    function ticket_edit()
    {
        // Store old status before parent processes the form
        $ticketId = (int)$this->app->Secure->GetGET('id');
        $oldStatus = '';
        if ($ticketId > 0) {
            $row = $this->app->DB->SelectRow(
                "SELECT status FROM ticket WHERE id = '" . (int)$ticketId . "' LIMIT 1"
            );
            $oldStatus = $row['status'] ?? '';
        }

        // Call parent (handles form submission, status change, email sending)
        parent::ticket_edit();

        if ($ticketId <= 0) {
            return;
        }

        // Save repair diagnosis fields if form was submitted
        try {
            if ($this->app->Secure->GetPOST('submit') !== '') {
                $detailsGw = $this->app->Container->get('RepairDetailsGateway');
                $existingDetails = $detailsGw->getByTicketId($ticketId);
                if ($existingDetails !== null) {
                    $diagnosisResult = $this->app->Secure->GetPOST('repair_diagnosis_result');
                    $quoteAmount = $this->app->Secure->GetPOST('repair_quote_amount');
                    $actualCost = $this->app->Secure->GetPOST('repair_actual_cost');
                    $repairNotes = $this->app->Secure->GetPOST('repair_notes');

                    // Only save if at least one field has content
                    if ($diagnosisResult !== '' || $quoteAmount !== '' || $actualCost !== '' || $repairNotes !== '') {
                        $detailsGw->updateDiagnosisFields(
                            $ticketId,
                            $diagnosisResult !== '' ? $diagnosisResult : null,
                            $quoteAmount !== '' ? $quoteAmount : null,
                            $actualCost !== '' ? $actualCost : null,
                            $repairNotes !== '' ? $repairNotes : null,
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently skip if module not available
        }

        // Extend status dropdown with repair-specific statuses
        try {
            $statusService = $this->app->Container->get('RepairStatusService');
            $serviceType = $statusService->getServiceTypeForTicket($ticketId);

            if ($serviceType !== null) {
                $category = $serviceType->statusCategory();
                $ticket = $this->app->DB->SelectRow(
                    "SELECT status FROM ticket WHERE id = '" . (int)$ticketId . "' LIMIT 1"
                );
                $currentStatus = $ticket['status'] ?? 'neu';
                $statusHtml = $statusService->renderStatusDropdown($currentStatus, $category);
                $this->app->Tpl->Set('REPAIRSTATUSDROPDOWN', $statusHtml);
            }

            // Load repair details for tab
            $detailsGateway = $this->app->Container->get('RepairDetailsGateway');
            $details = $detailsGateway->getByTicketId($ticketId);
            if ($details !== null) {
                $this->app->Tpl->Set('REPAIR_TAB_DISPLAY', 'block');
                $this->app->Tpl->Set('REPAIR_SERVICE_TYPE', htmlspecialchars(ucfirst($details['service_type'] ?? '')));
                $this->app->Tpl->Set('REPAIR_MANUFACTURER', htmlspecialchars($details['manufacturer'] ?? ''));
                $this->app->Tpl->Set('REPAIR_MODEL', htmlspecialchars($details['model'] ?? ''));
                $this->app->Tpl->Set('REPAIR_SERIAL', htmlspecialchars($details['serial_number'] ?? ''));
                $this->app->Tpl->Set('REPAIR_ISSUE_CAT', htmlspecialchars($details['issue_category'] ?? ''));
                $this->app->Tpl->Set('REPAIR_ISSUE_DESC', htmlspecialchars($details['issue_description'] ?? ''));
                $this->app->Tpl->Set('REPAIR_WARRANTY', htmlspecialchars($details['warranty_status'] ?? ''));
                $this->app->Tpl->Set('REPAIR_COST_LIMIT', htmlspecialchars($details['cost_limit'] ?? ''));
                $this->app->Tpl->Set('REPAIR_EXPRESS', $details['is_express'] ? 'Ja' : 'Nein');
                $this->app->Tpl->Set('REPAIR_DIAGNOSIS', htmlspecialchars($details['diagnosis_result'] ?? ''));
                $this->app->Tpl->Set('REPAIR_QUOTE', htmlspecialchars($details['quote_amount'] ?? ''));
                $this->app->Tpl->Set('REPAIR_ACTUAL_COST', htmlspecialchars($details['actual_cost'] ?? ''));
                $this->app->Tpl->Set('REPAIR_NOTES', htmlspecialchars($details['repair_notes'] ?? ''));
            } else {
                $this->app->Tpl->Set('REPAIR_TAB_DISPLAY', 'none');
            }

            // Load beleg links
            $belegGateway = $this->app->Container->get('RepairBelegGateway');
            $belege = $belegGateway->getByTicketId($ticketId);
            if (!empty($belege)) {
                $belegHtml = '';
                foreach ($belege as $beleg) {
                    $belegHtml .= '<tr>';
                    $belegHtml .= '<td>' . htmlspecialchars(ucfirst($beleg['beleg_typ'])) . '</td>';
                    $belegHtml .= '<td><a href="index.php?module=' . htmlspecialchars($beleg['beleg_typ'], ENT_QUOTES, 'UTF-8') . '&action=edit&id=' . (int)$beleg['beleg_id'] . '">' . htmlspecialchars($beleg['beleg_nr'] ?? '-') . '</a></td>';
                    $belegHtml .= '<td>' . htmlspecialchars($beleg['created_at'] ?? '') . '</td>';
                    $belegHtml .= '</tr>';
                }
                $this->app->Tpl->Set('REPAIR_BELEGE_ROWS', $belegHtml);
            }

            // Trigger status change hook
            if ($oldStatus !== '' && $this->app->Secure->GetPOST('status') !== '') {
                $hook = new \Xentral\Modules\RepairIntegration\Hook\TicketStatusChangeHook(
                    $this->app->Container->get('Database'),
                    $this->app->Container->get('RepairSyncService'),
                    $this->app->Container->get('RepairEmailService'),
                );
                $hook->onTicketEditAfter($ticketId, $oldStatus);
            }
        } catch (\Exception $e) {
            // RepairIntegration not installed or not configured -- silently skip
        }
    }
}
