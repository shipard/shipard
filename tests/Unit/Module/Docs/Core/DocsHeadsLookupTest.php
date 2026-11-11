<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\DocsHeadsLookup;

/**
 * Lookup dokladů pro výběr původního dokladu opravného dokladu (#92 U9):
 * jen V pořádku, hledání v čísle, variabilním symbolu a partnerovi, filtry
 * `doc_type` a `partner`, řádek `číslo — partner` s datem a částkou.
 */
class DocsHeadsLookupTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function lookup(array $rows, ?string &$sql = null, ?array &$args = null): DocsHeadsLookup
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $query, mixed ...$params) use ($rows, &$sql, &$args): array {
                $sql = $query;
                $args = $params;
                return $rows;
            },
        );
        $lookup = new DocsHeadsLookup();
        $lookup->setDb($db);
        return $lookup;
    }

    public function testSearchOffersOnlyDoneDocumentsAndSearchesNumberSymbolAndPartner(): void
    {
        $lookup = $this->lookup([
            ['id' => 50, 'doc_number' => '126A0007', 'issue_date' => '2026-03-10', 'total_amount' => 1210.0,
             'doc_currency' => 'czk', 'partner_name' => 'Alfa s.r.o.'],
        ], $sql, $args);

        $items = $lookup->search('alfa', [], 20);

        $this->assertStringContainsString('WHERE h.`docState` = %i', $sql);
        $this->assertSame(40, $args[0]);
        $this->assertStringNotContainsString('h.`doc_type` = %s', $sql, 'bez filtru typu');
        $this->assertStringNotContainsString('h.`partner` = %i', $sql);
        foreach (['h.`doc_number`', 'h.`payment_reference`', 'p.`full_name`'] as $column) {
            $this->assertStringContainsString($column, $sql);
        }
        $this->assertStringContainsString('ORDER BY h.`issue_date` DESC, h.`id` DESC LIMIT %i', $sql);
        $this->assertSame(20, end($args));

        $this->assertCount(1, $items);
        $this->assertSame(50, $items[0]->id);
        $this->assertSame('126A0007 — Alfa s.r.o.', $items[0]->primary);
        $this->assertSame('10. 3. 2026 · 1 210,00 CZK', $items[0]->secondary);
    }

    public function testFiltersByDocTypeAndPartner(): void
    {
        $lookup = $this->lookup([], $sql, $args);
        $lookup->search('', [DocsHeadsLookup::FILTER_DOC_TYPE => 'invno', DocsHeadsLookup::FILTER_PARTNER => '5'], 10);

        $this->assertStringContainsString('h.`doc_type` = %s', $sql);
        $this->assertStringContainsString('h.`partner` = %i', $sql);
        $this->assertSame([40, 'invno', 5, 10], $args, 'prázdný dotaz: bez hledací podmínky');
        $this->assertSame(['doc_type', 'partner'], $lookup->getAllowedFilterKeys());

        // Prázdný typ a nulový partner filtr nepřidají.
        $lookup = $this->lookup([], $sql, $args);
        $lookup->search('', [DocsHeadsLookup::FILTER_DOC_TYPE => '', DocsHeadsLookup::FILTER_PARTNER => 0], 10);
        $this->assertSame([40, 10], $args);
    }

    public function testResolveReadsByIdsWithoutStateRestriction(): void
    {
        $lookup = $this->lookup([
            ['id' => 50, 'doc_number' => '126A0007', 'issue_date' => null, 'total_amount' => null,
             'doc_currency' => 'czk', 'partner_name' => null],
        ], $sql, $args);

        $items = $lookup->resolve(['50', 0, 'x']);

        $this->assertStringContainsString('WHERE h.`id` IN %in', $sql);
        $this->assertStringNotContainsString('docState', $sql, 'vybraný původní doklad se zobrazí v každém stavu');
        $this->assertSame([[50]], $args);
        $this->assertSame('126A0007', $items[0]->primary);
        $this->assertNull($items[0]->secondary);
        $this->assertSame([], $lookup->resolve([]));
        $this->assertSame([], (new DocsHeadsLookup())->search('a', [], 5), 'bez databáze prázdné');
    }

    public function testBuildItemFallbacks(): void
    {
        $item = DocsHeadsLookup::buildItem(['id' => 7, 'doc_number' => '', 'partner_name' => 'Beta a.s.',
            'issue_date' => '2026-01-05', 'total_amount' => -500.5, 'doc_currency' => 'eur']);
        $this->assertSame('Beta a.s.', $item->primary, 'koncept bez čísla: jen partner');
        $this->assertSame('5. 1. 2026 · -500,50 EUR', $item->secondary);

        $item = DocsHeadsLookup::buildItem(['id' => 8, 'doc_number' => '', 'partner_name' => '']);
        $this->assertSame('#8', $item->primary);
        $this->assertNull($item->secondary);

        $item = DocsHeadsLookup::buildItem(['id' => 9, 'doc_number' => 'X', 'total_amount' => 10, 'doc_currency' => '']);
        $this->assertSame('10,00', $item->secondary, 'bez měny jen částka');
    }
}
