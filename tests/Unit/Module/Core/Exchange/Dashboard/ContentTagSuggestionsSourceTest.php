<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dashboard;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedContext;
use Shipard\Module\Core\Exchange\Dashboard\ContentTagSuggestionsSource;
use Shipard\Tests\Fixtures\Core\Feed\ShippedFeedTexts;

/**
 * Karta položky k založení (tasks/content-tag-ui.md D25) — dedupe per
 * štítek, zmizení při pokrytí položkou, volba účtu u goods.stock, štítky bez
 * mapování nekartují; sekce Položky k založení a titulek = label štítku
 * (#101 D2, D6). Texty z katalogu core.exchange.feedTexts (#101 D11):
 * české plurály počtu dokladů, en katalog × en fallback dávají totéž.
 * Štítky řádkových výjimek kartují jako primární, doklad se do každé karty
 * počítá jednou (tasks/content-tag-row-exceptions.md D1).
 */
class ContentTagSuggestionsSourceTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $analysisRows otevřené návrhy —
     *        řádek poslední analýzy per zpráva: tag = primární štítek
     *        (`content_tag`), row_tags = JSON pole štítků řádkových výjimek
     *        jako string (výstup JSON_EXTRACT) nebo null, latest
     * @param list<string> $coveredTags štítky nesené živou položkou
     * @param bool $withCatalog dodávaný katalog textů feedu nad taxonomií
     *        štítků; bez něj zdroj dává anglický fallback (#101 D15)
     */
    private function context(
        array $analysisRows = [],
        array $coveredTags = [],
        string $language = 'cs',
        bool $withCatalog = true,
    ): FeedContext {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($analysisRows, $coveredTags): array {
                $sql = (string) $args[0];
                if (str_contains($sql, 'core_mail_message_analyses')) {
                    return $analysisRows;
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

        $taxonomy = $this->createMock(ConfigRuntime::class);
        $taxonomy->method('cfgItem')->willReturnMap([
            ['core.exchange.contentTags', [
                'vehicle.fuel'         => ['name' => 'Pohonné hmoty', 'order' => 10],
                'vehicle.parking'      => ['name' => 'Parkovné', 'order' => 60],
                'premises.rent'        => ['name' => 'Nájemné', 'order' => 170],
                'premises.electricity' => ['name' => 'Elektřina', 'order' => 180],
                'goods.stock'          => ['name' => 'Zboží / materiál na sklad', 'order' => 310],
                'admin.other'          => ['name' => 'Ostatní (bez zařazení)', 'order' => 400],
            ]],
        ]);
        $config = $taxonomy;
        if ($withCatalog) {
            $config = $this->createMock(ConfigRuntime::class);
            $config->method('cfgItem')->willReturnCallback(ShippedFeedTexts::resolver($language, $taxonomy));
        }

        return new FeedContext($db, $config, $language, 30);
    }

    /**
     * N otevřených návrhů s primárním štítkem a bez řádkových výjimek —
     * dřívější agregát `waiting` rozepsaný na řádky analýz.
     *
     * @return list<array<string, mixed>>
     */
    private static function open(string $tag, int $count = 1, ?string $latest = null): array
    {
        return array_fill(0, $count, ['tag' => $tag, 'row_tags' => null, 'latest' => $latest]);
    }

    /**
     * Otevřený návrh s primárním štítkem a řádkovými výjimkami — `row_tags`
     * ve tvaru JSON_EXTRACT (string s JSON polem, duplicity zachované).
     *
     * @param list<string> $exceptions
     * @return array<string, mixed>
     */
    private static function withExceptions(?string $tag, array $exceptions, ?string $latest = null): array
    {
        return ['tag' => $tag, 'row_tags' => json_encode($exceptions), 'latest' => $latest];
    }

    public function testUncoveredTagWithOpenSuggestionsCards(): void
    {
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: self::open('vehicle.fuel', 3, '2026-08-18 10:00:00'),
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
        $this->assertSame(['tag' => 'vehicle.fuel', 'waiting' => 3], $card['context']);
        $this->assertSame('2026-08-18T10:00:00', substr((string) $card['timestamp'], 0, 19));
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
            analysisRows: self::open('vehicle.fuel', 3),
            coveredTags: ['vehicle.fuel'],
        ));

        $this->assertSame([], $cards);
    }

    public function testGoodsStockOffersMaterialOrGoodsAccounts(): void
    {
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: self::open('goods.stock'),
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
            analysisRows: self::open('admin.other', 5),
        ));

        $this->assertSame([], $cards);
    }

    public function testMultipleTagsCardIndependently(): void
    {
        // Dedupe per štítek dělá agregace v PHP — zdroj z řádků analýz
        // staví jednu kartu per štítek, řazení waiting DESC.
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: [...self::open('goods.stock', 1), ...self::open('vehicle.fuel', 2)],
        ));

        $this->assertSame(
            ['content_tag:vehicle.fuel', 'content_tag:goods.stock'],
            array_column($cards, 'id'),
        );
    }

    public function testRowExceptionTagCardsWithoutPrimaryCoverage(): void
    {
        // Faktura za nájem (primární štítek pokrytý položkou) s řádkem
        // elektřiny ve výjimkách — karta vznikne pro elektřinu (D1).
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: [self::withExceptions('premises.rent', ['premises.electricity'], '2026-10-01 08:00:00')],
            coveredTags: ['premises.rent'],
        ));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('content_tag:premises.electricity', $card['id']);
        $this->assertSame('Elektřina', $card['title']);
        $this->assertSame('1 doklad čeká · návrh: Spotřeba energie (elektřina) (502100)', $card['subtitle']);
        $this->assertSame(['tag' => 'premises.electricity'], $card['actions'][0]['target']);
        $this->assertSame('2026-10-01T08:00:00', substr((string) $card['timestamp'], 0, 19));
    }

    public function testSameTagPrimaryAndInExceptionCountsEachDocumentOnce(): void
    {
        // Doklad A s primárním štítkem X, doklad B s X ve výjimce → jedna
        // karta, waiting = 2; latest = pozdější z obou.
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: [
                ...self::open('premises.electricity', 1, '2026-09-20 12:00:00'),
                self::withExceptions('premises.rent', ['premises.electricity'], '2026-10-01 08:00:00'),
            ],
            coveredTags: ['premises.rent'],
        ));

        $this->assertSame(['content_tag:premises.electricity'], array_column($cards, 'id'));
        $this->assertSame(2, $cards[0]['context']['waiting']);
        $this->assertStringStartsWith('2 doklady čekají · ', $cards[0]['subtitle']);
        $this->assertSame('2026-10-01T08:00:00', substr((string) $cards[0]['timestamp'], 0, 19));
    }

    public function testRepeatedExceptionTagInOneDocumentCountsOnce(): void
    {
        // Dvě parkovné na jedné faktuře — JSON_EXTRACT duplicity nechává,
        // dedupe dělá PHP: waiting = 1.
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: [self::withExceptions('premises.rent', ['vehicle.parking', 'vehicle.parking'])],
            coveredTags: ['premises.rent'],
        ));

        $this->assertSame(['content_tag:vehicle.parking'], array_column($cards, 'id'));
        $this->assertSame(1, $cards[0]['context']['waiting']);
        $this->assertStringStartsWith('1 doklad čeká · návrh: Ostatní služby (518100)', $cards[0]['subtitle']);
    }

    public function testPrimaryAndExceptionTagsCardSeparately(): void
    {
        // Nepokrytý primární štítek i výjimka → dvě karty, doklad v obou.
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: [self::withExceptions('premises.rent', ['vehicle.parking'])],
        ));

        $this->assertSame(
            ['content_tag:premises.rent', 'content_tag:vehicle.parking'],
            array_column($cards, 'id'),
        );
        $this->assertSame([1, 1], array_column(array_column($cards, 'context'), 'waiting'));
    }

    public function testExceptionsWithoutPrimaryTagStillCard(): void
    {
        // Návrh bez primárního štítku, ale s výjimkami (podmínka
        // `rowExceptions[0] IS NOT NULL` v dotazu) — kartuje jen z výjimek.
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: [self::withExceptions(null, ['vehicle.parking'])],
        ));

        $this->assertSame(['content_tag:vehicle.parking'], array_column($cards, 'id'));
    }

    public function testNullRowTagsBehaveAsPrimaryOnly(): void
    {
        // Rule-sourced blok bez výjimek (JSON_EXTRACT → NULL) — jako dřív.
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: [['tag' => 'vehicle.fuel', 'row_tags' => null, 'latest' => null]],
        ));

        $this->assertSame(['content_tag:vehicle.fuel'], array_column($cards, 'id'));
        $this->assertSame(1, $cards[0]['context']['waiting']);
        $this->assertNull($cards[0]['timestamp']);
    }

    public function testOrderingWaitingThenLatestThenTag(): void
    {
        // waiting DESC, latest DESC (null poslední), tag ASC jako tie-break.
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: [
                ...self::open('vehicle.parking', 1, '2026-09-01 00:00:00'),
                ...self::open('premises.rent', 1),
                ...self::open('premises.electricity', 1, '2026-09-01 00:00:00'),
                ...self::open('vehicle.fuel', 2),
            ],
        ));

        $this->assertSame(
            [
                'content_tag:vehicle.fuel',          // waiting 2
                'content_tag:premises.electricity',  // waiting 1, latest shodné, tag ASC
                'content_tag:vehicle.parking',
                'content_tag:premises.rent',         // waiting 1, latest null
            ],
            array_column($cards, 'id'),
        );
    }

    public function testEnglishTexts(): void
    {
        $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: self::open('vehicle.fuel', 2),
            language: 'en',
        ));

        $this->assertSame('Pohonné hmoty', $cards[0]['title']);
        $this->assertStringContainsString('2 documents waiting', $cards[0]['subtitle']);
        $this->assertSame('Create item', $cards[0]['actions'][0]['label']);
    }

    public function testWaitingPluralBoundaries(): void
    {
        // Hranice dřívějšího ručního skloňování (=== 1, < 5): ICU one / few / other.
        $expected = [
            1  => '1 doklad čeká',
            2  => '2 doklady čekají',
            4  => '4 doklady čekají',
            5  => '5 dokladů čeká',
            21 => '21 dokladů čeká',
        ];
        foreach ($expected as $waiting => $text) {
            $cards = (new ContentTagSuggestionsSource())->collectCards($this->context(
                analysisRows: self::open('vehicle.fuel', $waiting),
            ));
            $this->assertStringStartsWith($text . ' · ', $cards[0]['subtitle'], "waiting = {$waiting}");
        }

        $en = (new ContentTagSuggestionsSource())->collectCards($this->context(
            analysisRows: self::open('vehicle.fuel', 1),
            language: 'en',
        ));
        $this->assertStringStartsWith('1 document waiting · ', $en[0]['subtitle']);
    }

    public function testEnglishCatalogAndFallbackWithoutCatalogAgree(): void
    {
        // DS před ds-upgrade (bez cfgItemu) dává stejné anglické texty jako
        // katalog — fallbacky ve zdroji kopírují holé pole katalogu.
        $analysisRows = [...self::open('vehicle.fuel', 2), ...self::open('goods.stock', 1)];
        $fromCatalog  = (new ContentTagSuggestionsSource())->collectCards($this->context($analysisRows, language: 'en'));
        $fromFallback = (new ContentTagSuggestionsSource())->collectCards($this->context($analysisRows, language: 'en', withCatalog: false));

        $this->assertCount(2, $fromCatalog);
        $this->assertStringStartsWith('2 documents waiting · suggestion: ', $fromCatalog[0]['subtitle']);
        $this->assertSame('1 document waiting · choose posting: material or goods', $fromCatalog[1]['subtitle']);
        $this->assertSame('As material (501100)', $fromCatalog[1]['actions'][0]['label']);
        $this->assertSame('As goods (504100)', $fromCatalog[1]['actions'][1]['label']);
        foreach ($fromCatalog as $i => $card) {
            $this->assertSame($card['subtitle'], $fromFallback[$i]['subtitle']);
            $this->assertSame(array_column($card['actions'], 'label'), array_column($fromFallback[$i]['actions'], 'label'));
        }
    }
}
