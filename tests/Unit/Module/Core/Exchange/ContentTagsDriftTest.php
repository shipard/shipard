<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Document\VatCodeDerivation;

/**
 * Drift guard obsahové taxonomie (tasks/content-tag-enrichment.md):
 * klíče `contentTags` v nabídkách účetních položek (default + NPO)
 * a klíče v economy.items.contentTagDefaults musí existovat v cfgItem
 * core.exchange.contentTags. Bez tohoto testu překlep ve štítku v nabídce
 * potichu vyrobí položku, kterou resolver nikdy nenajde.
 *
 * Repair: přidej štítek do modules/core/exchange/config/contentTags.jsonc
 * (jen pokud se opravdu účtuje jinak — břitva D2), nebo oprav překlep
 * v nabídce / contentTagDefaults.
 *
 * Druhý guard: `crossBorderSupply` (tasks/exchange-received-supply-kind.md
 * D2/D4) musí přesně odpovídat whitelistu — tiché smazání nebo přidání
 * atributu mění kód DPH návrhu u dokladů ze zahraničí. Repair: změnu
 * nejdřív rozhodnout v tasku, pak upravit obě místa.
 */
class ContentTagsDriftTest extends TestCase
{
    /** Whitelist z tasku (D2 mapování): hodnota → štítky. Ostatní štítky atribut nemají. */
    private const CROSS_BORDER_SUPPLY = [
        'services' => [
            'it.software', 'it.hosting', 'it.phone', 'it.internet',
            'services.accounting', 'services.legal', 'services.marketing', 'services.shipping',
        ],
        'goods' => [
            'it.hardware', 'office.supplies', 'office.equipment', 'office.cleaning',
            'goods.stock', 'vehicle.parts', 'vehicle.consumables', 'people.workwear',
        ],
        'special' => [
            'travel.accommodation', 'travel.fares', 'people.catering',
            'premises.rent', 'premises.electricity', 'premises.gas', 'premises.water',
            'premises.maintenance', 'premises.security', 'vehicle.toll', 'vehicle.parking',
        ],
    ];

    private function modulesDir(): string
    {
        return dirname(__DIR__, 5) . '/modules';
    }

    /** @return array<string, mixed> */
    private function taxonomy(): array
    {
        $taxonomy = JsoncParser::parseFile(
            $this->modulesDir() . '/core/exchange/config/contentTags.jsonc',
        );
        $this->assertIsArray($taxonomy);
        $this->assertNotEmpty($taxonomy);
        return $taxonomy;
    }

    public function testTaxonomyEntriesAreWellFormed(): void
    {
        foreach ($this->taxonomy() as $key => $entry) {
            $this->assertMatchesRegularExpression(
                '/^[a-z]+\.[a-zA-Z]+$/',
                (string) $key,
                'Klíč štítku musí mít prefixovou konvenci group.tag',
            );
            $this->assertIsArray($entry, $key);
            foreach (['name', 'name:cs', 'name:en'] as $field) {
                $this->assertArrayHasKey($field, $entry, "{$key}: chybí {$field}");
            }
        }
    }

    public function testOfferContentTagsExistInTaxonomy(): void
    {
        $taxonomy = $this->taxonomy();
        $offers = [
            'default' => '/economy/items/config/accountingItemsDefault.jsonc',
            'npo'     => '/economy/items/config/accountingItemsNpo.jsonc',
        ];

        foreach ($offers as $variant => $file) {
            $seed = JsoncParser::parseFile($this->modulesDir() . $file);
            $this->assertIsArray($seed['items'] ?? null, $variant);
            foreach ($seed['items'] as $item) {
                foreach ($item['contentTags'] ?? [] as $tag) {
                    $this->assertArrayHasKey(
                        $tag,
                        $taxonomy,
                        "{$variant}: položka {$item['code']} nese neznámý štítek {$tag}",
                    );
                }
            }
        }
    }

    public function testContentTagDefaultsKeysExistInTaxonomy(): void
    {
        $taxonomy = $this->taxonomy();
        $defaults = JsoncParser::parseFile(
            $this->modulesDir() . '/economy/items/config/contentTagDefaults.jsonc',
        );
        $this->assertIsArray($defaults);

        foreach (array_keys($defaults) as $tag) {
            $this->assertArrayHasKey(
                $tag,
                $taxonomy,
                "contentTagDefaults: neznámý štítek {$tag}",
            );
        }
    }

    public function testCrossBorderSupplyMatchesWhitelist(): void
    {
        $taxonomy = $this->taxonomy();

        $expected = [];
        foreach (self::CROSS_BORDER_SUPPLY as $value => $tags) {
            $this->assertContains($value, VatCodeDerivation::TAG_SUPPLY_VALUES);
            foreach ($tags as $tag) {
                $this->assertArrayHasKey($tag, $taxonomy, "whitelist: neznámý štítek {$tag}");
                $expected[$tag] = $value;
            }
        }

        foreach ($taxonomy as $key => $entry) {
            $actual = $entry['crossBorderSupply'] ?? null;
            if ($actual !== null) {
                $this->assertContains(
                    $actual,
                    VatCodeDerivation::TAG_SUPPLY_VALUES,
                    "{$key}: crossBorderSupply mimo výčet goods / services / special",
                );
            }
            $this->assertSame(
                $expected[$key] ?? null,
                $actual,
                "{$key}: crossBorderSupply neodpovídá whitelistu (tasks/exchange-received-supply-kind.md D2)",
            );
        }
    }
}
