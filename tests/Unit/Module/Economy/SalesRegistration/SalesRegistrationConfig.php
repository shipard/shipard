<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\MockObject\MockObject;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;

/**
 * Konfigurace doplňku pro testy: skutečné zdrojové JSONC (typy world.cz,
 * režimy, katalogy textů) lokalizované do češtiny, jak je dá kompilace —
 * testy tak hlídají skutečné texty a masky.
 */
trait SalesRegistrationConfig
{
    /** Třída engine, na kterou testy mapují typ `cz.eet2`, dokud neznají skutečnou. */
    protected const string TEST_ENGINE = 'Shipard\\Tests\\Unit\\Module\\Economy\\SalesRegistration\\NoEngine';

    /** Zdrojový JSONC z repozitáře v daném jazyce. */
    protected static function repoJsonc(string $repoPath, string $language = 'cs'): array
    {
        return ConfigLocalizer::localize(JsoncParser::parseFile(dirname(__DIR__, 5) . '/' . $repoPath), $language);
    }

    /**
     * @param array<string, mixed> $override cfgItem id → hodnota (null = cfgItem chybí)
     * @return array<string, mixed>
     */
    protected static function salesRegistrationItems(array $override = []): array
    {
        $module = 'modules/economy/salesRegistration/config/';
        return $override + [
            'economy.salesRegistration.engines'            => ['cz.eet2' => self::TEST_ENGINE],
            'world.cz.salesRegistrationTypes'              => self::repoJsonc('modules/world/cz/config/salesRegistrationTypes.jsonc'),
            'economy.salesRegistration.modes'              => self::repoJsonc($module . 'modes.jsonc'),
            'economy.salesRegistration.viewerDetailLabels' => self::repoJsonc($module . 'viewerDetailLabels.jsonc'),
            'economy.salesRegistration.formTexts'          => self::repoJsonc($module . 'formTexts.jsonc'),
            'economy.salesRegistration.texts'              => self::repoJsonc($module . 'texts.jsonc'),
            'core.system.viewerDetailLabels'               => self::repoJsonc('modules/core/system/config/viewerDetailLabels.jsonc'),
            'core.system.docStatesArchive'                 => self::repoJsonc('modules/core/system/config/docStatesArchive.jsonc'),
        ];
    }

    /** @param array<string, mixed> $override */
    protected function salesRegistrationConfig(array $override = []): ConfigRuntime&MockObject
    {
        $items = self::salesRegistrationItems($override);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => $items[$id] ?? null);
        return $config;
    }
}
