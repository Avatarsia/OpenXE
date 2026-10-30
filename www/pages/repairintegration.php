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
            "$('#repair-show-completed, #repair-status-select').change(function() {
                var selected = $('#repair-status-select').val();
                var terminal = $('#repair-status-select option:selected').data('terminal');
                if (this.id === 'repair-status-select' && terminal) $('#repair-show-completed').prop('checked', true);
                if (this.id === 'repair-show-completed' && !this.checked && terminal) { selected = ''; $('#repair-status-select').val(''); }
                var hidden = $('#repair-show-completed').prop('checked') ? [] : $('#repair-status-filter').data('hidden-default').split(',');
                if (selected) $('#repair-status-select option').each(function() { if (this.value && this.value !== selected) hidden.push(this.value); });
                oMoreData1repair_list = hidden.length ? hidden.join(',') : 'all';
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

        $options = '<option value="">Alle Status</option>';
        foreach ($statuses as $status) {
            if (!preg_match('/^[a-z0-9_]+$/', (string)$status['slug'])) continue;
            $options .= '<option value="' . $status['slug'] . '" data-terminal="' . (int)$status['is_terminal'] . '">'
                . htmlspecialchars((string)$status['label_de']) . '</option>';
        }
        $this->app->Tpl->Set('STATUSFILTER', '<div id="repair-status-filter" data-hidden-default="'
            . htmlspecialchars(implode(',', $this->getDefaultHiddenStatuses($statuses)), ENT_QUOTES, 'UTF-8')
            . '"><label>Status: <select id="repair-status-select">' . $options . '</select></label>'
            . '<label><input type="checkbox" id="repair-show-completed"> Erledigte anzeigen</label></div>');
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
        $this->renderPositionSettings($config);

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

    private function renderPositionSettings($config): void
    {
        $defaults = \Xentral\Modules\RepairIntegration\Service\RepairPositionTemplate::defaults();
        $db = $this->app->Container->get('Database');
        if ($this->app->Secure->GetPOST('submit') === 'save_position_settings') {
            try {
                if (!$this->app->Container->get('Session')->isCsrfTokenValid('repair_settings', (string)$this->app->Secure->GetPOST('repair_settings_csrf'))) {
                    throw new \RuntimeException('Formular abgelaufen. Einstellungen neu laden.');
                }
                $number = trim((string)$this->app->Secure->GetPOST('repair_service_article', 'nothtml', '', 1));
                $article = $db->fetchRow('SELECT nummer FROM artikel WHERE nummer = :number AND geloescht = 0 LIMIT 1', ['number' => $number]);
                if (!$article) throw new \RuntimeException('Bitte eine vorhandene, nicht geloeschte Artikelnummer auswaehlen.');
                $templates = [];
                foreach ($defaults as $key => $default) {
                    $value = $this->app->Secure->GetPOST('repair_template_' . $key, 'nothtml', '', 1);
                    if (!is_string($value)) throw new \RuntimeException('Ungueltige Produkttext-Vorlage.');
                    \Xentral\Modules\RepairIntegration\Service\RepairPositionTemplate::validate($value);
                    $templates[$key] = $value;
                }
                $db->beginTransaction();
                try {
                    $config->set('service_article_number', (string)$article['nummer']);
                    foreach ($templates as $key => $value) $config->set('position_template_' . $key, $value);
                    $db->commit();
                } catch (\Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }
                $this->app->Tpl->Set('POSITION_SETTINGS_MESSAGE', '<div class="info">Artikel und Produkttexte gespeichert.</div>');
            } catch (\Throwable $e) {
                $this->app->Tpl->Set('POSITION_SETTINGS_MESSAGE', '<div class="error">' . htmlspecialchars($e->getMessage()) . '</div>');
            }
        }
        $labels = ['reparatur' => 'Reparatur: Angebot / Auftrag', 'reparatur_rechnung' => 'Reparatur: Rechnung / Leistungsnachweis',
            'fee' => 'Keine Reparatur: nur KVA', 'wartung' => 'Wartung', 'reverse_engineering' => 'Reverse Engineering', 'individualisierung' => 'Individualisierung'];
        $options = ''; $editors = '';
        foreach ($defaults as $key => $default) {
            $value = $config->get('position_template_' . $key, $default);
            if ($this->app->Secure->GetPOST('submit') === 'save_position_settings') {
                $posted = $this->app->Secure->GetPOST('repair_template_' . $key, 'nothtml', '', 1);
                if (is_string($posted)) $value = $posted;
            }
            $options .= '<option value="' . $key . '">' . htmlspecialchars($labels[$key]) . '</option>';
            $editors .= '<textarea class="repair-template-editor" id="repair_template_' . $key . '" name="repair_template_' . $key
                . '" rows="16" data-repair-template="' . $key . '" aria-label="' . htmlspecialchars($labels[$key], ENT_QUOTES, 'UTF-8') . '"'
                . ($key !== 'reparatur' ? ' hidden' : '') . '>' . htmlspecialchars($value) . '</textarea>';
        }
        $variables = '';
        foreach (\Xentral\Modules\RepairIntegration\Service\RepairPositionTemplate::VARIABLES as $key => $label) {
            $variables .= '<button type="button" class="repair-variable button button-secondary" data-variable="' . $key
                . '" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">{' . $key . '}</button> ';
        }
        $this->app->YUI->AutoComplete('repair_service_article', 'artikelnummer');
        $this->app->Tpl->Set('REPAIR_SETTINGS_CSRF', htmlspecialchars($this->app->Container->get('Session')->createCsrfToken('repair_settings'), ENT_QUOTES, 'UTF-8'));
        $number = $config->get('service_article_number');
        if ($this->app->Secure->GetPOST('submit') === 'save_position_settings') $number = (string)$this->app->Secure->GetPOST('repair_service_article', 'nothtml', '', 1);
        $this->app->Tpl->Set('REPAIR_ARTICLE_NUMBER', htmlspecialchars($number, ENT_QUOTES, 'UTF-8'));
        $this->app->Tpl->Set('REPAIR_TEMPLATE_OPTIONS', $options);
        $this->app->Tpl->Set('REPAIR_TEMPLATE_EDITORS', $editors);
        $this->app->Tpl->Set('REPAIR_TEMPLATE_VARIABLES', $variables);
        \Xentral\Components\Http\Session\SessionHandler::commitSession($this->app->Container->get('Session'));
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

    private function renderExchangeStatus(): string
    {
        $service = $this->app->Container->get('RepairReconcileService');
        $config = $this->app->Container->get('RepairConfigService');
        $session = $this->app->Container->get('Session');
        $message = '';
        $job = (int)$this->app->Secure->GetPOST('exchange_job');
        $action = (string)$this->app->Secure->GetPOST('exchange_action');
        if ($action !== '') {
            try {
                if (!$session->isCsrfTokenValid('repair_exchange', (string)$this->app->Secure->GetPOST('exchange_csrf'))) {
                    throw new \RuntimeException('Formular abgelaufen. Seite neu laden.');
                }
                if ($action === 'configure') {
                    if (!$this->app->erp->RechteVorhanden('repairintegration', 'einstellungen')) throw new \RuntimeException('Keine Einstellungsberechtigung.');
                    $config->set('sync_v2_enabled', $this->app->Secure->GetPOST('exchange_enabled') ? '1' : '0');
                    $message = 'Status-/Bildsync-Einstellung gespeichert. Auch im WP-Plugin entsprechend einstellen.';
                } elseif ($action === 'preview' || $action === 'apply') {
                    $job = $service->start($action);
                    $service->tick($job, 3, 15);
                    $message = 'Abgleich gestartet. Der Bestand wird in fortsetzbaren Schritten geprueft.';
                } elseif ($action === 'view') {
                    // Show a previous run without starting or continuing a sync.
                } elseif ($action === 'continue') {
                    $service->tick($job, 10, 25);
                } elseif ($action === 'local' || $action === 'remote') {
                    $result = $service->resolve((string)$this->app->Secure->GetPOST('exchange_number'), $action);
                    $message = 'Statusentscheidung uebernommen: ' . $result['state'];
                } else { throw new \RuntimeException('Unbekannte Abgleichaktion.'); }
            } catch (\Throwable $e) { $message = $e->getMessage(); }
        }
        $token = $session->createCsrfToken('repair_exchange');
        \Xentral\Components\Http\Session\SessionHandler::commitSession($session);
        $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hidden = '<input type="hidden" name="exchange_csrf" value="' . $escape($token) . '">';
        $enabled = $service->enabled();
        $jobs = $service->jobs();
        if ($job === 0 && $jobs) $job = (int)$jobs[0]['id'];
        $stateLabels = [
            'running' => ['info', 'In Bearbeitung'], 'complete' => ['success', 'Abgeschlossen'],
            'attention' => ['warning', 'Prüfung erforderlich'], 'equal' => ['success', 'Gleicher Status'],
            'local_newer' => ['info', 'OpenXE-Status neuer'], 'remote_newer' => ['info', 'WP-/App-Status neuer'],
            'unclear' => ['warning', 'Statusentscheidung nötig'], 'unmapped' => ['warning', 'Statuszuordnung fehlt'],
            'unlinked' => ['warning', 'Verknüpfung fehlt'], 'waiting_creation' => ['info', 'Ticketanlage ausstehend'],
            'pending_images' => ['info', 'Bilder werden ergänzt'], 'error' => ['error', 'Fehler'],
        ];
        $badge = static function ($state) use ($stateLabels, $escape): string {
            [$tone, $label] = $stateLabels[$state] ?? ['neutral', $state];
            return '<span class="repair-sync-badge repair-sync-badge--' . $tone . '">' . $escape($label) . '</span>';
        };
        $statusLabels = ['new'=>'Neu', 'in_repair'=>'In Reparatur', 'in_reparatur'=>'In Reparatur',
            'repaired'=>'Repariert', 'returned'=>'Zurückgesendet', 'versendet'=>'Versendet'];
        $statusLabel = static fn($status) => $escape($statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)));
        $html = '<section class="repair-sync-section" aria-labelledby="repair-sync-title"><header class="repair-sync-header"><div>'
            . '<h2 id="repair-sync-title">Status und Reparaturbilder</h2>'
            . '<p>WordPress, OpenXE und App auf demselben Stand halten.</p></div>'
            . '<span class="repair-sync-badge repair-sync-badge--' . ($enabled ? 'success' : 'neutral') . '">'
            . ($enabled ? 'Sync aktiviert' : 'Sync deaktiviert') . '</span></header>';
        if ($message !== '') $html .= '<div class="info repair-sync-message" role="status">' . $escape($message) . '</div>';
        $html .= '<div class="repair-sync-rules"><span>Letzte echte Statusänderung zählt</span>'
            . '<span>Fehlende Bilder ergänzen</span><span>Unklare Altfälle zur Entscheidung</span></div>';
        $html .= '<details class="repair-sync-settings"' . (!$enabled ? ' open' : '') . '><summary>Sync-Einstellung</summary>'
            . '<form method="post" class="repair-sync-form">' . $hidden . '<input type="hidden" name="exchange_action" value="configure">'
            . '<label><input type="checkbox" name="exchange_enabled" value="1"' . ($config->get('sync_v2_enabled', '0') === '1' ? ' checked' : '')
            . '> Status- und Bildsync aktivieren</label><button type="submit">Einstellung speichern</button></form>'
            . '<p class="repair-sync-muted">Die Integration und der Status-/Bildsync müssen auch im WP-Plugin aktiviert sein.</p></details>';
        $html .= '<div class="repair-sync-actions"><div><h3>Bestand abgleichen</h3>'
            . '<p>Vorschau: Unterschiede prüfen. Abgleich: Status übernehmen und fehlende Bilder ergänzen.</p></div>'
            . '<form method="post" class="repair-sync-form">' . $hidden
            . '<button name="exchange_action" value="preview" type="submit"' . (!$enabled ? ' disabled' : '') . '>Vorschau erstellen</button>'
            . '<button name="exchange_action" value="apply" type="submit" class="btnGreen"' . (!$enabled ? ' disabled' : '') . '>Jetzt abgleichen</button></form></div>';
        if (!$enabled) $html .= '<p class="repair-sync-muted">Zum Prüfen und Abgleichen zuerst den Sync aktivieren.</p>';
        $html .= '</section>';
        if ($job > 0) {
            $html .= '<section class="repair-sync-section" aria-labelledby="repair-sync-results"><h3 id="repair-sync-results">Ergebnisse · Lauf #' . $job . '</h3>';
            foreach ($jobs as $row) {
                if ((int)$row['id'] !== $job) continue;
                $html .= '<div class="repair-sync-run-summary">' . $badge($row['state']) . '<span>'
                    . ($row['mode'] === 'preview' ? 'Vorschau' : 'Abgleich') . '</span><span><strong>' . (int)$row['checked']
                    . '</strong> Vorgänge geprüft</span><span><strong>' . (int)$row['issues'] . '</strong> offene Punkte</span>';
                if ($row['state'] === 'running' && $enabled) $html .= '<form method="post">' . $hidden . '<input type="hidden" name="exchange_job" value="' . $job
                    . '"><button name="exchange_action" value="continue">Lauf fortsetzen</button></form>';
                $html .= '</div>';
                if ($row['last_error']) $html .= '<div class="error">' . $escape($row['last_error']) . '</div>';
            }
            $results = $service->results($job);
            if (!$results) $html .= '<p class="repair-sync-empty">Für diesen Lauf liegen noch keine Ergebnisse vor.</p>';
            else {
                $html .= '<div class="repair-sync-table-scroll" tabindex="0" role="region" aria-label="Ergebnisse des Bestandsabgleichs"><table class="mkTable repair-sync-table"><thead><tr>'
                    . '<th scope="col">Vorgang</th><th scope="col">Stand bei der Prüfung</th><th scope="col">Status OpenXE / WP und App</th>'
                    . '<th scope="col">Fehlende Bilder</th><th scope="col">Hinweise / Entscheidung</th></tr></thead><tbody>';
                foreach ($results as $row) {
                    $r = $row['result'] ?? [];
                    $number = $escape($row['request_number']);
                    if (!empty($row['ticket_id'])) $number = '<a href="index.php?module=ticket&amp;action=edit&amp;id=' . (int)$row['ticket_id'] . '">' . $number . '</a>';
                    $html .= '<tr><td>' . $number . '</td><td>' . $badge($row['state']) . '</td><td>'
                        . '<div><span class="repair-sync-muted">OpenXE:</span> ' . $statusLabel($r['local_status'] ?? '—') . '</div>'
                        . '<div><span class="repair-sync-muted">WP / App:</span> ' . $statusLabel($r['remote_status'] ?? '—') . '</div></td><td>'
                        . '<div>In OpenXE: <strong>' . (int)($r['missing_local'] ?? 0) . '</strong></div><div>In WP / App: <strong>' . (int)($r['missing_remote'] ?? 0) . '</strong></div></td>'
                        . '<td class="repair-sync-note">' . $escape($r['error'] ?? implode('; ', $r['errors'] ?? []));
                    if (($r['status']['state'] ?? '') === 'unclear' && $enabled) {
                        $html .= '<form method="post" class="repair-sync-decisions">' . $hidden . '<input type="hidden" name="exchange_job" value="' . $job
                            . '"><input type="hidden" name="exchange_number" value="' . $escape($row['request_number']) . '">'
                            . '<button name="exchange_action" value="local">OpenXE-Status übernehmen</button>'
                            . '<button name="exchange_action" value="remote">WP-/App-Status übernehmen</button></form>';
                    }
                    $html .= '</td></tr>';
                }
                $html .= '</tbody></table></div>';
            }
            $html .= '</section>';
        } else $html .= '<div class="repair-sync-empty"><h3>Noch kein Bestandsabgleich</h3><p>Eine Vorschau zeigt Statusunterschiede und fehlende Bilder, ohne die Vorgänge zu ändern.</p></div>';
        if ($jobs) {
            $html .= '<details class="repair-sync-history"><summary>Letzte Abgleichläufe (' . count($jobs) . ')</summary>'
                . '<div class="repair-sync-table-scroll" tabindex="0" role="region" aria-label="Letzte Abgleichläufe"><table class="mkTable repair-sync-table"><thead><tr>'
                . '<th scope="col">Lauf</th><th scope="col">Gestartet</th><th scope="col">Modus</th><th scope="col">Stand</th><th scope="col">Geprüft</th><th scope="col">Offene Punkte</th><th scope="col">Aktion</th></tr></thead><tbody>';
            foreach ($jobs as $row) {
                $html .= '<tr' . ((int)$row['id'] === $job ? ' class="repair-sync-selected"' : '') . '><td>#' . (int)$row['id'] . '</td><td>' . $escape($row['created_at'])
                    . '</td><td>' . ($row['mode'] === 'preview' ? 'Vorschau' : 'Abgleich') . '</td><td>' . $badge($row['state']) . '</td><td>'
                    . (int)$row['checked'] . '</td><td>' . (int)$row['issues'] . '</td><td><form method="post">' . $hidden
                    . '<input type="hidden" name="exchange_job" value="' . (int)$row['id'] . '"><button name="exchange_action" value="view">Ergebnis anzeigen</button></form></td></tr>';
            }
            $html .= '</tbody></table></div></details>';
        }
        return $html;
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
        $technical = '<details class="repair-sync-technical"><summary>Technische Details · Übertragungswarteschlange</summary>'
            . '<dl class="repair-sync-queue"><div><dt>Ausstehend</dt><dd>' . (int)$stats['pending'] . '</dd></div>'
            . '<div><dt>Fehlgeschlagen</dt><dd>' . (int)$stats['failed'] . '</dd></div>'
            . '<div><dt>Dauerhaft fehlgeschlagen</dt><dd>' . (int)$stats['permanently_failed'] . '</dd></div>'
            . '<div><dt>Erfolgreich übertragen</dt><dd>' . (int)$stats['completed'] . '</dd></div>'
            . '<div><dt>Letzte erfolgreiche Übertragung</dt><dd>' . htmlspecialchars($stats['last_successful_sync'] ?? 'Noch keine', ENT_QUOTES, 'UTF-8') . '</dd></div></dl></details>';
        $this->app->Tpl->Set('TAB1', '<div class="repair-sync-page">' . $this->renderExchangeStatus() . $technical . '</div>');
        $this->app->Tpl->Parse('PAGE', 'tabview.tpl');
    }

    /**
     * Leitet zurueck auf das Ticket und uebergibt eine Meldung base64-kodiert
     * per GET. ticket_edit (www/pages/ticket.php) dekodiert den msg-Parameter
     * und rendert ihn als MESSAGE.
     */
    /**
     * Core-Hook DokumentMaskVersendet(typ, id), registriert via hook_register
     * (Migration 007). Der Core instanziiert die Klasse mit $intern = true,
     * daher keine Action-Handler und kein ensureInstalled() im Konstruktor.
     * Fehler duerfen den Belegversand nie abbrechen, nur ins Log.
     */
    public function RepairOnBelegVersendet($typ, $id): void
    {
        try {
            $hook = new \Xentral\Modules\RepairIntegration\Hook\BelegVersendetHook(
                $this->app->Container->get('Database'),
                $this->app->Container->get('RepairBelegGateway'),
                $this->app->Container->get('RepairDetailsGateway'),
                $this->app->Container->get('RepairStatusConfigGateway'),
                $this->app->Container->get('RepairSyncService'),
            );
            $hook->onBelegVersendet((string)$typ, (int)$id);
        } catch (\Throwable $e) {
            error_log(sprintf(
                'RepairIntegration RepairOnBelegVersendet(%s, %d) failed: %s',
                (string)$typ,
                (int)$id,
                $e->getMessage()
            ));
        }
    }

    public function RepairOnAngebotZuAuftrag($sourceId, $targetId): void
    {
        $this->repairLinkConvertedBeleg('angebot', (int)$sourceId, 'auftrag', (int)$targetId);
    }

    public function RepairOnAuftragZuRechnung($sourceId, $targetId): void
    {
        $this->repairLinkConvertedBeleg('auftrag', (int)$sourceId, 'rechnung', (int)$targetId);
    }

    private function repairLinkConvertedBeleg(string $sourceType, int $sourceId, string $targetType, int $targetId): void
    {
        try {
            $this->app->Container->get('RepairBelegService')->onBelegWeiterfuehren(
                $sourceType, $sourceId, $targetType, $targetId, null, (string)$this->app->User->GetName()
            );
        } catch (\Throwable $e) {
            error_log('RepairIntegration Belegverknuepfung fehlgeschlagen: ' . $e->getMessage());
        }
    }

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

    /** Creates a native draft including the configured repair article. */
    function RepairCreateBeleg()
    {
        $ticketId = (int)$this->app->Secure->GetGET('ticket');
        $type = (string)$this->app->Secure->GetPOST('repair_beleg_typ');
        $mode = $type === 'fee' ? 'fee' : 'service';
        if ($type === 'fee') $type = 'rechnung';
        if (!in_array($type, ['angebot', 'auftrag', 'rechnung'], true) || $ticketId <= 0) {
            $this->redirectToTicket($ticketId, 'error', 'Bitte Beleg ueber die Reparaturmaske anlegen.');
            return;
        }
        if (!$this->app->erp->RechteVorhanden('repairintegration', 'list')
            || !$this->app->erp->RechteVorhanden('ticket', 'edit')
            || !$this->app->erp->RechteVorhanden($type, 'edit')) {
            $this->redirectToTicket($ticketId, 'error', 'Keine Berechtigung.');
            return;
        }
        $token = $this->app->Secure->GetPOST('repair_csrf');
        if (!is_string($token) || !$this->app->Container->get('Session')->isCsrfTokenValid('repair_beleg', $token)) {
            $this->redirectToTicket($ticketId, 'error', 'Formular abgelaufen. Ticket neu laden.');
            return;
        }
        $db = $this->app->Container->get('Database');
        $lock = 'repair_beleg_' . $ticketId;
        $locked = false;
        $belegId = 0;
        try {
            $locked = (int)$db->fetchValue('SELECT GET_LOCK(:name, 10)', ['name' => $lock]) === 1;
            if (!$locked) throw new \RuntimeException('Beleg wird bereits angelegt. Bitte erneut versuchen.');
            foreach ($this->app->Container->get('RepairBelegGateway')->getByTicketId($ticketId) as $existing) {
                if ($existing['beleg_typ'] === $type && (int)$existing['beleg_id'] > 0) {
                    $existingId = (int)$existing['beleg_id'];
                    if ((int)$db->fetchValue("SELECT id FROM `$type` WHERE id = :id", ['id' => $existingId]) !== $existingId) {
                        $db->perform('DELETE FROM repair_ticket_beleg WHERE id = :id', ['id' => (int)$existing['id']]);
                        continue;
                    }
                    $belegId = $existingId;
                    break;
                }
            }
            if ($belegId === 0) {
                $service = $this->app->Container->get('RepairBelegService');
                $input = [];
                foreach (\Xentral\Modules\RepairIntegration\Service\RepairBelegService::SERVICE_FIELDS as $field) {
                    $post = 'repair_' . $field;
                    if (isset($this->app->Secure->POST[$post])) {
                        $input[$field] = $this->app->Secure->GetPOST($post, 'nothtml', '', 1);
                    }
                }
                $service->saveServiceFields($ticketId, $input);
                $prepared = $service->prepareBelegCreation($ticketId, $type, $mode);
                $this->ensureKundennummer((int)$prepared['adresse_id']);
                $belegId = \Xentral\Modules\RepairIntegration\Service\RepairDraftCreator::create(
                    $this->app, $prepared, (string)$this->app->User->GetName()
                );
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            if ($locked) $db->fetchValue('SELECT RELEASE_LOCK(:name)', ['name' => $lock]);
        }
        if (isset($error)) {
            $this->redirectToTicket($ticketId, 'error', $error);
            return;
        }
        $this->app->Location->execute('index.php?module=' . $type . '&action=edit&id=' . $belegId . '#tabs-2');
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
                if ($hiddenRaw === '') $hiddenRaw = implode(',', $this->getDefaultHiddenStatuses());
                if ($hiddenRaw === 'all') $hiddenRaw = '';
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
