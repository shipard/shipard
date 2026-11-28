<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\EscPos;

use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Prints\EscPos\EscPosDriver;
use Shipard\Core\Prints\EscPos\EscPosDrivers;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Utils\JsoncParser;

/**
 * Konfigurace tisku na účtenkovou tiskárnu pro testy: skutečné zdrojové
 * JSONC modulu core.prints lokalizované do češtiny, jak je dá kompilace.
 */
trait EscPosConfig
{
    /** @return array<string, mixed> */
    protected static function printsJsonc(string $file, string $language = 'cs'): array
    {
        $path = dirname(__DIR__, 5) . '/modules/core/prints/config/' . $file;
        return ConfigLocalizer::localize(JsoncParser::parseFile($path), $language);
    }

    protected static function drivers(): EscPosDrivers
    {
        return new EscPosDrivers(self::printsJsonc('printerDrivers.jsonc'));
    }

    protected static function driver(): EscPosDriver
    {
        return self::drivers()->get('escpos-generic');
    }

    /**
     * Profil pro testy sazby — 42 / 56 znaků jako široká tiskárna katalogu.
     *
     * @param array<string, mixed> $override
     */
    protected static function profile(array $override = []): PrinterProfile
    {
        return PrinterProfile::fromArray('test-profile', $override + [
            'name'         => 'Test',
            'driver'       => 'escpos-generic',
            'columns'      => 42,
            'columnsSmall' => 56,
            'dotsPerLine'  => 512,
            'codePage'     => 'cp852',
            'features'     => ['cut', 'drawer'],
            'models'       => [],
        ]);
    }
}
