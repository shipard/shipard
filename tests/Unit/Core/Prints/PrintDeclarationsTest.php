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
use Shipard\Core\Prints\Texts\PrintTextSlot;
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
