<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Module\Core\Prints\PrintTextResolver;
use Shipard\Tests\Unit\Core\Prints\PrintDefinitionTest;

/**
 * Výběr textů pro tisk záznamu (#90 D47, D48, D52). Stav, slot, jazyk
 * a platnost filtruje SQL (ověřují se jeho parametry; chování nad skutečnou
 * databází má integrační test), cílení na tisk, typ a řadu PHP.
 */
class PrintTextResolverTest extends TestCase
{
    private const INVOICE_RECORD = ['id' => 123, 'doc_type' => 'invno', 'number_series' => 3, 'docState' => 40];

    /** @var list<array<int, mixed>> */
    private array $queries = [];

    /**
     * @param list<array<string, mixed>> $rows Řádky, které „vrátí databáze“.
     */
    private function resolver(array $rows, bool $tableExists = true): PrintTextResolver
    {
        $this->queries = [];
        $db = $this->createStub(DataSourceConnection::class);
        $db->method('getTableColumns')->willReturn($tableExists ? ['id' => 'int(11)'] : []);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use ($rows): array {
            $this->queries[] = $args;
            return $rows;
        });
        return new PrintTextResolver($db);
    }

    /** @param array<string, mixed> $overrides */
    private static function invoice(array $overrides = []): PrintDefinition
    {
        return PrintDefinition::fromArray(PrintDefinitionTest::declaration($overrides + [
            'sendPurpose' => 'invoices', 'recipientPerson' => 'partner',
            'textSlots'   => ['header', 'footer', 'emailBody'],
        ]), 'docs.invoicesOut');
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function row(int $id, array $override = []): array
    {
        return $override + [
            'id' => $id, 'slot' => 'footer', 'text' => "text {$id}",
            'prints' => null, 'doc_types' => null, 'number_series' => null,
        ];
    }

    /**
     * @param array<string, list<array{id: int, text: string}>> $texts
     * @return array<string, list<int>> slot → id textů
     */
    private static function ids(array $texts): array
    {
        return array_map(static fn (array $slot): array => array_column($slot, 'id'), $texts);
    }

    private static function day(string $date = '2026-10-05'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . 'T23:30:00');
    }

    public function testQueryFiltersStateSlotsLanguageAndPrintDay(): void
    {
        $this->resolver([])->resolve(self::invoice(), self::INVOICE_RECORD, 'de', self::day('2026-10-05'));

        $this->assertCount(1, $this->queries);
        [$sql, $table, $docState, $slots, $language, $from, $to] = $this->queries[0];
        $this->assertSame('core_prints_texts', $table);
        // Jen stav V pořádku a sloty, které tisk deklaruje.
        $this->assertSame(40, $docState);
        $this->assertSame(['header', 'footer', 'emailBody'], $slots);
        $this->assertSame('de', $language);
        // Platnost ke dni tisku, oba kraje včetně.
        $this->assertSame('2026-10-05', $from);
        $this->assertSame('2026-10-05', $to);
        $this->assertStringContainsString('[docState] = %i', $sql);
        $this->assertStringContainsString('[language] IS NULL OR [language] = %s', $sql);
        $this->assertStringContainsString('[valid_from] IS NULL OR [valid_from] <= %s', $sql);
        $this->assertStringContainsString('[valid_to] IS NULL OR [valid_to] >= %s', $sql);
        $this->assertStringContainsString('ORDER BY [order_pos], [id]', $sql);
    }

    public function testAllTextsOfSlotAreReturnedInDatabaseOrder(): void
    {
        $texts = $this->resolver([
            self::row(5),
            self::row(2),
            self::row(9, ['slot' => 'header']),
            self::row(3),
        ])->resolve(self::invoice(), self::INVOICE_RECORD, 'cs', self::day());

        $this->assertSame(['footer' => [5, 2, 3], 'header' => [9]], self::ids($texts));
        $this->assertSame(['id' => 5, 'text' => 'text 5'], $texts['footer'][0]);
    }

    public function testTextTargetedAtOtherPrintsIsSkipped(): void
    {
        $texts = $this->resolver([
            self::row(1, ['prints' => '["docs.invoicesOut.invoice","docs.cashDocs.cash"]']),
            self::row(2, ['prints' => '["docs.cashDocs.cash"]']),
            self::row(3, ['prints' => null]),
            self::row(4, ['prints' => '[]']),
        ])->resolve(self::invoice(), self::INVOICE_RECORD, 'cs', self::day());

        $this->assertSame(['footer' => [1, 3, 4]], self::ids($texts));
    }

    public function testDocTypeAndSeriesMustMatchTheRecord(): void
    {
        $rows = [
            self::row(1, ['doc_types' => '["invno"]']),
            self::row(2, ['doc_types' => '["cash","invpo"]']),
            self::row(3, ['number_series' => '[3,7]']),
            self::row(4, ['number_series' => '[7]']),
            self::row(5, ['doc_types' => '["invno"]', 'number_series' => '[3]']),
            // Typ sedí, řada ne — obě omezení platí zároveň.
            self::row(6, ['doc_types' => '["invno"]', 'number_series' => '[7]']),
        ];

        $texts = $this->resolver($rows)->resolve(self::invoice(), self::INVOICE_RECORD, 'cs', self::day());
        $this->assertSame(['footer' => [1, 3, 5]], self::ids($texts));

        // Jiná faktura: jiná řada.
        $other = ['number_series' => 7] + self::INVOICE_RECORD;
        $texts = $this->resolver($rows)->resolve(self::invoice(), $other, 'cs', self::day());
        $this->assertSame(['footer' => [1, 3, 4, 6]], self::ids($texts));
    }

    public function testRestrictedTextDoesNotApplyToPrintOverAnotherTable(): void
    {
        $assetCard = self::invoice(['id' => 'economy.assets.card', 'table' => 'economy_assets_assets', 'filter' => []]);
        $record    = ['id' => 5, 'docState' => 40, 'doc_type' => 'invno', 'number_series' => 3];

        $texts = $this->resolver([
            self::row(1),
            // U tabulky bez typu a řady text s omezením neplatí — ne „platí vždy“.
            self::row(2, ['doc_types' => '["invno"]']),
            self::row(3, ['number_series' => '[3]']),
        ])->resolve($assetCard, $record, 'cs', self::day());

        $this->assertSame(['footer' => [1]], self::ids($texts));
    }

    public function testPrintWithoutTextSlotsAsksNothing(): void
    {
        $texts = $this->resolver([self::row(1)])
            ->resolve(self::invoice(['textSlots' => []]), self::INVOICE_RECORD, 'cs', self::day());

        $this->assertSame([], $texts);
        $this->assertSame([], $this->queries);
    }

    public function testDataSourceWithoutTextsTablePrintsWithoutTexts(): void
    {
        // Zdroj dat před `ds-upgrade`: tabulka neexistuje, tisk nesmí spadnout.
        $resolver = $this->resolver([self::row(1)], tableExists: false);

        $this->assertSame([], $resolver->resolve(self::invoice(), self::INVOICE_RECORD, 'cs', self::day()));
        $this->assertSame([], $resolver->resolve(self::invoice(), self::INVOICE_RECORD, 'cs', self::day()));
        $this->assertSame([], $this->queries);
    }
}
