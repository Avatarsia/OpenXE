<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Http\Session\SessionHandler;

/**
 * Block "Reparaturtickets" in der Minidetail eines Belegs (Bereich unter
 * "Protokoll"). Aufruf aus www/pages/{angebot,auftrag,lieferschein,rechnung,gutschrift}_custom.php.
 *
 * Die Minidetail-Methoden des Cores enden bei leerem $parsetarget mit
 * Tpl->Output() + ExitXentral(). Die Overrides rufen den Parent deshalb mit
 * einem eigenen Parse-Ziel auf, haengen den Block an und geben selbst aus.
 */
final class RepairBelegMiniDetailPanel
{
    public const PARSE_TARGET = 'REPAIR_MINIDETAIL';

    /** @param \ApplicationCore|\erpooSystem $app */
    public static function render($app, string $typ, int $belegId, string $kontoInput = ''): string
    {
        try {
            if ($belegId <= 0 || !RepairBelegChainResolver::isChainType($typ)
                || !$app->erp->RechteVorhanden('repairintegration', 'list')) {
                return '';
            }
            $db = $app->Container->get('Database');
            $migration = new \Xentral\Modules\RepairIntegration\Migration\RepairIntegrationMigration($db);
            if ($migration->needsInstall()) {
                return '';
            }

            $canEdit = $app->erp->RechteVorhanden('ticket', 'edit') && $app->erp->RechteVorhanden($typ, 'edit');
            $token = '';
            if ($canEdit) {
                $session = $app->Container->get('Session');
                $token = (string)$session->createCsrfToken(RepairBelegLinkRenderer::CSRF_KEY_BELEG);
                SessionHandler::commitSession($session);
            }

            $renderer = new RepairBelegLinkRenderer(
                $app->Container->get('RepairBelegGateway'),
                new RepairBelegChainResolver($db)
            );
            return $renderer->belegPanel($typ, $belegId, $kontoInput, $token, $canEdit);
        } catch (\Throwable $e) {
            error_log('RepairIntegration Minidetail-Panel (' . $typ . '): ' . $e->getMessage());
            return '';
        }
    }

    /** Haengt den Block an ein bereits geparstes Template-Ziel an. */
    public static function append($app, string $typ, string $parseTarget): void
    {
        $html = self::render($app, $typ, (int)$app->Secure->GetGET('id'));
        if ($html !== '') {
            $app->Tpl->Add($parseTarget, $html);
        }
    }

    /** Entspricht Tpl->Output() des Cores fuer ein Parse-Ziel, danach Exit. */
    public static function output($app, string $parseTarget): void
    {
        echo $app->erp->ClearDataBeforeOutput($app->Tpl->ParseTranslation($app->Tpl->Get($parseTarget)));
        $app->ExitXentral();
    }
}
