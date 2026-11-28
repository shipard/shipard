<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\MockObject\MockObject;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;

/**
 * Konfigurace modulu core.devices pro testy: skutečné zdrojové JSONC
 * lokalizované do češtiny, jak je dá kompilace.
 */
trait DevicesConfig
{
    /** Zdrojový JSONC z repozitáře v daném jazyce. */
    protected static function repoJsonc(string $repoPath, string $language = 'cs'): array
    {
        return ConfigLocalizer::localize(JsoncParser::parseFile(dirname(__DIR__, 5) . '/' . $repoPath), $language);
    }

    /**
     * @param array<string, mixed> $override cfgItem id → hodnota (null = cfgItem chybí)
     * @return array<string, mixed>
     */
    protected static function devicesItems(array $override = []): array
    {
        $module = 'modules/core/devices/config/';
        $items = [
            'core.devices.docStates'       => self::repoJsonc($module . 'docStates.jsonc'),
            'core.devices.modes'           => self::repoJsonc($module . 'modes.jsonc'),
            'core.devices.peripheralRoles' => self::repoJsonc($module . 'peripheralRoles.jsonc'),
        ];
        foreach (['viewerLabels', 'formTexts'] as $catalog) {
            $file = dirname(__DIR__, 5) . '/' . $module . $catalog . '.jsonc';
            if (is_file($file)) {
                $items['core.devices.' . $catalog] = self::repoJsonc($module . $catalog . '.jsonc');
            }
        }
        // Katalog profilů tiskáren patří modulu core.prints; controller
        // zařízení ho čte z konfigurace zdroje dat (#177 U5).
        $items['core.prints.printerProfiles'] = self::repoJsonc('modules/core/prints/config/printerProfiles.jsonc');
        $items['core.system.viewerDetailLabels'] = self::repoJsonc('modules/core/system/config/viewerDetailLabels.jsonc');
        return $override + $items;
    }

    /** @param array<string, mixed> $override */
    protected function devicesConfig(array $override = []): ConfigRuntime&MockObject
    {
        $items = self::devicesItems($override);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => $items[$id] ?? null);
        return $config;
    }
}
