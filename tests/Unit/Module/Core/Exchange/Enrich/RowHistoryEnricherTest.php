<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Enrich;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;

class RowHistoryEnricherTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $history
     */
    private function buildEnricher(array $history, ?PartyResolver $party = null): RowHistoryEnricher
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn(array_map(
            static fn(array $row) => new Row($row),
            $history,
        ));

        if ($party === null) {
            $party = $this->createMock(PartyResolver::class);
            $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        }

        return new RowHistoryEnricher($db, $party);
    }

    /**
     * @return array<string, mixed>
     */
    private function histRow(
        string $description,
        string $itemCode,
        ?string $vatCode = 'std21',
        ?string $accountNumber = '518001',
        int $docHead = 1001,
        ?string $docNumber = 'FP-2026-0042',
        float|string|null $totalPrice = null,
        ?string $itemName = null,
    ): array {
        return [
            'description'    => $description,
            'vat_code'       => $vatCode,
            'total_price'    => $totalPrice,
            'item_code'      => $itemCode,
            'item_name'      => $itemName ?? ('Položka ' . $itemCode),
            'account_number' => $accountNumber,
            'doc_head'       => $docHead,
            'doc_number'     => $docNumber,
        ];
    }

    /**
     * Historie pro dominance scénáře: $dominantCount řádků s položkou MAT
     * (unikátní texty, disjunktní s čímkoli v testovacích řádcích) +
     * $otherCount řádků s položkou OTHER. Nejnovější první (h.id DESC),
     * nejnovější MAT řádek má doc_head 9999 / FP-2026-9999.
     *
     * @param list<float|string|null> $dominantTotals total_price MAT řádků v pořadí
     * @return list<array<string, mixed>>
     */
    private function dominanceHistory(
        int $dominantCount,
        int $otherCount = 0,
        array $dominantTotals = [],
    ): array {
        $rows = [];
        for ($i = 0; $i < $dominantCount; $i++) {
            $rows[] = $this->histRow(
                "Spojovací materiál šarže {$i}",
                'MAT',
                accountNumber: '501001',
                docHead: 9999 - $i,
                docNumber: 'FP-2026-' . (9999 - $i),
                totalPrice: $dominantTotals[$i] ?? null,
            );
        }
        for ($i = 0; $i < $otherCount; $i++) {
            $rows[] = $this->histRow(
                "Zhodnocení budovy etapa {$i}",
                'OTHER',
                docHead: 8999 - $i,
                docNumber: 'FP-2026-' . (8999 - $i),
            );
        }
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function canonical(array $rows, ?string $selfParty = 'customer'): array
    {
        return [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => 'invoiceReceived',
            'selfParty'     => $selfParty,
            'supplier'      => ['name' => 'O2 Czech Republic', 'companyId' => '60193336'],
            'rows'          => $rows,
        ];
    }

    public function testNormalizedExactMatchFillsTriple(): void
    {
        // Datumy/období/částky se normalizací odstraní → 6/2026 vs 7/2026 je exact-norm.
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M 6/2026', 'NET500'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Internet 500M 7/2026'],
        ]));

        $row = $result['rows'][0];
        $this->assertSame('NET500', $row['item']['ourCode']);
        $this->assertSame('std21', $row['vat']['code']);
        $this->assertSame('518001', $row['account']);

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame(0, $result['_resolve']['rows'][0]['index']);
        $this->assertSame('historyExactNorm', $enrichment['matchedBy']);
        $this->assertSame('high', $enrichment['confidence']);
        $this->assertSame(1001, $enrichment['sourceDocId']);
        $this->assertSame('FP-2026-0042', $enrichment['sourceDocNumber']);
        $this->assertSame(
            ['ourCode' => 'NET500', 'vatCode' => 'std21', 'account' => '518001'],
            $enrichment['suggested'],
        );
    }

    public function testMissingHistoryDocNumberFallsBackToNull(): void
    {
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M', 'NET500', docNumber: null),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Internet 500M'],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertNull($enrichment['sourceDocNumber']);
    }

    public function testRawExactMatchBeatsNormalizedCollision(): void
    {
        // „Linka 500" a „Linka 1000" po normalizaci kolidují („linka"). Historie
        // nejnovější první — bez přednosti stupně 0 by vyhrála novější L1000.
        $enricher = $this->buildEnricher([
            $this->histRow('Linka 1000', 'L1000', docHead: 2002),
            $this->histRow('Linka 500', 'L500', docHead: 2001),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Linka 500'],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('L500', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertSame('high', $enrichment['confidence']);
        $this->assertSame(2001, $enrichment['sourceDocId']);
    }

    public function testFuzzyMatchAboveThresholdIsMedium(): void
    {
        // 4 společné tokeny z 6 → Jaccard 0.667 ≥ 0.6, ale ne exact-norm.
        $enricher = $this->buildEnricher([
            $this->histRow('Pronájem kancelářských prostor budova A', 'RENT-A'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Pronájem kancelářských prostor budova B'],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('RENT-A', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyFuzzy', $enrichment['matchedBy']);
        $this->assertSame('medium', $enrichment['confidence']);
    }

    public function testFuzzyBelowThresholdYieldsNoSuggestion(): void
    {
        $enricher = $this->buildEnricher([
            $this->histRow('Instalace zásuvek elektro', 'ELE01'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Konzultace vývoje software'],
        ]));

        $this->assertArrayNotHasKey('item', $result['rows'][0]);
        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertNull($enrichment['matchedBy']);
        $this->assertNull($enrichment['confidence']);
        $this->assertNull($enrichment['sourceDocNumber']);
        $this->assertSame([], $enrichment['suggested']);
    }

    public function testFilledOurCodeIsSkipped(): void
    {
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M', 'NET500'),
        ]);

        $input = $this->canonical([
            ['description' => 'Internet 500M', 'item' => ['ourCode' => 'X1']],
        ]);
        $result = $enricher->enrich($input);

        $this->assertSame('X1', $result['rows'][0]['item']['ourCode']);
        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertNull($enrichment['matchedBy']);
        $this->assertSame('hasOurCode', $enrichment['skipped']);
    }

    public function testAiVatCodeNotOverwrittenAndEmptyHistoryAccountNotSet(): void
    {
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M', 'NET500', vatCode: 'std21', accountNumber: null),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Internet 500M', 'vat' => ['code' => 'osvob']],
        ]));

        $row = $result['rows'][0];
        $this->assertSame('NET500', $row['item']['ourCode']);
        $this->assertSame('osvob', $row['vat']['code']);
        $this->assertArrayNotHasKey('account', $row);
        $this->assertSame(
            ['ourCode' => 'NET500'],
            $result['_resolve']['rows'][0]['enrichment']['suggested'],
        );
    }

    public function testAccountingRowIsSkipped(): void
    {
        $enricher = $this->buildEnricher([
            $this->histRow('Poplatek za vedení účtu', 'FEE01'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Poplatek za vedení účtu', 'operation' => 'acc.record', 'accSide' => 'debit', 'account' => '568001'],
        ]));

        $this->assertArrayNotHasKey('item', $result['rows'][0]);
        $this->assertSame('568001', $result['rows'][0]['account']);
        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertNull($enrichment['matchedBy']);
        $this->assertSame('noItemRow', $enrichment['skipped']);
    }

    public function testUnmatchedPartnerLeavesCanonicalUntouched(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::notFound());

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');

        $enricher = new RowHistoryEnricher($db, $party);
        $input = $this->canonical([['description' => 'Internet 500M']]);

        $this->assertSame($input, $enricher->enrich($input));
    }

    public function testMissingSelfPartySkipsWithoutResolve(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->expects($this->never())->method('resolve');

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');

        $enricher = new RowHistoryEnricher($db, $party);
        $input = $this->canonical([['description' => 'Internet 500M']], selfParty: null);

        $this->assertSame($input, $enricher->enrich($input));
    }

    public function testHistoryQueryFiltersStatesAndMapsDocType(): void
    {
        // Kandidáti s itemem mimo aktivní stavy (smazáno/archiv) se filtrují už
        // v SQL (JOIN economy_items ... docState IN 10/40/80); historie jen
        // z dokladů 40/80 (doklad s číslem), docType přeložený na short code.
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('docs_core_rows'),
                    $this->stringContains('economy_items'),
                    $this->stringContains('economy_accounting_accounts'),
                ),
                10, 40, 80,
                42, 'invni',
                40, 80,
                200,
            )
            ->willReturn([]);

        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $enricher = new RowHistoryEnricher($db, $party);
        $enricher->enrich($this->canonical([['description' => 'Internet 500M']]));
    }

    public function testItemNameMatchesWhenDescriptionDoesNot(): void
    {
        // Reprodukce DS B (dodavatel A): AI dala do item.description fakturované
        // období (v historii není), item.name je text z historie → dřív
        // matchedBy = null, teď exactRaw přes dalšího kandidáta.
        $enricher = $this->buildEnricher([
            $this->histRow('Měsíční paušál za Internet - TARIF+', 'NET1000', vatCode: 'cz-110', accountNumber: '518100'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['item' => [
                'name'        => 'Měsíční paušál za Internet - TARIF+',
                'description' => 'Fakturované období: 01.07.2026 - 31.07.2026',
            ]],
        ]));

        $row = $result['rows'][0];
        $this->assertSame('NET1000', $row['item']['ourCode']);
        $this->assertSame('cz-110', $row['vat']['code']);
        $this->assertSame('518100', $row['account']);

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertSame('high', $enrichment['confidence']);
        $this->assertSame('Měsíční paušál za Internet - TARIF+', $enrichment['matchedText']);
        $this->assertSame('Položka NET1000', $enrichment['itemName']);
    }

    public function testNameOnlyRowMatches(): void
    {
        // DS C scénář: AI vyplnila jen item.name, description chybí —
        // funguje beze změny (jediný kandidát).
        $enricher = $this->buildEnricher([
            $this->histRow('Konektivita 4LAN', 'LAN01'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['item' => ['name' => 'Konektivita 4LAN']],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('LAN01', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertSame('Konektivita 4LAN', $enrichment['matchedText']);
    }

    public function testDescriptionCandidatePreferredWithinTier(): void
    {
        // Oba kandidáty mají exactRaw zásah na různé historické řádky →
        // uvnitř úrovně vyhrává dřívější kandidát (item.description),
        // i když zásah item.name je na novější historii.
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M', 'NET500', docHead: 3002),
            $this->histRow('Servisní podpora', 'SUP01', docHead: 3001),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['item' => ['name' => 'Internet 500M', 'description' => 'Servisní podpora']],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('SUP01', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertSame('Servisní podpora', $enrichment['matchedText']);
        $this->assertSame(3001, $enrichment['sourceDocId']);
    }

    public function testExactOnNameBeatsFuzzyOnDescription(): void
    {
        // item.description má jen fuzzy zásah, item.name exactRaw na jiný
        // řádek → tier-major: exact vyhrává, i když je na pozdějším kandidátovi.
        $enricher = $this->buildEnricher([
            $this->histRow('Pronájem kancelářských prostor budova A', 'RENT-A', docHead: 4002),
            $this->histRow('Údržba serveru', 'SRV01', docHead: 4001),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['item' => [
                'name'        => 'Údržba serveru',
                'description' => 'Pronájem kancelářských prostor budova B',
            ]],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('SRV01', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertSame('high', $enrichment['confidence']);
        $this->assertSame('Údržba serveru', $enrichment['matchedText']);
    }

    public function testDuplicateCandidatesDeduplicated(): void
    {
        // row.description == item.name → jeden kandidát, chování beze změny.
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M', 'NET500'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Internet 500M', 'item' => ['name' => 'Internet 500M']],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('NET500', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertSame('Internet 500M', $enrichment['matchedText']);
    }

    public function testComposedRowTextMatchesOwnHistoryExactly(): void
    {
        // Historie vzniklá po #84 nese složený text (CanonicalRowText:
        // name — description). Bez složeného textu jako prvního kandidáta
        // by exactRaw na vlastní dřívější řádky selhal a spadlo by se
        // na fuzzy (nebo nic).
        $enricher = $this->buildEnricher([
            $this->histRow('Měsíční paušál za Internet — Fakturované období: 01.08.2026 - 31.08.2026', 'NET500', docHead: 5002),
            $this->histRow('Servisní podpora', 'SUP01', docHead: 5001),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['item' => [
                'name'        => 'Měsíční paušál za Internet',
                'description' => 'Fakturované období: 01.08.2026 - 31.08.2026',
            ]],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('NET500', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertSame('high', $enrichment['confidence']);
        $this->assertSame(
            'Měsíční paušál za Internet — Fakturované období: 01.08.2026 - 31.08.2026',
            $enrichment['matchedText'],
        );
        $this->assertSame(5002, $enrichment['sourceDocId']);
    }

    public function testMatchedTextOriginalFormOnFuzzyAndNullWhenUnmatched(): void
    {
        // Fuzzy vítěz → matchedText nese originální (nenormalizovaný) tvar
        // kandidáta včetně čísel; nenapárovaný řádek má matchedText null.
        $enricher = $this->buildEnricher([
            $this->histRow('Pronájem kancelářských prostor budova A', 'RENT-A'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['item' => [
                'name'        => 'Pronájem kancelářských prostor budova B 7/2026',
                'description' => 'Fakturované období: 01.07.2026 - 31.07.2026',
            ]],
            ['description' => 'Konzultace vývoje software'],
        ]));

        $matched = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('RENT-A', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyFuzzy', $matched['matchedBy']);
        $this->assertSame('medium', $matched['confidence']);
        $this->assertSame('Pronájem kancelářských prostor budova B 7/2026', $matched['matchedText']);

        $unmatched = $result['_resolve']['rows'][1]['enrichment'];
        $this->assertSame(1, $result['_resolve']['rows'][1]['index']);
        $this->assertNull($unmatched['matchedBy']);
        $this->assertNull($unmatched['matchedText']);
        $this->assertNull($unmatched['itemName']);
    }

    public function testDoubleRunIsIdempotent(): void
    {
        // Fresh běh nad už obohaceným canonical: revert vlastních návrhů +
        // nový match musí dát identický výstup (D2, determinismus).
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M 6/2026', 'NET500'),
        ]);

        $first = $enricher->enrich($this->canonical([
            ['description' => 'Internet 500M 7/2026'],
        ]));
        $second = $enricher->enrich($first);

        $this->assertSame($first, $second);
        $this->assertSame('NET500', $second['rows'][0]['item']['ourCode']);
        $this->assertSame('historyExactNorm', $second['_resolve']['rows'][0]['enrichment']['matchedBy']);
    }

    public function testFreshRunDropsSuggestionWhenHistoryNoLongerMatches(): void
    {
        // Persistnutý canonical nese návrh z /result; při apply už položka
        // v historii není (např. smazána) → autoritativní fresh běh návrh odvolá.
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([]);

        $enricher = new RowHistoryEnricher($db, $party);

        $persisted = $this->canonical([
            [
                'description' => 'Internet 500M 7/2026',
                'item'        => ['ourCode' => 'NET500'],
                'vat'         => ['code' => 'std21'],
                'account'     => '518001',
            ],
        ]);
        $persisted['_resolve']['rows'] = [[
            'index'      => 0,
            'enrichment' => [
                'matchedBy'   => 'historyExactNorm',
                'confidence'  => 'high',
                'sourceDocId' => 1001,
                'suggested'   => ['ourCode' => 'NET500', 'vatCode' => 'std21', 'account' => '518001'],
            ],
        ]];

        $result = $enricher->enrich($persisted);

        $row = $result['rows'][0];
        $this->assertNull($row['item']['ourCode']);
        $this->assertNull($row['vat']['code']);
        $this->assertNull($row['account']);
        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertNull($enrichment['matchedBy']);
        $this->assertSame([], $enrichment['suggested']);
    }

    // ── Úroveň 3 — dominantní položka partnera ──────────────────────────────

    public function testDominantItemSuggestedWhenTextTiersFail(): void
    {
        // UNI HOBBY scénář: texty disjunktní, ale 10/12 řádků historie má
        // stejnou položku → historyDominantItem / low, trojice z nejnovějšího
        // MAT řádku, matchedText null, audit dominance {share, rows}.
        $enricher = $this->buildEnricher($this->dominanceHistory(10, 2));

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Hmoždinky, vruty a silikon'],
        ]));

        $row = $result['rows'][0];
        $this->assertSame('MAT', $row['item']['ourCode']);
        $this->assertSame('std21', $row['vat']['code']);
        $this->assertSame('501001', $row['account']);

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('historyDominantItem', $enrichment['matchedBy']);
        $this->assertSame('low', $enrichment['confidence']);
        $this->assertNull($enrichment['matchedText']);
        $this->assertSame('Položka MAT', $enrichment['itemName']);
        $this->assertSame(9999, $enrichment['sourceDocId']);
        $this->assertSame('FP-2026-9999', $enrichment['sourceDocNumber']);
        $this->assertSame(['share' => 0.83, 'rows' => 12], $enrichment['dominance']);
    }

    public function testDominanceBelowShareThresholdNoSuggestion(): void
    {
        // Dělená dominance 7:5 → podíl 0.58 < 0.8, žádný návrh (D4).
        $enricher = $this->buildEnricher($this->dominanceHistory(7, 5));

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Hmoždinky, vruty a silikon'],
        ]));

        $this->assertArrayNotHasKey('item', $result['rows'][0]);
        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertNull($enrichment['matchedBy']);
        $this->assertArrayNotHasKey('dominance', $enrichment);
    }

    public function testDominanceBelowMinRowsNoSuggestion(): void
    {
        // Scénář Svatoňová: malá historie (6 řádků) — i 100% podíl propadne.
        $enricher = $this->buildEnricher($this->dominanceHistory(6));

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Hmoždinky, vruty a silikon'],
        ]));

        $this->assertArrayNotHasKey('item', $result['rows'][0]);
        $this->assertNull($result['_resolve']['rows'][0]['enrichment']['matchedBy']);
    }

    public function testDominanceAmountGuardSuppresses(): void
    {
        // Guard přes částku (D3): max historických total_price MAT je 1200
        // (NULL hodnoty se z maxima vynechají). Řádek 0 nad max → potlačeno;
        // řádek 1 bez totalPrice → guard se neuplatní; řádek 2 pod max → projde.
        $enricher = $this->buildEnricher($this->dominanceHistory(
            10,
            dominantTotals: [1200.0, '850.00', null, 320.0],
        ));

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Vestavěná pergola vč. montáže', 'totalPrice' => 25000.0],
            ['description' => 'Hmoždinky, vruty a silikon'],
            ['description' => 'Spárovací hmota a lepidlo', 'totalPrice' => 500.0],
        ]));

        $this->assertArrayNotHasKey('item', $result['rows'][0]);
        $this->assertNull($result['_resolve']['rows'][0]['enrichment']['matchedBy']);

        $this->assertSame('MAT', $result['rows'][1]['item']['ourCode']);
        $this->assertSame(
            'historyDominantItem',
            $result['_resolve']['rows'][1]['enrichment']['matchedBy'],
        );

        $this->assertSame('MAT', $result['rows'][2]['item']['ourCode']);
        $this->assertSame(
            'historyDominantItem',
            $result['_resolve']['rows'][2]['enrichment']['matchedBy'],
        );
    }

    public function testTextMatchBeatsDominance(): void
    {
        // Fuzzy zásah existuje → vyhraje historyFuzzy, dominance se
        // nekonzultuje (fallback až když textové úrovně selžou).
        $history = $this->dominanceHistory(11);
        $history[] = $this->histRow('Pronájem kancelářských prostor budova A', 'RENT-A', docHead: 8000);
        $enricher = $this->buildEnricher($history);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Pronájem kancelářských prostor budova B'],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('RENT-A', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyFuzzy', $enrichment['matchedBy']);
        $this->assertSame('medium', $enrichment['confidence']);
        $this->assertArrayNotHasKey('dominance', $enrichment);
    }

    public function testDominanceRowWithoutTextCandidates(): void
    {
        // Dominance nevyžaduje kandidátní texty — řádek bez description/name
        // návrh dostane (textové úrovně se bez kandidátů vůbec nezkouší).
        $enricher = $this->buildEnricher($this->dominanceHistory(12));

        $result = $enricher->enrich($this->canonical([
            ['totalPrice' => 480.0],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('MAT', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyDominantItem', $enrichment['matchedBy']);
        $this->assertNull($enrichment['matchedText']);
        $this->assertSame(['share' => 1.0, 'rows' => 12], $enrichment['dominance']);
    }

    public function testDoubleRunIsIdempotentWithDominance(): void
    {
        // Fresh běh nad canonical obohaceným dominancí: revert vlastních
        // návrhů + nové matchování musí dát identický výstup (D2).
        $enricher = $this->buildEnricher($this->dominanceHistory(10, 2));

        $first = $enricher->enrich($this->canonical([
            ['description' => 'Hmoždinky, vruty a silikon'],
        ]));
        $second = $enricher->enrich($first);

        $this->assertSame($first, $second);
        $this->assertSame('MAT', $second['rows'][0]['item']['ourCode']);
        $this->assertSame(
            'historyDominantItem',
            $second['_resolve']['rows'][0]['enrichment']['matchedBy'],
        );
    }
}
