<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Units;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\PrintLanguageResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Tiskové zkratky systémových jednotek (`core.units.printShortcuts`,
 * #90 D31) proti seedu jednotek: každá systémová jednotka má zkratku ve
 * všech jazycích tisku a česká odpovídá zkratce, kterou seed zapíše do dat.
 */
class PrintShortcutsTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../../../../modules/core/units/config';

    /** @return array<string, string> system_code → zkratka seedu */
    private static function seedShortcuts(): array
    {
        $seed = JsoncParser::parseFile(self::CONFIG_DIR . '/unitsSeed.jsonc');
        return array_column($seed, 'shortcut', 'system_code');
    }

    public function testEverySeedUnitHasPrintShortcutInEveryPrintLanguage(): void
    {
        $shortcuts = JsoncParser::parseFile(self::CONFIG_DIR . '/printShortcuts.jsonc');
        $seed      = self::seedShortcuts();

        $this->assertNotSame([], $seed);
        $this->assertSame(array_keys($seed), array_keys($shortcuts), 'jednotky seedu a tiskových zkratek');

        foreach ($shortcuts as $systemCode => $variants) {
            $this->assertNotSame('', trim((string) ($variants['shortcut'] ?? '')), "{$systemCode}: holé pole");
            foreach (PrintLanguageResolver::LANGUAGES as $language) {
                $this->assertNotSame(
                    '',
                    trim((string) ($variants['shortcut:' . $language] ?? '')),
                    "{$systemCode}: chybí shortcut:{$language}",
                );
            }
        }
    }

    public function testCzechPrintShortcutMatchesSeed(): void
    {
        $shortcuts = JsoncParser::parseFile(self::CONFIG_DIR . '/printShortcuts.jsonc');

        foreach (self::seedShortcuts() as $systemCode => $shortcut) {
            $this->assertSame($shortcut, $shortcuts[$systemCode]['shortcut:cs'], $systemCode);
        }
    }
}
