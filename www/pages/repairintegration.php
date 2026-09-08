<?php
// www/pages/repairintegration.php

class Repairintegration
{
    const MODULE_NAME = 'RepairIntegration';

    public $javascript = [
        './classes/Modules/RepairIntegration/www/js/repairintegration.js',
    ];

    public $stylesheet = [
        './classes/Modules/RepairIntegration/www/css/repairintegration.css',
    ];

    /** @var \ApplicationCore */
    public $app;

    function __construct($app, $intern = false)
    {
        $this->app = $app;
        if ($intern) return;

        // Schema/Cronjobs/Permissions idempotent sicherstellen,
        // bevor irgendeine Action laeuft. Schuetzt vor "Tabelle existiert nicht"-
        // Crashes wenn das Modul deployed wurde, aber install.php noch nie lief.
        $this->ensureInstalled();

        $this->app->ActionHandlerInit($this);
        $this->app->ActionHandler('list', 'RepairList');
        $this->app->ActionHandler('einstellungen', 'RepairSettings');
        $this->app->ActionHandler('merge', 'RepairMerge');
        $this->app->ActionHandler('syncstatus', 'SyncStatus');
        $this->app->ActionHandler('createbeleg', 'RepairCreateBeleg');
        $this->app->ActionHandler('createadresse', 'RepairCreateAdresse');
        $this->app->ActionHandler('install', 'Install');
        $this->app->ActionHandlerListen($app);
    }

    /**
     * Sorgt einmalig dafuer, dass alle DB-Tabellen, Cronjobs
     * und Permissions fuer das Modul existieren. Faengt
     * ausserdem bestehende Installationen ab, deren Schema-Version hinter
     * der ausgelieferten liegt (needsUpgrade).
     *
     * Performance: needsInstall()/needsUpgrade() lesen dieselbe systemconfig-
     * Zeile (sub-Millisekunde). Im aktuellen Normalfall ist die Methode no-op.
     *
     * Sicherheit: install.php ist DDL-only / Cronjob/Permission-Setup,
     * keine User-Daten. Alle Statements sind idempotent (CREATE TABLE IF NOT
     * EXISTS, INSERT mit Vorab-SELECT). Falls ein non-Admin die Page oeffnet,
     * leitet die Action-Methode anschliessend per redirectNoRights() um.
     */
    private function ensureInstalled(): void
    {
        try {
            $db = $this->app->Container->get('Database');
            $migration = new \Xentral\Modules\RepairIntegration\Migration\RepairIntegrationMigration($db);
            if (!$migration->needsInstall() && !$migration->needsUpgrade()) {
                return;
            }
        } catch (\Throwable $e) {
            // systemconfig fehlt evtl. noch -> install muss erst laufen
        }

        // install.php inkludieren, Output verwerfen (idempotent durch
        // needsInstall-Check und Vorab-SELECTs innerhalb des Scripts).
        //
        // Bewusst der volle Include statt nur RepairIntegrationMigration::
        // upgrade(): install.php registriert zusaetzlich Cronjobs
        // und Permissions. Da diese Methode nach einem
        // erfolgreichen Upgrade nie wieder etwas tut, ist der Upgrade-Lauf die
        // einzige Gelegenheit, in der eine neue Schema-Version auch neue
        // Cronjobs nachziehen kann.
        $app = $this->app;
        ob_start();
        try {
            include __DIR__ . '/../../classes/Modules/RepairIntegration/install.php';
        } catch (\Throwable $e) {
            // Nicht still verschlucken: schlaegt das Upgrade fehl, bleibt die
            // Schema-Version zurueck und dieser Block laeuft bei JEDEM
            // Seitenaufruf erneut. Ohne Log-Zeile haette der Betreiber davon
            // kein Signal. Danach laeuft die Seite weiter (ggf. auf altem
            // Schema) - eine fehlerhafte Action-Page zeigt einen klareren
            // Fehler als ein Auto-Install-Crash mitten im Header.
            error_log('RepairIntegration install/upgrade failed: ' . $e->getMessage());
        }
        ob_get_clean();
    }

    /**
     * Leitet bei fehlenden Rechten auf die Info-Seite um (Core-Muster,
     * vgl. welcome.php). erp->NoRights() existiert nicht - die frueheren
     * Aufrufe waeren als Fatal gelaufen, sobald sie erreicht worden waeren.
     */
    private function redirectNoRights(): void
    {
        $msg = $this->app->erp->base64_url_encode(
            '<div class="error">Keine Berechtigung.</div>'
        );
        $this->app->Location->execute('index.php?module=welcome&action=info&msg=' . $msg);
    }

    private function RepairMenu()
    {
        $this->app->erp->MenuEintrag('index.php?module=repairintegration&action=list', '&Uuml;bersicht');
        $this->app->erp->MenuEintrag('index.php?module=repairintegration&action=merge', 'Tickets mergen');
        $this->app->erp->MenuEintrag('index.php?module=repairintegration&action=syncstatus', 'Sync-Status');
        $this->app->erp->MenuEintrag('index.php?module=repairintegration&action=einstellungen', 'Einstellungen');
    }

    function RepairList()
    {
        if (!$this->app->erp->RechteVorhanden('repairintegration', 'list')) {
            $this->redirectNoRights();
            return;
        }

        $this->RepairMenu();

        // Status-Filterleiste: eine Checkbox pro aktivem Status aus
        // ticket_status_config. Abgewaehlte Stati werden als kommaseparierte
        // Liste in more_data1 transportiert und serverseitig per NOT IN
        // ausgefiltert (ein Slot reicht, die YUI-Toggle-Helper decken nur
        // boolsche Einzelfilter ab).
        $this->renderStatusFilter();

        $this->app->Tpl->Set('KURZUEBERSCHRIFT', 'Reparaturen');
        $this->app->YUI->TableSearch('TAB1', 'repair_list', 'show', '', '', basename(__FILE__), __CLASS__);

        // Initiale Belegung NACH dem TableSearch-Aufruf setzen (dort werden
        // die oMoreData*-Variablen deklariert): terminale Stati (z.B.
        // abgeschlossen) sind per Default ausgeblendet.
        $hiddenDefault = implode(',', $this->getDefaultHiddenStatuses());
        $this->app->Tpl->Add('JAVASCRIPT', "oMoreData1repair_list = '" . $hiddenDefault . "';");
        $this->app->Tpl->Add('JQUERYREADY',
            "$('.repair-status-filter').change(function() {
                var hidden = [];
                $('.repair-status-filter').each(function() {
                    if (!$(this).prop('checked')) { hidden.push($(this).data('slug')); }
                });
                oMoreData1repair_list = hidden.join(',');
                $('#repair_list').dataTable().fnFilter('', 0, 0, 0);
            });"
        );

        $this->app->Tpl->Parse('PAGE', 'repair_list.tpl');
    }

    /**
     * Rendert die Checkbox-Leiste ueber der Liste. Liest die aktiven Stati
     * aus ticket_status_config; faellt die Tabelle weg (Modul nicht
     * installiert), bleibt die Leiste einfach leer.
     */
    private function renderStatusFilter(): void
    {
        try {
            $db = $this->app->Container->get('Database');
            $statuses = $db->fetchAll(
                'SELECT slug, label_de, is_terminal, category FROM `ticket_status_config`
                 WHERE is_active = 1 ORDER BY category, sort_order, id'
            );
        } catch (\Throwable $e) {
            $this->app->Tpl->Set('STATUSFILTER', '');
            return;
        }

        // Nach category gruppieren, damit die Leiste bei vielen Stati
        // lesbar bleibt. Reihenfolge der Gruppen bewusst festlegen.
        $categoryLabels = array(
            'general' => 'Allgemein',
            'repair' => 'Reparatur',
            'maintenance' => 'Wartung',
            'reverse_engineering' => 'Reverse Engineering',
            'individualization' => 'Individualisierung',
        );

        $grouped = array();
        foreach ($statuses as $status) {
            $slug = (string)$status['slug'];
            if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
                continue;
            }
            $category = (string)($status['category'] ?? 'general');
            $grouped[$category][] = $status;
        }

        $hiddenDefault = $this->getDefaultHiddenStatuses($statuses);
        $groupsHtml = array();
        foreach ($categoryLabels as $category => $label) {
            if (empty($grouped[$category])) {
                continue;
            }
            $group = '<span class="filter-group"><strong>' . $label . ':</strong> ';
            foreach ($grouped[$category] as $status) {
                $slug = (string)$status['slug'];
                $checked = in_array($slug, $hiddenDefault, true) ? '' : ' checked';
                $group .= '<label><input type="checkbox" class="repair-status-filter" data-slug="'
                    . $slug . '"' . $checked . '> '
                    . htmlspecialchars((string)$status['label_de']) . '</label> ';
            }
            $group .= '</span>';
            $groupsHtml[] = $group;
        }
        // Unbekannte/zukuenftige Kategorien nicht verschlucken
        foreach ($grouped as $category => $items) {
            if (isset($categoryLabels[$category])) {
                continue;
            }
            $group = '<span class="filter-group"><strong>' . htmlspecialchars(ucfirst($category)) . ':</strong> ';
            foreach ($items as $status) {
                $slug = (string)$status['slug'];
                $checked = in_array($slug, $hiddenDefault, true) ? '' : ' checked';
                $group .= '<label><input type="checkbox" class="repair-status-filter" data-slug="'
                    . $slug . '"' . $checked . '> '
                    . htmlspecialchars((string)$status['label_de']) . '</label> ';
            }
            $group .= '</span>';
            $groupsHtml[] = $group;
        }

        $this->app->Tpl->Set(
            'STATUSFILTER',
            '<div id="repair-status-filter">' . implode('', $groupsHtml) . '</div>'
        );
    }

    /**
     * Stati, die beim ersten Laden ausgeblendet werden: alle als terminal
     * markierten (z.B. abgeschlossen). Fallback auf 'abgeschlossen', falls
     * kein Status das Flag traegt.
     */
    private function getDefaultHiddenStatuses(?array $statuses = null): array
    {
        if ($statuses === null) {
            try {
                $db = $this->app->Container->get('Database');
                $statuses = $db->fetchAll(
                    'SELECT slug, is_terminal FROM `ticket_status_config` WHERE is_active = 1'
                );
            } catch (\Throwable $e) {
                return array('abgeschlossen');
            }
        }

        $hidden = array();
        foreach ($statuses as $status) {
            if ((int)$status['is_terminal'] === 1
                && preg_match('/^[a-z0-9_]+$/', (string)$status['slug'])) {
                $hidden[] = (string)$status['slug'];
            }
        }

        return $hidden !== array() ? $hidden : array('abgeschlossen');
    }

    function RepairSettings()
    {
        if (!$this->app->erp->RechteVorhanden('repairintegration', 'einstellungen')) {
            $this->redirectNoRights();
            return;
        }

        $this->RepairMenu();

        /** @var \Xentral\Modules\RepairIntegration\Service\RepairConfigService */
        $config = $this->app->Container->get('RepairConfigService');

        // Meldung aus dem Redirect nach dem Generieren (siehe unten).
        $incomingMsg = $this->app->erp->base64_url_decode((string)$this->app->Secure->GetGET('msg'));
        if ($incomingMsg !== '') {
            $this->app->Tpl->Set('MESSAGE', $incomingMsg);
        }

        if ($this->app->Secure->GetPOST('submit') === 'save') {
            $config->set('enabled', $this->app->Secure->GetPOST('enabled') ? '1' : '0');
            $config->set('wp_api_url', $this->app->Secure->GetPOST('wp_api_url'));
            $config->set('wp_api_key', $this->app->Secure->GetPOST('wp_api_key'));
            $config->set('inbound_shared_secret', $this->app->Secure->GetPOST('inbound_shared_secret'));
            $config->set('max_retries', $this->app->Secure->GetPOST('max_retries'));
            $config->set('retention_anonymize_years', $this->app->Secure->GetPOST('retention_anonymize_years'));
            $config->set('notify_on_permanent_fail', $this->app->Secure->GetPOST('notify_on_permanent_fail'));
            $this->app->Tpl->Set('MESSAGE', '<div class="info">Einstellungen gespeichert.</div>');
        }

        // Post/Redirect/Get: nach dem Generieren umleiten, damit ein Reload
        // (F5) den Schluessel nicht erneut rotiert. Das #tabs-2-Fragment
        // laesst jQuery UI Tabs den Verbindungs-Tab wieder oeffnen.
        $submit = $this->app->Secure->GetPOST('submit');
        if ($submit === 'generate_inbound_secret' || $submit === 'generate_wp_api_key') {
            $configKey = $submit === 'generate_inbound_secret' ? 'inbound_shared_secret' : 'wp_api_key';
            $generated = false;
            try {
                $config->set($configKey, bin2hex(random_bytes(32)));
                $generated = true;
            } catch (\Throwable $e) {
                $this->app->Tpl->Set(
                    'MESSAGE',
                    '<div class="error">Generierung fehlgeschlagen: ' . htmlspecialchars($e->getMessage()) . '</div>'
                );
            }
            // Redirect bewusst ausserhalb des try: ein Fehlschlag rendert
            // direkt weiter, ein Erfolg verlaesst die Methode sofort.
            if ($generated) {
                $msg = $this->app->erp->base64_url_encode(
                    '<div class="info">Neuer Schluessel generiert. Wert ins WordPress-Plugin uebernehmen.</div>'
                );
                $this->app->Location->execute(
                    'index.php?module=repairintegration&action=einstellungen&msg=' . $msg . '#tabs-2'
                );
                return;
            }
        }

        // Kein Post/Redirect/Get: der Verbindungstest ist idempotent und soll
        // per F5 wiederholbar sein, das Ergebnis wird direkt gerendert.
        if ($submit === 'test_wp_connection') {
            try {
                /** @var \Xentral\Modules\RepairIntegration\Service\RepairSyncService $syncService */
                $syncService = $this->app->Container->get('RepairSyncService');
                $this->app->Tpl->Set('MESSAGE', $this->renderConnectionTestResult($syncService->testConnection()));
            } catch (\Throwable $e) {
                $this->app->Tpl->Set(
                    'MESSAGE',
                    '<div class="error">Verbindungstest fehlgeschlagen: '
                    . htmlspecialchars($e->getMessage()) . '</div>'
                );
            }
        }

        $this->app->Tpl->Set('ENABLED', $config->isEnabled() ? ' checked' : '');
        $this->app->Tpl->Set('WP_API_URL', htmlspecialchars($config->getWpApiUrl()));
        $this->app->Tpl->Set('WP_API_KEY', htmlspecialchars($config->getWpApiKey()));
        $this->app->Tpl->Set('INBOUND_SHARED_SECRET', htmlspecialchars($config->getInboundSharedSecret()));
        $this->app->Tpl->Set('MAX_RETRIES', htmlspecialchars((string)$config->getMaxRetries()));
        $this->app->Tpl->Set('RETENTION_YEARS', htmlspecialchars((string)$config->getRetentionAnonymizeYears()));
        $this->app->Tpl->Set('NOTIFY_EMAIL', htmlspecialchars($config->getNotifyOnPermanentFailEmail()));

        $this->app->Tpl->Set(
            'ENDPOINT_URL',
            htmlspecialchars(\Xentral\Modules\RepairIntegration\Service\RepairConnectionInfo::endpointUrl($_SERVER))
        );
        $inboundSecret = $config->getInboundSharedSecret();
        $wpApiKey = $config->getWpApiKey();
        $this->app->Tpl->Set('GEN_INBOUND_LABEL', $inboundSecret === '' ? 'Generieren' : 'Neu generieren');
        $this->app->Tpl->Set('GEN_INBOUND_CONFIRM', $inboundSecret === '' ? '' : ' data-confirm="1"');
        $this->app->Tpl->Set('GEN_WPKEY_LABEL', $wpApiKey === '' ? 'Generieren' : 'Neu generieren');
        $this->app->Tpl->Set('GEN_WPKEY_CONFIRM', $wpApiKey === '' ? '' : ' data-confirm="1"');

        $this->app->Tpl->Set('KURZUEBERSCHRIFT', 'RepairIntegration Einstellungen');
        $this->app->Tpl->Parse('PAGE', 'repair_config.tpl');
    }

    /**
     * Rendert das Rohergebnis von RepairSyncService::testConnection() als
     * MESSAGE-Div. Bewusst ohne Interpretation: HTTP-Code und Antwort-Anfang
     * werden unveraendert gezeigt, damit z.B. ein 404 einer alten
     * Plugin-Version sofort erkennbar ist.
     *
     * @param array{http_code: int|null, body: string, error: string|null} $result
     */
    private function renderConnectionTestResult(array $result): string
    {
        $httpCode = $result['http_code'];

        if ($httpCode === null) {
            $detail = 'kein HTTP-Status erreicht. Transportfehler: '
                . htmlspecialchars((string)($result['error'] ?? 'unbekannt'));
        } else {
            $detail = 'HTTP-Code ' . $httpCode;
            // ENT_SUBSTITUTE: der Body kommt von aussen und kann durch das
            // Kuerzen auf 300 Bytes ein UTF-8-Zeichen zerschneiden.
            $body = substr($result['body'], 0, 300);
            if ($body !== '') {
                $detail .= '<br><code>'
                    . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            }
        }

        return '<div class="' . ($httpCode === 200 ? 'info' : 'error') . '">'
            . 'Verbindungstest zu WordPress: ' . $detail . '</div>';
    }

    function Install()
    {
        // Henne-Ei: solange das Modul nicht installiert ist, existieren keine
        // userrights-Eintraege, daher hier kein RechteVorhanden-Check.
        // Nur Admin-User duerfen den Installer triggern.
        if ($this->app->User->GetType() !== 'admin') {
            $this->redirectNoRights();
            return;
        }

        $app = $this->app;
        $output = '';

        ob_start();
        try {
            include __DIR__ . '/../../classes/Modules/RepairIntegration/install.php';
        } catch (\Throwable $e) {
            echo "\nFEHLER: " . $e->getMessage() . "\n";
        }
        $output = ob_get_clean();

        $this->app->Tpl->Set('KURZUEBERSCHRIFT', 'RepairIntegration Installation');
        $this->app->Tpl->Set(
            'TAB1',
            '<pre>' . htmlspecialchars($output) . '</pre>'
            . '<p><a href="index.php?module=repairintegration&amp;action=einstellungen">Zurueck zu den Einstellungen</a></p>'
        );
        $this->app->Tpl->Parse('PAGE', 'tabview.tpl');
    }

    function RepairMerge()
    {
        if (!$this->app->erp->RechteVorhanden('repairintegration', 'merge')) {
            $this->redirectNoRights();
            return;
        }

        $this->RepairMenu();

        $sourceId = (int)$this->app->Secure->GetPOST('source');
        $targetId = (int)$this->app->Secure->GetPOST('target');

        if ($sourceId > 0 && $targetId > 0 && $this->app->Secure->GetPOST('confirm') === '1') {
            $mergeService = $this->app->Container->get('RepairTicketMergeService');
            try {
                $result = $mergeService->mergeTickets($sourceId, $targetId);
                $this->app->Location->execute(
                    'index.php?module=ticket&action=edit&id=' . $targetId
                    . '&msg=' . $this->app->erp->base64_url_encode('<div class="info">Tickets zusammengefuehrt.</div>')
                );
            } catch (\Exception $e) {
                $this->app->Tpl->Set('MESSAGE', '<div class="error">' . htmlspecialchars($e->getMessage()) . '</div>');
            }
        }

        $this->app->Tpl->Set('KURZUEBERSCHRIFT', 'Tickets zusammenfuehren');
        $this->app->Tpl->Parse('PAGE', 'repair_merge.tpl');
    }

    function SyncStatus()
    {
        if (!$this->app->erp->RechteVorhanden('repairintegration', 'syncstatus')) {
            $this->redirectNoRights();
            return;
        }

        $this->RepairMenu();

        $syncService = $this->app->Container->get('RepairSyncService');
        $stats = $syncService->getQueueStatus();

        $this->app->Tpl->Set('SYNC_PENDING', (string)$stats['pending']);
        $this->app->Tpl->Set('SYNC_FAILED', (string)$stats['failed']);
        $this->app->Tpl->Set('SYNC_PERMANENT', (string)$stats['permanently_failed']);
        $this->app->Tpl->Set('SYNC_COMPLETED', (string)$stats['completed']);
        $this->app->Tpl->Set('SYNC_LAST', $stats['last_successful_sync'] ?? 'Nie');

        $this->app->Tpl->Set('KURZUEBERSCHRIFT', 'Sync-Status');
        $this->app->Tpl->Set('TAB1', '<table class="mkTableFormular">
            <tr><td width="200">Pending:</td><td>' . (string)$stats['pending'] . '</td></tr>
            <tr><td>Failed:</td><td>' . (string)$stats['failed'] . '</td></tr>
            <tr><td>Permanently Failed:</td><td>' . (string)$stats['permanently_failed'] . '</td></tr>
            <tr><td>Completed:</td><td>' . (string)$stats['completed'] . '</td></tr>
            <tr><td>Letzter erfolgreicher Sync:</td><td>' . htmlspecialchars($stats['last_successful_sync'] ?? 'Nie') . '</td></tr>
        </table>');
        $this->app->Tpl->Parse('PAGE', 'tabview.tpl');
    }

    /**
     * Leitet zurueck auf das Ticket und uebergibt eine Meldung base64-kodiert
     * per GET. ticket_edit (www/pages/ticket.php) dekodiert den msg-Parameter
     * und rendert ihn als MESSAGE.
     */
    private function redirectToTicket(int $ticketId, string $cssClass, string $text): void
    {
        $msg = $this->app->erp->base64_url_encode(
            '<div class="' . $cssClass . '">' . htmlspecialchars($text) . '</div>'
        );
        $this->app->Location->execute(
            'index.php?module=ticket&action=edit&id=' . $ticketId . '&msg=' . $msg
        );
    }

    /**
     * Vergibt eine Kundennummer, falls die Adresse noch keine hat. Per API
     * angelegte Adressen bleiben zunaechst ohne Nummer (der Standalone-Endpoint
     * hat keinen Zugriff auf den Nummernkreis) - spaetestens beim ersten
     * Beleg aus dem Web-Kontext wird sie hier nachgezogen.
     */
    private function ensureKundennummer(int $adresseId): void
    {
        if ($adresseId <= 0) {
            return;
        }
        $kundennummer = (string)$this->app->DB->Select(
            'SELECT kundennummer FROM adresse WHERE id = ' . $adresseId . ' LIMIT 1'
        );
        if ($kundennummer !== '') {
            return;
        }
        $neu = (string)$this->app->erp->GetNextKundennummer();
        if ($neu !== '') {
            $this->app->DB->Update(
                "UPDATE adresse SET kundennummer = '"
                . $this->app->DB->real_escape_string($neu)
                . "' WHERE id = " . $adresseId . " LIMIT 1"
            );
        }
    }

    /**
     * Legt aus einem Reparatur-Ticket heraus einen leeren Beleg an
     * (Angebot/Auftrag/Rechnung), setzt Adresse und Betreff und verknuepft ihn
     * mit dem Ticket. Positionen werden bewusst nicht befuellt - die
     * Bearbeitung findet anschliessend im jeweiligen Beleg-Modul statt.
     */
    function RepairCreateBeleg()
    {
        $ticketId = (int)$this->app->Secure->GetGET('ticket');
        $typ = (string)$this->app->Secure->GetGET('typ');

        if (!$this->app->erp->RechteVorhanden('repairintegration', 'list')) {
            $this->redirectToTicket($ticketId, 'error', 'Keine Berechtigung.');
            return;
        }

        // Whitelist vor dem zweiten Rechtecheck: $typ wird weiter unten als
        // Tabellenname im SQL und als Modulname in der Redirect-URL verwendet.
        if (!in_array($typ, array('angebot', 'auftrag', 'rechnung'), true)) {
            $this->redirectToTicket($ticketId, 'error', 'Unbekannte Belegart.');
            return;
        }

        if (!$this->app->erp->RechteVorhanden($typ, 'edit')) {
            $this->redirectToTicket($ticketId, 'error', 'Keine Berechtigung fuer Belegart ' . $typ . '.');
            return;
        }

        // Idempotenz: existiert fuer das Ticket bereits ein Beleg desselben
        // Typs, direkt dorthin weiterleiten, statt bei Reload/Doppelklick
        // einen zweiten Beleg anzulegen.
        $belegGateway = $this->app->Container->get('RepairBelegGateway');
        foreach ($belegGateway->getByTicketId($ticketId) as $bestehenderBeleg) {
            if ($bestehenderBeleg['beleg_typ'] === $typ && (int)$bestehenderBeleg['beleg_id'] > 0) {
                $this->app->Location->execute(
                    'index.php?module=' . $typ . '&action=edit&id=' . (int)$bestehenderBeleg['beleg_id']
                );
                return;
            }
        }

        /** @var \Xentral\Modules\RepairIntegration\Service\RepairBelegService $belegService */
        $belegService = $this->app->Container->get('RepairBelegService');
        try {
            $prepared = $belegService->prepareBelegCreation($ticketId, $typ);
        } catch (\Throwable $e) {
            // Haeufigster Fall: RepairIntegrationException, weil am Ticket
            // keine Adresse haengt. Dann erst action=createadresse aufrufen.
            $this->redirectToTicket($ticketId, 'error', $e->getMessage());
            return;
        }

        $adresseId = (int)$prepared['adresse_id'];
        $this->ensureKundennummer($adresseId);

        // Gleiches Muster wie AdresseCreateDokument in www/pages/adresse.php:
        // Beleg mit Adresse anlegen, danach die Standardwerte der Adresse
        // (Zahlungsziel, Steuersaetze, Lieferadresse ...) nachziehen.
        switch ($typ) {
            case 'angebot':
                $belegId = (int)$this->app->erp->CreateAngebot($adresseId);
                if ($belegId > 0) {
                    $this->app->erp->LoadAngebotStandardwerte($belegId, $adresseId);
                }
                break;
            case 'auftrag':
                $belegId = (int)$this->app->erp->CreateAuftrag($adresseId);
                if ($belegId > 0) {
                    $this->app->erp->LoadAuftragStandardwerte($belegId, $adresseId);
                }
                break;
            default:
                $belegId = (int)$this->app->erp->CreateRechnung($adresseId);
                if ($belegId > 0) {
                    $this->app->erp->LoadRechnungStandardwerte($belegId, $adresseId);
                }
                break;
        }

        if ($belegId <= 0) {
            $this->redirectToTicket($ticketId, 'error', 'Beleg konnte nicht angelegt werden.');
            return;
        }

        $betreff = (string)$prepared['betreff'];
        if ($betreff !== '') {
            $this->app->DB->Update(
                "UPDATE `" . $typ . "` SET betreff = '"
                . $this->app->DB->real_escape_string($betreff)
                . "' WHERE id = " . $belegId . " LIMIT 1"
            );
        }

        // Die Belegnummer vergibt OpenXE erst bei der Freigabe, ein frisch
        // angelegter Beleg ist ein Entwurf mit leerer belegnr. Der Wert wird
        // trotzdem gelesen, damit die Verknuepfung korrekt ist, falls in der
        // Firmenkonfiguration eine Schnellfreigabe aktiv ist.
        $belegNr = (string)$this->app->DB->Select(
            "SELECT belegnr FROM `" . $typ . "` WHERE id = " . $belegId . " LIMIT 1"
        );

        try {
            $belegService->linkBelegToTicket(
                $ticketId,
                (string)$prepared['ticket_schluessel'],
                $typ,
                $belegId,
                $belegNr === '' ? null : $belegNr,
                (string)$this->app->User->GetName()
            );
        } catch (\Throwable $e) {
            // Der Beleg existiert an dieser Stelle bereits. Ein Fehler beim
            // Verknuepfen darf den Anwender nicht auf einen Fehlerbildschirm
            // werfen - die Verknuepfung laesst sich nachziehen, der Fehler
            // landet im Log.
            error_log('RepairIntegration linkBelegToTicket failed: ' . $e->getMessage());
        }

        $this->app->Location->execute('index.php?module=' . $typ . '&action=edit&id=' . $belegId);
    }

    /**
     * Verknuepft ein Reparatur-Ticket mit einem Kundenkonto: der Service sucht
     * per E-Mail nach einer bestehenden Adresse und legt sonst eine neue an.
     */
    function RepairCreateAdresse()
    {
        $ticketId = (int)$this->app->Secure->GetGET('ticket');

        if (!$this->app->erp->RechteVorhanden('repairintegration', 'list')
            || !$this->app->erp->RechteVorhanden('adresse', 'edit')) {
            $this->redirectToTicket($ticketId, 'error', 'Keine Berechtigung.');
            return;
        }

        try {
            /** @var \Xentral\Modules\RepairIntegration\Service\RepairAdresseService $adresseService */
            $adresseService = $this->app->Container->get('RepairAdresseService');
            // Leeres Array: der Service liest die Kundendaten selbst aus
            // ticket und ticket_repair_details.
            $adresseId = (int)$adresseService->ensureAdresseForTicket($ticketId, array());
        } catch (\Throwable $e) {
            $this->redirectToTicket($ticketId, 'error', $e->getMessage());
            return;
        }

        if ($adresseId <= 0) {
            $this->redirectToTicket($ticketId, 'error', 'Kundenkonto konnte nicht angelegt werden.');
            return;
        }

        $this->ensureKundennummer($adresseId);
        $this->redirectToTicket($ticketId, 'info', 'Kundenkonto verknuepft.');
    }

    function TableSearch(&$app, $name, $erlaubtevars)
    {
        switch ($name) {
            case 'repair_list':
                $allowed['repair_list'] = array('list');
                $heading = array('Ticket #', 'Typ', 'Hersteller/Modell', 'Kunde', 'Status', 'Express', 'Erstellt', 'Men&uuml;');
                $width = array('8%', '8%', '18%', '22%', '12%', '4%', '10%', '1%');

                // Klartext-Ausdruecke der beiden zusammengesetzten Spalten.
                // Sie werden im SELECT in einen Ticket-Link gewickelt, in
                // findcols aber roh verwendet: sonst wuerde die Sortierung auf
                // dem gemeinsamen '<a href=...'-Praefix statt auf dem Inhalt
                // arbeiten. Die Geraete-Spalte wird nur verlinkt, wenn
                // Hersteller oder Modell gefuellt ist (SQL-IF unten) - sonst
                // stuende ein Link auf ein einzelnes Leerzeichen in der Liste.
                $device = "CONCAT(COALESCE(rd.manufacturer,''), ' ', COALESCE(rd.model,''))";
                // Die Spitzklammern um die E-Mail bleiben als Entity kodiert -
                // rohe < > zerreissen das DOM der Liste.
                $customer = "CONCAT(COALESCE(t.kunde,''), ' &lt;', COALESCE(t.mailadresse,''), '&gt;')";
                $linkopen = "CONCAT('<a href=\"index.php?module=ticket&action=edit&id=',t.id,'\">',";

                $findcols = array('t.schluessel', 'rd.service_type', $device, $customer, 'status_label', 'rd.is_express', 't.zeit', 't.id');
                $searchsql = array('t.schluessel', 'rd.manufacturer', 'rd.model', 't.kunde', 't.mailadresse', 'rd.serial_number');

                $defaultorder = 7;
                $defaultorderdesc = 1;

                $menu = "<table><tr><td nowrap><a href=\"index.php?module=ticket&action=edit&id=%value%\"><img src=\"themes/new/images/edit.svg\" border=\"0\"></a></td></tr></table>";

                $sql = "SELECT SQL_CALC_FOUND_ROWS
                    t.id,
                    " . $linkopen . "t.schluessel,'</a>'),
                    rd.service_type,
                    IF(TRIM(" . $device . ") != '',
                        " . $linkopen . $device . ",'</a>'),
                        '') as device,
                    " . $linkopen . $customer . ",'</a>') as customer,
                    COALESCE(sc.label_de, t.status) as status_label,
                    IF(rd.is_express = 1, 'Ja', '') as is_express,
                    t.zeit,
                    t.id
                FROM ticket t
                INNER JOIN ticket_repair_details rd ON rd.ticket_id = t.id
                LEFT JOIN ticket_status_config sc ON sc.slug = t.status";

                // Status-Filterleiste (RepairList): more_data1 transportiert
                // die abgewaehlten Stati als CSV. Whitelist per Regex, dann
                // einzeln escaped -> NOT IN ist injection-sicher.
                $statusFilter = '';
                $hiddenRaw = (string)$this->app->Secure->GetGET('more_data1');
                if ($hiddenRaw !== '') {
                    $hidden = array();
                    foreach (explode(',', $hiddenRaw) as $slug) {
                        $slug = trim($slug);
                        if (preg_match('/^[a-z0-9_]+$/', $slug)) {
                            $hidden[] = $this->app->DB->real_escape_string($slug);
                        }
                    }
                    if ($hidden !== array()) {
                        $statusFilter = " AND t.status NOT IN ('" . implode("','", $hidden) . "')";
                    }
                }

                $where = "t.status != 'spam'" . $statusFilter;
                $count = "SELECT COUNT(t.id) FROM ticket t INNER JOIN ticket_repair_details rd ON rd.ticket_id = t.id WHERE t.status != 'spam'" . $statusFilter;

                $moreinfo = false;
                $menucol = 7;

                break;
        }

        $erg = [];
        foreach ($erlaubtevars as $k => $v) {
            if (isset($$v)) {
                $erg[$v] = $$v;
            }
        }
        return $erg;
    }
}
