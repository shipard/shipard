<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Reports;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Reports\FiscalPeriodProvider;
use Shipard\Core\Reports\ReportDefinition;
use Shipard\Core\Reports\ReportParamValidator;
use Shipard\Core\Reports\ReportPeriodProvider;

/**
 * periodSource 'vatPeriod': deklarace (vatReportType) + validace parametru
 * `period` (id instance tvrzení → VatPeriodRange).
 */
class VatPeriodValidatorTest extends TestCase
{
    // ── ReportDefinition::fromArray — periodSource ──────────────────────────

    public function testDefinitionVatPeriodWithoutGranularities(): void
    {
        $definition = ReportDefinition::fromArray([
            'id'           => 'economy.vat.returnLive',
            'name'         => 'Přiznání k DPH — živě',
            'builder'      => 'X',
            'periodSource' => 'vatPeriod',
            'vatReportType' => 'return',
        ], 'economy.vat');

        $this->assertSame('vatPeriod', $definition->periodSource);
        $this->assertSame('return', $definition->vatReportType);
        $this->assertSame([], $definition->periodGranularities);
    }

    public function testDefinitionVatPeriodRequiresReportType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("requires 'vatReportType'");
        ReportDefinition::fromArray([
            'id' => 'test.report', 'name' => 'Test', 'builder' => 'X', 'periodSource' => 'vatPeriod',
        ], 'test.module');
    }

    public function testDefinitionRejectsUnknownReportType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("requires 'vatReportType'");
        ReportDefinition::fromArray([
            'id' => 'test.report', 'name' => 'Test', 'builder' => 'X',
            'periodSource' => 'vatPeriod', 'vatReportType' => 'oss',
        ], 'test.module');
    }

    public function testDefinitionFiscalRejectsReportType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("only allowed for periodSource 'vatPeriod'");
        ReportDefinition::fromArray([
            'id' => 'test.report', 'name' => 'Test', 'builder' => 'X',
            'periodGranularities' => ['month'], 'vatReportType' => 'return',
        ], 'test.module');
    }

    public function testDefinitionDefaultsToFiscal(): void
    {
        $definition = ReportDefinition::fromArray([
            'id'                  => 'test.report',
            'name'                => 'Test',
            'builder'             => 'X',
            'periodGranularities' => ['month'],
        ], 'test.module');

        $this->assertSame('fiscal', $definition->periodSource);
    }

    public function testDefinitionVatPeriodRejectsGranularities(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("must not be declared for periodSource 'vatPeriod'");
        ReportDefinition::fromArray([
            'id'                  => 'test.report',
            'name'                => 'Test',
            'builder'             => 'X',
            'periodSource'        => 'vatPeriod',
            'vatReportType'       => 'return',
            'periodGranularities' => ['month'],
        ], 'test.module');
    }

    public function testDefinitionRejectsUnknownPeriodSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'periodSource' must be one of");
        ReportDefinition::fromArray([
            'id'           => 'test.report',
            'name'         => 'Test',
            'builder'      => 'X',
            'periodSource' => 'calendar',
        ], 'test.module');
    }

    // ── ReportParamValidator — vatPeriod větev ──────────────────────────────

    /** Registrace 5: return Q1/2026 (id 1), cs 01–03/2026 (11–13, 03 locked), rs 01/2026 (21). */
    private function fakePeriods(): ReportPeriodProvider
    {
        return new class implements ReportPeriodProvider {
            private const PERIODS = [
                1  => ['type' => 'return', 'name' => 'Q1/2026', 'dateBegin' => '2026-01-01', 'dateEnd' => '2026-03-31', 'locked' => false, 'docState' => 40],
                11 => ['type' => 'cs', 'name' => '01/2026', 'dateBegin' => '2026-01-01', 'dateEnd' => '2026-01-31', 'locked' => false, 'docState' => 40],
                12 => ['type' => 'cs', 'name' => '02/2026', 'dateBegin' => '2026-02-01', 'dateEnd' => '2026-02-28', 'locked' => false, 'docState' => 10],
                13 => ['type' => 'cs', 'name' => '03/2026', 'dateBegin' => '2026-03-01', 'dateEnd' => '2026-03-31', 'locked' => true, 'docState' => 40],
                21 => ['type' => 'rs', 'name' => '01/2026', 'dateBegin' => '2026-01-01', 'dateEnd' => '2026-01-31', 'locked' => false, 'docState' => 40],
            ];

            public function findPeriod(int $id): ?array
            {
                if (!isset(self::PERIODS[$id])) {
                    return null;
                }
                return self::PERIODS[$id] + ['id' => $id, 'registrationId' => 5, 'registrationName' => 'CZ plátce'];
            }

            public function registrationsWithPeriods(): array
            {
                $periods = [];
                foreach (self::PERIODS as $id => $p) {
                    $periods[] = ['id' => $id] + $p;
                }
                return [['id' => 5, 'name' => 'CZ plátce', 'vatId' => 'CZ12345678', 'periods' => $periods]];
            }
        };
    }

    private function fakeFiscalPeriods(): FiscalPeriodProvider
    {
        return new class implements FiscalPeriodProvider {
            public function findYearByName(string $name): ?array
            {
                return null;
            }

            public function monthsOfYear(int $fiscalYearId): array
            {
                return [];
            }

            public function regularYears(): array
            {
                return [];
            }

            public function yearForDate(string $date): ?array
            {
                return null;
            }

            public function years(): array
            {
                return [];
            }
        };
    }

    private function definition(string $type = 'cs'): ReportDefinition
    {
        return new ReportDefinition(
            id: 'test.vat',
            name: 'Test VAT',
            builderClass: 'X',
            periodGranularities: [],
            params: [],
            moduleId: 'test.module',
            periodSource: 'vatPeriod',
            vatReportType: $type,
        );
    }

    private function validator(?ReportPeriodProvider $periods = null): ReportParamValidator
    {
        return new ReportParamValidator($this->fakeFiscalPeriods(), $periods ?? $this->fakePeriods());
    }

    public function testValidatorResolvesInstance(): void
    {
        $result = $this->validator()->validate($this->definition('cs'), ['period' => '12']);

        $this->assertNull($result['range']);
        $vatRange = $result['vatRange'];
        $this->assertSame(12, $vatRange->periodId);
        $this->assertSame('cs', $vatRange->reportType);
        $this->assertSame('02/2026', $vatRange->periodName);
        $this->assertSame(5, $vatRange->registrationId);
        $this->assertSame('CZ plátce', $vatRange->registrationName);
        $this->assertSame('2026-02-01', $vatRange->dateBegin);
        $this->assertSame('2026-02-28', $vatRange->dateEnd);
        $this->assertFalse($vatRange->locked);

        $this->assertSame(
            ['period' => 12, 'reportType' => 'cs', 'name' => '02/2026', 'vatRegistration' => 5,
                'dateFrom' => '2026-02-01', 'dateTo' => '2026-02-28'],
            $result['params']['period'],
        );
    }

    public function testValidatorAcceptsLockedAndConceptInstances(): void
    {
        // Zámek se v reportech nevynucuje (Fáze 4), koncept je čitelný.
        $this->assertTrue($this->validator()->validate($this->definition('cs'), ['period' => 13])['vatRange']->locked);
        $this->assertSame(12, $this->validator()->validate($this->definition('cs'), ['period' => 12])['vatRange']->periodId);
    }

    /** Původní bug z alfy: KH čtvrtletního plátce nabízí měsíce — DP3 bere Q1, KH měsíc. */
    public function testReturnAndCsReportsUseInstancesOfTheirOwnType(): void
    {
        $this->assertSame('Q1/2026', $this->validator()->validate($this->definition('return'), ['period' => 1])['vatRange']->periodName);
        $this->assertSame('01/2026', $this->validator()->validate($this->definition('cs'), ['period' => 11])['vatRange']->periodName);
    }

    public function testValidatorRejectsTypeMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("is of type 'return', report 'test.vat' requires 'cs'");
        $this->validator()->validate($this->definition('cs'), ['period' => 1]);
    }

    public function testValidatorRejectsUnknownInstance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("VAT report period '99' does not exist");
        $this->validator()->validate($this->definition('cs'), ['period' => '99']);
    }

    public function testValidatorRejectsMissingOrInvalidPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing or invalid required parameter 'period'");
        $this->validator()->validate($this->definition('cs'), ['period' => 'abc']);
    }

    public function testValidatorRejectsLegacyIntervalParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown parameter 'dateFrom'");
        $this->validator()->validate($this->definition('cs'), ['period' => 11, 'dateFrom' => '2026-01-01']);
    }

    public function testValidatorRejectsFiscalParamsOnVatReport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown parameter 'fiscalYear'");
        $this->validator()->validate($this->definition('cs'), ['period' => 11, 'fiscalYear' => '2026']);
    }

    public function testValidatorRequiresProviderWiring(): void
    {
        $validator = new ReportParamValidator($this->fakeFiscalPeriods());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a ReportPeriodProvider');
        $validator->validate($this->definition('cs'), ['period' => 11]);
    }
}
