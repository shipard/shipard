<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dashboard;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedContext;
use Shipard\Module\Core\Exchange\Dashboard\ContentTagSuggestionsSource;

/**
 * Karta položky k založení (tasks/content-tag-ui.md D25) — dedupe per
 * štítek, zmizení při pokrytí položkou, volba účtu u goods.stock, štítky bez
 * mapování nekartují; sekce Položky k založení a titulek = label štítku
 * (#101 D2, D6).
 */
class ContentTagSuggestionsSourceTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $tagRows agregované otevřené návrhy
     *        (tag, waiting, latest)
     * @param list<string> $coveredTags štítky nesené živou položkou
     */
    private function context(
        array $tagRows = [],
        array $coveredTags = [],
        string $language = 'cs',
    ): FeedContext {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($tagRows, $coveredTags): array {
                $sql = (string) $args[0];
                if (str_contains($sql, 'core_mail_message_analyses')) {
                    return $tagRows;
                }
                if (str_contains($sql, 'economy_items')) {
                    return $coveredTags === []
                        ? []
                        : [['content_tags' => json_encode($coveredTags)]];
                }
                return [];
            },
        );
        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args): mixed {
                $sql = (string) $args[0];
                if (str_contains($sql, 'core_system_settings')) {
                    return json_encode('default');
                }
                if (str_contains($sql, 'economy_accounting_accounts')) {
                    return match ((string) ($args[1] ?? '')) {
                        '501' => '501100',
                        '504' => '504100',
                        default => null,
                    };
                }
                return null;
            },
        );

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.exchange.contentTags', [
                'vehicle.fuel' => ['name' => 'Pohonné hmoty', 'order' => 10],
                'goods.stock'  => ['name' => 'Zboží / materiál na sklad', 'order' => 310],
                'admin.other'  => ['name' => 'Ostatní (bez zařazení)', 'order' => 400],
            ]],
        ]);

        return new FeedContext($db, $config, $language, 30);
    }

    public function testUncoveredTagWithOpenSuggestionsCards(): void
    {
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            tagRows: [['tag' => 'vehicle.fuel', 'waiting' => 3, 'latest' => '2026-08-18 10:00:00']],
        ));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('content_tag:vehicle.fuel', $card['id']);
        $this->assertSame('review', $card['kind']);
        // Sekce Položky k založení (#101 D2) — kind zůstává review.
        $this->assertSame('newItems', $card['feedSection']);
        // Titulek bez prefixu „Nová kategorie:“ (#101 D6).
        $this->assertSame('Pohonné hmoty', $card['title']);
        $this->assertStringContainsString('3 doklady čekají', $card['subtitle']);
        $this->assertStringContainsString('Spotřeba PHM (503100)', $card['subtitle']);
        $this->assertCount(1, $card['actions']);
        $action = $card['actions'][0];
        $this->assertSame('materialize_content_tag', $action['kind']);
        $this->assertSame('Založit položku', $action['label']);
        $this->assertSame(['tag' => 'vehicle.fuel'], $action['target']);
        $this->assertTrue($action['primary']);
    }

    public function testCoveredTagDoesNotCard(): void
    {
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            tagRows: [['tag' => 'vehicle.fuel', 'waiting' => 3, 'latest' => null]],
            coveredTags: ['vehicle.fuel'],
        ));

        $this->assertSame([], $cards);
    }

    public function testGoodsStockOffersMaterialOrGoodsAccounts(): void
    {
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            tagRows: [['tag' => 'goods.stock', 'waiting' => 1, 'latest' => null]],
        ));

        $this->assertCount(1, $cards);
        $actions = $cards[0]['actions'];
        $this->assertCount(2, $actions);
        $this->assertSame('Jako materiál (501100)', $actions[0]['label']);
        $this->assertSame(['tag' => 'goods.stock', 'account' => '501100'], $actions[0]['target']);
        $this->assertSame('Jako zboží (504100)', $actions[1]['label']);
        $this->assertSame(['tag' => 'goods.stock', 'account' => '504100'], $actions[1]['target']);
        $this->assertStringContainsString('1 doklad čeká', $cards[0]['subtitle']);
    }

    public function testTagWithoutOfferMappingDoesNotCard(): void
    {
        // admin.other je vědomě bez mapování (review by design) — karta by
        // neměla co založit.
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            tagRows: [['tag' => 'admin.other', 'waiting' => 5, 'latest' => null]],
        ));

        $this->assertSame([], $cards);
    }

    public function testMultipleTagsCardIndependently(): void
    {
        // Dedupe per štítek dělá GROUP BY v SQL — zdroj z agregovaných řádků
        // staví jednu kartu per štítek.
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            tagRows: [
                ['tag' => 'vehicle.fuel', 'waiting' => 2, 'latest' => null],
                ['tag' => 'goods.stock', 'waiting' => 1, 'latest' => null],
            ],
        ));

        $this->assertSame(
            ['content_tag:vehicle.fuel', 'content_tag:goods.stock'],
            array_column($cards, 'id'),
        );
    }

    public function testEnglishTexts(): void
    {
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            tagRows: [['tag' => 'vehicle.fuel', 'waiting' => 2, 'latest' => null]],
            language: 'en',
        ));

        $this->assertSame('Pohonné hmoty', $cards[0]['title']);
        $this->assertStringContainsString('2 documents waiting', $cards[0]['subtitle']);
        $this->assertSame('Create item', $cards[0]['actions'][0]['label']);
    }
}
