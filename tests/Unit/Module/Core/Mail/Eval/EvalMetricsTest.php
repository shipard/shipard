<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Compare\FieldOutcome;
use Shipard\Module\Core\Exchange\Compare\FieldState;
use Shipard\Module\Core\Mail\Eval\EvalCallResult;
use Shipard\Module\Core\Mail\Eval\EvalFieldGroups;
use Shipard\Module\Core\Mail\Eval\EvalMessage;
use Shipard\Module\Core\Mail\Eval\EvalMetrics;

/** Metriky (D37) ze syntetických výsledků: skupiny, doklady, selhání (D44 — missing polí očekávání), opakování (D41 — stabilita bez formátu), varianty korpusu. */
final class EvalMetricsTest extends TestCase
{
    /** @param array<string, string> $states cesta → stav (u selhaného volání missing polí očekávání, D44) */
    private static function callResult(string $id, int $repeat, array $states, float $cost = 0.01, ?string $error = null): EvalCallResult
    {
        $outcomes = [];
        foreach ($states as $path => $state) {
            $outcomes[] = new FieldOutcome($path, FieldState::from($state), 'e', 'a');
        }
        if ($error !== null) {
            return new EvalCallResult($id, $repeat, EvalCallResult::STATUS_FAILED, $error, 'x', $cost, 100, 10, 1000, 'm', null, null, $outcomes);
        }
        return new EvalCallResult($id, $repeat, EvalCallResult::STATUS_OK, null, null, $cost, 100, 10, 1000, 'm', 'invoiceReceived', 'it.hosting', $outcomes);
    }

    private static function message(string $id, string $variant, string $form = 'pdf'): EvalMessage
    {
        return new EvalMessage($id, '2026-08-01T08:00:00', [], 'invoiceReceived', "0101-{$variant}", $variant, $form);
    }

    public function testGroupsOfNodePaths(): void
    {
        $this->assertSame('header', EvalFieldGroups::groupOf('docNumber'));
        $this->assertSame('header', EvalFieldGroups::groupOf('dates.issueDate'));
        $this->assertSame('supplier', EvalFieldGroups::groupOf('supplier.address.zip'));
        $this->assertSame('customer', EvalFieldGroups::groupOf('customer'));
        $this->assertSame('vat', EvalFieldGroups::groupOf('vat.mode'));
        $this->assertSame('vat', EvalFieldGroups::groupOf('rows[].vat.pct'));
        $this->assertSame('rows', EvalFieldGroups::groupOf('rows'));
        $this->assertSame('rows', EvalFieldGroups::groupOf('rows[].item.name'));
        $this->assertSame('vatRecap', EvalFieldGroups::groupOf('vatRecap[].base'));
        $this->assertSame('totals', EvalFieldGroups::groupOf('totals.totalAmount'));
        $this->assertSame('tag', EvalFieldGroups::groupOf('_resolve.contentTag.tag'));
        $this->assertSame('rowExceptions', EvalFieldGroups::groupOf('_resolve.contentTag.rowExceptions[].tag'));
        $this->assertSame('classification', EvalFieldGroups::groupOf(EvalFieldGroups::CLASSIFICATION_PATH));
        $this->assertTrue(EvalFieldGroups::isAmountNode('rows[].totalPrice'));
        $this->assertTrue(EvalFieldGroups::isAmountNode('vatRecap'));
        $this->assertTrue(EvalFieldGroups::isAmountNode('totals.totalVat'));
        $this->assertFalse(EvalFieldGroups::isAmountNode('rows[].unitPrice'));
    }

    public function testSingleRunMetrics(): void
    {
        $clean = ['docNumber' => 'match', 'supplier.name' => 'matchNormalized', 'rows[0].totalPrice' => 'match', 'totals.totalAmount' => 'match', '_resolve.contentTag.tag' => 'match', EvalFieldGroups::CLASSIFICATION_PATH => 'match'];
        $dirty = ['docNumber' => 'wrong', 'supplier.name' => 'match', 'rows' => 'missing', 'rows[0].totalPrice' => 'match', 'totals.totalAmount' => 'wrong', '_resolve.contentTag.tag' => 'missing', EvalFieldGroups::CLASSIFICATION_PATH => 'wrong'];
        $results = [
            self::callResult('A', 1, $clean, 0.02),
            self::callResult('B', 1, $dirty, 0.03),
            self::callResult('C', 1, [], 0.01, 'schema_error'),
            self::callResult('D', 1, [], 0.0, 'ai_error'),
        ];
        $messages = [self::message('A', 'domestic-goods'), self::message('B', 'domestic-goods', 'pdfIsdoc'), self::message('C', 'eu-goods'), self::message('D', 'eu-goods')];

        $out = EvalMetrics::compute($results, $messages, 1);
        $m = $out['metrics'];
        $this->assertSame(4, $m['calls.total']);
        $this->assertSame(2, $m['calls.ok']);
        $this->assertSame(2, $m['calls.failed']);
        $this->assertSame(1, $m['calls.failed.schema_error']);
        $this->assertSame(1, $m['calls.failed.ai_error']);
        $this->assertSame(2, $m['fields.header.n']);
        $this->assertSame(0.5, $m['fields.header.hit']);
        $this->assertSame(1, $m['fields.header.wrong']);
        $this->assertSame(1.0, $m['fields.supplier.hit']);
        $this->assertSame(0.5, $m['fields.supplier.normalized']);
        $this->assertSame(3, $m['fields.rows.n'], 'včetně výsledku za počet řádků');
        $this->assertSame(1, $m['fields.rows.missing']);
        $this->assertSame(0.5, $m['fields.tag.hit']);
        $this->assertSame(0.5, $m['fields.classification.hit']);
        $this->assertSame(13, $m['fields.all.n']);
        $this->assertSame(0.25, $m['documents.clean']);
        $this->assertSame(0.25, $m['documents.amountsCorrect']);
        $this->assertSame(0.25, $m['documents.rowCountOk']);
        $this->assertSame(0.25, $m['documents.classificationOk']);
        $this->assertSame(0.25, $m['documents.tagOk']);
        $this->assertSame(0.06, $m['cost.usd']);
        $this->assertSame(0.015, $m['cost.perDocument']);
        $this->assertSame(400, $m['tokens.input']);
        $this->assertSame(100, $m['tokens.inputPerDocument']);
        $this->assertSame(4000, $m['time.ms']);
        $this->assertSame(1000, $m['time.perDocumentMs']);
        $this->assertArrayNotHasKey('range', $out);
        $this->assertArrayNotHasKey('fieldStability', $out);

        $this->assertSame(['domestic-goods', 'eu-goods'], array_keys($out['byVariant']));
        $this->assertSame(2, $out['byVariant']['domestic-goods']['calls.total']);
        $this->assertSame(0.5, $out['byVariant']['domestic-goods']['documents.clean']);
        $this->assertSame(0.0, $out['byVariant']['eu-goods']['documents.clean']);
        $this->assertSame(['pdf', 'pdfIsdoc'], array_keys($out['byForm']));
        $this->assertSame(['ai'], array_keys($out['byPath']), 'výsledky bez cesty jsou ai');
        $this->assertSame(4, $out['byPath']['ai']['calls.total']);
        $this->assertSame(0.0, $out['byPath']['ai']['tags.usd']);
        $this->assertSame(['_resolve.contentTag.tag' => 1, 'docNumber' => 1, 'message_classification.primary_type' => 1, 'rows' => 1, 'totals.totalAmount' => 1], $out['deviationsByField']);
    }

    /** F6b D56: štítky zvlášť (`tags.*`), `cost.usd` jen extrakce, `cost.total*` = extrakce + štítky; přes opakování součty. */
    public function testTagCostIsSummedSeparatelyAndAddedToTheTotal(): void
    {
        $tags = ['tokensIn' => 100, 'tokensOut' => 20, 'model' => 'claude-haiku-5-5', 'costUsd' => 0.0005];
        $withTags = static fn (EvalCallResult $r): EvalCallResult => new EvalCallResult(
            $r->messageId, $r->repeat, $r->status, $r->errorType, $r->note, $r->costUsd, $r->tokensIn, $r->tokensOut, $r->durationMs, $r->model, $r->primaryType, $r->tag, $r->outcomes, $tags,
        );
        $results = [
            $withTags(self::callResult('A', 1, ['docNumber' => 'match'], 0.02)),
            self::callResult('B', 1, ['docNumber' => 'match'], 0.03),
            $withTags(self::callResult('A', 2, ['docNumber' => 'match'], 0.02)),
            self::callResult('B', 2, ['docNumber' => 'match'], 0.03),
        ];
        $m = EvalMetrics::slice(array_slice($results, 0, 2));
        $this->assertSame(0.05, $m['cost.usd'], 'jen extrakce');
        $this->assertSame(0.025, $m['cost.perDocument']);
        $this->assertSame(1, $m['tags.calls']);
        $this->assertSame(0.0005, $m['tags.usd']);
        $this->assertSame(100, $m['tags.tokens.input']);
        $this->assertSame(20, $m['tags.tokens.output']);
        $this->assertSame(0.0505, $m['cost.totalUsd']);
        $this->assertSame(0.02525, $m['cost.totalPerDocument']);

        $out = EvalMetrics::compute($results, [self::message('A', 'x'), self::message('B', 'y')], 2);
        $this->assertSame(0.1, $out['metrics']['cost.usd']);
        $this->assertSame(2, $out['metrics']['tags.calls']);
        $this->assertSame(0.001, $out['metrics']['tags.usd']);
        $this->assertSame(0.101, $out['metrics']['cost.totalUsd']);
        $this->assertSame(0.02525, $out['metrics']['cost.totalPerDocument'], 'celkem na volání i přes opakování');
        $this->assertSame(['min' => 0.0005, 'max' => 0.0005], $out['range']['tags.usd']);
    }

    public function testRepeatsAverageRatiosSumCountsAndMeasureStability(): void
    {
        $results = [
            self::callResult('A', 1, ['docNumber' => 'match', 'supplier.name' => 'match'], 0.02),
            self::callResult('A', 2, ['docNumber' => 'wrong', 'supplier.name' => 'match'], 0.04),
            self::callResult('B', 1, ['docNumber' => 'match', 'supplier.name' => 'wrong'], 0.02),
            self::callResult('B', 2, ['docNumber' => 'match', 'supplier.name' => 'wrong'], 0.02),
        ];
        $messages = [self::message('A', 'x'), self::message('B', 'y')];

        $out = EvalMetrics::compute($results, $messages, 2);
        $m = $out['metrics'];
        $this->assertSame(4, $m['calls.total'], 'součty přes opakování');
        $this->assertSame(0.1, $m['cost.usd']);
        $this->assertSame(0.025, $m['cost.perDocument']);
        $this->assertSame(0.25, $m['documents.clean'], 'průměr podílů: (0,5 + 0) / 2');
        $this->assertSame(0.625, $m['fields.all.hit'], '(0,75 + 0,5) / 2');
        $this->assertSame(['min' => 0.5, 'max' => 0.75], $out['range']['fields.all.hit']);
        $this->assertSame(['min' => 0.04, 'max' => 0.06], $out['range']['cost.usd']);
        $this->assertSame(['docNumber' => 0.5, 'supplier.name' => 1.0], $out['fieldStability']);
        $this->assertSame(['docNumber' => 1.0, 'supplier.name' => 1.0], $out['formatStability'], 'B docNumber a A supplier.name: shoda stejného tvaru; zbytek bez shody se nepočítá');
    }

    public function testStabilityIgnoresFormatAndReportsItSeparately(): void
    {
        // D44: match ↔ matchNormalized (mezery v IBAN) není věcná nestabilita; vykazuje se jako formatStability.
        $results = [
            self::callResult('A', 1, ['docNumber' => 'match', 'supplier.vatId' => 'match', 'supplier.bankAccount.iban' => 'matchNormalized']),
            self::callResult('A', 2, ['docNumber' => 'wrong', 'supplier.vatId' => 'matchNormalized', 'supplier.bankAccount.iban' => 'matchNormalized']),
            self::callResult('B', 1, ['docNumber' => 'match', 'supplier.vatId' => 'match', 'supplier.bankAccount.iban' => 'missing']),
            self::callResult('B', 2, ['docNumber' => 'match', 'supplier.vatId' => 'match', 'supplier.bankAccount.iban' => 'match']),
            self::callResult('C', 1, ['docNumber' => 'missing', 'supplier.vatId' => 'missing', 'supplier.bankAccount.iban' => 'missing'], 0.01, 'schema_error'),
            self::callResult('C', 2, ['docNumber' => 'match', 'supplier.vatId' => 'wrong', 'supplier.bankAccount.iban' => 'match']),
        ];
        $out = EvalMetrics::compute($results, [self::message('A', 'x'), self::message('B', 'x'), self::message('C', 'x')], 2);

        $this->assertSame(
            ['docNumber' => 0.6667, 'supplier.bankAccount.iban' => 0.6667, 'supplier.vatId' => 1.0],
            $out['fieldStability'],
            'A docNumber match/wrong nestabilní; A vatId match/matchNormalized stabilní; B iban missing/match nestabilní; C jen z úspěšného opakování (stabilní)',
        );
        $this->assertSame(
            ['docNumber' => 1.0, 'supplier.bankAccount.iban' => 1.0, 'supplier.vatId' => 0.5],
            $out['formatStability'],
            'jmenovatel = zprávy se shodou ve všech opakováních; A vatId mix tvarů',
        );
    }

    /**
     * #103 D76: `notCarried` mimo `n`, shodu i odchylky (jen vlastní počet),
     * mimo stabilitu a `deviationsByField`; `byPath` kyblíkuje podle cesty
     * volání a buňka `isdoc` nese jen cenu štítků.
     */
    public function testNotCarriedIsOutsideCountsAndByPathSplitsByCallPath(): void
    {
        $tags = ['tokensIn' => 100, 'tokensOut' => 20, 'model' => 'claude-haiku-5-5', 'costUsd' => 0.0005];
        $isdoc = static fn (string $id, int $repeat, array $states): EvalCallResult => new EvalCallResult(
            $id, $repeat, EvalCallResult::STATUS_OK, null, null, 0.0, 0, 0, 10, 'isdoc', 'invoiceReceived', 'it.hosting',
            array_map(static fn (string $path, string $state): FieldOutcome => new FieldOutcome($path, FieldState::from($state), 'e', 'a', null, $state === 'notCarried' ? 'důvod' : null), array_keys($states), $states),
            $tags, EvalCallResult::PATH_ISDOC,
        );
        $states = ['docNumber' => 'match', 'vat.place' => 'notCarried', 'rows[0].priceCalcMode' => 'notCarried', 'rows[0].totalPrice' => 'match', 'totals.totalAmount' => 'match', '_resolve.contentTag.tag' => 'match', EvalFieldGroups::CLASSIFICATION_PATH => 'match'];
        $results = [
            self::callResult('A', 1, ['docNumber' => 'match', 'vat.place' => 'match', 'totals.totalAmount' => 'wrong', EvalFieldGroups::CLASSIFICATION_PATH => 'match'], 0.02),
            $isdoc('B', 1, $states),
            self::callResult('A', 2, ['docNumber' => 'match', 'vat.place' => 'match', 'totals.totalAmount' => 'wrong', EvalFieldGroups::CLASSIFICATION_PATH => 'match'], 0.02),
            $isdoc('B', 2, ['vat.place' => 'match'] + $states),   // jiná třída na vat.place u notCarried se do stability nepočítá
        ];
        $out = EvalMetrics::compute($results, [self::message('A', 'x'), self::message('B', 'y', 'pdfIsdoc')], 2);
        $m = $out['metrics'];

        // DPH: A má vat.place match v obou opakováních, B ho má notCarried v prvním a match ve druhém → n = 3, notCarried = 1.
        $this->assertSame(3, $m['fields.vat.n']);
        $this->assertSame(1.0, $m['fields.vat.hit']);
        $this->assertSame(1, $m['fields.vat.notCarried']);
        $this->assertSame(0, $m['fields.vat.missing'] + $m['fields.vat.extra'] + $m['fields.vat.wrong']);
        $this->assertSame(2, $m['fields.rows.notCarried'], 'priceCalcMode je skupina řádků, v obou opakováních');
        $this->assertSame(2, $m['fields.rows.n'], 'jen totalPrice');
        $this->assertSame(3, $m['fields.all.notCarried']);
        $this->assertSame(['min' => 0, 'max' => 1], $out['range']['fields.vat.notCarried'], 'počet za opakování, ne průměr');
        $this->assertSame(0.5, $m['documents.clean'], 'B čistý i s notCarried, A špatná částka');
        $this->assertSame(0.5, $m['documents.amountsCorrect']);
        $this->assertSame(['totals.totalAmount' => 2], $out['deviationsByField']);
        $this->assertArrayNotHasKey('rows[].priceCalcMode', $out['fieldStability'], 'uzel jen s notCarried se neměří');
        $this->assertSame(1.0, $out['fieldStability']['vat.place'], 'notCarried opakování se nepočítá — B má jen jedno měřené');

        $this->assertSame(['ai', 'isdoc'], array_keys($out['byPath']));
        $this->assertSame(2, $out['byPath']['isdoc']['calls.total']);
        $this->assertSame(0.0, $out['byPath']['isdoc']['cost.usd']);
        $this->assertSame(0.001, $out['byPath']['isdoc']['tags.usd']);
        $this->assertSame(1.0, $out['byPath']['isdoc']['documents.clean']);
        $this->assertSame(0.04, $out['byPath']['ai']['cost.usd']);
        $this->assertSame(['pdf', 'pdfIsdoc'], array_keys($out['byForm']));
    }

    public function testFailedCallCountsItsExpectedFieldsAsMissing(): void
    {
        // D44: selhané volání = missing všech neprázdných polí očekávání ve fields.*;
        // documents.* a calls.* jako dřív, deviationsByField jen z úspěšných volání.
        $results = [
            self::callResult('A', 1, ['docNumber' => 'match', 'totals.totalAmount' => 'match', EvalFieldGroups::CLASSIFICATION_PATH => 'match'], 0.02),
            self::callResult('C', 1, ['docNumber' => 'missing', 'totals.totalAmount' => 'missing', EvalFieldGroups::CLASSIFICATION_PATH => 'missing'], 0.01, 'schema_error'),
        ];
        $m = EvalMetrics::compute($results, [self::message('A', 'x'), self::message('C', 'y')], 1);

        $this->assertSame(2, $m['metrics']['fields.header.n']);
        $this->assertSame(1, $m['metrics']['fields.header.missing']);
        $this->assertSame(0.5, $m['metrics']['fields.header.hit']);
        $this->assertSame(0.5, $m['metrics']['fields.totals.hit']);
        $this->assertSame(0.5, $m['metrics']['fields.classification.hit']);
        $this->assertSame(6, $m['metrics']['fields.all.n']);
        $this->assertSame(3, $m['metrics']['fields.all.missing']);
        $this->assertSame(0.5, $m['metrics']['fields.all.hit']);
        $this->assertSame(1, $m['metrics']['calls.failed.schema_error']);
        $this->assertSame(0.5, $m['metrics']['documents.clean']);
        $this->assertSame(0.5, $m['metrics']['documents.amountsCorrect']);
        $this->assertSame(0.5, $m['metrics']['documents.classificationOk']);
        $this->assertSame([], $m['deviationsByField'], 'selhání není odchylka pole — je v calls.failed');
        $this->assertSame(0.0, $m['byVariant']['y']['fields.all.hit']);
        $this->assertSame(['match' => 0, 'matchNormalized' => 0, 'missing' => 3, 'extra' => 0, 'wrong' => 0, 'notCarried' => 0], $results[1]->toArray()['counts']);
        $this->assertArrayNotHasKey('deviations', $results[1]->toArray(), 'seznam by jen opisoval očekávání');
    }
}
