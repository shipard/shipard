<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval\Browse;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Isdoc\IsdocFieldCoverage;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalDatasetMessages;
use Shipard\Module\Core\Mail\Eval\Browse\EvalOutputReconstruction;
use Shipard\Module\Core\Mail\Eval\EvalCallResult;
use Shipard\Module\Core\Mail\Eval\EvalMessage;
use Shipard\Module\Core\Mail\Eval\EvalRunner;

/**
 * Rekonstrukce výstupu modelu z očekávání a odchylek (#103 D53) nad
 * skutečným během v repu i syntetickými voláními. Běh 2026-10-16 je
 * pojmenovaný (jen ke čtení, D106); očekávání, proti kterému vznikl, je
 * zmrazené ve fixtuře `tests/Fixtures/Eval/cz-vat-payer-2026-10-16/`
 * (štíhlé zprávy sady bez `analysisJson`, projekce, seznam zpráv) — živá
 * sada `tests/datasets/cz/vat-payer/` se s korpusem mění (D102–D105
 * posunuly výjimky řádků dlouhého dokladu) a invariant D53 platí jen
 * pro dvojici běh × očekávání z téže doby.
 */
final class EvalOutputReconstructionTest extends TestCase
{
    private const string FROZEN_DATASET = '/tests/Fixtures/Eval/cz-vat-payer-2026-10-16';

    private static ?EvalCatalog $catalog = null;
    private static ?EvalDatasetMessages $messages = null;
    private static ?Projection $projection = null;

    private static function catalog(): EvalCatalog
    {
        return self::$catalog ??= new EvalCatalog(dirname(__DIR__, 7));
    }

    private static function messages(): EvalDatasetMessages
    {
        return self::$messages ??= new EvalDatasetMessages(DatasetReader::open(dirname(__DIR__, 7) . self::FROZEN_DATASET));
    }

    private static function projection(): Projection
    {
        return self::$projection ??= self::messages()->projection();
    }

    /** @return array<string, mixed> */
    private static function runFile(string $file): array
    {
        $run = self::catalog()->run(self::catalog()->dataset('cz-vat-payer'), $file);
        self::assertNotNull($run, $file);
        return $run;
    }

    /** @return array<string, mixed> */
    private static function call(array $run, string $messageId, int $repeat): array
    {
        foreach ($run['calls'] as $call) {
            if ($call['messageId'] === $messageId && $call['repeat'] === $repeat) {
                return $call;
            }
        }
        self::fail("volání {$messageId} × {$repeat} není v běhu");
    }

    /** @return array<string, mixed> */
    private static function field(array $detail, string $path): array
    {
        foreach ($detail['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if ($field['path'] === $path) {
                    return $field;
                }
            }
        }
        foreach ($detail['tables'] as $table) {
            foreach ($table['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if ($cell['path'] === $path) {
                        return $cell;
                    }
                }
                if ($row['node'] !== null && $row['node']['path'] === $path) {
                    return $row['node'];
                }
            }
        }
        self::fail("pole {$path} není v rekonstrukci");
    }

    /**
     * Invariant D53: stavy z rekonstrukce dávají tytéž počty jako volání
     * v souboru — pro každé volání běhu. Očekávání bere ze zmrazené fixtury
     * (viz hlavička třídy): proti živé sadě by 1002 po D104 měl o dvě
     * výjimky řádků víc a počty by neseděly.
     */
    public function testCountsMatchTheFileForEveryCallOfARun(): void
    {
        $run = self::runFile('2026-10-16-baseline.json');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $run['set']['hash']);
        $checked = 0;
        foreach ($run['calls'] as $call) {
            $detail = EvalOutputReconstruction::build(self::projection(), self::messages()->find($call['messageId']), $call);
            $this->assertTrue($detail['countsMatch'], "{$call['messageId']} × {$call['repeat']}: " . json_encode($detail['counts']) . ' vs ' . json_encode($detail['fileCounts']));
            $this->assertSame('match', $detail['classification']['state']);
            $checked++;
        }
        $this->assertSame(60, $checked);
    }

    public function testDeviationsNormalizedAndGroupsOfARealCall(): void
    {
        $detail = EvalOutputReconstruction::build(self::projection(), self::messages()->find('MSG-CORPUS-VP-0301'), self::call(self::runFile('2026-10-16-baseline.json'), 'MSG-CORPUS-VP-0301', 1));

        $this->assertSame('ok', $detail['status']);
        $this->assertSame(1, $detail['repeat']);
        $this->assertSame('domestic-rounding', $detail['cell']['variant']);
        $this->assertSame(['match' => 106, 'matchNormalized' => 1, 'missing' => 3, 'extra' => 0, 'wrong' => 1, 'notCarried' => 0], $detail['counts']);
        $this->assertSame('ai', $detail['path'], 'běh před D76 bez klíče path = cesta ai');

        $court = self::field($detail, 'supplier.courtRegistration');
        $this->assertSame('wrong', $court['state']);
        $this->assertSame('Spisová značka B 994209 vedená u Krajského soudu v Ostravě', $court['expected']);
        $this->assertSame('B 994209 vedená u Krajského soudu v Ostravě', $court['actual']);
        $this->assertSame(0.7414, $court['similarity']);
        $this->assertSame('supplier', $court['group']);

        $phone = self::field($detail, 'supplier.contact.phone');
        $this->assertSame('matchNormalized', $phone['state']);
        $this->assertFalse($phone['actualKnown'], 'běh ze 16. 10. ukládal u shody po normalizaci jen cestu');
        $this->assertSame($phone['expected'], $phone['actual'], 'bez známého výstupu se ukáže očekávání');

        $from = self::field($detail, 'dates.periodFrom');
        $this->assertSame(['missing', '2026-07-01', null, 'header'], [$from['state'], $from['expected'], $from['actual'], $from['group']]);
        $this->assertSame('header', self::field($detail, 'payment.method')['group'], 'platba spadá do hlavičky jako v metrikách');

        $docNumber = self::field($detail, 'docNumber');
        $this->assertSame('match', $docNumber['state']);
        $this->assertSame($docNumber['expected'], $docNumber['actual']);
        $this->assertSame('text', $docNumber['shape']);

        $groups = array_column($detail['groups'], 'id');
        $this->assertSame(['header', 'supplier', 'customer', 'vat', 'rows', 'vatRecap', 'totals', 'tag', 'rowExceptions', 'classification', 'informative'], $groups);
        // Běh v1 (#103 D114): poznámka je obyčejný list hlavičky, informativní skupina prázdná.
        $this->assertSame(['match' => 0, 'matchNormalized' => 0, 'missing' => 0, 'extra' => 0, 'wrong' => 0], $detail['informativeCounts']);
        $this->assertNull($detail['fileInformativeCounts']);
        $this->assertSame([], $detail['groups'][10]['fields']);
        $note = self::field($detail, 'notes.onDocument');
        $this->assertSame([false, 'header'], [$note['informative'], $note['group']]);

        $rows = array_values(array_filter($detail['tables'], static fn (array $t): bool => $t['path'] === 'rows'))[0];
        $this->assertContains('item.name', $rows['columns']);
        $this->assertContains('vat.pct', $rows['columns'], 'DPH řádku je sloupec tabulky řádků, i když metricky patří do skupiny DPH');
        $this->assertSame('vat', $rows['rows'][0]['cells']['vat.pct']['group']);
        $this->assertSame(0, $rows['rows'][0]['key']);
        $this->assertSame([], $detail['arrays']);
    }

    public function testExtraValueAndKeyedRowExceptions(): void
    {
        $run = self::runFile('2026-10-16-baseline.json');

        $detail = EvalOutputReconstruction::build(self::projection(), self::messages()->find('MSG-CORPUS-VP-0501'), self::call($run, 'MSG-CORPUS-VP-0501', 1));
        $reference = self::field($detail, 'payment.paymentReference');
        $this->assertSame(['extra', null, 'INV-2026-0388'], [$reference['state'], $reference['expected'], $reference['actual']]);

        $detail = EvalOutputReconstruction::build(self::projection(), self::messages()->find('MSG-CORPUS-VP-1001'), self::call($run, 'MSG-CORPUS-VP-1001', 1));
        $exceptions = array_values(array_filter($detail['tables'], static fn (array $t): bool => $t['path'] === '_resolve.contentTag.rowExceptions'))[0];
        $byKey = array_column($exceptions['rows'], null, 'key');
        $this->assertArrayHasKey(25, $byKey, 'výjimka navíc je klíčovaná rowIndex, ne pozicí');
        $this->assertSame('extra', $byKey[25]['node']['state']);
        $this->assertSame(['rowIndex' => 25, 'tag' => 'people.catering'], $byKey[25]['node']['actual']);
        $this->assertSame([], $byKey[25]['cells'], 'záznam na prvku bez listů');
        $this->assertSame('rowExceptions', $byKey[25]['node']['group']);
        $this->assertTrue($detail['countsMatch']);
    }

    public function testFailedCallMarksExpectedValuesMissingAndTheRestNotCompared(): void
    {
        $run = self::runFile('2026-10-15-baseline-2.json');
        $failed = array_values(array_filter($run['calls'], static fn (array $c): bool => $c['status'] !== 'ok'));
        $this->assertNotEmpty($failed, 'baseline-2 má dvě selhaná volání (D44)');
        $call = $failed[0];
        $detail = EvalOutputReconstruction::build(self::projection(), self::messages()->find($call['messageId']), $call, currentSet: false);

        $this->assertSame('failed', $detail['status']);
        $this->assertFalse($detail['currentSet']);
        $this->assertSame(0, $detail['counts']['match']);
        $this->assertSame(0, $detail['counts']['wrong']);
        $this->assertGreaterThan(50, $detail['counts']['missing']);
        $this->assertNull($detail['countsMatch'], 'selhaná volání z 15. 10. jsou z doby před D44 — bez counts není s čím srovnat');
        $this->assertNull($detail['fileCounts']);
        $docNumber = self::field($detail, 'docNumber');
        $this->assertSame(['missing', null], [$docNumber['state'], $docNumber['actual']]);
        $states = [];
        foreach ($detail['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $states[$field['state']] = true;
            }
        }
        $this->assertArrayHasKey(EvalOutputReconstruction::STATE_NOT_COMPARED, $states, 'prázdné listy očekávání se proti ničemu neporovnávaly');
        $this->assertArrayNotHasKey('match', $states);
    }

    public function testSyntheticCallWithNewNormalizedShapeArrayCountAndObjectDeviation(): void
    {
        $message = self::messages()->find('MSG-CORPUS-VP-0101');
        $rowCount = count($message->expectedCanonical['rows']);
        $this->assertGreaterThan(1, $rowCount);
        $call = [
            'messageId' => $message->messageId, 'repeat' => 2, 'status' => 'ok',
            'costUsd' => 0.01, 'tokensIn' => 1, 'tokensOut' => 1, 'durationMs' => 5, 'model' => 'm', 'primaryType' => 'receipt', 'tag' => null,
            'normalized' => [['path' => 'supplier.vatId', 'actual' => 'cz 12345678', 'similarity' => null]],
            'deviations' => [
                ['path' => 'rows', 'state' => 'missing', 'expected' => $rowCount, 'actual' => $rowCount - 1],
                ['path' => 'customer', 'state' => 'missing', 'expected' => $message->expectedCanonical['customer'], 'actual' => null],
            ],
        ];
        $detail = EvalOutputReconstruction::build(self::projection(), $message, $call);

        $vatId = self::field($detail, 'supplier.vatId');
        $this->assertSame(['matchNormalized', 'cz 12345678', true], [$vatId['state'], $vatId['actual'], $vatId['actualKnown']]);
        $this->assertSame($message->expectedCanonical['supplier']['vatId'], $vatId['expected']);

        $this->assertSame([['path' => 'rows', 'label' => 'Řádky', 'state' => 'missing', 'expected' => $rowCount, 'actual' => $rowCount - 1]], $detail['arrays']);
        $rows = array_values(array_filter($detail['tables'], static fn (array $t): bool => $t['path'] === 'rows'))[0];
        $last = $rows['rows'][$rowCount - 1];
        $this->assertSame(EvalOutputReconstruction::STATE_NOT_COMPARED, $last['cells']['item.name']['state'], 'řádek očekávání nad počet výstupu se neporovnával');
        $this->assertFalse($last['cells']['item.name']['counted']);
        $this->assertSame('match', $rows['rows'][0]['cells']['item.name']['state']);

        $customerGroup = array_values(array_filter($detail['groups'], static fn (array $g): bool => $g['id'] === 'customer'))[0];
        $this->assertCount(1, $customerGroup['fields'], 'odchylka na uzlu nahradila listy odběratele');
        $this->assertSame(['customer', 'missing', null], [$customerGroup['fields'][0]['path'], $customerGroup['fields'][0]['state'], $customerGroup['fields'][0]['actual']]);
        $this->assertSame(['match' => 0, 'matchNormalized' => 0, 'missing' => 1, 'extra' => 0, 'wrong' => 0, 'notCarried' => 0], $customerGroup['counts']);

        $this->assertSame(['expected' => $message->expectedPrimaryType, 'actual' => 'receipt', 'state' => 'wrong'], $detail['classification']);
        $this->assertSame(1, $detail['counts']['wrong']);
        $this->assertSame(2, $detail['counts']['missing'], 'uzel pole + odběratel');
        $this->assertNull($detail['countsMatch'], 'syntetické volání bez counts');
    }

    /**
     * Cesta `isdoc` (#103 D76): listy, které ISDOC nenese, jsou `notCarried`
     * s důvodem a bez výstupu; počty sedí s voláním, které by vydal
     * `EvalRunner` (týž zdroj `IsdocFieldCoverage`).
     */
    public function testIsdocPathMarksNotCarriedLeavesAndCountsMatchTheRunner(): void
    {
        $message = self::messages()->find('MSG-CORPUS-VP-0102');
        $comparator = new CanonicalComparator(self::projection());
        $outcomes = EvalRunner::forPath($comparator->evaluate($message->expectedCanonical, $message->expectedCanonical), EvalCallResult::PATH_ISDOC);
        $outcomes[] = EvalRunner::classificationOutcome($message->expectedPrimaryType, 'invoiceReceived');
        $result = new EvalCallResult($message->messageId, 1, EvalCallResult::STATUS_OK, null, null, 0.0, 0, 0, 12, 'isdoc', 'invoiceReceived', 'goods.stock', $outcomes, null, EvalCallResult::PATH_ISDOC);
        $call = $result->toArray();
        $this->assertSame('isdoc', $call['path']);
        $this->assertGreaterThan(0, $call['counts']['notCarried']);
        $this->assertSame([], $call['deviations'], 'notCarried není odchylka');

        $detail = EvalOutputReconstruction::build(self::projection(), $message, $call);

        $this->assertSame('isdoc', $detail['path']);
        $this->assertTrue($detail['countsMatch'], json_encode($detail['counts']) . ' vs ' . json_encode($detail['fileCounts']));
        $this->assertSame($call['counts'], $detail['counts']);
        $place = self::field($detail, 'vat.place');
        $this->assertSame('notCarried', $place['state']);
        $this->assertSame($message->expectedCanonical['vat']['place'], $place['expected']);
        $this->assertNull($place['actual']);
        $this->assertSame(IsdocFieldCoverage::NOT_CARRIED['vat.place'], $place['reason']);
        $this->assertTrue($place['counted']);
        $mode = self::field($detail, 'rows[0].priceCalcMode');
        $this->assertSame(['notCarried', IsdocFieldCoverage::NOT_CARRIED['rows[].priceCalcMode']], [$mode['state'], $mode['reason']]);
        $this->assertSame('match', self::field($detail, 'docNumber')['state']);
        $this->assertNull(self::field($detail, 'docNumber')['reason']);
        $vatGroup = array_values(array_filter($detail['groups'], static fn (array $g): bool => $g['id'] === 'vat'))[0];
        $this->assertGreaterThan(0, $vatGroup['counts']['notCarried']);

        // Totéž volání bez klíče path (nebo s `ai`) notCarried nemá.
        unset($call['path']);
        $call['counts']['notCarried'] = 0;
        $this->assertSame('match', self::field(EvalOutputReconstruction::build(self::projection(), $message, $call), 'vat.place')['state']);
    }

    /**
     * #103 D114: informativní listy u běhu s `metricsVersion` 2 — záznamy
     * z `informativeDeviations` a značených `normalized`, shody z projekce
     * živé sady; vlastní skupina a `informativeCounts` sedí s voláním, které
     * by vydal `EvalRunner`; týž záznam čtený jako verze 1 je obyčejný list.
     */
    public function testInformativeLeavesOfAVersion2CallHaveTheirOwnGroupAndCounts(): void
    {
        $messages = new EvalDatasetMessages(DatasetReader::open(dirname(__DIR__, 7) . '/tests/datasets/cz/vat-payer'));
        $projection = $messages->projection();
        $this->assertTrue($projection->isInformativeNode('notes.onDocument'), 'živá projekce nese informativeNodes');
        $message = $messages->find('MSG-CORPUS-VP-0601');
        $this->assertNotNull($message);
        $actual = $message->expectedCanonical;
        $actual['notes']['onDocument'] = 'Something else entirely.';
        $actual['supplier']['contact']['web'] = null;
        $comparator = new CanonicalComparator($projection);
        $outcomes = EvalRunner::markInformative(EvalRunner::forPath($comparator->evaluate($message->expectedCanonical, $actual), EvalCallResult::PATH_AI), $projection);
        $outcomes[] = EvalRunner::classificationOutcome($message->expectedPrimaryType, $message->expectedPrimaryType);
        $result = new EvalCallResult($message->messageId, 1, EvalCallResult::STATUS_OK, null, null, 0.01, 10, 10, 12, 'm', $message->expectedPrimaryType, 'it.hardware', $outcomes);
        $call = $result->toArray();
        $this->assertSame(['supplier.contact.web'], array_column($call['deviations'], 'path'));
        $this->assertSame([['notes.onDocument', 'wrong']], array_map(static fn (array $d): array => [$d['path'], $d['state']], $call['informativeDeviations']));
        $this->assertSame(1, $call['informativeCounts']['wrong']);

        $detail = EvalOutputReconstruction::build($projection, $message, $call, true, 2);
        $this->assertTrue($detail['countsMatch'], json_encode([$detail['counts'], $detail['fileCounts'], $detail['informativeCounts'], $detail['fileInformativeCounts']]));
        $this->assertSame($call['counts'], $detail['counts']);
        $this->assertSame($call['informativeCounts'], $detail['informativeCounts']);
        $note = self::field($detail, 'notes.onDocument');
        $this->assertSame(['wrong', true, 'header', 'Something else entirely.'], [$note['state'], $note['informative'], $note['group'], $note['actual']]);
        $groups = array_column($detail['groups'], null, 'id');
        $this->assertSame(['notes.onDocument'], array_column($groups['informative']['fields'], 'path'));
        $this->assertNotContains('notes.onDocument', array_column($groups['header']['fields'], 'path'));
        $this->assertSame(0, $groups['header']['counts']['wrong']);
        $this->assertSame(1, $groups['informative']['counts']['wrong']);
        $this->assertSame('missing', self::field($detail, 'supplier.contact.web')['state']);

        $normalized = ['messageId' => $message->messageId, 'repeat' => 1, 'status' => 'ok', 'primaryType' => $message->expectedPrimaryType,
            'normalized' => [['path' => 'notes.onDocument', 'actual' => 'goods remain our property', 'informative' => true]], 'deviations' => [], 'informativeDeviations' => []];
        $v2 = EvalOutputReconstruction::build($projection, $message, $normalized, true, 2);
        $this->assertSame(['matchNormalized', true], [self::field($v2, 'notes.onDocument')['state'], self::field($v2, 'notes.onDocument')['informative']]);
        $this->assertSame(1, $v2['informativeCounts']['matchNormalized']);
        $this->assertSame(0, $v2['counts']['matchNormalized']);
        $this->assertNull($v2['countsMatch'], 'bez counts ve volání');

        $v1 = EvalOutputReconstruction::build($projection, $message, $normalized, true, 1);
        $this->assertSame(['matchNormalized', false], [self::field($v1, 'notes.onDocument')['state'], self::field($v1, 'notes.onDocument')['informative']]);
        $this->assertSame(1, $v1['counts']['matchNormalized']);
        $this->assertContains('notes.onDocument', array_column(array_column($v1['groups'], null, 'id')['header']['fields'], 'path'));
    }

    public function testEmptyNullableCustomerIsASingleLeaf(): void
    {

        $receipt = self::messages()->find('MSG-CORPUS-VP-0901');
        $this->assertNull($receipt->expectedCanonical['customer'] ?? null, 'účtenka bez odběratele');
        $call = ['messageId' => $receipt->messageId, 'repeat' => 1, 'status' => 'ok', 'primaryType' => $receipt->expectedPrimaryType, 'normalized' => [], 'deviations' => []];
        $detail = EvalOutputReconstruction::build(self::projection(), $receipt, $call);
        $customer = array_values(array_filter($detail['groups'], static fn (array $g): bool => $g['id'] === 'customer'))[0];
        $this->assertSame([['customer', 'match']], array_map(static fn (array $f): array => [$f['path'], $f['state']], $customer['fields']));
    }
}
