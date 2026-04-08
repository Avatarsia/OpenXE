<?php

declare(strict_types=1);

namespace Xentral\Modules\LexwareOffice;

use ApplicationCore;
use Xentral\Components\Database\Database;
use Xentral\Components\Logger\Logger;
use Xentral\Core\DependencyInjection\ContainerInterface;
use Xentral\Modules\LexwareOffice\Service\LexwareOfficeApiClient;
use Xentral\Modules\LexwareOffice\Service\LexwareOfficeConfigService;
use Xentral\Modules\LexwareOffice\Service\LexwareOfficePayloadMapper;
use Xentral\Modules\LexwareOffice\Service\LexwareOfficeService;
use Xentral\Modules\SystemConfig\SystemConfigModule;

final class Bootstrap
{
    /**
     * Service-Registrierung fuer den OpenXE-Container.
     *
     * Wird von Xentral\Core\Installer\Installer::getBootstrapFiles()
     * per Auto-Discovery gefunden und statisch aufgerufen.
     *
     * @return array<string, string>
     */
    public static function registerServices(): array
    {
        return [
            'LexwareOfficeConfigService'  => 'onInitLexwareOfficeConfigService',
            'LexwareOfficeApiClient'      => 'onInitLexwareOfficeApiClient',
            'LexwareOfficePayloadMapper'  => 'onInitLexwareOfficePayloadMapper',
            'LexwareOfficeService'        => 'onInitLexwareOfficeService',
        ];
    }

    public static function onInitLexwareOfficeConfigService(ContainerInterface $container): LexwareOfficeConfigService
    {
        return new LexwareOfficeConfigService(
            $container->get('SystemConfigModule')
        );
    }

    public static function onInitLexwareOfficeApiClient(ContainerInterface $container): LexwareOfficeApiClient
    {
        return new LexwareOfficeApiClient();
    }

    public static function onInitLexwareOfficePayloadMapper(ContainerInterface $container): LexwareOfficePayloadMapper
    {
        /** @var ApplicationCore $app */
        $app = $container->get('LegacyApplication');

        return new LexwareOfficePayloadMapper(
            $app->erp ?? null,
            $container->get('Logger')
        );
    }

    public static function onInitLexwareOfficeService(ContainerInterface $container): LexwareOfficeService
    {
        /** @var ApplicationCore $app */
        $app = $container->get('LegacyApplication');

        return new LexwareOfficeService(
            $container->get('Database'),
            $container->get('LexwareOfficeConfigService'),
            $container->get('LexwareOfficeApiClient'),
            $container->get('Logger'),
            $app->erp ?? null,
            $container->get('LexwareOfficePayloadMapper')
        );
    }
}
