<?php

declare(strict_types=1);

namespace Xentral\Modules\MenuConfigurator\Service;

use Exception;
use Xentral\Modules\User\Service\UserConfigService;

/**
 * Service-Klasse fuer die Per-User Menu-Customization.
 *
 * Speichert die Konfiguration als JSON-String unter dem Key
 * "menu_configurator" im UserConfigService.
 *
 * Konfigurations-Struktur:
 *   [
 *     'applyCustomStructure' => bool,    // wenn true, eigene Sortierung anwenden
 *     'items' => [
 *       'first|<title>' => [
 *         'visible'     => bool,
 *         'order'       => float|null,
 *         'customTitle' => string|null,
 *       ],
 *       'sec|<firstTitle>|<module>|<action>|<secTitle>' => [...],
 *       ...
 *     ],
 *   ]
 */
final class MenuConfiguratorService
{
    public const CONFIG_KEY = 'menu_configurator';

    /** @var UserConfigService */
    private $userConfig;

    public function __construct(UserConfigService $userConfig)
    {
        $this->userConfig = $userConfig;
    }

    /**
     * Liefert den eindeutigen Schluessel fuer einen Menueintrag.
     *
     * @param string     $firstTitle Hauptmenu-Titel
     * @param array|null $secnav     Untermenu-Eintrag im Format [titel, modul, action]
     */
    public function getKey(string $firstTitle, ?array $secnav = null): string
    {
        if ($secnav === null) {
            return 'first|' . $firstTitle;
        }
        $secTitle = isset($secnav[0]) ? (string)$secnav[0] : '';
        $module = isset($secnav[1]) ? (string)$secnav[1] : '';
        $action = isset($secnav[2]) ? (string)$secnav[2] : '';

        return 'sec|' . $firstTitle . '|' . $module . '|' . $action . '|' . $secTitle;
    }

    /**
     * Default-Konfiguration (alles sichtbar, keine Sortierung).
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return ['items' => [], 'applyCustomStructure' => false];
    }

    /**
     * Laedt die User-Konfiguration aus dem UserConfigService.
     *
     * @return array gibt immer ein gueltiges Array zurueck
     */
    public function getConfig(int $userId): array
    {
        $default = $this->getDefaultConfig();
        if ($userId <= 0) {
            return $default;
        }
        try {
            $stored = $this->userConfig->tryGet(self::CONFIG_KEY, $userId);
        } catch (Exception $e) {
            return $default;
        }
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        if (!is_array($stored)) {
            return $default;
        }

        return array_merge($default, $stored);
    }

    /**
     * Persistiert die User-Konfiguration als JSON-String.
     */
    public function saveConfig(int $userId, array $config): void
    {
        if ($userId <= 0) {
            return;
        }
        $payload = array_merge($this->getDefaultConfig(), $config);
        $this->userConfig->set(self::CONFIG_KEY, json_encode($payload), $userId);
    }

    /**
     * Wendet die User-Konfiguration auf ein bereits berechnetes Menu-Array an.
     *
     * Entfernt unsichtbare Eintraege, ueberschreibt Custom-Titles und sortiert
     * (wenn applyCustomStructure true ist).
     *
     * @param array      $menu   Ergebnis von erpAPI::Navigation()
     * @param array|null $config wenn null, wird die Config fuer den uebergebenen User geladen
     */
    public function apply(array $menu, ?array $config = null, ?int $userId = null): array
    {
        if (empty($menu)) {
            return $menu;
        }
        if ($config === null) {
            $config = $this->getConfig($userId !== null ? $userId : 0);
        }
        $config = array_merge($this->getDefaultConfig(), $config);
        $configItems = is_array($config['items']) ? $config['items'] : [];
        $applyCustomStructure = !empty($config['applyCustomStructure']);

        $result = [];
        $defaultTopOrder = 0;

        foreach ($menu as $entry) {
            $firstTitle = isset($entry['first'][0]) ? (string)$entry['first'][0] : '';
            $firstKey = $this->getKey($firstTitle);
            $firstConfig = isset($configItems[$firstKey]) && is_array($configItems[$firstKey])
                ? $configItems[$firstKey]
                : [];

            if (array_key_exists('visible', $firstConfig) && $firstConfig['visible'] === false) {
                continue;
            }

            $entry['_default_order'] = $defaultTopOrder++;
            $entry['_custom_order'] = $this->extractOrder($firstConfig);
            if (!empty($firstConfig['customTitle'])) {
                $entry['first'][0] = $firstConfig['customTitle'];
            }

            $secList = [];
            if (isset($entry['sec']) && is_array($entry['sec'])) {
                $defaultSecOrder = 0;
                foreach ($entry['sec'] as $sec) {
                    $secKey = $this->getKey($firstTitle, $sec);
                    $secConfig = isset($configItems[$secKey]) && is_array($configItems[$secKey])
                        ? $configItems[$secKey]
                        : [];
                    if (array_key_exists('visible', $secConfig) && $secConfig['visible'] === false) {
                        continue;
                    }
                    $sec['_default_order'] = $defaultSecOrder++;
                    $sec['_custom_order'] = $this->extractOrder($secConfig);
                    if (!empty($secConfig['customTitle'])) {
                        $sec[0] = $secConfig['customTitle'];
                    }
                    $secList[] = $sec;
                }
            }

            if ($applyCustomStructure && count($secList) > 1) {
                usort($secList, [$this, 'compareEntries']);
            }

            foreach ($secList as &$secEntry) {
                unset($secEntry['_default_order'], $secEntry['_custom_order']);
            }
            unset($secEntry);

            $entry['sec'] = $secList;
            $result[] = $entry;
        }

        if ($applyCustomStructure && count($result) > 1) {
            usort($result, [$this, 'compareEntries']);
        }

        foreach ($result as &$entry) {
            unset($entry['_default_order'], $entry['_custom_order']);
        }
        unset($entry);

        return $result;
    }

    /**
     * Sammelt alle gueltigen Schluessel des aktuellen Menu-Baums fuer
     * Whitelist-Validation der eingehenden Save-Payload.
     *
     * @return string[]
     */
    public function collectKeys(array $menu): array
    {
        $keys = [];
        foreach ($menu as $entry) {
            if (!isset($entry['first'][0])) {
                continue;
            }
            $firstTitle = (string)$entry['first'][0];
            $keys[] = $this->getKey($firstTitle);
            if (isset($entry['sec']) && is_array($entry['sec'])) {
                foreach ($entry['sec'] as $sec) {
                    $keys[] = $this->getKey($firstTitle, $sec);
                }
            }
        }

        return $keys;
    }

    /**
     * @param array $config
     *
     * @return float|null
     */
    private function extractOrder(array $config)
    {
        if (!isset($config['order'])) {
            return null;
        }
        if ($config['order'] === '' || $config['order'] === null) {
            return null;
        }

        return (float)$config['order'];
    }

    /**
     * Vergleichsfunktion fuer usort. Faellt auf Default-Order zurueck wenn keine
     * Custom-Order gesetzt ist.
     */
    public function compareEntries(array $a, array $b): int
    {
        $orderA = array_key_exists('_custom_order', $a) && $a['_custom_order'] !== null
            ? $a['_custom_order']
            : ($a['_default_order'] ?? 0);
        $orderB = array_key_exists('_custom_order', $b) && $b['_custom_order'] !== null
            ? $b['_custom_order']
            : ($b['_default_order'] ?? 0);
        if ($orderA == $orderB) {
            return 0;
        }

        return $orderA < $orderB ? -1 : 1;
    }
}
