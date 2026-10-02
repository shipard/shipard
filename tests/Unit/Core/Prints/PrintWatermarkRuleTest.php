<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintRenderer;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Utils\JsoncParser;

/**
 * Pravidlo vodoznaku (#90 D23): layout tisku, jehož deklarace má
 * `watermarks`, musí `meta.watermark` vykreslit — jinak by stornovaný
 * doklad vyšel jako platný. Každý takový tisk potřebuje fixture `PrintData`
 * v `tests/Fixtures/Prints/` (hledá se podle `printId`); test ji vyrenderuje
 * ve stavu s vodoznakem a bez něj.
 */
class PrintWatermarkRuleTest extends TestCase
{
    private static function modules(): ModulePathResolver
    {
        return new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
    }

    /** @return array<string, array<string, mixed>> printId → obálka fixture */
    private static function fixtures(): array
    {
        $fixtures = [];
        foreach (glob(dirname(__DIR__, 3) . '/Fixtures/Prints/*.json') ?: [] as $file) {
            $envelope = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $fixtures[(string) $envelope['printId']] ??= $envelope;
        }
        return $fixtures;
    }

    public function testEveryLayoutWithWatermarksRendersIt(): void
    {
        $resolver = self::modules();
        $paths    = new PrintTemplatePaths($resolver);
        $catalogs = new PrintCatalogLoader($paths);
        $renderer = new PrintRenderer($paths, new PrintTwigFactory($paths), new RenderClient(null));
        $fixtures = self::fixtures();

        $checked = 0;
        foreach (ModuleLoader::loadAllModules($resolver) as $module) {
            foreach ($module->prints as $entry) {
                foreach (JsoncParser::parseFile($resolver->getPath($module->id) . '/' . $entry['file']) as $raw) {
                    $definition = PrintDefinition::fromArray(ConfigLocalizer::localize($raw, 'cs'), $module->id);
                    if ($definition->watermarks === []) {
                        continue;
                    }
                    $this->assertArrayHasKey(
                        $definition->id,
                        $fixtures,
                        "{$definition->id}: tisk s vodoznakem potřebuje fixture v tests/Fixtures/Prints",
                    );
                    $translator = $catalogs->translator($definition, 'cs');

                    foreach ($definition->watermarks as $docState => $key) {
                        $this->assertTrue($translator->has($key), "{$definition->id}: klíč '{$key}' v katalogu");
                        $text = $translator->t($key);

                        $envelope = $fixtures[$definition->id];
                        $envelope['record']['docState'] = $docState;
                        $envelope['meta']['watermark']  = null;
                        $without = $renderer->renderDocument($definition, PrintData::fromArray($envelope), $translator);

                        $envelope['meta']['watermark'] = $text;
                        $with = $renderer->renderDocument($definition, PrintData::fromArray($envelope), $translator);

                        $this->assertGreaterThan(
                            substr_count($without->html, $text),
                            substr_count($with->html, $text),
                            "{$definition->id}: layout nevykreslil vodoznak '{$text}' stavu {$docState}",
                        );
                        $checked++;
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'aspoň jeden tisk má vodoznak');
    }
}
