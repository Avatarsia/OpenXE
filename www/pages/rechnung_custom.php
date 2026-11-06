<?php
// www/pages/rechnung_custom.php
// RepairIntegration: Block "Reparaturtickets" in der Minidetail (unter Protokoll).
// OpenXE's loadModule() prefers *_custom.php over *.php automatically.

class RechnungCustom extends Rechnung
{
    public function RechnungMiniDetail($parsetarget = '', $menu = true)
    {
        if ($parsetarget !== '') {
            parent::RechnungMiniDetail($parsetarget, $menu);
            \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::append($this->app, 'rechnung', $parsetarget);
            return;
        }
        // Parent wuerde selbst ausgeben und beenden (Tpl->Output + ExitXentral)
        parent::RechnungMiniDetail(\Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET, $menu);
        \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::append($this->app, 'rechnung', \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET);
        \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::output($this->app, \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET);
    }
}
