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

        $this->app->ActionHandlerInit($this);
        $this->app->ActionHandler('list', 'RepairList');
        $this->app->ActionHandler('einstellungen', 'RepairSettings');
        $this->app->ActionHandler('merge', 'RepairMerge');
        $this->app->ActionHandler('syncstatus', 'SyncStatus');
        $this->app->ActionHandlerListen($app);
    }

    function RepairList()
    {
        if (!$this->app->erp->RechteVorhanden('repairintegration', 'list')) {
            $this->app->erp->NoRights();
            return;
        }

        $this->app->Tpl->Set('KURZUEBERSCHRIFT', 'Reparaturen');
        $this->app->YUI->TableSearch('TAB1', 'repair_list', 'show', '', '', basename(__FILE__), __CLASS__);
        $this->app->Tpl->Parse('PAGE', 'repair_list.tpl');
    }

    function RepairSettings()
    {
        if (!$this->app->erp->RechteVorhanden('repairintegration', 'einstellungen')) {
            $this->app->erp->NoRights();
            return;
        }

        /** @var \Xentral\Modules\RepairIntegration\Service\RepairConfigService */
        $config = $this->app->Container->get('RepairConfigService');

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

        $this->app->Tpl->Set('ENABLED', $config->isEnabled() ? ' checked' : '');
        $this->app->Tpl->Set('WP_API_URL', htmlspecialchars($config->getWpApiUrl()));
        $this->app->Tpl->Set('WP_API_KEY', htmlspecialchars($config->getWpApiKey()));
        $this->app->Tpl->Set('INBOUND_SHARED_SECRET', htmlspecialchars($config->getInboundSharedSecret()));
        $this->app->Tpl->Set('MAX_RETRIES', htmlspecialchars((string)$config->getMaxRetries()));
        $this->app->Tpl->Set('RETENTION_YEARS', htmlspecialchars((string)$config->getRetentionAnonymizeYears()));
        $this->app->Tpl->Set('NOTIFY_EMAIL', htmlspecialchars($config->getNotifyOnPermanentFailEmail()));

        $this->app->Tpl->Set('KURZUEBERSCHRIFT', 'RepairIntegration Einstellungen');
        $this->app->Tpl->Parse('PAGE', 'repair_config.tpl');
    }

    function RepairMerge()
    {
        if (!$this->app->erp->RechteVorhanden('repairintegration', 'merge')) {
            $this->app->erp->NoRights();
            return;
        }

        $sourceId = (int)$this->app->Secure->GetPOST('source');
        $targetId = (int)$this->app->Secure->GetPOST('target');

        if ($sourceId > 0 && $targetId > 0 && $this->app->Secure->GetPOST('confirm') === '1') {
            $mergeService = $this->app->Container->get('RepairTicketMergeService');
            try {
                $result = $mergeService->mergeTickets($sourceId, $targetId);
                $this->app->Location("index.php?module=ticket&action=edit&id={$targetId}&msg=merged");
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
            $this->app->erp->NoRights();
            return;
        }

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

    function TableSearch(&$app, $name, $erlaubtevars)
    {
        switch ($name) {
            case 'repair_list':
                $allowed['repair_list'] = array('list');
                $heading = array('Ticket #', 'Typ', 'Kunde', 'Hersteller/Modell', 'Status', 'Express', 'Erstellt', 'Men&uuml;');
                $width = array('5%', '5%', '15%', '15%', '10%', '3%', '8%', '1%');
                $findcols = array('ticket_link', 'rd.service_type', 'customer', 'device', 'status_label', 'rd.is_express', 't.zeit');
                $searchsql = array('t.schluessel', 'rd.manufacturer', 'rd.model', 't.kunde', 't.mailadresse', 'rd.serial_number');

                $defaultorder = 6;
                $defaultorderdesc = 1;

                $menu = "<table><tr><td nowrap><a href=\"index.php?module=ticket&action=edit&id=%value%\"><img src=\"themes/new/images/edit.svg\" border=\"0\"></a></td></tr></table>";

                $sql = "SELECT SQL_CALC_FOUND_ROWS
                    t.id,
                    CONCAT('<a href=\"index.php?module=ticket&action=edit&id=', t.id, '\">', t.schluessel, '</a>') as ticket_link,
                    rd.service_type,
                    CONCAT(COALESCE(t.kunde,''), ' &lt;', COALESCE(t.mailadresse,''), '&gt;') as customer,
                    CONCAT(COALESCE(rd.manufacturer,''), ' ', COALESCE(rd.model,'')) as device,
                    COALESCE(sc.label_de, t.status) as status_label,
                    IF(rd.is_express = 1, '<span style=\"color:#FFB800;font-size:16px\">&#9733;</span> Ja', '') as is_express,
                    t.zeit,
                    t.id
                FROM ticket t
                INNER JOIN ticket_repair_details rd ON rd.ticket_id = t.id
                LEFT JOIN ticket_status_config sc ON sc.slug = t.status";

                $where = "t.status != 'spam'";
                $count = "SELECT COUNT(t.id) FROM ticket t INNER JOIN ticket_repair_details rd ON rd.ticket_id = t.id WHERE t.status != 'spam'";

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
