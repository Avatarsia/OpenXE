<?php
// www/pages/ticket_custom.php
// Extends the core Ticket page with RepairIntegration features.
// OpenXE's loadModule() prefers *_custom.php over *.php automatically.

class TicketCustom extends Ticket
{
    public $javascript = ['./classes/Modules/RepairIntegration/www/js/repairintegration.js'];
    public $stylesheet = ['./classes/Modules/RepairIntegration/www/css/repairintegration.css'];
    /** @var string Warnmeldung aus dem Panel-Save, wird im Panel gerendert */
    private string $repairPanelMessage = '';

    function __construct($app, $intern = false)
    {
        if (!$intern) {
            $app->ModuleScriptCache->IncludeJavascriptFiles('RepairIntegration', $this->javascript);
            $app->ModuleScriptCache->IncludeStylesheetFiles('RepairIntegration', $this->stylesheet);
        }
        parent::__construct($app, $intern);
    }

    function ticket_dateien()
    {
        parent::ticket_dateien();
        if (!empty($_FILES)) $this->repair_queue_images((int)$this->app->Secure->GetGET('id'));
    }

    private function repair_queue_images(int $ticketId): void
    {
        if ($ticketId <= 0) return;
        try {
            $service = $this->app->Container->get('RepairReconcileService');
            $service->queueTicket($ticketId);
            $service->processPending(1);
        } catch (\Throwable $e) { error_log('Repair image sync deferred for ticket #' . $ticketId); }
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

        if (!empty($_FILES)) $this->repair_queue_images($ticketId);

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
        } catch (\Throwable $e) {
            error_log('Repair status queue failed for ticket #' . $ticketId . ': ' . $e->getMessage());
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
            $migration = new \Xentral\Modules\RepairIntegration\Migration\RepairIntegrationMigration($this->app->Container->get('Database'));
            if ($migration->needsUpgrade()) $migration->upgrade();
            $detailsGateway = $this->app->Container->get('RepairDetailsGateway');
            $details = $detailsGateway->getByTicketId($ticketId);
            if ($details === null) {
                $this->app->Tpl->Set('REPAIR_TAB_DISPLAY', 'none');
                return;
            }

            $details = $this->repair_save_panel_fields($detailsGateway, $ticketId, $details);

            $this->app->Tpl->Set('REPAIR_MESSAGE', $this->repairPanelMessage);
            $this->app->Tpl->Set('REPAIR_TAB_DISPLAY', 'block');
            $this->app->Tpl->Set('REPAIR_DIAGNOSIS', htmlspecialchars($details['diagnosis_result'] ?? ''));
            $this->app->Tpl->Set('REPAIR_NOTES', htmlspecialchars($details['repair_notes'] ?? ''));
            $this->repair_render_service_fields($details, $ticketId);

            // Customer account and beleg shortcuts
            $ticket = $this->app->DB->SelectRow(
                "SELECT adresse FROM ticket WHERE id = '" . (int)$ticketId . "' LIMIT 1"
            );
            $adresseId = (int)($ticket['adresse'] ?? 0);
            $this->app->Tpl->Set('REPAIR_ADRESSE_BLOCK', $this->repair_adresse_block($ticketId, $adresseId));
            $this->app->Tpl->Set('REPAIR_BELEG_BUTTONS', $this->repair_beleg_buttons($ticketId, $adresseId, (string)$details['service_type']));

            // Load beleg links
            $belegGateway = $this->app->Container->get('RepairBelegGateway');
            $belege = $belegGateway->getByTicketId($ticketId);
            $this->app->Tpl->Set('REPAIR_BELEGE_ROWS', '<tr><td colspan="3">Keine verknuepften Belege.</td></tr>');
            if (!empty($belege)) {
                $belegHtml = '';
                foreach ($belege as $beleg) {
                    // Belegnummer und Status live aus der Belegtabelle: der Link
                    // entsteht beim Entwurf (belegnr leer), die Nummer erst bei
                    // Freigabe. Typ ist ein ENUM aus repair_ticket_beleg.
                    $belegNr = trim((string)($beleg['beleg_nr'] ?? ''));
                    $belegStatus = '';
                    if (in_array($beleg['beleg_typ'], ['angebot', 'auftrag', 'rechnung', 'lieferschein', 'gutschrift'], true)) {
                        $live = $this->app->DB->SelectRow(
                            "SELECT belegnr, status FROM `" . $beleg['beleg_typ'] . "` WHERE id = '" . (int)$beleg['beleg_id'] . "' LIMIT 1"
                        );
                        if (!empty($live)) {
                            if (trim((string)$live['belegnr']) !== '') {
                                $belegNr = trim((string)$live['belegnr']);
                            }
                            $belegStatus = (string)$live['status'];
                        }
                    }
                    $belegHtml .= '<tr>';
                    $belegHtml .= '<td>' . htmlspecialchars(ucfirst($beleg['beleg_typ'])) . '</td>';
                    $belegHtml .= '<td><a href="index.php?module=' . htmlspecialchars($beleg['beleg_typ'], ENT_QUOTES, 'UTF-8') . '&action=edit&id=' . (int)$beleg['beleg_id'] . '">' . htmlspecialchars($belegNr !== '' ? $belegNr : 'Entwurf #' . (int)$beleg['beleg_id']) . '</a>'
                        . ($belegStatus !== '' ? ' <small>(' . htmlspecialchars($belegStatus) . ')</small>' : '') . '</td>';
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

        $token = $this->app->Secure->GetPOST('repair_csrf');
        if (!is_string($token) || !$this->app->Container->get('Session')->isCsrfTokenValid('repair_beleg', $token)) {
            $this->repairPanelMessage = '<div class="error">Reparaturformular abgelaufen. Ticket neu laden.</div>';
            return $details;
        }
        $input = [];
        foreach (\Xentral\Modules\RepairIntegration\Service\RepairBelegService::SERVICE_FIELDS as $field) {
            if (isset($this->app->Secure->POST['repair_' . $field])) {
                $input[$field] = $this->app->Secure->GetPOST('repair_' . $field, 'nothtml', '', 1);
            }
        }
        try {
            $saved = $this->app->Container->get('RepairBelegService')->saveServiceFields($ticketId, $input);
            $details = array_merge($details, $saved);
        } catch (\Throwable $e) {
            $this->repairPanelMessage = '<div class="error">' . htmlspecialchars($e->getMessage()) . '</div>';
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

    private function repair_render_service_fields(array $details, int $ticketId): void
    {
        $tpl = $this->app->Tpl;
        $tpl->Set('REPAIR_CSRF', htmlspecialchars($this->app->Container->get('Session')->createCsrfToken('repair_beleg'), ENT_QUOTES, 'UTF-8'));
        $fieldDefaults = \Xentral\Modules\RepairIntegration\Service\RepairPositionTemplate::serviceFieldDefaults((string)($details['service_type'] ?? 'reparatur'));
        foreach (['public_diagnosis', 'service_work', 'final_check', 'position_net_amount'] as $field) {
            $value = $details[$field] ?? ($fieldDefaults[$field] ?? '');
            if ($field === 'position_net_amount' && $value !== '') $value = number_format((float)$value, 2, ',', '.');
            $tpl->Set('REPAIR_' . strtoupper($field), htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
        }
        $type = $details['service_type'] ?? 'reparatur';
        $serviceLabels = ['reparatur' => 'Reparatur', 'wartung' => 'Wartung', 'reverse_engineering' => 'Reverse Engineering', 'individualisierung' => 'Individualisierung'];
        $facts = ['Service' => $serviceLabels[$type] ?? $type, 'Hersteller' => $details['manufacturer'] ?? '',
            'Modell' => $details['model'] ?? '', 'Seriennummer' => $details['serial_number'] ?? ''];
        if ($type === 'reparatur') {
            $limit = (string)($details['cost_limit'] ?? '');
            $facts['Kostenfreigabe'] = $limit === 'consult_always' ? 'Vor jeder Reparatur Ruecksprache'
                : (is_numeric($limit) ? 'Bis ' . $limit . ' EUR, darueber Ruecksprache' : (in_array($limit, ['unlimited', 'no_limit'], true) ? 'Keine Begrenzung' : $limit));
            $facts['Garantie'] = ['yes' => 'Ja', 'no' => 'Nein'][$details['warranty_status'] ?? ''] ?? '';
        }
        $values = \Xentral\Modules\RepairIntegration\Service\RepairPositionTemplate::values($details, (string)($details['ticket_schluessel'] ?? $ticketId));
        if ($type === 'wartung') $facts['Wartungspaket'] = $values['wartungspaket'];
        if ($type === 'reverse_engineering') {
            foreach (['has_original_part' => 'Originalteil', 'has_templates' => 'Vorlagen', 're_tolerance' => 'Toleranz', 're_output_format' => 'Ausgabeformat'] as $key => $label) $facts[$label] = $details[$key] ?? '';
        }
        if ($type === 'individualisierung') {
            foreach (['has_3d_file' => '3D-Datei', 'material_preference' => 'Material', 'color_preference' => 'Farbe', 'functional_requirements' => 'Anforderungen'] as $key => $label) $facts[$label] = $details[$key] ?? '';
        }
        $facts['Express'] = !empty($details['is_express']) ? 'Ja' : 'Nein';
        $rows = '';
        foreach ($facts as $label => $value) {
            if ($value === null || trim((string)$value) === '') continue;
            $rows .= '<tr><td width="145"><strong>' . htmlspecialchars($label) . ':</strong></td><td>' . nl2br(htmlspecialchars((string)$value)) . '</td></tr>';
        }
        $tpl->Set('REPAIR_FORM_ROWS', $rows);
        $priceField = $type === 'wartung' ? 'wartung_paket_price_snapshot' : 'diagnosis_cost_snapshot';
        $price = $details[$priceField] ?? null;
        $priceHtml = '';
        if (in_array($type, ['reparatur', 'wartung'], true)) {
            $priceHtml = '<strong>' . ($type === 'wartung' ? 'Paketpreis laut Formular: ' : 'KVA-Preis laut Formular: ')
                . '</strong>' . ($price === null || $price === '' ? 'Nicht uebermittelt' : number_format((float)$price, 2, ',', '.') . ' EUR');
        }
        $tpl->Set('REPAIR_FORM_PRICE', $priceHtml);
        $tpl->Set('REPAIR_DESCRIPTION_LABEL', $type === 'wartung' ? 'Anmerkungen des Kunden:' : 'Fehlerbeschreibung / Anforderung:');
        $tpl->Set('REPAIR_DESCRIPTION', htmlspecialchars((string)($type === 'wartung' ? ($details['wartung_notes'] ?? '') : ($details['issue_description'] ?? ''))));
        $config = $this->app->Container->get('RepairConfigService');
        $number = $config->get('service_article_number');
        $article = $number !== '' ? $this->app->Container->get('Database')->fetchRow('SELECT nummer, name_de FROM artikel WHERE nummer = :number AND geloescht = 0 LIMIT 1', ['number' => $number]) : [];
        $tpl->Set('REPAIR_SERVICE_ARTICLE_NUMBER', htmlspecialchars((string)($article['nummer'] ?? '')));
        $tpl->Set('REPAIR_SERVICE_ARTICLE_NAME', htmlspecialchars((string)($article['name_de'] ?? 'Serviceartikel in Einstellungen hinterlegen')));
        $defaults = \Xentral\Modules\RepairIntegration\Service\RepairPositionTemplate::defaults();
        $tpl->Set('REPAIR_POSITION_TEMPLATE', htmlspecialchars($config->get('position_template_' . $type, $defaults[$type] ?? $defaults['reparatur'])));
        $tpl->Set('REPAIR_POSITION_VALUES', json_encode($values, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE));
        \Xentral\Components\Http\Session\SessionHandler::commitSession($this->app->Container->get('Session'));
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

    private function repair_beleg_buttons(int $ticketId, int $adresseId, string $serviceType): string
    {
        if ($adresseId <= 0) {
            return '<i>Erst Kundenkonto verkn&uuml;pfen</i>';
        }

        $typen = [
            'angebot' => 'Kostenvoranschlag/Angebot',
            'auftrag' => 'Auftrag',
            'rechnung' => 'Rechnung',
        ];

        if ($serviceType === 'reparatur') $typen['fee'] = 'Nur KVA abrechnen';
        $html = '';
        foreach ($typen as $typ => $label) {
            $html .= '<button type="submit" class="button" name="repair_beleg_typ" value="' . $typ
                . '" formaction="index.php?module=repairintegration&amp;action=createbeleg&amp;ticket=' . $ticketId
                . '" formmethod="post">' . $label . '</button> ';
        }

        return $html;
    }
}
