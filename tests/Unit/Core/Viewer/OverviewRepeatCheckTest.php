<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Tests\Integration\Api\ViewerDetailContractTest;

/**
 * Porovnání Přehledu s hlavičkou detailu (#140 D26) nad ručně sestaveným
 * detailem — čisté statické metody kontraktu `ViewerDetailContractTest`,
 * bez zdroje dat: shoda s titulkem, s částí podtitulku (i jako „popisek
 * hodnota“ / „popisek: hodnota“), se štítkem, rekurze `composite`, obsah
 * `document` mimo, prázdná hodnota se nepočítá, ojedinělá vs. soustavná
 * shoda, výjimka.
 */
class OverviewRepeatCheckTest extends TestCase
{
    /**
     * @param list<array{label: string, value: mixed}> $items
     * @param list<array{label: string, style: string}> $badges
     * @return array<string, mixed>
     */
    private static function detail(
        string $title,
        ?string $subtitle,
        array $items,
        array $badges = [],
        ?array $content = null,
    ): array {
        return [
            'title'    => $title,
            'subtitle' => $subtitle,
            'badges'   => $badges,
            'icon'     => 'table',
            'tabs'     => [
                [
                    'id'      => 'overview',
                    'label'   => 'Přehled',
                    'content' => $content ?? [
                        'type'   => 'properties',
                        'groups' => [['title' => 'Identifikace', 'items' => $items]],
                    ],
                ],
                ['id' => 'other', 'label' => 'Jiný', 'content' => ['type' => 'properties', 'groups' => [
                    ['title' => 'Mimo Přehled', 'items' => [['label' => 'Název', 'value' => $title]]],
                ]]],
            ],
        ];
    }

    public function testValueEqualToTitleRepeatsTitle(): void
    {
        $detail = self::detail('Hlavní pokladna', 'HP', [
            ['label' => 'Název', 'value' => 'Hlavní pokladna'],
            ['label' => 'Měna', 'value' => 'CZK'],
        ]);

        $this->assertSame(
            [['label' => 'Název', 'part' => 'title']],
            ViewerDetailContractTest::overviewRepeats($detail),
        );
    }

    public function testValueEqualToSubtitlePartRepeatsSubtitleCaseInsensitively(): void
    {
        $detail = self::detail('Jan Novák', 'Odběratelé · VS 2026001 · 2026', [
            ['label' => 'Saldokonto', 'value' => 'odběratelé'],
            ['label' => 'Období', 'value' => '2026'],
            ['label' => 'Variabilní symbol', 'value' => '2026001'],
            ['label' => 'Měna', 'value' => 'CZK'],
        ]);

        // „VS 2026001“ není ani hodnota, ani „popisek hodnota“ položky Variabilní symbol.
        $this->assertSame(
            [
                ['label' => 'Saldokonto', 'part' => 'subtitle'],
                ['label' => 'Období', 'part' => 'subtitle'],
            ],
            ViewerDetailContractTest::overviewRepeats($detail),
        );
    }

    public function testLabelValuePairInSubtitleRepeatsSubtitle(): void
    {
        $detail = self::detail('Úkol', 'Termín 1. 3. 2026 · Kód osoby: 123', [
            ['label' => 'Termín', 'value' => '1. 3. 2026'],
            ['label' => 'Kód osoby', 'value' => '123'],
            ['label' => 'Kód', 'value' => '123'],
        ]);

        $this->assertSame(
            [
                ['label' => 'Termín', 'part' => 'subtitle'],
                ['label' => 'Kód osoby', 'part' => 'subtitle'],
            ],
            ViewerDetailContractTest::overviewRepeats($detail),
        );
    }

    public function testValueEqualToBadgeLabelRepeatsBadge(): void
    {
        $detail = self::detail('Výpis 12', null, [
            ['label' => 'Stav', 'value' => 'Zaúčtováno'],
            ['label' => 'Rekonciliace', 'value' => 'Souhlasí'],
            ['label' => 'Poznámka', 'value' => 'Nesouhlasí s ničím'],
        ], [
            ['label' => 'Zaúčtováno', 'style' => 'done'],
            ['label' => 'Souhlasí', 'style' => 'success'],
        ]);

        $this->assertSame(
            [
                ['label' => 'Stav', 'part' => 'badge'],
                ['label' => 'Rekonciliace', 'part' => 'badge'],
            ],
            ViewerDetailContractTest::overviewRepeats($detail),
        );
    }

    public function testNormalizationStripsHtmlAndWhitespace(): void
    {
        $detail = self::detail('Název  firmy', null, [
            ['label' => 'Název', 'value' => "  <b>Název</b>\nfirmy &amp; spol."],
            ['label' => 'Jiný', 'value' => 'Název firmy & spol.'],
        ]);
        $detail['title'] = 'Název firmy &amp; spol.';

        $this->assertSame(
            [['label' => 'Název', 'part' => 'title'], ['label' => 'Jiný', 'part' => 'title']],
            ViewerDetailContractTest::overviewRepeats($detail),
        );
    }

    public function testCompositeContentIsWalkedRecursively(): void
    {
        $detail = self::detail('Smlouva o dílo', 'Partner s.r.o. · Smlouvy', [], [], [
            'type'   => 'composite',
            'blocks' => [
                ['type' => 'text', 'text' => 'Smlouva o dílo'],
                ['type' => 'properties', 'groups' => [
                    ['title' => 'Identifikace', 'items' => [
                        ['label' => 'Šanon', 'value' => 'Smlouvy'],
                        ['label' => 'Číslo / značka', 'value' => 'SOD-7'],
                    ]],
                ]],
                ['type' => 'composite', 'blocks' => [
                    ['type' => 'properties', 'groups' => [
                        ['title' => 'Strany', 'items' => [['label' => 'Partner', 'value' => 'Partner s.r.o.']]],
                    ]],
                ]],
            ],
        ]);

        $this->assertSame(
            [['label' => 'Šanon', 'part' => 'subtitle'], ['label' => 'Partner', 'part' => 'subtitle']],
            ViewerDetailContractTest::overviewRepeats($detail),
        );
        $this->assertCount(3, ViewerDetailContractTest::overviewItems($detail));
    }

    public function testDocumentContentHasNoItems(): void
    {
        $detail = self::detail('Dodavatel a.s.', 'FP-2026-0142', [], [], [
            'type'  => 'document',
            'head'  => ['number' => 'FP-2026-0142', 'partner' => 'Dodavatel a.s.'],
            'items' => [['label' => 'Číslo', 'value' => 'FP-2026-0142']],
        ]);

        $this->assertSame([], ViewerDetailContractTest::overviewItems($detail));
        $this->assertSame([], ViewerDetailContractTest::overviewRepeats($detail));
    }

    public function testEmptyValuesAndMissingTabsAreIgnored(): void
    {
        $detail = self::detail('Něco', 'Kód', [
            ['label' => 'Název', 'value' => ''],
            ['label' => 'Kód', 'value' => null],
            ['label' => 'Pole', 'value' => ['ne', 'skalár']],
            ['label' => 'Počet', 'value' => 0],
        ]);

        $this->assertSame(
            [['label' => 'Počet', 'value' => '0']],
            ViewerDetailContractTest::overviewItems($detail),
        );
        $this->assertSame([], ViewerDetailContractTest::overviewRepeats($detail));
        $this->assertSame([], ViewerDetailContractTest::overviewRepeats(['title' => 'Bez tabů', 'tabs' => []]));
    }

    public function testEmptyTitleNeverMatches(): void
    {
        $detail = self::detail('#7', null, [['label' => 'Poznámka', 'value' => 'text']]);
        $detail['title'] = '';

        $this->assertSame([], ViewerDetailContractTest::overviewRepeats($detail));
    }

    public function testSystematicRepeatNeedsEveryRecordWithTheItem(): void
    {
        $a = self::detail('Pokladna A', 'A', [['label' => 'Název', 'value' => 'Pokladna A'], ['label' => 'Kód', 'value' => 'A']]);
        $b = self::detail('Pokladna B', 'B', [['label' => 'Název', 'value' => 'Pokladna B'], ['label' => 'Kód', 'value' => 'X']]);
        $c = self::detail('Pokladna C', 'C', [['label' => 'Název', 'value' => 'Pokladna C'], ['label' => 'Kód', 'value' => 'Y']]);

        // Název opakuje titulek ve všech třech; Kód jen v jednom — ojedinělá shoda.
        $this->assertSame(
            [['label' => 'Název', 'part' => 'title', 'samples' => 3]],
            ViewerDetailContractTest::systematicRepeats([$a, $b, $c]),
        );
        $this->assertSame(
            [['label' => 'Název', 'part' => 'title', 'samples' => 2], ['label' => 'Kód', 'part' => 'subtitle', 'samples' => 2]],
            ViewerDetailContractTest::systematicRepeats([$a, self::detail('Pokladna D', 'D', [
                ['label' => 'Název', 'value' => 'Pokladna D'],
                ['label' => 'Kód', 'value' => 'D'],
            ])]),
        );
    }

    public function testSingleRecordOrSingleOccurrenceIsNotSystematic(): void
    {
        $only = self::detail('Jediný', null, [['label' => 'Název', 'value' => 'Jediný']]);
        $this->assertSame([], ViewerDetailContractTest::systematicRepeats([$only]));

        // Položka Zkratka je jen v jednom ze dvou záznamů — aspoň dva s položkou chybí.
        $withShortcut = self::detail('Metr', 'm', [['label' => 'Název', 'value' => 'Metr'], ['label' => 'Zkratka', 'value' => 'm']]);
        $withoutShortcut = self::detail('Kus', null, [['label' => 'Název', 'value' => 'Kus']]);
        $this->assertSame(
            [['label' => 'Název', 'part' => 'title', 'samples' => 2]],
            ViewerDetailContractTest::systematicRepeats([$withShortcut, $withoutShortcut]),
        );
        $this->assertSame([], ViewerDetailContractTest::systematicRepeats([]));
    }

    public function testAllowedLabelsAreSkippedAndUnusedAllowanceIsHarmless(): void
    {
        $a = self::detail('Služba', null, [['label' => 'Typ položky', 'value' => 'Služba'], ['label' => 'Název', 'value' => 'Služba']]);
        $b = self::detail('Zboží', null, [['label' => 'Typ položky', 'value' => 'Zboží'], ['label' => 'Název', 'value' => 'Zboží']]);

        $this->assertSame(
            [['label' => 'Název', 'part' => 'title', 'samples' => 2]],
            ViewerDetailContractTest::systematicRepeats([$a, $b], ['Typ položky', 'Nikde nepoužitý popisek']),
        );
    }

    public function testDifferentPartsAcrossRecordsAreNotOneSystematicPair(): void
    {
        // Text je v prvním záznamu titulkem, ve druhém částí podtitulku — ani jedna dvojice není soustavná.
        $a = self::detail('Faktura za služby', 'Účet 311', [['label' => 'Text', 'value' => 'Faktura za služby']]);
        $b = self::detail('Partner', 'Faktura za zboží · 311', [['label' => 'Text', 'value' => 'Faktura za zboží']]);

        $this->assertSame([], ViewerDetailContractTest::systematicRepeats([$a, $b]));
    }
}
