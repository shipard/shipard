<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Operace majetku `asset.*` (docs/assets.md D48) nad skutečnou konfigurací:
 * vlajky v `rowOperations.jsonc` a krok předpisu CZ pro `cmnbkp`. Bez kroku
 * by řádek dokladu majetku tiše nevznikl v deníku.
 */
class AssetRowOperationsParityTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    private const OPERATIONS = [
        'asset.activation', 'asset.improvement', 'asset.reduction', 'asset.depreciation', 'asset.disposal',
    ];

    /** @return array<string, mixed> */
    private function operations(): array
    {
        return JsoncParser::parseFile(self::MODULES . '/docs/core/config/rowOperations.jsonc');
    }

    public function testAssetOperationsCarryAccountSideAssetAndSystemFlags(): void
    {
        $cfg = $this->operations();

        $flagged = array_keys(array_filter($cfg, static fn(mixed $op): bool => is_array($op) && !empty($op['rowAsset'])));
        $this->assertSame(self::OPERATIONS, $flagged, 'operace s kartou majetku');

        foreach (self::OPERATIONS as $operation) {
            $entry = $cfg[$operation];
            $this->assertSame(1, $entry['rowSide'], $operation);
            $this->assertSame('direct', $entry['rowAccount'], $operation);
            $this->assertSame(1, $entry['system'], "{$operation}: ručně zadat nejde");
            $this->assertSame(['cmnbkp'], array_keys($entry['docTypes']), "{$operation}: jen účetní doklad");
            $this->assertArrayHasKey('name:cs', $entry);
            $this->assertArrayHasKey('name:en', $entry);
        }
    }

    public function testCzRulesBookEveryAssetOperationFromRow(): void
    {
        $rules = JsoncParser::parseFile(self::MODULES . '/economy/accounting/config/accountingRules.cz.jsonc');
        $steps = [];
        foreach ($rules['documents'] as $document) {
            if ($document['docType'] === 'cmnbkp') {
                $steps = $document['accounting'];
            }
        }

        foreach (self::OPERATIONS as $operation) {
            $covering = array_values(array_filter($steps, static fn(array $step): bool
                => ($step['operation'] ?? null) === $operation
                    || in_array($operation, $step['operations'] ?? [], true)));
            $this->assertCount(1, $covering, "{$operation}: právě jeden krok předpisu cmnbkp");
            $this->assertSame('rows', $covering[0]['src']);
            $this->assertSame('row', $covering[0]['accountSrc'], "{$operation}: účet z řádku");
            $this->assertSame('row', $covering[0]['sideSrc'], "{$operation}: strana z řádku");
        }
    }
}
