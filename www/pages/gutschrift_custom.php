<?php
// www/pages/gutschrift_custom.php
// RepairIntegration: Block "Reparaturtickets" in der Minidetail (unter Protokoll).
// OpenXE's loadModule() prefers *_custom.php over *.php automatically.

class GutschriftCustom extends Gutschrift
{
    public function GutschriftMiniDetail($parsetarget = '', $menu = true)
    {
        if ($parsetarget !== '') {
            parent::GutschriftMiniDetail($parsetarget, $menu);
            \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::append($this->app, 'gutschrift', $parsetarget);
            return;
        }
        // Parent wuerde selbst ausgeben und beenden (Tpl->Output + ExitXentral)
        parent::GutschriftMiniDetail(\Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET, $menu);
        \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::append($this->app, 'gutschrift', \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET);
        \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::output($this->app, \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET);
    }
}
