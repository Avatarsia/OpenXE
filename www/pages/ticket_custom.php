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

    function ticket_protokoll()
    {
        $id = $this->app->Secure->GetGET('id');
        $this->ticket_menu($id);

        // Custom rendering that preserves HTML links in protocol entries
        $rows = $this->app->DB->SelectArr(
            "SELECT zeit, bearbeiter, grund FROM ticket_protokoll WHERE ticket = '" . (int)$id . "' ORDER BY zeit DESC"
        );

        $html = '<table cellpadding="0" cellspacing="0" class="mkTable" width="99.9%">';
        $html .= '<tr><td style="font-weight:bold; background:#555; color:#fff; padding:4px;">Zeit</td>';
        $html .= '<td style="font-weight:bold; background:#555; color:#fff; padding:4px;">Bearbeiter</td>';
        $html .= '<td style="font-weight:bold; background:#555; color:#fff; padding:4px;">Grund</td></tr>';

        if (!empty($rows)) {
            $even = false;
            foreach ($rows as $row) {
                $bg = $even ? '#e0e0e0' : '#fff';
                $html .= '<tr style="background:' . $bg . '">';
                $zeit = $row['zeit'] ?? '';
                if ($zeit !== '') {
                    $dt = strtotime($zeit);
                    $zeit = $dt !== false ? date('d.m.Y H:i', $dt) : $zeit;
                }
                $html .= '<td style="padding:4px;">' . htmlspecialchars($zeit) . '</td>';
                $html .= '<td style="padding:4px;">' . htmlspecialchars($row['bearbeiter'] ?? '') . '</td>';
                // Allow <a href> links, strip everything else
                $grund = strip_tags($row['grund'] ?? '', '<a>');
                $html .= '<td style="padding:4px;">' . $grund . '</td>';
                $html .= '</tr>';
                $even = !$even;
            }
        } else {
            $html .= '<tr><td colspan="3" style="padding:8px;">Keine Eintr&auml;ge</td></tr>';
        }
        $html .= '</table>';

        $this->app->Tpl->Set('TAB1', $html);
        $this->app->Tpl->Set('KURZUEBERSCHRIFT', 'Protokoll');
        $this->app->Tpl->Parse('PAGE', 'tabview.tpl');
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
                    $timelineHtml = $this->renderMessageTimeline($ticketId);

                    // Build repair-specific status dropdown override (JS)
                    $serviceType = $details['service_type'] ?? 'reparatur';
                    $statusOverrideJs = $this->buildStatusDropdownOverride($ticketId, $serviceType);
                    $this->app->Tpl->Set('REPAIR_PANEL', $repairHtml . $timelineHtml . $statusOverrideJs);

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

        // Get Belegnummer (nach SchnellFreigabe ist die Nummer vergeben)
        $belegNr = $this->app->DB->Select(
            "SELECT belegnr FROM `" . $action . "` WHERE id = '" . (int)$belegId . "' LIMIT 1"
        );
        if (empty($belegNr) || $belegNr === '0') {
            $belegNr = (string)$belegId;
        }

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
            '<a href="index.php?module=' . $action . '&action=edit&id=' . (int)$belegId . '">' . ucfirst($action) . ' #' . htmlspecialchars($belegNr ?: (string)$belegId) . ' erstellt</a>'
        );

        $protokollMethode = ucfirst($action) . 'Protokoll';
        if (method_exists($this->app->erp, $protokollMethode)) {
            $this->app->erp->$protokollMethode(
                $belegId,
                '<a href="index.php?module=ticket&action=edit&id=' . $ticketId . '">Erstellt aus Ticket #' . htmlspecialchars($ticket['schluessel']) . '</a>'
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
        // --- Fetch ticket status ---
        $ticketRow = $this->app->DB->SelectRow("SELECT status FROM ticket WHERE id = '" . (int)$ticketId . "'");
        $status = $ticketRow['status'] ?? 'neu';

        // Status color mapping
        $statusColors = [
            'neu' => '#BBBBBB',
            'offen' => '#5991FF',
            'in_diagnose' => '#ee8667',
            'kv_erstellt' => '#e56eca',
            'warten_kd' => '#FDCB56',
            'in_reparatur' => '#5962ec',
            'qualitaetskontrolle' => '#3bb8c3',
            'versendet' => '#2DCA73',
            'abgeschlossen' => '#2DCA73',
        ];
        $statusLabels = [
            'neu' => 'Neu',
            'offen' => 'Offen',
            'in_diagnose' => 'In Diagnose',
            'kv_erstellt' => 'KV erstellt',
            'warten_kd' => 'Warten auf Kunde',
            'in_reparatur' => 'In Reparatur',
            'qualitaetskontrolle' => 'QS',
            'versendet' => 'Versendet',
            'abgeschlossen' => 'Abgeschlossen',
        ];
        $statusColor = $statusColors[$status] ?? '#BBBBBB';
        $statusLabel = $statusLabels[$status] ?? ucfirst($status);

        // Determine if text should be dark on light backgrounds
        $statusTextColor = in_array($status, ['warten_kd'], true) ? '#333' : '#fff';

        $isExpress = !empty($details['is_express']);

        // --- Panel container ---
        $panelBorder = $isExpress ? 'border-left:4px solid #FDCB56;' : '';
        $html = '<div style="margin-top:15px; padding:0; border:1px solid #d9d9d9; background:#F5F6FA; border-radius:6px; ' . $panelBorder . '">';

        // --- Panel header with status badge ---
        $html .= '<div style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #d9d9d9; background:#EBECF1; border-radius:6px 6px 0 0;">';
        $html .= '<div style="display:flex; align-items:center; gap:10px;">';
        $html .= '<h3 style="margin:0; font-size:15px;">Reparatur-Details</h3>';
        $html .= '<span style="display:inline-block; padding:.25em .6em; border-radius:.25rem; font-weight:600; font-size:11px; background:' . $statusColor . '; color:' . $statusTextColor . ';">' . htmlspecialchars($statusLabel) . '</span>';
        $html .= '</div>';
        if ($isExpress) {
            $html .= '<span style="background:#F05A5C;color:#fff;padding:3px 10px;border-radius:3px;font-weight:700;font-size:12px;">EXPRESS</span>';
        }
        $html .= '</div>';

        // --- Workflow stepper ---
        $steps = ['Eingang', 'Diagnose', 'KV', 'Freigabe', 'Reparatur', 'QS', 'Versand'];
        $stepMapping = [
            'neu' => 1, 'offen' => 1,
            'in_diagnose' => 2,
            'kv_erstellt' => 3,
            'warten_kd' => 4,
            'in_reparatur' => 5,
            'qualitaetskontrolle' => 6,
            'versendet' => 7, 'abgeschlossen' => 7,
        ];
        $activeStep = $stepMapping[$status] ?? 1;

        $html .= '<div style="padding:16px 16px 12px; display:flex; align-items:center; justify-content:center;">';
        foreach ($steps as $i => $stepLabel) {
            $stepNum = $i + 1;
            if ($stepNum < $activeStep) {
                // Completed
                $circleStyle = 'width:24px;height:24px;border-radius:50%;background:#2DCA73;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;';
                $circleContent = '&#10003;';
                $labelColor = '#2DCA73';
            } elseif ($stepNum === $activeStep) {
                // Active
                $circleStyle = 'width:24px;height:24px;border-radius:50%;background:#5991FF;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;';
                $circleContent = (string)$stepNum;
                $labelColor = '#5991FF';
            } else {
                // Future
                $circleStyle = 'width:24px;height:24px;border-radius:50%;background:#BBBBBB;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;';
                $circleContent = (string)$stepNum;
                $labelColor = '#BBBBBB';
            }

            $html .= '<div style="display:flex;flex-direction:column;align-items:center;min-width:50px;">';
            $html .= '<div style="' . $circleStyle . '">' . $circleContent . '</div>';
            $html .= '<div style="font-size:10px;margin-top:4px;color:' . $labelColor . ';font-weight:600;white-space:nowrap;">' . htmlspecialchars($stepLabel) . '</div>';
            $html .= '</div>';

            // Connector line between steps
            if ($stepNum < count($steps)) {
                $lineColor = ($stepNum < $activeStep) ? '#2DCA73' : '#BBBBBB';
                $html .= '<div style="flex:1;height:2px;background:' . $lineColor . ';margin:0 4px;align-self:flex-start;margin-top:12px;"></div>';
            }
        }
        $html .= '</div>';

        // --- Inner padding wrapper ---
        $html .= '<div style="padding:0 16px 16px;">';

        // --- Device info card (Punkt 4) ---
        $manufacturer = htmlspecialchars($details['manufacturer'] ?? '');
        $model = htmlspecialchars($details['model'] ?? '');
        $serialNumber = htmlspecialchars($details['serial_number'] ?? '');
        $issueCategory = htmlspecialchars($details['issue_category'] ?? '');
        $warrantyStatus = $details['warranty_status'] ?? '';
        $serviceType = $details['service_type'] ?? '';

        // Warranty badge
        $warrantyLower = strtolower($warrantyStatus);
        if (in_array($warrantyLower, ['ja', 'yes', 'garantie'], true)) {
            $warrantyBadge = '<span style="display:inline-block;padding:.15em .5em;border-radius:.25rem;font-weight:600;font-size:10px;background:#2DCA73;color:#fff;">Garantie</span>';
        } elseif (in_array($warrantyLower, ['nein', 'no', 'keine'], true)) {
            $warrantyBadge = '<span style="display:inline-block;padding:.15em .5em;border-radius:.25rem;font-weight:600;font-size:10px;background:#F05A5C;color:#fff;">Keine Garantie</span>';
        } else {
            $warrantyBadge = '<span style="display:inline-block;padding:.15em .5em;border-radius:.25rem;font-weight:600;font-size:10px;background:#BBBBBB;color:#fff;">' . htmlspecialchars($warrantyStatus ?: 'Unbekannt') . '</span>';
        }

        // Service type badge
        $serviceTypeColors = [
            'reparatur' => '#5962ec',
            'wartung' => '#3bb8c3',
            'installation' => '#639ed4',
            'inspektion' => '#ee8667',
            'upgrade' => '#e56eca',
        ];
        $stLower = strtolower($serviceType);
        $stColor = $serviceTypeColors[$stLower] ?? '#BBBBBB';
        $serviceTypeBadge = '<span style="display:inline-block;padding:.15em .5em;border-radius:.25rem;font-weight:600;font-size:10px;background:' . $stColor . ';color:#fff;">' . htmlspecialchars(ucfirst($serviceType)) . '</span>';

        $html .= '<div style="background:#fff; border:1px solid #d9d9d9; border-radius:6px; padding:12px 16px; margin:10px 0;">';
        $html .= '<div style="display:flex; align-items:center; gap:12px;">';
        $html .= '<span style="font-size:28px;">&#128424;</span>';
        $html .= '<div style="flex:1;">';
        $html .= '<div style="font-weight:700; font-size:14px;">' . $manufacturer . ' ' . $model . '</div>';
        $html .= '<div style="color:#666; font-size:11px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:2px;">';
        $html .= $serviceTypeBadge . ' ';
        $html .= 'SN: <code style="background:#f0f0f0;padding:1px 4px;border-radius:2px;font-size:11px;">' . $serialNumber . '</code>';
        $html .= ' &middot; ' . $issueCategory;
        $html .= ' &middot; ' . $warrantyBadge;
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        if (!empty($details['issue_description'])) {
            $html .= '<div style="margin-top:8px; padding-top:8px; border-top:1px solid #eee; font-size:12px; color:#555;">';
            $html .= nl2br(htmlspecialchars($details['issue_description']));
            $html .= '</div>';
        }
        $html .= '</div>';

        // --- Diagnosis & Cost section (Punkt 5) ---
        $html .= '<h3 style="margin-top:15px; margin-bottom:8px; font-size:14px;">Diagnose &amp; Kosten</h3>';

        $quoteAmount = (float)($details['quote_amount'] ?? 0);
        $actualCost = (float)($details['actual_cost'] ?? 0);
        $costLimit = (float)($details['cost_limit'] ?? 0);

        // Cost overview with progress bar
        if ($costLimit > 0 || $quoteAmount > 0 || $actualCost > 0) {
            $html .= '<div style="background:#fff; border:1px solid #d9d9d9; border-radius:6px; padding:12px 16px; margin-bottom:10px;">';

            // KV and actual cost side by side
            $html .= '<div style="display:flex; gap:24px; margin-bottom:10px;">';
            $html .= '<div><div style="font-size:11px;color:#666;">KV-Betrag</div><div style="font-size:18px;font-weight:700;color:#333;">' . number_format($quoteAmount, 2, ',', '.') . ' &euro;</div></div>';
            $html .= '<div><div style="font-size:11px;color:#666;">Ist-Kosten</div><div style="font-size:18px;font-weight:700;color:#333;">' . number_format($actualCost, 2, ',', '.') . ' &euro;</div></div>';
            if ($costLimit > 0) {
                $html .= '<div><div style="font-size:11px;color:#666;">Kostenrahmen</div><div style="font-size:18px;font-weight:700;color:#333;">' . number_format($costLimit, 2, ',', '.') . ' &euro;</div></div>';
            }
            $html .= '</div>';

            // Progress bar
            $barMax = $costLimit > 0 ? $costLimit : ($quoteAmount > 0 ? $quoteAmount : 0);
            if ($barMax > 0) {
                $pct = min(($actualCost / $barMax) * 100, 100);
                if ($pct < 70) {
                    $barColor = '#2DCA73';
                } elseif ($pct < 90) {
                    $barColor = '#FDCB56';
                } else {
                    $barColor = '#F05A5C';
                }
                $html .= '<div style="background:#eee;border-radius:3px;height:8px;width:100%;overflow:hidden;">';
                $html .= '<div style="background:' . $barColor . ';height:100%;width:' . round($pct, 1) . '%;border-radius:3px;transition:width .3s;"></div>';
                $html .= '</div>';
                $html .= '<div style="font-size:10px;color:#999;margin-top:2px;text-align:right;">' . round($pct, 0) . '% von ' . number_format($barMax, 2, ',', '.') . ' &euro;</div>';
            }

            // Warning if actual > quote
            if ($quoteAmount > 0 && $actualCost > $quoteAmount) {
                $html .= '<div style="background:#FFF3CD;border:1px solid #FDCB56;border-radius:4px;padding:6px 10px;margin-top:8px;font-size:12px;color:#856404;">';
                $html .= '&#9888; Ist-Kosten (' . number_format($actualCost, 2, ',', '.') . ' &euro;) &uuml;berschreiten den KV-Betrag (' . number_format($quoteAmount, 2, ',', '.') . ' &euro;)';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        // Editable fields — inline layout without mkTableFormular
        $html .= '<div style="margin-bottom:10px;">';
        $html .= '<div style="margin-bottom:8px;">';
        $html .= '<label style="display:block;font-size:11px;color:#666;margin-bottom:3px;">Diagnose-Ergebnis</label>';
        $html .= '<textarea name="repair_diagnosis_result" rows="2" style="width:100%;box-sizing:border-box;border:1px solid #d9d9d9;border-radius:4px;padding:6px 8px;font-size:12px;font-family:inherit;">' . htmlspecialchars($details['diagnosis_result'] ?? '') . '</textarea>';
        $html .= '</div>';
        $html .= '<div style="display:flex;gap:12px;margin-bottom:8px;">';
        $html .= '<div style="flex:1;">';
        $html .= '<label style="display:block;font-size:11px;color:#666;margin-bottom:3px;">KV-Betrag (EUR)</label>';
        $html .= '<input type="text" name="repair_quote_amount" value="' . htmlspecialchars($details['quote_amount'] ?? '') . '" style="width:100%;box-sizing:border-box;border:1px solid #d9d9d9;border-radius:4px;padding:5px 8px;font-size:12px;">';
        $html .= '</div>';
        $html .= '<div style="flex:1;">';
        $html .= '<label style="display:block;font-size:11px;color:#666;margin-bottom:3px;">Tats. Kosten (EUR)</label>';
        $html .= '<input type="text" name="repair_actual_cost" value="' . htmlspecialchars($details['actual_cost'] ?? '') . '" style="width:100%;box-sizing:border-box;border:1px solid #d9d9d9;border-radius:4px;padding:5px 8px;font-size:12px;">';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div>';
        $html .= '<label style="display:block;font-size:11px;color:#666;margin-bottom:3px;">Reparatur-Notizen</label>';
        $html .= '<textarea name="repair_notes" rows="2" style="width:100%;box-sizing:border-box;border:1px solid #d9d9d9;border-radius:4px;padding:6px 8px;font-size:12px;font-family:inherit;">' . htmlspecialchars($details['repair_notes'] ?? '') . '</textarea>';
        $html .= '</div>';
        $html .= '</div>';

        // --- Image/File Preview Gallery ---
        $dateiIds = $this->app->erp->GetDateiSubjektObjekt('%', 'ticket_header', $ticketId);
        // Also check for message attachments
        $dateiIdsAnhang = $this->app->erp->GetDateiSubjektObjekt('Anhang', 'Ticket', $ticketId);
        if (!is_array($dateiIds)) $dateiIds = [];
        if (is_array($dateiIdsAnhang)) $dateiIds = array_merge($dateiIds, $dateiIdsAnhang);
        $dateiIds = array_unique($dateiIds);

        if (!empty($dateiIds)) {
            $html .= '<h3 style="margin-top:15px; margin-bottom:8px; font-size:14px;">Dateien &amp; Fotos</h3>';
            $html .= '<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">';

            foreach ($dateiIds as $dateiId) {
                $dateiId = (int)$dateiId;
                // Get filename from datei_version
                $dateiInfo = $this->app->DB->SelectRow(
                    "SELECT dv.dateiname, dv.size FROM datei_version dv WHERE dv.datei = '" . $dateiId . "' ORDER BY dv.version DESC LIMIT 1"
                );
                $filename = $dateiInfo['dateiname'] ?? '';
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                $isVideo = in_array($ext, ['mp4', 'mov', 'webm'], true);

                $thumbUrl = 'index.php?module=ajax&action=thumbnail&cmd=ticket&id=' . $dateiId;
                $downloadUrl = 'index.php?module=dateien&action=send&id=' . $dateiId;
                $sizeKb = isset($dateiInfo['size']) ? round((int)$dateiInfo['size'] / 1024) : 0;

                $html .= '<div style="border:1px solid #d9d9d9; border-radius:6px; overflow:hidden; width:130px; background:#fff; text-align:center;">';

                if ($isImage) {
                    // Clickable thumbnail that opens full image
                    $html .= '<a href="' . $downloadUrl . '" target="_blank" title="' . htmlspecialchars($filename) . '">';
                    $html .= '<img src="' . $thumbUrl . '" style="width:130px; height:100px; object-fit:cover; display:block; cursor:pointer;" alt="' . htmlspecialchars($filename) . '">';
                    $html .= '</a>';
                } elseif ($isVideo) {
                    $html .= '<a href="' . $downloadUrl . '" target="_blank" title="' . htmlspecialchars($filename) . '">';
                    $html .= '<div style="width:130px; height:100px; background:#333; display:flex; align-items:center; justify-content:center;">';
                    $html .= '<span style="font-size:32px; color:#fff;">&#9654;</span>';
                    $html .= '</div>';
                    $html .= '</a>';
                } else {
                    $html .= '<a href="' . $downloadUrl . '" target="_blank" title="' . htmlspecialchars($filename) . '">';
                    $html .= '<div style="width:130px; height:100px; background:#f5f5f5; display:flex; align-items:center; justify-content:center;">';
                    $html .= '<span style="font-size:28px;">&#128196;</span>';
                    $html .= '</div>';
                    $html .= '</a>';
                }

                $html .= '<div style="padding:4px 6px; font-size:10px; color:#666; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="' . htmlspecialchars($filename) . '">';
                $html .= htmlspecialchars($filename ?: 'Datei #' . $dateiId);
                if ($sizeKb > 0) {
                    $html .= '<br><span style="color:#999;">' . ($sizeKb >= 1024 ? round($sizeKb / 1024, 1) . ' MB' : $sizeKb . ' KB') . '</span>';
                }
                $html .= '</div>';
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        // --- Linked Belege as cards (Punkt 10) ---
        $belegGateway = $this->app->Container->get('RepairBelegGateway');
        $belege = $belegGateway->getByTicketId($ticketId);
        if (!empty($belege)) {
            $belegLabels = ['angebot' => 'Angebot', 'auftrag' => 'Auftrag', 'rechnung' => 'Rechnung', 'lieferschein' => 'Lieferschein', 'gutschrift' => 'Gutschrift', 'verbindlichkeit' => 'Verbindlichkeit'];
            $belegPrefixes = ['angebot' => 'AN', 'auftrag' => 'AU', 'rechnung' => 'RE', 'lieferschein' => 'LS', 'gutschrift' => 'GS', 'verbindlichkeit' => 'VB'];
            $html .= '<h3 style="margin-top:15px; margin-bottom:8px; font-size:14px;">Verknuepfte Belege</h3>';
            $html .= '<div style="display:flex; gap:8px; flex-wrap:wrap;">';
            foreach ($belege as $beleg) {
                // Hole aktuelle Belegnummer live aus der Beleg-Tabelle falls NULL
                $belegNr = $beleg['beleg_nr'];
                if (empty($belegNr)) {
                    $belegNr = $this->app->DB->Select(
                        "SELECT belegnr FROM `" . $beleg['beleg_typ'] . "` WHERE id = '" . (int)$beleg['beleg_id'] . "' LIMIT 1"
                    );
                    // Update in repair_ticket_beleg fuer naechstes Mal
                    if (!empty($belegNr)) {
                        $this->app->DB->Update(
                            "UPDATE repair_ticket_beleg SET beleg_nr = '" . $this->app->DB->real_escape_string($belegNr) . "' WHERE id = '" . (int)$beleg['id'] . "'"
                        );
                    }
                }
                $label = $belegLabels[$beleg['beleg_typ']] ?? ucfirst($beleg['beleg_typ']);
                $prefix = $belegPrefixes[$beleg['beleg_typ']] ?? '';
                $displayNr = $belegNr ? ($prefix ? $prefix . '-' . $belegNr : $belegNr) : '-';
                $createdAt = $beleg['created_at'] ?? '';
                if ($createdAt !== '') {
                    $dt = strtotime($createdAt);
                    $createdAt = $dt !== false ? date('d.m.Y', $dt) : $createdAt;
                }
                $html .= '<div style="background:#fff; border:1px solid #d9d9d9; border-radius:4px; padding:8px 12px; min-width:120px;">';
                $html .= '<div style="font-size:11px; color:#666;">' . htmlspecialchars($label) . '</div>';
                $html .= '<div style="font-weight:700;"><a href="index.php?module=' . htmlspecialchars($beleg['beleg_typ']) . '&action=edit&id=' . (int)$beleg['beleg_id'] . '" style="color:#5991FF;text-decoration:none;">#' . htmlspecialchars($displayNr) . '</a></div>';
                if ($createdAt !== '') {
                    $html .= '<div style="font-size:10px; color:#999;">' . htmlspecialchars($createdAt) . '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>'; // end inner padding wrapper
        $html .= '</div>'; // end panel container
        return $html;
    }

    /**
     * Render message timeline for repair tickets (replaces iFrame display)
     */
    private function renderMessageTimeline(int $ticketId): string
    {
        // Load messages — ticket_nachricht.ticket references ticket.schluessel (VARCHAR)
        $messages = $this->app->DB->SelectArr(
            "SELECT id, zeit, verfasser, mail, text, betreff, medium, status
             FROM ticket_nachricht
             WHERE ticket = (SELECT schluessel FROM ticket WHERE id = '" . (int)$ticketId . "')
             ORDER BY zeit ASC"
        );

        // Load protocol entries (status changes)
        $protokoll = $this->app->DB->SelectArr(
            "SELECT zeit, bearbeiter, grund FROM ticket_protokoll WHERE ticket = '" . (int)$ticketId . "' ORDER BY zeit ASC"
        );

        // Merge both into a single chronological list
        $timeline = [];

        if (!empty($messages)) {
            foreach ($messages as $msg) {
                $ts = strtotime($msg['zeit'] ?? '');
                $timeline[] = [
                    'type' => 'message',
                    'ts' => $ts !== false ? $ts : 0,
                    'data' => $msg,
                ];
            }
        }

        if (!empty($protokoll)) {
            foreach ($protokoll as $entry) {
                $ts = strtotime($entry['zeit'] ?? '');
                $timeline[] = [
                    'type' => 'event',
                    'ts' => $ts !== false ? $ts : 0,
                    'data' => $entry,
                ];
            }
        }

        // Sort chronologically
        usort($timeline, function ($a, $b) {
            return $a['ts'] <=> $b['ts'];
        });

        // Render
        $html = '<div id="repair_timeline_panel" style="margin-top:15px; padding:10px; border:1px solid #ddd; background:#f9f9f9; border-radius:4px;">';
        $html .= '<h3 style="margin-top:0;">Nachrichten-Verlauf</h3>';

        if (empty($timeline)) {
            $html .= '<p style="color:#999; font-size:12px;">Keine Nachrichten vorhanden.</p>';
        } else {
            $html .= '<div style="max-height:600px; overflow-y:auto; padding:4px;">';

            foreach ($timeline as $idx => $item) {
                if ($item['type'] === 'message') {
                    $msg = $item['data'];
                    $zeit = $item['ts'] > 0 ? date('d.m.Y H:i', $item['ts']) : '';
                    $verfasser = htmlspecialchars($msg['verfasser'] ?? '');
                    $rawText = strip_tags($msg['text'] ?? '');
                    $rawText = trim($rawText);
                    $fullText = nl2br(htmlspecialchars($rawText));
                    $isLong = mb_strlen($rawText) > 500;

                    if ($isLong) {
                        $truncated = nl2br(htmlspecialchars(mb_substr($rawText, 0, 500))) . '&hellip;';
                    }

                    $isIntern = ($msg['medium'] ?? '') === 'intern';
                    $borderColor = $isIntern ? '#BBBBBB' : '#5991FF';

                    $html .= '<div style="margin:8px 0; padding:10px 14px; background:#fff; border-left:4px solid ' . $borderColor . '; border-radius:0 4px 4px 0;">';
                    $html .= '<div style="font-size:11px; color:#666; margin-bottom:4px;">';
                    $html .= '<strong>' . $verfasser . '</strong> &middot; ' . htmlspecialchars($zeit);
                    if ($isIntern) {
                        $html .= ' <span style="background:#EBECF1; padding:1px 6px; border-radius:3px; font-size:10px;">intern</span>';
                    }
                    $html .= '</div>';

                    if ($isLong) {
                        $uniqueId = 'msg_' . (int)($msg['id'] ?? $idx);
                        $html .= '<div id="' . $uniqueId . '_short" style="font-size:12px; white-space:pre-wrap;">' . $truncated . ' ';
                        $html .= '<a href="#" onclick="document.getElementById(\'' . $uniqueId . '_short\').style.display=\'none\';document.getElementById(\'' . $uniqueId . '_full\').style.display=\'block\';return false;" style="color:#5991FF; font-size:11px;">Mehr anzeigen</a>';
                        $html .= '</div>';
                        $html .= '<div id="' . $uniqueId . '_full" style="display:none; font-size:12px; white-space:pre-wrap;">' . $fullText . ' ';
                        $html .= '<a href="#" onclick="document.getElementById(\'' . $uniqueId . '_full\').style.display=\'none\';document.getElementById(\'' . $uniqueId . '_short\').style.display=\'block\';return false;" style="color:#5991FF; font-size:11px;">Weniger anzeigen</a>';
                        $html .= '</div>';
                    } else {
                        $html .= '<div style="font-size:12px; white-space:pre-wrap;">' . $fullText . '</div>';
                    }

                    $html .= '</div>';
                } elseif ($item['type'] === 'event') {
                    $entry = $item['data'];
                    $zeit = $item['ts'] > 0 ? date('d.m.Y H:i', $item['ts']) : '';
                    $grund = strip_tags($entry['grund'] ?? '', '<a>');

                    $html .= '<div style="text-align:center; margin:6px 0; font-size:10px; color:#999;">';
                    $html .= '<span style="background:#EBECF1; padding:2px 10px; border-radius:10px;">';
                    $html .= htmlspecialchars($zeit) . ' &mdash; ' . $grund;
                    $html .= '</span>';
                    $html .= '</div>';
                }
            }

            $html .= '</div>'; // scrollable container
        }

        // JavaScript to hide default iFrame-based message display
        $html .= '<script>';
        $html .= 'setTimeout(function() {';
        $html .= '  var panel = document.getElementById("repair_timeline_panel");';
        $html .= '  if (!panel) return;';
        // The panel is inside REPAIR_PANEL placeholder which is appended to the tabpanel content.
        // Old messages are sibling divs of the tabpanel's direct children.
        // Find parent that is a direct child of the tabpanel (role=tabpanel or .ui-tabs-panel)
        $html .= '  var tabpanel = document.querySelector("[role=tabpanel], .ui-tabs-panel, #tabs-1");';
        $html .= '  if (!tabpanel) return;';
        $html .= '  var found = false;';
        $html .= '  Array.prototype.forEach.call(tabpanel.children, function(child) {';
        $html .= '    if (found) { child.style.display = "none"; return; }';
        $html .= '    if (child.contains(panel) || child.id === "repair_timeline_panel") found = true;';
        $html .= '  });';
        $html .= '}, 50);';
        $html .= '</script>';

        $html .= '</div>';
        return $html;
    }

    /**
     * Build JavaScript to override the status dropdown with repair-specific statuses
     */
    private function buildStatusDropdownOverride(int $ticketId, string $serviceType): string
    {
        // Get current ticket status
        $ticketRow = $this->app->DB->SelectRow(
            "SELECT status FROM ticket WHERE id = '" . (int)$ticketId . "' LIMIT 1"
        );
        $currentStatus = $ticketRow['status'] ?? 'neu';

        // Map service type to category
        $categoryMap = [
            'reparatur' => 'repair',
            'wartung' => 'maintenance',
            'reverse_engineering' => 'reverse_engineering',
            'individualisierung' => 'individualization',
        ];
        $category = $categoryMap[$serviceType] ?? 'repair';

        // Load statuses: general + service-specific
        $statuses = $this->app->DB->SelectArr(
            "SELECT slug, label_de FROM ticket_status_config
             WHERE is_active = 1 AND (category = 'general' OR category = '" . $this->app->DB->real_escape_string($category) . "')
             ORDER BY sort_order ASC"
        );

        if (empty($statuses)) {
            return '';
        }

        // Build JS options array
        $jsOptions = [];
        foreach ($statuses as $s) {
            $slug = addslashes($s['slug']);
            $label = addslashes($s['label_de']);
            $selected = ($s['slug'] === $currentStatus) ? 'true' : 'false';
            $jsOptions[] = "{v:\"{$slug}\",l:\"{$label}\",s:{$selected}}";
        }
        $jsArray = implode(',', $jsOptions);

        $js = '<script>';
        $js .= 'setTimeout(function() {';
        $js .= '  var sel = document.querySelector("select[name=status]");';
        $js .= '  if (!sel) return;';
        $js .= '  var opts = [' . $jsArray . '];';
        $js .= '  sel.innerHTML = "";';
        $js .= '  opts.forEach(function(o) {';
        $js .= '    var opt = document.createElement("option");';
        $js .= '    opt.value = o.v; opt.textContent = o.l;';
        $js .= '    if (o.s) opt.selected = true;';
        $js .= '    sel.appendChild(opt);';
        $js .= '  });';
        $js .= '}, 50);';
        $js .= '</script>';

        return $js;
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
