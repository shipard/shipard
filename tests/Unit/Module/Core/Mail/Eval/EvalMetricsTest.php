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

/** Metriky (D37) ze syntetických výsledků: skupiny, doklady, selhání, opakování (D41), varianty korpusu. */
final class EvalMetricsTest extends TestCase
{
    /** @param array<string, string> $states cesta → stav */
    private static function callResult(string $id, int $repeat, array $states, float $cost = 0.01, ?string $error = null): EvalCallResult
    {
        if ($error !== null) {
            return new EvalCallResult($id, $repeat, EvalCallResult::STATUS_FAILED, $error, 'x', $cost, 100, 10, 1000, 'm', null, null);
        }
        $outcomes = [];
        foreach ($states as $path => $state) {
            $outcomes[] = new FieldOutcome($path, FieldState::from($state), 'e', 'a');
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
        $this->assertSame(['_resolve.contentTag.tag' => 1, 'docNumber' => 1, 'message_classification.primary_type' => 1, 'rows' => 1, 'totals.totalAmount' => 1], $out['deviationsByField']);
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
    }
}
