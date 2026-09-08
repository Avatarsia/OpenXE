<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration;

use Xentral\Core\DependencyInjection\ContainerInterface;
use Xentral\Modules\RepairIntegration\Api\RepairApiAuth;
use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairSyncQueueGateway;
use Xentral\Modules\RepairIntegration\Service\RepairAdresseService;
use Xentral\Modules\RepairIntegration\Service\RepairBelegService;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;
use Xentral\Modules\RepairIntegration\Service\RepairEmailService;
use Xentral\Modules\RepairIntegration\Service\RepairStatusService;
use Xentral\Modules\RepairIntegration\Service\RepairSyncService;
use Xentral\Modules\RepairIntegration\Service\RepairTicketMergeService;

final class Bootstrap
{
    public static function registerServices(): array
    {
        return [
            'RepairDetailsGateway'      => 'onInitRepairDetailsGateway',
            'RepairStatusConfigGateway' => 'onInitRepairStatusConfigGateway',
            'RepairSyncQueueGateway'    => 'onInitRepairSyncQueueGateway',
            'RepairBelegGateway'        => 'onInitRepairBelegGateway',
            'RepairStatusService'       => 'onInitRepairStatusService',
            'RepairSyncService'         => 'onInitRepairSyncService',
            'RepairEmailService'        => 'onInitRepairEmailService',
            'RepairBelegService'        => 'onInitRepairBelegService',
            'RepairTicketMergeService'  => 'onInitRepairTicketMergeService',
            'RepairAdresseService'      => 'onInitRepairAdresseService',
            'RepairConfigService'       => 'onInitRepairConfigService',
            'RepairApiAuth'             => 'onInitRepairApiAuth',
            'RepairApiController'       => 'onInitRepairApiController',
        ];
    }

    public static function onInitRepairDetailsGateway(ContainerInterface $container): RepairDetailsGateway
    {
        return new RepairDetailsGateway($container->get('Database'));
    }

    public static function onInitRepairStatusConfigGateway(ContainerInterface $container): RepairStatusConfigGateway
    {
        return new RepairStatusConfigGateway($container->get('Database'));
    }

    public static function onInitRepairSyncQueueGateway(ContainerInterface $container): RepairSyncQueueGateway
    {
        return new RepairSyncQueueGateway($container->get('Database'));
    }

    public static function onInitRepairBelegGateway(ContainerInterface $container): RepairBelegGateway
    {
        return new RepairBelegGateway($container->get('Database'));
    }

    public static function onInitRepairStatusService(ContainerInterface $container): RepairStatusService
    {
        return new RepairStatusService(
            $container->get('Database'),
            $container->get('RepairStatusConfigGateway'),
            $container->get('RepairDetailsGateway'),
        );
    }

    public static function onInitRepairSyncService(ContainerInterface $container): RepairSyncService
    {
        return new RepairSyncService(
            $container->get('Database'),
            $container->get('RepairSyncQueueGateway'),
            $container->get('RepairStatusConfigGateway'),
            $container->get('RepairDetailsGateway'),
            $container->get('RepairConfigService'),
            // Benachrichtigung bei permanentem Sync-Fehler ueber die uebliche
            // Legacy-Mail-API; lazy, damit LegacyApplication erst im
            // Fehlerfall aufgeloest wird.
            static function (string $to, string $subject, string $text) use ($container): void {
                $erp = $container->get('LegacyApplication')->erp;
                $erp->MailSend($erp->GetFirmaMail(), $erp->GetFirmaAbsender(), $to, $to, $subject, $text);
            },
        );
    }

    public static function onInitRepairEmailService(ContainerInterface $container): RepairEmailService
    {
        return new RepairEmailService(
            $container->get('Database'),
            $container->get('RepairStatusConfigGateway'),
            $container->get('RepairDetailsGateway'),
        );
    }

    public static function onInitRepairBelegService(ContainerInterface $container): RepairBelegService
    {
        return new RepairBelegService(
            $container->get('Database'),
            $container->get('RepairDetailsGateway'),
            $container->get('RepairBelegGateway'),
        );
    }

    public static function onInitRepairTicketMergeService(ContainerInterface $container): RepairTicketMergeService
    {
        return new RepairTicketMergeService(
            $container->get('Database'),
            $container->get('RepairDetailsGateway'),
            $container->get('RepairSyncQueueGateway'),
            $container->get('RepairBelegGateway'),
        );
    }

    public static function onInitRepairAdresseService(ContainerInterface $container): RepairAdresseService
    {
        return new RepairAdresseService($container->get('Database'));
    }

    public static function onInitRepairConfigService(ContainerInterface $container): RepairConfigService
    {
        return new RepairConfigService($container->get('Database'));
    }

    public static function onInitRepairApiAuth(ContainerInterface $container): RepairApiAuth
    {
        return new RepairApiAuth();
    }

    public static function onInitRepairApiController(ContainerInterface $container): Api\RepairApiController
    {
        return new Api\RepairApiController(
            $container->get('Database'),
            $container->get('RepairApiAuth'),
            $container->get('RepairConfigService'),
            $container->get('RepairDetailsGateway'),
            $container->get('RepairStatusConfigGateway'),
            $container->get('RepairAdresseService'),
        );
    }
}
