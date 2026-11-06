<?php
// www/pages/auftrag_custom.php
// RepairIntegration: Block "Reparaturtickets" in der Minidetail (unter Protokoll).
// OpenXE's loadModule() prefers *_custom.php over *.php automatically.

class AuftragCustom extends Auftrag
{
    public function AuftragMiniDetail($parsetarget = '', $menu = true)
    {
        if ($parsetarget !== '') {
            parent::AuftragMiniDetail($parsetarget, $menu);
            \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::append($this->app, 'auftrag', $parsetarget);
            return;
        }
        // Parent wuerde selbst ausgeben und beenden (Tpl->Output + ExitXentral)
        parent::AuftragMiniDetail(\Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET, $menu);
        \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::append($this->app, 'auftrag', \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET);
        \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::output($this->app, \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET);
    }
}
