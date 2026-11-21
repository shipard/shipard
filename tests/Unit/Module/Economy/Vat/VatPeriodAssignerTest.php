<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\DocsHeadsVatPeriodHandler;
use Shipard\Module\Economy\Vat\ReportPeriodLookup;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\Economy\Vat\VatPeriodAssigner;

/** In-memory instance: [id, reg, type, begin, end]. */
final class FakePeriodLookup implements ReportPeriodLookup
{
    /** @var array<string, int> počet volání covering() per typ */
    public array $calls = ['return' => 0, 'cs' => 0, 'rs' => 0];

    /** @param list<array{0: int, 1: int, 2: string, 3: string, 4: string}> $instances */
    public function __construct(private readonly array $instances) {}

    public function covering(int $registrationId, string $type, string $date): ?array
    {
        $this->calls[$type] = ($this->calls[$type] ?? 0) + 1;
        foreach ($this->instances as [$id, $reg, $t, $begin, $end]) {
            if ($reg === $registrationId && $t === $type && $begin <= $date && $end >= $date) {
                return ['id' => $id, 'date_begin' => $begin, 'date_end' => $end];
            }
        }
        return null;
    }
}

final class VatPeriodAssignerTest extends TestCase
{
    private function mapping(): VatOutputsMapping
    {
        return new VatOutputsMapping([
            'vatOutputs' => [
                'cz-210' => ['dp3' => ['row' => 1], 'kh' => ['group' => 'A4A5'], 'sh' => null],
                'cz-410' => ['dp3' => ['row' => 20], 'kh' => null, 'sh' => ['kod' => 0]],
                'cz-114' => ['dp3' => null, 'kh' => null, 'sh' => null],
            ],
            // jako reálný config: KH od 1. 1. 2016 (#58), SH bez omezení
            'reportTypes' => ['cs' => ['validFrom' => '2016-01-01']],
        ]);
    }

    /** Přelom 2015/2016: instance KH i SH pro prosinec 2015 existují — KH se přesto nesmí použít. */
    private function turn2016Lookup(): FakePeriodLookup
    {
        return new FakePeriodLookup([
            [101, 5, 'return', '2015-10-01', '2015-12-31'],
            [111, 5, 'cs', '2015-12-01', '2015-12-31'],
            [121, 5, 'rs', '2015-12-01', '2015-12-31'],
            [102, 5, 'return', '2016-01-01', '2016-03-31'],
            [112, 5, 'cs', '2016-01-01', '2016-01-31'],
            [122, 5, 'rs', '2016-01-01', '2016-01-31'],
        ]);
    }

    /** Q1/2026 přiznání + měsíční KH a SH. */
    private function q1Lookup(): FakePeriodLookup
    {
        return new FakePeriodLookup([
            [1, 5, 'return', '2026-01-01', '2026-03-31'],
            [11, 5, 'cs', '2026-01-01', '2026-01-31'],
            [12, 5, 'cs', '2026-02-01', '2026-02-28'],
            [13, 5, 'cs', '2026-03-01', '2026-03-31'],
            [21, 5, 'rs', '2026-01-01', '2026-01-31'],
            [22, 5, 'rs', '2026-02-01', '2026-02-28'],
            [23, 5, 'rs', '2026-03-01', '2026-03-31'],
            // sousední Q2 s měsíci — sem se nic z Q1 nesmí dostat
            [2, 5, 'return', '2026-04-01', '2026-06-30'],
            [14, 5, 'cs', '2026-04-01', '2026-04-30'],
        ]);
    }

    private function assigner(?VatOutputsMapping $mapping = null, ?ReportPeriodLookup $lookup = null): VatPeriodAssigner
    {
        return new VatPeriodAssigner($lookup ?? $this->q1Lookup(), $mapping ?? $this->mapping());
    }

    public function testReturnByDuzpAndCsByDppd(): void
    {
        $out = $this->assigner()->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2026-02-10', 'vat_dppd' => '2026-03-02'],
            [['vat_code' => 'cz-210']],
        );
        $this->assertSame(['vat_period' => 1, 'cs_period' => 13, 'rs_period' => null], $out);
    }

    public function testDppdOutsideReturnRangeIsClampedToReturnEnd(): void
    {
        // DPPD v dubnu, DUZP v březnu → KH březen (03), ne duben (14)
        $out = $this->assigner()->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2026-03-20', 'vat_dppd' => '2026-04-05'],
            [['vat_code' => 'cz-210']],
        );
        $this->assertSame(1, $out['vat_period']);
        $this->assertSame(13, $out['cs_period']);
    }

    public function testDppdBeforeReturnRangeIsClampedToReturnBegin(): void
    {
        $out = $this->assigner()->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2026-01-15', 'vat_dppd' => '2025-12-20'],
            [['vat_code' => 'cz-210']],
        );
        $this->assertSame(11, $out['cs_period']);
    }

    public function testNoKhCodesLeavesCsNull(): void
    {
        $out = $this->assigner()->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2026-02-10', 'vat_dppd' => null],
            [['vat_code' => 'cz-410'], ['vat_code' => 'cz-114']],
        );
        $this->assertSame(['vat_period' => 1, 'cs_period' => null, 'rs_period' => 22], $out);
    }

    public function testUnknownCodeIsSkippedNotFatal(): void
    {
        $out = $this->assigner()->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2026-02-10'],
            [['vat_code' => 'cz-999'], ['vat_code' => 'cz-210']],
        );
        $this->assertSame(12, $out['cs_period']);
    }

    public function testMissingMappingDegradesToReturnOnly(): void
    {
        $out = (new VatPeriodAssigner($this->q1Lookup(), null))->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2026-02-10'],
            [['vat_code' => 'cz-210']],
        );
        $this->assertSame(['vat_period' => 1, 'cs_period' => null, 'rs_period' => null], $out);
    }

    public function testWithoutDuzpOrRegistrationEverythingIsNull(): void
    {
        $empty = ['vat_period' => null, 'cs_period' => null, 'rs_period' => null];
        $this->assertSame($empty, $this->assigner()->compute(['vat_registration' => 5], [['vat_code' => 'cz-210']]));
        $this->assertSame($empty, $this->assigner()->compute(['vat_duzp' => '2026-02-10'], [['vat_code' => 'cz-210']]));
    }

    public function testMissingInstanceYieldsNullFromFindOnlyLookup(): void
    {
        $out = $this->assigner()->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2027-02-10'],
            [['vat_code' => 'cz-210']],
        );
        $this->assertSame(['vat_period' => null, 'cs_period' => null, 'rs_period' => null], $out);
    }

    public function testDateTimeInputsAreNormalized(): void
    {
        $out = $this->assigner()->compute(
            ['vat_registration' => 5, 'vat_duzp' => new \DateTimeImmutable('2026-02-10'), 'vat_dppd' => new \DateTimeImmutable('2026-02-11')],
            [['vat_code' => 'cz-210']],
        );
        $this->assertSame(12, $out['cs_period']);
    }

    /**
     * Partition invarianta (D8): sjednocení dokladů měsíčních KH instancí Q1
     * = doklady s KH kódem v Q1 přiznání, beze zbytku a bez průniku.
     */
    public function testMonthlyCsInstancesPartitionQuarterlyReturn(): void
    {
        $docs = [
            ['id' => 1, 'vat_duzp' => '2026-01-05', 'vat_dppd' => '2026-01-05'],
            ['id' => 2, 'vat_duzp' => '2026-01-31', 'vat_dppd' => '2026-02-03'], // DPPD únor → KH 02
            ['id' => 3, 'vat_duzp' => '2026-02-15', 'vat_dppd' => null],
            ['id' => 4, 'vat_duzp' => '2026-03-31', 'vat_dppd' => '2026-04-10'], // clamp → KH 03
            ['id' => 5, 'vat_duzp' => '2026-03-01', 'vat_dppd' => '2025-12-01'], // clamp → KH 01
            ['id' => 6, 'vat_duzp' => '2026-04-02', 'vat_dppd' => '2026-03-30'], // Q2 přiznání → KH 04, clamp na začátek Q2
        ];
        $assigner = $this->assigner();
        $byCs = [];
        $inQ1 = [];
        foreach ($docs as $doc) {
            $out = $assigner->compute($doc + ['vat_registration' => 5], [['vat_code' => 'cz-210']]);
            $this->assertNotNull($out['cs_period'], "doc {$doc['id']} bez KH instance");
            $byCs[$out['cs_period']][] = $doc['id'];
            if ($out['vat_period'] === 1) {
                $inQ1[] = $doc['id'];
            }
        }
        $q1Union = array_merge($byCs[11] ?? [], $byCs[12] ?? [], $byCs[13] ?? []);
        sort($q1Union);
        sort($inQ1);
        $this->assertSame([1, 2, 3, 4, 5], $inQ1);
        $this->assertSame($inQ1, $q1Union);
        $this->assertSame(count($q1Union), count(array_unique($q1Union)), 'doklad ve dvou KH instancích');
        $this->assertSame([6], $byCs[14]);
    }

    // ── Zákonný počátek výstupu (#58) ───────────────────────────────────────

    public function testCsBeforeValidFromIsNullAndLookupNotCalled(): void
    {
        $lookup = $this->turn2016Lookup();
        $out = $this->assigner(lookup: $lookup)->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2015-12-31', 'vat_dppd' => null],
            [['vat_code' => 'cz-210'], ['vat_code' => 'cz-410']],
        );
        $this->assertSame(['vat_period' => 101, 'cs_period' => null, 'rs_period' => 121], $out, 'přiznání a SH ano, KH ne');
        $this->assertSame(0, $lookup->calls['cs'], 'lookup pro cs se nesmí volat — žádný on-demand koncept');
        $this->assertSame(1, $lookup->calls['rs']);
    }

    public function testCsFromValidFromIsAssigned(): void
    {
        $out = $this->assigner(lookup: $this->turn2016Lookup())->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2016-01-01', 'vat_dppd' => null],
            [['vat_code' => 'cz-210']],
        );
        $this->assertSame(['vat_period' => 102, 'cs_period' => 112, 'rs_period' => null], $out);
    }

    public function testValidFromAppliesToClampedEffectiveDate(): void
    {
        // DUZP v prosinci 2015, DPPD v lednu 2016 → efektivní datum se ořízne
        // do Q4/2015 přiznání (2015-12-31) → před validFrom → KH NULL.
        $lookup = $this->turn2016Lookup();
        $out = $this->assigner(lookup: $lookup)->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2015-12-20', 'vat_dppd' => '2016-01-05'],
            [['vat_code' => 'cz-210']],
        );
        $this->assertSame(101, $out['vat_period']);
        $this->assertNull($out['cs_period']);
        $this->assertSame(0, $lookup->calls['cs']);
    }

    public function testWithoutReportTypesSectionNoLimitApplies(): void
    {
        $mapping = new VatOutputsMapping(['vatOutputs' => [
            'cz-210' => ['dp3' => ['row' => 1], 'kh' => ['group' => 'A4A5'], 'sh' => null],
        ]]);
        $out = $this->assigner($mapping, $this->turn2016Lookup())->compute(
            ['vat_registration' => 5, 'vat_duzp' => '2015-12-31'],
            [['vat_code' => 'cz-210']],
        );
        $this->assertSame(111, $out['cs_period']);
    }

    public function testIsBeforeValidFrom(): void
    {
        $assigner = $this->assigner();
        $this->assertTrue($assigner->isBeforeValidFrom('cs', '2015-12-31'));
        $this->assertFalse($assigner->isBeforeValidFrom('cs', '2016-01-01'));
        $this->assertFalse($assigner->isBeforeValidFrom('rs', '2013-01-01'));
        $this->assertFalse($assigner->isBeforeValidFrom('return', '2013-01-01'));
        $this->assertFalse((new VatPeriodAssigner($this->q1Lookup(), null))->isBeforeValidFrom('cs', '2013-01-01'), 'bez mapování bez omezení');
    }

    // ── Ruční přepis (handler) ──────────────────────────────────────────────

    public function testManualOverridesDetectChangedFieldsOnly(): void
    {
        $original = ['vat_period' => 1, 'cs_period' => 12, 'rs_period' => null];
        $data = ['vat_period' => '1', 'cs_period' => 13, 'rs_period' => null];
        $this->assertSame(['cs_period' => 13], DocsHeadsVatPeriodHandler::manualOverrides($data, $original));

        // vynulování pole je taky ruční zásah
        $this->assertSame(['cs_period' => null], DocsHeadsVatPeriodHandler::manualOverrides(['cs_period' => ''], $original));

        // insert: jen ne-null hodnoty
        $this->assertSame(['vat_period' => 7], DocsHeadsVatPeriodHandler::manualOverrides(['vat_period' => 7, 'cs_period' => null], null));

        // pole v payloadu chybí → nic ručního
        $this->assertSame([], DocsHeadsVatPeriodHandler::manualOverrides(['description' => 'x'], $original));
    }
}
