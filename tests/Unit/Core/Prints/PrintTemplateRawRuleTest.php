<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\Texts\PrintTextSlot;

/**
 * Pravidlo `|raw` v tiskových šablonách (#90 D50): filtr je povolený jen
 * pro sloty uživatelských textů — `texts.<slot>` je HTML, které vyrobil
 * Markdown z escapovaného vstupu. Cokoli jiného vypsané přes `|raw` by do
 * tisku pustilo neescapovaná data dokladu.
 */
class PrintTemplateRawRuleTest extends TestCase
{
    /** @return list<string> */
    private static function templates(): array
    {
        $modules   = dirname(__DIR__, 4) . '/modules';
        $templates = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modules, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, '.twig') && str_contains($path, '/prints/')) {
                $templates[] = $path;
            }
        }
        sort($templates);
        return $templates;
    }

    public function testRawFilterIsUsedOnlyForUserTextSlots(): void
    {
        $slots   = implode('|', array_map('preg_quote', PrintTextSlot::ids()));
        $allowed = '/\{\{\s*texts\.(' . $slots . ')\|default\(\'\'\)\|raw\s*\}\}/';

        $templates = self::templates();
        $this->assertNotEmpty($templates);

        $used = 0;
        foreach ($templates as $path) {
            $source = (string) file_get_contents($path);
            // Komentáře Twigu pravidlo jen popisují.
            $code = (string) preg_replace('/\{#.*?#\}/s', '', $source);

            $withoutAllowed = (string) preg_replace($allowed, '', $code, -1, $count);
            $used += $count;
            $this->assertDoesNotMatchRegularExpression(
                '/\|\s*raw\b/',
                $withoutAllowed,
                basename(dirname($path)) . '/' . basename($path) . ': `|raw` smí jen na `texts.<slot>|default(\'\')|raw`',
            );
        }

        // Layout dokladů kreslí čtyři sloty stránky.
        $this->assertSame(4, $used);
    }

    public function testEmailSlotsAreNeverPrintedIntoPage(): void
    {
        foreach (self::templates() as $path) {
            if (!str_ends_with($path, '.html.twig')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            $this->assertStringNotContainsString('texts.emailSubject', $source, $path);
            $this->assertStringNotContainsString('texts.emailBody', $source, $path);
        }
    }
}
