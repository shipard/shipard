<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Katalog `economy.salesRegistration.texts` proti voláním `Texts::t()`
 * ve zdrojích doplňku: každý klíč existuje, anglický fallback v PHP se
 * shoduje s holým `name` znak po znaku, každý text má češtinu i angličtinu
 * a žádný klíč katalogu není nepoužitý.
 */
class SalesRegistrationTextsTest extends TestCase
{
    use SalesRegistrationConfig;

    private const MODULE = __DIR__ . '/../../../../../modules/economy/salesRegistration';

    /** @return array<string, array<string, string>> */
    private function catalog(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/config/texts.jsonc')['texts'];
    }

    /** @return array<string, list<array{file: string, fallback: string}>> klíč → volání */
    private function calls(): array
    {
        $calls = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::MODULE . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // ->t('klíč', 'anglický fallback'
            preg_match_all("/->t\\(\\s*'([^']+)',\\s*'((?:[^'\\\\]|\\\\.)*)'/s", $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $calls[$match[1]][] = ['file' => $file->getFilename(), 'fallback' => stripcslashes($match[2])];
            }
            // Volání s jiným než literálním klíčem nebo fallbackem by test neviděl.
            $this->assertSame(
                preg_match_all('/->t\(/', $source),
                count($matches),
                $file->getFilename() . ': Texts::t() volat s literálním klíčem i anglickým fallbackem',
            );
        }
        return $calls;
    }

    public function testEveryCallMatchesTheCatalog(): void
    {
        $catalog = $this->catalog();
        $calls = $this->calls();

        $this->assertNotEmpty($calls);
        foreach ($calls as $key => $uses) {
            $this->assertArrayHasKey($key, $catalog, "klíč {$key} ({$uses[0]['file']}) v katalogu chybí");
            foreach ($uses as $use) {
                $this->assertSame($catalog[$key]['name'], $use['fallback'], "{$key} ({$use['file']}): fallback se liší od holého name");
            }
        }
        $this->assertSame([], array_values(array_diff(array_keys($catalog), array_keys($calls))), 'nepoužité klíče katalogu');
    }

    public function testEveryTextHasCzechAndEnglishWithTheSameParameters(): void
    {
        foreach ($this->catalog() as $key => $text) {
            $this->assertSame($text['name'], $text['name:en'] ?? null, "{$key}: holé name je anglická varianta");
            $this->assertNotSame('', trim((string) ($text['name:cs'] ?? '')), "{$key}: chybí čeština");

            preg_match_all('/\{(\w+)\}/', $text['name'], $english);
            preg_match_all('/\{(\w+)\}/', $text['name:cs'], $czech);
            sort($english[1]);
            sort($czech[1]);
            $this->assertSame($english[1], $czech[1], "{$key}: parametry češtiny a angličtiny se liší");
        }
    }

    public function testReadsLocalizedTextReplacesParametersAndFallsBack(): void
    {
        $texts = Texts::of($this->salesRegistrationConfig());

        $this->assertSame('Nahrát certifikát', $texts->t('upload.label', 'Upload certificate'));
        $this->assertSame(
            'Certifikát nahrán — EIČ CZ00000019, platnost do 14.05.2027.',
            $texts->t('upload.done', 'x', ['label' => 'EIČ', 'taxpayerId' => 'CZ00000019', 'validTo' => '14.05.2027']),
        );
        $this->assertSame('Fallback 5', $texts->t('missing.key', 'Fallback {n}', ['n' => 5]));
        $this->assertSame('Upload certificate', Texts::of(null)->t('upload.label', 'Upload certificate'), 'bez zkompilované konfigurace anglicky');
        $this->assertSame(
            'Upload certificate',
            Texts::of($this->salesRegistrationConfig(['economy.salesRegistration.texts' => null]))->t('upload.label', 'Upload certificate'),
        );
    }
}
