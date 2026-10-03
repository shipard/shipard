<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintLanguageResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Konfigurace, kterou tisk čte, ve všech jazycích tisku (#90 D29). Čte se
 * ze surových JSONC modulů: každá položka musí mít **vlastní** variantu
 * `:<jazyk>` — kompilát by chybějící jazyk tiše nahradil angličtinou
 * (`LocalizedFieldResolver`) a slovenský doklad by měl anglické sazby DPH.
 *
 * Nový cfgItem čtený builderem tisku patří do `LABELS` (nebo do vlastního
 * testu níž, má-li jiný tvar).
 */
class PrintConfigLanguagesTest extends TestCase
{
    /** cfgItem → pole s popiskem; položky cfgItemu jsou objekty s tímto polem. */
    private const LABELS = [
        'docs.core.paymentMethods'            => 'name',     // DocPaymentBlock
        'docs.core.docTypes'                  => 'name',     // DocJournalPrintBuilder
        'economy.accounting.accountingStates' => 'name',     // DocJournalPrintBuilder
        'core.units.printShortcuts'           => 'shortcut', // DocRowsBlock
    ];

    /** Prefix cfgItemů s kódy DPH per země (`DocVatCodes`). */
    private const VAT_PREFIX = 'world.vat.';

    private static function resolver(): ModulePathResolver
    {
        return new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
    }

    /** @return ModuleDefinition[] */
    private static function modules(): array
    {
        return ModuleLoader::loadAllModules(self::resolver());
    }

    /** @return array<string, mixed> cfgItem → surová data (s variantami `:jazyk`) */
    private static function rawItems(): array
    {
        $resolver = self::resolver();
        $items    = [];
        foreach (self::modules() as $module) {
            foreach ($module->config as $entry) {
                $items[$entry['id']] = JsoncParser::parseFile($resolver->getPath($module->id) . '/' . $entry['file']);
            }
        }
        return $items;
    }

    /**
     * Vlastní varianta pole v jazyce tisku. Čeština smí být i holé pole —
     * u řady cfgItemů je výchozí text český.
     *
     * @param array<string, mixed> $entry
     */
    private function assertOwnVariant(array $entry, string $field, string $language, string $where): void
    {
        $text = $entry[$field . ':' . $language] ?? ($language === 'cs' ? ($entry[$field] ?? null) : null);

        $this->assertIsString($text, "{$where}: chybí {$field}:{$language}");
        $this->assertNotSame('', trim($text), "{$where}: prázdné {$field}:{$language}");
    }

    public function testCodebookLabelsHaveEveryPrintLanguage(): void
    {
        $items = self::rawItems();

        foreach (self::LABELS as $cfgId => $field) {
            $this->assertIsArray($items[$cfgId] ?? null, "cfgItem {$cfgId}");
            $this->assertNotSame([], $items[$cfgId], "cfgItem {$cfgId}");

            foreach ($items[$cfgId] as $key => $entry) {
                foreach (PrintLanguageResolver::LANGUAGES as $language) {
                    $this->assertOwnVariant($entry, $field, $language, "{$cfgId}[{$key}]");
                }
            }
        }
    }

    public function testVatCodesAndNotesHaveEveryPrintLanguage(): void
    {
        $vatItems = array_filter(
            self::rawItems(),
            static fn (string $cfgId): bool => str_starts_with($cfgId, self::VAT_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );
        $this->assertNotSame([], $vatItems, 'cfgItemy world.vat.<země>');

        foreach ($vatItems as $cfgId => $item) {
            foreach ($item['vatCodes'] ?? [] as $code => $def) {
                // Stejné pole jako `DocVatCodes::label()`: `print`, jinak `name`.
                $field = isset($def['print']) ? 'print' : 'name';
                foreach (PrintLanguageResolver::LANGUAGES as $language) {
                    $this->assertOwnVariant($def, $field, $language, "{$cfgId}.vatCodes[{$code}]");
                }
            }
            foreach ($item['vatNotes'] ?? [] as $noteId => $note) {
                foreach (PrintLanguageResolver::LANGUAGES as $language) {
                    $this->assertOwnVariant($note, 'text', $language, "{$cfgId}.vatNotes[{$noteId}]");
                }
            }
        }
    }

    public function testJournalDimensionNamesHaveEveryPrintLanguage(): void
    {
        // Záhlaví sloupců dimenzí v Kontaci (`JournalDimensionLabels`).
        $dimensions = 0;
        foreach (self::modules() as $module) {
            foreach ($module->journalDimensions as $dimension) {
                $dimensions++;
                foreach (PrintLanguageResolver::LANGUAGES as $language) {
                    $this->assertOwnVariant($dimension, 'name', $language, "{$module->id}: dimenze '{$dimension['id']}'");
                }
            }
        }
        $this->assertGreaterThan(0, $dimensions);
    }

    public function testPrintLanguagesAreDocumentLanguagesAndGetCompiled(): void
    {
        $documentLanguages = array_keys(self::rawItems()[ConfigCompiler::DOCUMENT_LANGUAGES_ITEM]);
        $compiled          = ConfigCompiler::languages(self::modules(), self::resolver());

        foreach (PrintLanguageResolver::LANGUAGES as $language) {
            $this->assertContains($language, $documentLanguages, "jazyk tisku '{$language}' není jazykem dokumentů");
            $this->assertContains($language, $compiled, "ds-upgrade nekompiluje konfiguraci pro '{$language}'");
        }
        $this->assertContains(PrintLanguageResolver::FALLBACK, PrintLanguageResolver::LANGUAGES);
    }
}
