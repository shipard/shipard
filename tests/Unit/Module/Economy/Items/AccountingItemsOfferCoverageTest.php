<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Strážce pokrytí obsahových štítků nabídkami účetních položek
 * (tasks/ds-setup-12-accounting-items-tag-coverage.md, #103 D31, D32).
 *
 * Použít návrhu přijatého dokladu mapuje řádek na účetní položku podle
 * štítku; bez položky se štítkem `autoCreateMode` založí položku podle
 * řádku — bez účtu. Proto každý štítek taxonomie kromě vědomé výjimky
 * musí mít v každé variantě nabídky právě jednu položku a účet každé
 * položky musí existovat v osnově své varianty.
 *
 * Repair: chybějící štítek → doplň ho k položce nabídky (nebo založ
 * položku a případně analytiku v osnově, obě varianty); štítek u dvou
 * položek → nech ho jen u jedné (resolver bere první v pořadí souboru,
 * druhá by byla mrtvá); neznámý účet → doplň analytiku do osnovy.
 * Novou výjimku nepřidávej bez rozhodnutí v tasku.
 */
class AccountingItemsOfferCoverageTest extends TestCase
{
    /**
     * Štítky vědomě bez položky v nabídce → důvod. Jediný je
     * `admin.other`: nezařazené — rozhoduje uživatel, nabídka by jen
     * hádala účet (resolver ho přes prefix `admin.*` stejně dovede na
     * první admin položku, ale položku mu nezakládáme).
     */
    private const UNMAPPED_BY_DESIGN = [
        'admin.other' => 'nezařazené — rozhoduje uživatel',
    ];

    private const VARIANTS = [
        'default' => [
            'offer' => '/economy/items/config/accountingItemsDefault.jsonc',
            'chart' => '/economy/accounting/config/accountChartDefault.jsonc',
        ],
        'npo' => [
            'offer' => '/economy/items/config/accountingItemsNpo.jsonc',
            'chart' => '/economy/accounting/config/accountChartNpo.jsonc',
        ],
    ];

    private function modulesDir(): string
    {
        return dirname(__DIR__, 5) . '/modules';
    }

    /** @return list<string> */
    private function taxonomyTags(): array
    {
        $taxonomy = JsoncParser::parseFile($this->modulesDir() . '/core/exchange/config/contentTags.jsonc');
        $this->assertIsArray($taxonomy);
        $this->assertNotEmpty($taxonomy);
        return array_map(strval(...), array_keys($taxonomy));
    }

    /**
     * Štítek → kódy položek nabídky, které ho nesou (v pořadí souboru).
     *
     * @return array<string, list<string>>
     */
    private function tagsOfOffer(string $variant): array
    {
        $seed = JsoncParser::parseFile($this->modulesDir() . self::VARIANTS[$variant]['offer']);
        $this->assertIsArray($seed['items'] ?? null, $variant);

        $byTag = [];
        foreach ($seed['items'] as $item) {
            foreach ($item['contentTags'] ?? [] as $tag) {
                $byTag[$tag][] = (string) $item['code'];
            }
        }
        return $byTag;
    }

    /** @return list<string> čísla účtů osnovy */
    private function chartNumbers(string $variant): array
    {
        $chart = JsoncParser::parseFile($this->modulesDir() . self::VARIANTS[$variant]['chart']);
        $this->assertIsArray($chart, $variant);
        return array_map(strval(...), array_column($chart, 'number'));
    }

    /** @return iterable<string, array{string}> */
    public static function variants(): iterable
    {
        foreach (array_keys(self::VARIANTS) as $variant) {
            yield $variant => [$variant];
        }
    }

    public function testExceptionsAreTaxonomyTags(): void
    {
        foreach (array_keys(self::UNMAPPED_BY_DESIGN) as $tag) {
            $this->assertContains($tag, $this->taxonomyTags(), "výjimka {$tag} není v taxonomii");
        }
    }

    #[DataProvider('variants')]
    public function testEveryTagHasExactlyOneItem(string $variant): void
    {
        $byTag = $this->tagsOfOffer($variant);

        foreach ($this->taxonomyTags() as $tag) {
            if (isset(self::UNMAPPED_BY_DESIGN[$tag])) {
                $this->assertArrayNotHasKey(
                    $tag,
                    $byTag,
                    "{$variant}: {$tag} je výjimka (" . self::UNMAPPED_BY_DESIGN[$tag] . '), nabídka ho nést nemá',
                );
                continue;
            }
            $this->assertArrayHasKey($tag, $byTag, "{$variant}: štítek {$tag} nemá v nabídce žádnou položku");
            $this->assertCount(
                1,
                $byTag[$tag],
                "{$variant}: štítek {$tag} nese víc položek (" . implode(', ', $byTag[$tag]) . ')',
            );
        }
    }

    #[DataProvider('variants')]
    public function testOfferCarriesOnlyTaxonomyTags(string $variant): void
    {
        $taxonomy = $this->taxonomyTags();
        foreach ($this->tagsOfOffer($variant) as $tag => $codes) {
            $this->assertContains(
                $tag,
                $taxonomy,
                "{$variant}: položka " . implode(', ', $codes) . " nese štítek {$tag} mimo taxonomii",
            );
        }
    }

    #[DataProvider('variants')]
    public function testEveryItemAccountExistsInChart(string $variant): void
    {
        $numbers = array_flip($this->chartNumbers($variant));
        $seed = JsoncParser::parseFile($this->modulesDir() . self::VARIANTS[$variant]['offer']);

        foreach ($seed['items'] as $item) {
            $account = (string) ($item['account'] ?? '');
            $this->assertNotSame('', $account, "{$variant}: položka {$item['code']} bez účtu");
            $this->assertArrayHasKey(
                $account,
                $numbers,
                "{$variant}: položka {$item['code']} míří na účet {$account}, který v osnově není",
            );
        }
    }
}
