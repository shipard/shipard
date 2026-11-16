<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\TabBuilder;

/**
 * Kontrakt formulářů bez HTML (#150 D82, obdoba D30 v detailu —
 * `DetailContractTest`): prvek `html` neexistuje, `TabBuilder::html()` také
 * ne a žádný zdroj formuláře v `src/` a `modules/` ho nevolá ani nestaví
 * `FormElement` typu `html`; žádná jsonc definice formuláře nemá
 * `"type": "html"`. Jednoduchý obsah nese prvek `content` (bloky detailu),
 * složitější pojmenovaná komponenta.
 *
 * Kontrola zdrojů se dívá jen do **souborů formulářů** (třída dědí formulář,
 * nebo soubor pracuje s `TabBuilder` / `FormElement`) — `->html(` jinde je
 * něco jiného (`MailComposer`: tělo e-mailu).
 */
class FormContractTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    /** Soubor formuláře: dědí třídu formuláře, nebo staví prvky přes TabBuilder / FormElement. */
    private const FORM_SOURCE = '/\bextends\s+\\\\?[\w\\\\]*Form(?:Base)?\b|\bTabBuilder\b|\bFormElement\b/';

    private const HTML_CALL = '/->\s*html\s*\(/';
    private const HTML_ELEMENT = '/new\s+FormElement\s*\(\s*(?:type\s*:\s*)?[\'"]html[\'"]/';

    /** @return array<string, string> cesta od kořene repozitáře → zdroj */
    private static function phpSources(): array
    {
        $root = (string) realpath(self::ROOT);
        $out = [];
        foreach ([$root . '/src', ...(glob($root . '/modules/*/*/src', GLOB_ONLYDIR) ?: [])] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $out[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        ksort($out);
        return $out;
    }

    private static function isFormSource(string $source): bool
    {
        return preg_match(self::FORM_SOURCE, $source) === 1;
    }

    public function testHtmlElementDoesNotExist(): void
    {
        $this->assertNotContains('html', FormElement::ALLOWED_TYPES, 'Formulář HTML se vzhledem neposílá (#150 D82)');
        $this->assertContains('content', FormElement::ALLOWED_TYPES);
        $this->assertFalse(method_exists(TabBuilder::class, 'html'), 'TabBuilder::html() zanikl — použij content() nebo component()');
        $this->assertFalse(property_exists(FormElement::class, 'content'), 'FormElement nenese HTML obsah');
    }

    public function testNoFormSourceUsesHtmlElement(): void
    {
        $hits = [];
        $forms = 0;
        foreach (self::phpSources() as $path => $source) {
            if (!self::isFormSource($source)) {
                continue;
            }
            $forms++;
            foreach (explode("\n", $source) as $index => $line) {
                if (preg_match(self::HTML_CALL, $line) === 1) {
                    $hits[] = $path . ':' . ($index + 1);
                }
            }
            if (preg_match(self::HTML_ELEMENT, $source) === 1) {
                $hits[] = $path . ': FormElement typu html';
            }
        }
        $this->assertGreaterThan(30, $forms, 'Kontrola našla zdroje formulářů');
        $this->assertSame([], $hits, 'Formulář HTML neposílá (#150 D82): místo html() prvek content() s bloky nebo component()');
    }

    /** Tělo e-mailu (`MailComposer`) není formulářový prvek — kontrola ho nesmí hlásit. */
    public function testMailBodyHtmlIsNotAFormSource(): void
    {
        $sources = self::phpSources();
        $this->assertArrayHasKey('src/Core/Mail/MailComposer.php', $sources);
        $composer = $sources['src/Core/Mail/MailComposer.php'];

        $this->assertSame(1, preg_match(self::HTML_CALL, $composer), 'MailComposer volá ->html() na e-mailu');
        $this->assertFalse(self::isFormSource($composer), 'MailComposer se nepočítá mezi zdroje formulářů');
    }

    public function testNoJsoncFormDeclaresHtmlElement(): void
    {
        $root = (string) realpath(self::ROOT);
        $files = glob($root . '/modules/*/*/forms/*.jsonc') ?: [];
        $this->assertNotEmpty($files);

        $hits = [];
        foreach ($files as $file) {
            if (preg_match('/"type"\s*:\s*"html"/', (string) file_get_contents($file)) === 1) {
                $hits[] = substr($file, strlen($root) + 1);
            }
        }
        $this->assertSame([], $hits, 'Definice formuláře nemá prvek html (#150 D82)');
    }
}
