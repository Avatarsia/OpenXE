<?php
// www/pages/lieferschein_custom.php
// RepairIntegration: Block "Reparaturtickets" in der Minidetail (unter Protokoll).
// OpenXE's loadModule() prefers *_custom.php over *.php automatically.

class LieferscheinCustom extends Lieferschein
{
    public function LieferscheinMiniDetail($parsetarget = '', $menu = true)
    {
        if ($parsetarget !== '') {
            parent::LieferscheinMiniDetail($parsetarget, $menu);
            \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::append($this->app, 'lieferschein', $parsetarget);
            return;
        }
        // Parent wuerde selbst ausgeben und beenden (Tpl->Output + ExitXentral)
        parent::LieferscheinMiniDetail(\Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET, $menu);
        \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::append($this->app, 'lieferschein', \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET);
        \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::output($this->app, \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET);
    }
}
