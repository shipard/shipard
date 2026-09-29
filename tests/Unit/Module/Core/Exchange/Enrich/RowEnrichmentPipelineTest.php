<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Enrich;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Enrich\ContentTagClassifier;
use Shipard\Module\Core\Exchange\Enrich\ContentTagResolver;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;

class RowEnrichmentPipelineTest extends TestCase
{
    /**
     * Pipeline nad mock DB: fetchAll se větví podle SQL (historie Vrstvy 0
     * vs. otagované položky resolveru), fetch vrací pravidlo IČO.
     *
     * @param list<array<string, mixed>> $history docs_core_rows historie
     * @param list<array<string, mixed>> $items   otagované economy_items
     * @param array<string, mixed>|null  $rule    řádek core_exchange_tag_rules
     * @param array<string, mixed>       $defaults cfgItem contentTagDefaults
     */
    private function pipeline(
        array $history = [],
        array $items = [],
        ?array $rule = null,
        ?ContentTagClassifier $classifier = null,
        bool $beforeDominance = true,
        array $defaults = [],
        ?Connection $dibi = null,
        array $taxonomy = [],
    ): RowEnrichmentPipeline {
        $dibi ??= $this->dibi($history, $items, $rule);

        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.items.contentTagDefaults', $defaults],
            ['core.exchange.contentTags', $taxonomy],
        ]);

        return new RowEnrichmentPipeline(
            new RowHistoryEnricher($dibi, $party),
            new ContentTagResolver($dibi, null, $config),
            $classifier,
            $beforeDominance,
        );
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>|null  $rule
     */
    private function dibi(array $history, array $items, ?array $rule): Connection
    {
        $dibi = $this->createMock(Connection::class);
        $dibi->method('fetchAll')->willReturnCallback(
            static function (...$args) use ($history, $items): array {
                $sql = (string) ($args[0] ?? '');
                $rows = str_contains($sql, 'docs_core_rows') ? $history : $items;
                return array_map(static fn(array $r) => new Row($r), $rows);
            },
        );
        $dibi->method('fetch')->willReturn($rule !== null ? new Row($rule) : null);
        return $dibi;
    }

    /** @return array<string, mixed> */
    private function taggedItem(string $code, array $tags, ?string $account = '503100', int $id = 11): array
    {
        return [
            'id' => $id,
            'code' => $code,
            'name' => 'Položka ' . $code,
            'content_tags' => json_encode($tags),
            'account_number' => $account,
        ];
    }

    /** @return array<string, mixed> */
    private function canonical(array $rows): array
    {
        return [
            'docType' => 'invoiceReceived',
            'selfParty' => 'customer',
            'supplier' => ['name' => 'Benzina s.r.o.', 'companyId' => '12345678'],
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function itemRow(string $description, float $total = 100.0): array
    {
        return [
            'description' => $description,
            'totalPrice' => $total,
            'item' => ['ourCode' => null],
            'vat' => ['code' => null],
            'account' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function historyRow(string $description, string $itemCode): array
    {
        return [
            'description' => $description,
            'vat_code' => 'std21',
            'total_price' => null,
            'item_code' => $itemCode,
            'item_name' => 'Položka ' . $itemCode,
            'account_number' => '518100',
            'doc_head' => 777,
            'doc_number' => 'FP-1',
        ];
    }

    private function neverCalledClassifier(): ContentTagClassifier
    {
        $classifier = $this->createMock(ContentTagClassifier::class);
        $classifier->expects($this->never())->method('classify');
        return $classifier;
    }

    /** @param array<string, mixed>|null $result */
    private function classifierReturning(?array $result): ContentTagClassifier
    {
        $classifier = $this->createMock(ContentTagClassifier::class);
        $classifier->expects($this->once())->method('classify')->willReturn($result);
        return $classifier;
    }

    private function enrichmentAt(array $canonical, int $idx): ?array
    {
        foreach ($canonical['_resolve']['rows'] ?? [] as $entry) {
            if (($entry['index'] ?? null) === $idx) {
                return $entry['enrichment'] ?? null;
            }
        }
        return null;
    }

    // ── Eskalace se ne/spouští ──────────────────────────────────────────────

    public function testFullLayer0CoverageSkipsEscalation(): void
    {
        $pipeline = $this->pipeline(
            history: [$this->historyRow('Konzultace', 'KONZ01')],
            classifier: $this->neverCalledClassifier(),
        );

        $result = $pipeline->enrichAtResult($this->canonical([$this->itemRow('Konzultace')]));

        $this->assertSame('KONZ01', $result['rows'][0]['item']['ourCode']);
        $this->assertArrayNotHasKey('contentTag', $result['_resolve'] ?? []);
    }

    public function testRuleSkipsLlmAndResolvesTaggedItem(): void
    {
        $dibi = $this->dibi(
            history: [],
            items: [$this->taggedItem('FUEL', ['vehicle.fuel'])],
            rule: ['id' => 5, 'tag' => 'vehicle.fuel', 'origin' => 'learned'],
        );
        // markRuleHit při /result — statistiky se inkrementují jen tady.
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');
        $dibi->expects($this->once())->method('update')->willReturn($fluent);

        $pipeline = $this->pipeline(classifier: $this->neverCalledClassifier(), dibi: $dibi);
        $result = $pipeline->enrichAtResult($this->canonical([$this->itemRow('Natural 95')]));

        $this->assertSame(
            ['tag' => 'vehicle.fuel', 'tagSource' => 'rule', 'ruleId' => 5],
            $result['_resolve']['contentTag'],
        );
        $this->assertSame('FUEL', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('503100', $result['rows'][0]['account']);
        $enrichment = $this->enrichmentAt($result, 0);
        $this->assertSame('contentTag', $enrichment['matchedBy']);
        $this->assertSame('medium', $enrichment['confidence']);
        $this->assertSame('rule', $enrichment['tagSource']);
        $this->assertSame('item', $enrichment['resolution']);
    }

    public function testFreshRunAddsLocalizedTagLabel(): void
    {
        // Fresh čtení nese lokalizovaný label štítku (D23) — dokument-level
        // blok i řádkový audit.
        $pipeline = $this->pipeline(
            items: [$this->taggedItem('FUEL', ['vehicle.fuel'])],
            rule: ['id' => 5, 'tag' => 'vehicle.fuel', 'origin' => 'learned'],
            taxonomy: ['vehicle.fuel' => ['name' => 'Pohonné hmoty', 'order' => 10]],
        );
        $result = $pipeline->enrichFresh($this->canonical([$this->itemRow('Natural 95')]));

        $this->assertSame('Pohonné hmoty', $result['_resolve']['contentTag']['tagLabel']);
        $this->assertSame('Pohonné hmoty', $this->enrichmentAt($result, 0)['tagLabel']);
    }

    public function testResultRunOmitsTagLabel(): void
    {
        // /result běží bez uživatelského jazyka — persist label nenese (D23).
        $dibi = $this->dibi(
            history: [],
            items: [$this->taggedItem('FUEL', ['vehicle.fuel'])],
            rule: ['id' => 5, 'tag' => 'vehicle.fuel', 'origin' => 'learned'],
        );
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');
        $dibi->method('update')->willReturn($fluent);

        $pipeline = $this->pipeline(
            classifier: $this->neverCalledClassifier(),
            dibi: $dibi,
            taxonomy: ['vehicle.fuel' => ['name' => 'Pohonné hmoty', 'order' => 10]],
        );
        $result = $pipeline->enrichAtResult($this->canonical([$this->itemRow('Natural 95')]));

        $this->assertArrayNotHasKey('tagLabel', $result['_resolve']['contentTag']);
        $this->assertArrayNotHasKey('tagLabel', $this->enrichmentAt($result, 0));
    }

    public function testLlmClassifiesWithRowExceptions(): void
    {
        $classifier = $this->classifierReturning([
            'primaryTag' => 'vehicle.fuel',
            'confidence' => 0.9,
            'rowExceptions' => [['rowIndex' => 1, 'tag' => 'vehicle.consumables']],
        ]);
        $pipeline = $this->pipeline(
            items: [
                $this->taggedItem('FUEL', ['vehicle.fuel'], id: 11),
                $this->taggedItem('KAPAL', ['vehicle.consumables'], '501100', id: 12),
            ],
            classifier: $classifier,
        );

        $result = $pipeline->enrichAtResult($this->canonical([
            $this->itemRow('Natural 95'),
            $this->itemRow('Náplň do ostřikovačů'),
        ]));

        $block = $result['_resolve']['contentTag'];
        $this->assertSame('llm', $block['tagSource']);
        $this->assertSame('vehicle.fuel', $block['tag']);
        $this->assertSame(ContentTagClassifier::TAG_PROMPT_VERSION, $block['promptVersion']);
        $this->assertSame(0.9, $block['tagConfidence']);

        $this->assertSame('FUEL', $result['rows'][0]['item']['ourCode']);
        // rowException přebije primaryTag na svém řádku
        $this->assertSame('KAPAL', $result['rows'][1]['item']['ourCode']);
        $this->assertSame('vehicle.consumables', $this->enrichmentAt($result, 1)['tag']);
    }

    public function testLlmFailureLeavesCanonicalWithoutTag(): void
    {
        $pipeline = $this->pipeline(classifier: $this->classifierReturning(null));

        $result = $pipeline->enrichAtResult($this->canonical([$this->itemRow('Něco')]));

        $this->assertArrayNotHasKey('contentTag', $result['_resolve'] ?? []);
        $this->assertNull($result['rows'][0]['item']['ourCode']);
    }

    // ── Fresh běh (preview/apply) ───────────────────────────────────────────

    public function testFreshRecheckRuleOverridesPersistedLlmTag(): void
    {
        $pipeline = $this->pipeline(
            items: [$this->taggedItem('FUEL', ['vehicle.fuel'])],
            rule: ['id' => 5, 'tag' => 'vehicle.fuel', 'origin' => 'user'],
            classifier: $this->neverCalledClassifier(),
        );

        $canonical = $this->canonical([$this->itemRow('Natural 95')]);
        $canonical['_resolve']['contentTag'] = [
            'tag' => 'it.software', 'tagSource' => 'llm', 'tagConfidence' => 0.7,
        ];
        $result = $pipeline->enrichFresh($canonical);

        $this->assertSame('rule', $result['_resolve']['contentTag']['tagSource']);
        $this->assertSame('vehicle.fuel', $result['_resolve']['contentTag']['tag']);
        $this->assertSame('FUEL', $result['rows'][0]['item']['ourCode']);
    }

    public function testFreshKeepsPersistedLlmTagWhenNoRule(): void
    {
        $pipeline = $this->pipeline(
            items: [$this->taggedItem('SW', ['it.software'], '518206')],
            classifier: $this->neverCalledClassifier(),
        );

        $canonical = $this->canonical([$this->itemRow('Licence Foo')]);
        $canonical['_resolve']['contentTag'] = [
            'tag' => 'it.software', 'tagSource' => 'llm', 'tagConfidence' => 0.8,
        ];
        $result = $pipeline->enrichFresh($canonical);

        $this->assertSame('llm', $result['_resolve']['contentTag']['tagSource']);
        $this->assertSame('SW', $result['rows'][0]['item']['ourCode']);
    }

    public function testFreshRunIsDeterministicAndIdempotent(): void
    {
        $pipeline = $this->pipeline(
            items: [$this->taggedItem('FUEL', ['vehicle.fuel'])],
            rule: ['id' => 5, 'tag' => 'vehicle.fuel', 'origin' => 'learned'],
        );

        $first = $pipeline->enrichFresh($this->canonical([$this->itemRow('Natural 95')]));
        $second = $pipeline->enrichFresh($first);

        $this->assertSame($first, $second);
    }

    // ── Propsání jen prázdných polí + guard ─────────────────────────────────

    public function testOnlyEmptyFieldsArePropagated(): void
    {
        $pipeline = $this->pipeline(
            items: [$this->taggedItem('FUEL', ['vehicle.fuel'])],
            rule: ['id' => 5, 'tag' => 'vehicle.fuel', 'origin' => 'learned'],
        );

        $row = $this->itemRow('Natural 95');
        $row['account'] = '501999'; // AI extrakce už účet dodala
        $result = $pipeline->enrichFresh($this->canonical([$row]));

        $this->assertSame('FUEL', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('501999', $result['rows'][0]['account']);
        $this->assertSame(['ourCode' => 'FUEL'], $this->enrichmentAt($result, 0)['suggested']);
    }

    public function testAmountGuardHoldsSuggestion(): void
    {
        $pipeline = $this->pipeline(
            items: [$this->taggedItem('HW', ['it.hardware'], '501201')],
            rule: ['id' => 6, 'tag' => 'it.hardware', 'origin' => 'user'],
            defaults: ['it.hardware' => ['amountGuard' => ['over' => 80000, 'action' => 'review']]],
        );

        $result = $pipeline->enrichFresh($this->canonical([$this->itemRow('Server', 95000.0)]));

        $this->assertNull($result['rows'][0]['item']['ourCode']);
        $enrichment = $this->enrichmentAt($result, 0);
        $this->assertSame('guarded', $enrichment['resolution']);
        $this->assertSame('amount', $enrichment['guard']);
    }

    public function testUnmappedTagLeavesRowForReview(): void
    {
        $pipeline = $this->pipeline(
            rule: ['id' => 7, 'tag' => 'admin.other', 'origin' => 'learned'],
        );

        $result = $pipeline->enrichFresh($this->canonical([$this->itemRow('Cosi')]));

        $this->assertNull($result['rows'][0]['item']['ourCode']);
        $this->assertSame('unmapped', $this->enrichmentAt($result, 0)['resolution']);
    }

    // ── D13: precedence contentTag vs. dominance ────────────────────────────

    /** @return list<array<string, mixed>> Historie s dominantní položkou (podíl 1.0). */
    // ── contentTagOf() — denormalizace štítku do content_tag (#81) ───────────

    public function testContentTagOfReadsDocumentLevelTag(): void
    {
        $this->assertSame('vehicle.fuel', RowEnrichmentPipeline::contentTagOf([
            '_resolve' => ['contentTag' => ['tag' => 'vehicle.fuel', 'tagSource' => 'llm']],
        ]));
        $this->assertSame('software.saas', RowEnrichmentPipeline::contentTagOf([
            '_resolve' => ['contentTag' => ['tag' => 'software.saas', 'tagSource' => 'rule', 'ruleId' => 3]],
        ]));
    }

    public function testContentTagOfWithoutBlockOrEmptyTagIsNull(): void
    {
        $this->assertNull(RowEnrichmentPipeline::contentTagOf([]));
        $this->assertNull(RowEnrichmentPipeline::contentTagOf(['_resolve' => ['rows' => []]]));
        $this->assertNull(RowEnrichmentPipeline::contentTagOf(['_resolve' => ['contentTag' => ['tag' => '']]]));
        $this->assertNull(RowEnrichmentPipeline::contentTagOf(['_resolve' => ['contentTag' => ['tag' => null]]]));
        $this->assertNull(RowEnrichmentPipeline::contentTagOf(['_resolve' => ['contentTag' => ['tag' => 42]]]));
    }

    private function dominanceHistory(): array
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = $this->historyRow("Spojovací materiál šarže {$i}", 'MAT');
        }
        return $rows;
    }

    public function testContentTagBeforeDominanceWinsOnCoveredRows(): void
    {
        // Default (true): contentTag pokryje řádek dřív, dominance už na něj
        // nesáhne — přestože by dominantní položka MAT zabrala.
        $pipeline = $this->pipeline(
            history: $this->dominanceHistory(),
            items: [$this->taggedItem('FUEL', ['vehicle.fuel'])],
            rule: ['id' => 5, 'tag' => 'vehicle.fuel', 'origin' => 'user'],
            beforeDominance: true,
        );

        $result = $pipeline->enrichFresh($this->canonical([$this->itemRow('Natural 95')]));

        $this->assertSame('FUEL', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('contentTag', $this->enrichmentAt($result, 0)['matchedBy']);
    }

    public function testDominanceMopsUpRowsContentTagCouldNotCover(): void
    {
        // Default (true): štítek bez mapování řádek nepokryje → dominance
        // jako krok po contentTag ho dočistí.
        $pipeline = $this->pipeline(
            history: $this->dominanceHistory(),
            rule: ['id' => 7, 'tag' => 'admin.other', 'origin' => 'learned'],
            beforeDominance: true,
        );

        $result = $pipeline->enrichFresh($this->canonical([$this->itemRow('Cosi')]));

        $this->assertSame('MAT', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyDominantItem', $this->enrichmentAt($result, 0)['matchedBy']);
    }

    public function testDominanceFirstWhenSettingDisabled(): void
    {
        // false: stávající pořadí — dominance uvnitř Vrstvy 0 řádek pokryje,
        // eskalace se vůbec nespustí (žádný contentTag blok).
        $pipeline = $this->pipeline(
            history: $this->dominanceHistory(),
            items: [$this->taggedItem('FUEL', ['vehicle.fuel'])],
            rule: ['id' => 5, 'tag' => 'vehicle.fuel', 'origin' => 'user'],
            classifier: $this->neverCalledClassifier(),
            beforeDominance: false,
        );

        $result = $pipeline->enrichAtResult($this->canonical([$this->itemRow('Natural 95')]));

        $this->assertSame('MAT', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyDominantItem', $this->enrichmentAt($result, 0)['matchedBy']);
        $this->assertArrayNotHasKey('contentTag', $result['_resolve'] ?? []);
    }
}
