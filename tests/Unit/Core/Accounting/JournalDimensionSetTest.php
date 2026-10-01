<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalDimension;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Config\ConfigRuntime;

/** Dimenze deníku z kompilované konfigurace: hodnoty řádku a popisky. */
class JournalDimensionSetTest extends TestCase
{
    private const ASSET = [
        'id' => 'asset', 'rowColumn' => 'asset', 'headColumn' => null, 'journalColumn' => 'asset',
        'table' => 'economy_assets_assets', 'name' => 'Majetek', 'displayPattern' => '{asset_number} — {name}',
    ];

    private function config(mixed $item): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === JournalDimensionSet::CFG_ITEM ? $item : null,
        );
        return $config;
    }

    public function testFromConfigBuildsDimensionsInOrder(): void
    {
        $set = JournalDimensionSet::fromConfig($this->config([
            'asset'  => self::ASSET,
            'centre' => ['id' => 'centre', 'rowColumn' => 'centre', 'headColumn' => 'centre', 'journalColumn' => 'cost_centre', 'table' => 't', 'name' => 'Středisko'],
        ]));

        $this->assertCount(2, $set);
        $this->assertSame(['asset', 'centre'], array_map(static fn(JournalDimension $d): string => $d->id, iterator_to_array($set)));
        $this->assertSame('cost_centre', $set->get('centre')?->journalColumn);
        $this->assertNull($set->get('unknown'));
    }

    public function testMissingConfigOrItemGivesEmptySet(): void
    {
        $this->assertTrue(JournalDimensionSet::fromConfig(null)->isEmpty());
        $this->assertTrue(JournalDimensionSet::fromConfig($this->config(null))->isEmpty());
        $this->assertSame([], JournalDimensionSet::empty()->valuesOf(['asset' => 5], []));
    }

    public function testMalformedEntriesAreSkipped(): void
    {
        $set = JournalDimensionSet::fromConfig($this->config(['x' => ['name' => 'bez sloupců'], 'y' => 'text', 'asset' => self::ASSET]));

        $this->assertCount(1, $set);
    }

    public function testValueComesFromRowThenFromHeadColumn(): void
    {
        $set = JournalDimensionSet::fromConfig($this->config([
            'asset'  => self::ASSET,
            'centre' => ['id' => 'centre', 'rowColumn' => 'centre', 'headColumn' => 'head_centre', 'journalColumn' => 'centre', 'table' => 't', 'name' => 'Středisko'],
        ]));

        $this->assertSame(['asset' => 5, 'centre' => 9], $set->valuesOf(['asset' => '5', 'centre' => 9], ['head_centre' => 3]));
        // Prázdná hodnota řádku: dimenze s headColumn bere hlavičku, bez něj null.
        $this->assertSame(['asset' => null, 'centre' => 3], $set->valuesOf(['asset' => null, 'centre' => 0], ['head_centre' => 3, 'asset' => 8]));
        $this->assertSame(['asset' => null, 'centre' => null], $set->valuesOf([], []));
    }

    public function testLabelFollowsDisplayPatternOfTargetTable(): void
    {
        $dimension = JournalDimension::fromArray(self::ASSET);

        $this->assertSame(['asset_number', 'name'], $dimension->labelColumns());
        $this->assertSame('MA0007 — Soustruh', $dimension->label(['asset_number' => 'MA0007', 'name' => 'Soustruh']));
        $this->assertSame(
            'MA0007 — Soustruh',
            $dimension->label(['d_asset__asset_number' => 'MA0007', 'd_asset__name' => 'Soustruh'], 'd_asset__'),
        );
        $this->assertNull($dimension->label(['asset_number' => null, 'name' => null]), 'řádek bez hodnoty dimenze');
    }

    public function testLabelWithoutPatternFallsBackToId(): void
    {
        $dimension = JournalDimension::fromArray(['displayPattern' => null] + self::ASSET);

        $this->assertSame(['id'], $dimension->labelColumns());
        $this->assertSame('#7', $dimension->label(['id' => 7]));
    }
}
