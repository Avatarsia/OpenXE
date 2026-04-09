<?php

declare(strict_types=1);

namespace Xentral\Modules\MenuConfigurator;

use Xentral\Core\DependencyInjection\ContainerInterface;
use Xentral\Modules\MenuConfigurator\Service\MenuConfiguratorService;

final class Bootstrap
{
    /**
     * @return array
     */
    public static function registerServices()
    {
        return [
            'MenuConfiguratorService' => 'onInitMenuConfiguratorService',
        ];
    }

    /**
     * @param ContainerInterface $container
     *
     * @return MenuConfiguratorService
     */
    public static function onInitMenuConfiguratorService(ContainerInterface $container)
    {
        return new MenuConfiguratorService(
            $container->get('UserConfigService')
        );
    }
}
