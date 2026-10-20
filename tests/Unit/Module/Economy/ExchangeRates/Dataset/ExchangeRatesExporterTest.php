<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates\Dataset;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\AccountingDateRule;
use Shipard\Module\Economy\ExchangeRates\Dataset\ExchangeRatesExporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRate;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\FakeExchangeRateResolver;

/**
 * Dump sekce exchangeRates (#121 D10, U7): jen lístky, které resolver
 * použije pro doklady a návrhy v cizí měně; formát lístku bez zdroje a id;
 * dvojice bez lístku = varování; bez cizí měny prázdno.
 */
class ExchangeRatesExporterTest extends TestCase
{
    private const LISTS = [
        7 => ['id' => 7, 'list_type' => 'cz.cnb.daily', 'home_currency' => 'czk', 'valid_from' => '2026-06-05', 'valid_to' => null, 'list_number' => '110'],
        9 => ['id' => 9, 'list_type' => 'cz.cnb.daily', 'home_currency' => 'czk', 'valid_from' => '2026-06-12', 'valid_to' => null, 'list_number' => null],
        3 => ['id' => 3, 'list_type' => 'cz.cnb.other', 'home_currency' => 'czk', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'list_number' => '6'],
    ];
    private const VALUES = [
        7 => [['currency' => 'jpy', 'amount' => 100, 'rate' => '15.871000'], ['currency' => 'eur', 'amount' => 1, 'rate' => '24.325000']],
        9 => [['currency' => 'usd', 'amount' => 1, 'rate' => '21.000000']],
        3 => [['currency' => 'thb', 'amount' => 100, 'rate' => '69.500000']],
    ];

    /** @param list<string> $sql zachycené dotazy */
    private function db(array $docs, array $analyses, array &$sql): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use ($docs, $analyses, &$sql): array {
            $query = (string) $args[0];
            $sql[] = $query;
            if (str_contains($query, 'docs_core_heads')) {
                return $docs;
            }
            if (str_contains($query, 'core_mail_message_analyses')) {
                return $analyses;
            }
            if (str_contains($query, '[id] IN %in')) {
                $ids = $args[2];
                $rows = array_values(array_intersect_key(self::LISTS, array_flip($ids)));
                usort($rows, static fn(array $a, array $b): int => [$a['valid_from'], $a['list_type'], $a['id']] <=> [$b['valid_from'], $b['list_type'], $b['id']]);
                return $rows;
            }
            if (str_contains($query, '[list] = %i')) {
                $values = self::VALUES[(int) $args[2]] ?? [];
                usort($values, static fn(array $a, array $b): int => $a['currency'] <=> $b['currency']);
                return $values;
            }
            return [];
        });
        return $db;
    }

    private function resolver(): FakeExchangeRateResolver
    {
        return new FakeExchangeRateResolver([
            'eur|2026-06-10' => new ExchangeRate(24.325, 'cz.cnb.daily', '2026-06-05', '110', 7),
            'eur|2026-06-11' => new ExchangeRate(24.325, 'cz.cnb.daily', '2026-06-05', '110', 7),
            'usd|2026-06-12' => new ExchangeRate(21.0, 'cz.cnb.daily', '2026-06-12', null, 9),
            'thb|2026-06-03' => new ExchangeRate(0.695, 'cz.cnb.other', '2026-06-01', '6', 3),
        ]);
    }

    public function testExportsOnlyListsUsedByDocumentsAndProposals(): void
    {
        $sql = [];
        $docs = [
            ['doc_currency' => 'eur', 'accounting_date' => new \DateTimeImmutable('2026-06-10')],
            ['doc_currency' => 'usd', 'accounting_date' => '2026-06-12'],
            ['doc_currency' => 'eur', 'accounting_date' => '2026-06-11'],
        ];
        $analyses = [
            ['canonical_json' => json_encode(['currency' => 'THB', 'dates' => ['issueDate' => '2026-06-03']])],
            ['canonical_json' => json_encode(['currency' => 'CZK', 'dates' => ['issueDate' => '2026-06-03']])],
            ['canonical_json' => json_encode(['currency' => 'EUR', 'dates' => ['accountingDate' => '2026-06-10', 'issueDate' => '2026-06-01']])],
            ['canonical_json' => json_encode(['currency' => 'CHF', 'dates' => ['issueDate' => '2026-06-20']])],
            ['canonical_json' => 'not json'],
        ];
        $resolver = $this->resolver();
        $exporter = new ExchangeRatesExporter($this->db($docs, $analyses, $sql), $resolver);

        $records = $exporter->exportAll();

        $this->assertSame('exchangeRates', $exporter->section());
        $this->assertSame([3, 7, 9], array_map(static fn($r) => $r->id, $records), 'podle platnosti od');
        $this->assertSame(['cz.cnb.other-2026-06-01', 'cz.cnb.daily-2026-06-05', 'cz.cnb.daily-2026-06-12'], array_map(static fn($r) => $r->slug, $records));
        $this->assertSame([
            'format'       => 'shpd.exchangeRates.list.v1',
            'listType'     => 'cz.cnb.daily',
            'homeCurrency' => 'CZK',
            'validFrom'    => '2026-06-05',
            'listNumber'   => '110',
            'rates'        => [
                ['currency' => 'EUR', 'amount' => 1, 'rate' => 24.325],
                ['currency' => 'JPY', 'amount' => 100, 'rate' => 15.871],
            ],
        ], $records[1]->data, 'bez validTo (null vynecháno), bez zdroje a id');
        $this->assertSame('2026-06-30', $records[0]->data['validTo']);
        $this->assertArrayNotHasKey('listNumber', $records[2]->data);
        $this->assertSame(
            [['chf', '2026-06-20'], ['eur', '2026-06-10'], ['eur', '2026-06-11'], ['thb', '2026-06-03'], ['usd', '2026-06-12']],
            array_values(array_unique($resolver->lookups, SORT_REGULAR)),
            'každá dvojice měna × datum jednou (seřazené), domácí měna nikdy',
        );
        $this->assertSame(['exchangeRates: kurz CHF k 2026-06-20 není v lístcích — doklad po seedu kurz nedostane'], $exporter->getWarnings());
        $this->assertStringContainsString("[docState] <> 90", $sql[0]);
        $this->assertStringContainsString('[m.docState] <> 90', $sql[1]);
    }

    /** Účetní datum návrhu dává injektované pravidlo (#103 D78: přijatá faktura DUZP). */
    public function testProposalAccountingDateComesFromInjectedRule(): void
    {
        $sql = [];
        $analyses = [
            ['canonical_json' => json_encode(['docType' => 'invoiceReceived', 'currency' => 'EUR', 'dates' => ['taxPointDate' => '2026-06-10', 'issueDate' => '2026-06-12']])],
        ];
        $resolver = $this->resolver();
        $exporter = new ExchangeRatesExporter($this->db([], $analyses, $sql), $resolver, true, true, AccountingDateRule::fromCanonical(...));
        $exporter->exportAll();
        $this->assertSame([['eur', '2026-06-10']], array_values(array_unique($resolver->lookups, SORT_REGULAR)));

        // Bez pravidla platí accountingDate ?? issueDate.
        $resolver = $this->resolver();
        $exporter = new ExchangeRatesExporter($this->db([], $analyses, $sql), $resolver);
        $exporter->exportAll();
        $this->assertSame([['eur', '2026-06-12']], array_values(array_unique($resolver->lookups, SORT_REGULAR)));
    }

    public function testNoForeignCurrencyMeansEmptySection(): void
    {
        $sql = [];
        $exporter = new ExchangeRatesExporter($this->db([], [], $sql), $this->resolver());
        $this->assertSame([], $exporter->exportAll());
        $this->assertSame([], $exporter->getWarnings());
        $this->assertCount(2, $sql, 'jen dva dotazy na záznamy, žádný na lístky');
    }

    public function testTablesCanBeSkipped(): void
    {
        $sql = [];
        $exporter = new ExchangeRatesExporter($this->db([['doc_currency' => 'eur', 'accounting_date' => '2026-06-10']], [], $sql), $this->resolver(), hasDocuments: false, hasMail: false);
        $this->assertSame([], $exporter->exportAll());
        $this->assertSame([], $sql);

        $this->assertSame([7], array_map(static fn($r) => $r->id, $exporter->exportByIds([7, 7])));
    }
}
