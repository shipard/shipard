<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalDimension;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;

/**
 * Dimenze deníku `asset` nad skutečnou konfigurací modulu (docs/assets.md
 * D59–D61): výchozí hodnota z hlavičky, pole na formulářích dokladů řízené
 * nastavením, řádek pořízení kartu z hlavičky nedědí. Sloupce z deklarace
 * musí zakládat extensions modulu.
 */
class AssetDimensionParityTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/economy/assets';
    private const OPERATIONS = __DIR__ . '/../../../../../modules/docs/core/config/rowOperations.jsonc';

    private function dimension(): JournalDimension
    {
        $module = ModuleDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/module.jsonc'));
        $this->assertCount(1, $module->journalDimensions);
        return JournalDimension::fromArray($module->journalDimensions[0]);
    }

    public function testDimensionInheritsFromHeadAndOffersFormFields(): void
    {
        $dimension = $this->dimension();

        $this->assertSame('asset', $dimension->headColumn);
        $this->assertSame('economy.assets.trackExpenses', $dimension->enabledBySetting);
        foreach (['invni', 'invno', 'cash', 'cmnbkp'] as $docType) {
            $this->assertTrue($dimension->isOnForm($docType, true), "{$docType}: hlavička");
            $this->assertTrue($dimension->isOnForm($docType, false), "{$docType}: řádek");
        }
        // Zálohová faktura se neúčtuje do nákladů — pole nemá.
        $this->assertFalse($dimension->isOnForm('invpo', true));
    }

    public function testAcquisitionRowDoesNotInheritHeadCard(): void
    {
        $dimension = $this->dimension();
        $operations = JsoncParser::parseFile(self::OPERATIONS);

        $this->assertSame('rowAsset', $dimension->rowFlag);
        $this->assertNull($dimension->valueOf([], ['asset' => 8], $operations['purchase.asset']));
        $this->assertSame(8, $dimension->valueOf([], ['asset' => 8], $operations['purchase.goods']));
    }

    public function testExtensionsCreateDimensionColumns(): void
    {
        $dimension = $this->dimension();
        $column = static function (string $extension, string $column): bool {
            $def = JsoncParser::parseFile(self::MODULE . "/extensions/{$extension}.jsonc");
            return in_array($column, array_column($def['columns'], 'id'), true)
                && in_array([['column' => $column]], array_column($def['indexes'] ?? [], 'columns'), true);
        };

        $this->assertTrue($column('docs_core_rows', $dimension->rowColumn));
        $this->assertTrue($column('docs_core_heads', (string) $dimension->headColumn));
        $this->assertTrue($column('economy_accounting_journal', $dimension->journalColumn));
    }
}
