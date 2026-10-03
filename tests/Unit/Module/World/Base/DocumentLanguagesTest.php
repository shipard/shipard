<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Base;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Jazyky dokumentů (`world.base.documentLanguages`, #94 D3) — zdroj pravdy
 * pro jazyk osoby i odvození jazyka dokumentu. Test hlídá to, na čem stojí
 * `DocumentLanguageResolver`: `en` je záložní jazyk a nesmí ze seznamu
 * zmizet, klíče jsou jazyky známé v `world.base.languages`.
 */
class DocumentLanguagesTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/world/base';

    public function testIsRegisteredInModule(): void
    {
        $module = JsoncParser::parseFile(self::MODULE . '/module.jsonc');
        $files  = array_column($module['config'] ?? [], 'file', 'id');

        $this->assertSame('config/documentLanguages.jsonc', $files['world.base.documentLanguages'] ?? null);
    }

    public function testEnglishIsAlwaysAmongDocumentLanguages(): void
    {
        $this->assertArrayHasKey('en', $this->documentLanguages());
    }

    public function testEveryLanguageIsKnownAndNamedInBothLanguages(): void
    {
        $known = JsoncParser::parseFile(self::MODULE . '/config/languages.jsonc');

        foreach ($this->documentLanguages() as $code => $entry) {
            $this->assertArrayHasKey($code, $known, "'{$code}' není v world.base.languages");
            foreach (['name', 'name:cs', 'name:en'] as $key) {
                $this->assertNotSame('', (string) ($entry[$key] ?? ''), "'{$code}' nemá '{$key}'");
            }
        }
    }

    /** @return array<string, array<string, string>> */
    private function documentLanguages(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/config/documentLanguages.jsonc');
    }
}
