<?php
// www/pages/ticket_custom.php
// Extends the core Ticket page with RepairIntegration features.
// OpenXE's loadModule() prefers *_custom.php over *.php automatically.

class TicketCustom extends Ticket
{
    /** @var string Warnmeldung aus dem Panel-Save, wird im Panel gerendert */
    private string $repairPanelMessage = '';

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

        // The parent finishes with Tpl->Parse('PAGE', 'ticket_edit.tpl'), so every
        // template variable of the repair panel has to be filled before that call.
        if ($ticketId > 0) {
            $this->repair_render_panel($ticketId);
        }

        // Call parent (handles form submission, status change, email sending)
        parent::ticket_edit();

        if ($ticketId <= 0) {
            return;
        }

        // Trigger status change hook
        try {
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

    /**
     * Persists the panel form fields and renders repair_detail_tab.tpl into the
     * [NEW_MESSAGE] placeholder of ticket_edit.tpl.
     *
     * [NEW_MESSAGE] is the last placeholder inside the ticket <form>, so the panel
     * inputs are submitted together with the ticket. Tpl->Parse() appends (it calls
     * Add() internally) and the parent only ever writes NEW_MESSAGE via Parse(), so
     * the draft mail form stays intact and ticket_edit.tpl needs no modification.
     */
    private function repair_render_panel(int $ticketId): void
    {
        try {
            $detailsGateway = $this->app->Container->get('RepairDetailsGateway');
            $details = $detailsGateway->getByTicketId($ticketId);
            if ($details === null) {
                $this->app->Tpl->Set('REPAIR_TAB_DISPLAY', 'none');
                return;
            }

            $details = $this->repair_save_panel_fields($detailsGateway, $ticketId, $details);

            $this->app->Tpl->Set('REPAIR_MESSAGE', $this->repairPanelMessage);
            $this->app->Tpl->Set('REPAIR_TAB_DISPLAY', 'block');
            $this->app->Tpl->Set('REPAIR_SERVICE_TYPE', htmlspecialchars(ucfirst($details['service_type'] ?? '')));
            $this->app->Tpl->Set('REPAIR_MANUFACTURER', htmlspecialchars($details['manufacturer'] ?? ''));
            $this->app->Tpl->Set('REPAIR_MODEL', htmlspecialchars($details['model'] ?? ''));
            $this->app->Tpl->Set('REPAIR_SERIAL', htmlspecialchars($details['serial_number'] ?? ''));
            $this->app->Tpl->Set('REPAIR_ISSUE_CAT', htmlspecialchars($details['issue_category'] ?? ''));
            $this->app->Tpl->Set('REPAIR_ISSUE_DESC', htmlspecialchars($details['issue_description'] ?? ''));
            $this->app->Tpl->Set('REPAIR_WARRANTY', htmlspecialchars($details['warranty_status'] ?? ''));
            $this->app->Tpl->Set('REPAIR_COST_LIMIT', htmlspecialchars($details['cost_limit'] ?? ''));
            $this->app->Tpl->Set('REPAIR_EXPRESS', $details['is_express'] ? '<span class="repair-express-badge">Ja</span>' : 'Nein');
            $this->app->Tpl->Set('REPAIR_DIAGNOSIS', htmlspecialchars($details['diagnosis_result'] ?? ''));
            $this->app->Tpl->Set('REPAIR_QUOTE', htmlspecialchars($details['quote_amount'] ?? ''));
            $this->app->Tpl->Set('REPAIR_ACTUAL_COST', htmlspecialchars($details['actual_cost'] ?? ''));
            // Read-only: der Wert kommt ausschliesslich per Push von WP.
            // Leer -> Gedankenstrich, damit der Chip im Panel nicht leer steht.
            $customerQuote = trim((string)($details['customer_quote_amount'] ?? ''));
            $this->app->Tpl->Set(
                'REPAIR_CUSTOMER_QUOTE',
                $customerQuote !== '' ? htmlspecialchars($customerQuote) . ' &euro;' : '&ndash;'
            );
            $this->app->Tpl->Set('REPAIR_NOTES', htmlspecialchars($details['repair_notes'] ?? ''));

            // Customer account and beleg shortcuts
            $ticket = $this->app->DB->SelectRow(
                "SELECT adresse FROM ticket WHERE id = '" . (int)$ticketId . "' LIMIT 1"
            );
            $adresseId = (int)($ticket['adresse'] ?? 0);
            $this->app->Tpl->Set('REPAIR_ADRESSE_BLOCK', $this->repair_adresse_block($ticketId, $adresseId));
            $this->app->Tpl->Set('REPAIR_BELEG_BUTTONS', $this->repair_beleg_buttons($ticketId, $adresseId));

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

            $this->app->Tpl->Parse('NEW_MESSAGE', 'repair_detail_tab.tpl');
        } catch (\Exception $e) {
            // RepairIntegration not installed or not configured -- silently skip
        }
    }

    /**
     * Reads the panel fields from the POST request and writes them to
     * ticket_repair_details. Returns the details row with the new values applied.
     */
    private function repair_save_panel_fields($detailsGateway, int $ticketId, array $details): array
    {
        // The parent only saves when a submit button was used -- behave the same
        if ($this->app->Secure->GetPOST('submit') === '') {
            return $details;
        }

        $fields = [
            'diagnosis_result' => 'repair_diagnosis_result',
            'quote_amount' => 'repair_quote_amount',
            'actual_cost' => 'repair_actual_cost',
            'repair_notes' => 'repair_notes',
        ];

        $changed = false;
        foreach ($fields as $column => $postName) {
            if (!isset($this->app->Secure->POST[$postName])) {
                continue;
            }
            // 4. Parameter (sqlcheckoff): GetPOST escaped sonst bereits fuer
            // mysqli - in Kombination mit dem Prepared Statement des Gateways
            // wuerden Backslashes literal gespeichert (doppeltes Escaping).
            $raw = $this->app->Secure->GetPOST($postName, 'nothtml', '', 1);
            if (is_array($raw)) {
                continue;
            }
            if ($column === 'quote_amount' || $column === 'actual_cost') {
                $value = $this->repair_normalize_amount((string)$raw);
                if ($value === false) {
                    // Unparsebare Eingabe: alten Wert behalten und warnen,
                    // statt ihn kommentarlos mit NULL zu ueberschreiben.
                    // Die Meldung landet im Panel-Template ([REPAIR_MESSAGE]),
                    // weil der Parent MESSAGE beim Speichern ueberschreibt.
                    if ($this->repairPanelMessage === '') {
                        $this->repairPanelMessage = '<div class="error">Betrag &quot;'
                            . htmlspecialchars((string)$raw)
                            . '&quot; konnte nicht gelesen werden - der bisherige Wert bleibt erhalten.</div>';
                    }
                    continue;
                }
            } else {
                $value = trim((string)$raw);
                if ($value === '') {
                    $value = null;
                }
            }
            $details[$column] = $value;
            $changed = true;
        }

        if (!$changed) {
            return $details;
        }

        // RepairDetailsGateway::update() does not whitelist the diagnosis columns
        $detailsGateway->updateDiagnosisFields(
            $ticketId,
            isset($details['diagnosis_result']) ? (string)$details['diagnosis_result'] : null,
            isset($details['quote_amount']) ? (string)$details['quote_amount'] : null,
            isset($details['actual_cost']) ? (string)$details['actual_cost'] : null,
            isset($details['repair_notes']) ? (string)$details['repair_notes'] : null
        );

        return $details;
    }

    /**
     * Converts a user entered amount to a decimal string.
     * Rueckgabe: null bei leerer Eingabe (Feld leeren), false bei
     * unparsebarer Eingabe (alten Wert behalten), sonst String.
     *
     * @return string|null|false
     */
    private function repair_normalize_amount(string $value)
    {
        $value = str_replace(' ', '', trim($value));
        if ($value === '') {
            return null;
        }
        if (strpos($value, ',') !== false) {
            // German notation 1.234,56 -> 1234.56
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $value) === 1) {
            // Deutsche Tausender-Notation ohne Nachkommastellen: 1.234 -> 1234
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? $value : false;
    }

    private function repair_adresse_block(int $ticketId, int $adresseId): string
    {
        if ($adresseId <= 0) {
            return '<a class="button" href="index.php?module=repairintegration&action=createadresse&ticket='
                . $ticketId . '">Kundenkonto anlegen/verkn&uuml;pfen</a>';
        }

        $adresse = $this->app->DB->SelectRow(
            "SELECT name, kundennummer FROM adresse WHERE id = '" . $adresseId . "' LIMIT 1"
        );
        $name = trim((string)($adresse['name'] ?? ''));
        if ($name === '') {
            $name = 'Adresse #' . $adresseId;
        }
        $kundennummer = trim((string)($adresse['kundennummer'] ?? ''));

        return '<a href="index.php?module=adresse&action=edit&id=' . $adresseId . '">'
            . htmlspecialchars($name) . '</a>'
            . ($kundennummer !== '' ? ' (' . htmlspecialchars($kundennummer) . ')' : '');
    }

    private function repair_beleg_buttons(int $ticketId, int $adresseId): string
    {
        if ($adresseId <= 0) {
            return '<i>Erst Kundenkonto verkn&uuml;pfen</i>';
        }

        $typen = [
            'angebot' => 'Kostenvoranschlag/Angebot',
            'auftrag' => 'Auftrag',
            'rechnung' => 'Rechnung',
        ];

        $html = '';
        foreach ($typen as $typ => $label) {
            $html .= '<a class="button" style="margin-right:5px;" href="index.php?module=repairintegration&action=createbeleg&ticket='
                . $ticketId . '&typ=' . $typ . '">' . $label . '</a>';
        }

        return $html;
    }
}
