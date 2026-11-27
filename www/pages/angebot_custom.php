<?php
// www/pages/angebot_custom.php
// RepairIntegration: Block "Reparaturtickets" in der Minidetail (unter Protokoll).
// OpenXE's loadModule() prefers *_custom.php over *.php automatically.

class AngebotCustom extends Angebot
{
    public function AngebotMiniDetail($parsetarget = '', $menu = true)
    {
        if ($parsetarget !== '') {
            parent::AngebotMiniDetail($parsetarget, $menu);
            \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::append($this->app, 'angebot', $parsetarget);
            return;
        }
        // Parent wuerde selbst ausgeben und beenden (Tpl->Output + ExitXentral)
        parent::AngebotMiniDetail(\Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET, $menu);
        \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::append($this->app, 'angebot', \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET);
        \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::output($this->app, \Xentral\Modules\RepairIntegration\Service\RepairBelegMiniDetailPanel::PARSE_TARGET);
    }
}
