<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintBuilder;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintEmailRenderer;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Prints\PrintLanguageResolver;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRenderer;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\PrintTranslator;
use Shipard\Core\Prints\Texts\PrintTextCompiler;
use Shipard\Core\Prints\Texts\PrintTextSlot;
use Shipard\Core\Prints\Texts\PrintTextVariables;
use Shipard\Core\Utils\JsoncParser;

/**
 * Deklarace tisků v repozitáři (`prints` v module.jsonc): každá se dá
 * načíst, id jsou unikátní napříč moduly, builder existuje a katalogy
 * překladů mají všechny klíče ve všech jazycích tisku.
 */
class PrintDeclarationsTest extends TestCase
{
    private static function modules(): ModulePathResolver
    {
        return new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
    }

    /** @return list<PrintDefinition> */
    private static function definitions(): array
    {
        $resolver = self::modules();
        $registry = new PrintRegistry();
        foreach (ModuleLoader::loadAllModules($resolver) as $module) {
            foreach ($module->prints as $entry) {
                $declarations = JsoncParser::parseFile($resolver->getPath($module->id) . '/' . $entry['file']);
                self::assertIsArray($declarations, "{$module->id}: {$entry['file']}");
                foreach ($declarations as $raw) {
                    // add() hlídá duplicitní id napříč moduly.
                    $registry->add(PrintDefinition::fromArray(ConfigLocalizer::localize($raw, 'cs'), $module->id));
                }
            }
        }
        return $registry->getAll();
    }

    public function testRepositoryDeclaresInvoicePrints(): void
    {
        $ids = array_map(static fn (PrintDefinition $d): string => $d->id, self::definitions());

        $this->assertContains('docs.invoicesOut.invoice', $ids);
        $this->assertContains('docs.proformasOut.proforma', $ids);
        $this->assertContains('docs.cashDocs.cash', $ids);
        $this->assertContains('docs.cashRegister.receipt', $ids);
        $this->assertContains('economy.accounting.docJournal', $ids);
    }

    public function testBuildersExistAndTemplatesLiveInDeclaringModule(): void
    {
        $paths = new PrintTemplatePaths(self::modules());

        foreach (self::definitions() as $definition) {
            $this->assertTrue(class_exists($definition->builderClass), "{$definition->id}: builder");
            $this->assertTrue(
                is_subclass_of($definition->builderClass, PrintBuilder::class),
                "{$definition->id}: builder implements PrintBuilder",
            );
            $this->assertSame(
                $definition->moduleId,
                PrintTemplatePaths::parse($definition->template)['module'],
                "{$definition->id}: template belongs to the declaring module",
            );
            $this->assertFileExists(
                $paths->directory($definition->template) . '/' . PrintRenderer::PAGE_TEMPLATE,
                "{$definition->id}: page template",
            );
        }
    }

    public function testDocumentPrintsAreSendableAndInternalOnesAreNot(): void
    {
        $sendable = [];
        foreach (self::definitions() as $definition) {
            if ($definition->isSendable()) {
                $sendable[$definition->id] = [$definition->sendPurpose, $definition->recipientPerson];
            }
        }
        ksort($sendable);

        $this->assertSame([
            'docs.cashDocs.cash'         => ['invoices', 'partner'],
            'docs.cashRegister.receipt'  => ['invoices', 'partner'],
            'docs.invoicesOut.invoice'   => ['invoices', 'partner'],
            'docs.proformasOut.proforma' => ['invoices', 'partner'],
        ], $sendable);
    }

    public function testDocumentPrintsSupportAllTextSlotsAndJournalNone(): void
    {
        $slots = [];
        foreach (self::definitions() as $definition) {
            $slots[$definition->id] = $definition->textSlots;
        }
        ksort($slots);

        $all = PrintTextSlot::ids();
        $this->assertSame([
            'docs.cashDocs.cash'            => $all,
            'docs.cashRegister.receipt'     => $all,
            'docs.invoicesOut.invoice'      => $all,
            'docs.proformasOut.proforma'    => $all,
            // Kontace je interní tisk — uživatelské texty nenese (D48).
            'economy.accounting.docJournal' => [],
        ], $slots);
    }

    public function testSendablePrintsHaveKnownPurposeAndEmailTemplates(): void
    {
        $resolver = self::modules();
        $purposes = [];
        foreach (ModuleLoader::loadAllModules($resolver) as $module) {
            foreach ($module->sendPurposes as $purpose) {
                $purposes[] = $purpose['id'];
            }
        }

        $paths  = new PrintTemplatePaths($resolver);
        $emails = new PrintEmailRenderer($paths, new PrintTwigFactory($paths));

        foreach (self::definitions() as $definition) {
            if (!$definition->isSendable()) {
                continue;
            }
            $this->assertContains($definition->sendPurpose, $purposes, "{$definition->id}: sendPurpose");
            // Bez šablon předmětu a těla by odeslání spadlo až u uživatele.
            $this->assertTrue($emails->hasTemplates($definition), "{$definition->id}: e-mail templates");
        }
    }

    // ── proměnné pro texty na tiscích (#90 D51) ─────────────────────────────

    public function testDocumentPrintsOfferSharedTextVariablesAndJournalNone(): void
    {
        $variables = new PrintTextVariables(new PrintTemplatePaths(self::modules()));

        $offered = [];
        foreach (self::definitions() as $definition) {
            $offered[$definition->id] = array_column($variables->forPrint($definition), 'path');
        }

        $shared = $offered['docs.invoicesOut.invoice'];
        $this->assertContains('data.document.number', $shared);
        $this->assertContains('data.dates.due', $shared);
        $this->assertContains('data.payment.amountToPay', $shared);
        $this->assertContains('data.payment.reference', $shared);
        $this->assertContains('data.document.title', $shared);
        $this->assertContains('data.customer.name', $shared);
        $this->assertContains('meta.title', $shared);

        // Jedna sada pro všechny tisky dokladů — žádné kopie po deklaracích.
        foreach (['docs.proformasOut.proforma', 'docs.cashDocs.cash', 'docs.cashRegister.receipt'] as $printId) {
            $this->assertSame($shared, $offered[$printId], $printId);
        }
        $this->assertSame([], $offered['economy.accounting.docJournal']);
    }

    public function testEveryTextVariableHasLabelInPrintCatalogs(): void
    {
        $paths     = new PrintTemplatePaths(self::modules());
        $variables = new PrintTextVariables($paths);
        $loader    = new PrintCatalogLoader($paths);

        foreach (self::definitions() as $definition) {
            $messages = $loader->messages($definition);
            foreach ($variables->forPrint($definition) as $variable) {
                // Jazyky klíče hlídá testCatalogsAreCompleteInEveryPrintLanguage.
                $this->assertArrayHasKey(
                    PrintTextVariables::LABEL_PREFIX . $variable['path'],
                    $messages,
                    "{$definition->id}: proměnná '{$variable['path']}' nemá popisek v katalogu",
                );
            }
        }
    }

    public function testEveryTextVariableExampleRendersOverEveryFixtureOfItsPrint(): void
    {
        $variables   = new PrintTextVariables(new PrintTemplatePaths(self::modules()));
        $definitions = [];
        foreach (self::definitions() as $definition) {
            $definitions[$definition->id] = $definition;
        }

        $checked = 0;
        foreach (glob(dirname(__DIR__, 3) . '/Fixtures/Prints/*.json') ?: [] as $file) {
            $envelope   = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $definition = $definitions[$envelope['printId']] ?? null;
            $this->assertNotNull($definition, basename($file) . ': neznámý tisk');

            $context  = ['data' => $envelope['data'], 'meta' => $envelope['meta'], 'language' => $envelope['language']];
            $compiler = new PrintTextCompiler(new PrintTranslator([], $envelope['language']));
            foreach ($variables->forPrint($definition) as $variable) {
                $example = PrintTextVariables::example($variable['path'], $variable['filter']);
                // Ukázka musí projít sandboxem a najít hodnotu v datech každého
                // dokladu — i pokladního bez partnera (proto `default('')`).
                foreach ([true, false] as $markdown) {
                    try {
                        $compiler->compile($example, $markdown)->render($context);
                    } catch (\Throwable $e) {
                        $this->fail(basename($file) . ": {$example} — " . $e->getMessage());
                    }
                }
                $checked++;
            }
        }
        // Osm fixture dokladů × sada dokladů; Kontace proměnné nemá.
        $this->assertGreaterThan(50, $checked);
    }

    public function testCatalogsAreCompleteInEveryPrintLanguage(): void
    {
        $loader = new PrintCatalogLoader(new PrintTemplatePaths(self::modules()));

        foreach (self::definitions() as $definition) {
            $messages = $loader->messages($definition);
            $this->assertNotSame([], $messages, "{$definition->id}: catalog");
            foreach ($messages as $key => $variants) {
                foreach (PrintLanguageResolver::LANGUAGES as $language) {
                    $this->assertArrayHasKey(
                        $language,
                        $variants,
                        "{$definition->id}: key '{$key}' has no '{$language}' text",
                    );
                    $this->assertNotSame('', trim($variants[$language]), "{$definition->id}: '{$key}' {$language}");
                }
            }
        }
    }
}
