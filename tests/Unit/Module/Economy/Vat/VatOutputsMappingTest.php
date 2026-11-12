<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Vat\VatOutputsMapping;

class VatOutputsMappingTest extends TestCase
{
    private const CONFIG = __DIR__
        . '/../../../../../modules/economy/vat/config/vat-reports-cz.jsonc';

    private function mapping(): VatOutputsMapping
    {
        return new VatOutputsMapping(JsoncParser::parseFile(self::CONFIG));
    }

    public function testResolvesKnownCodes(): void
    {
        $mapping = $this->mapping();

        $this->assertSame(['row' => 40, 'col' => 'full'], $mapping->dp3('cz-110'));
        $this->assertSame(['group' => 'B2B3'], $mapping->kh('cz-110'));
        $this->assertNull($mapping->sh('cz-110'));

        // Předmět plnění mapování nenese — čte se z rekapitulace dokladu (#89 D13).
        $this->assertSame(['group' => 'B1'], $mapping->kh('cz-115'));
        $this->assertSame(['group' => 'B1'], $mapping->kh('cz-117'));
        $this->assertSame(['row' => 40, 'col' => 'reduced'], $mapping->dp3('cz-118'));
        $this->assertSame(['kod' => 0], $mapping->sh('cz-201'));
        $this->assertSame(['kod' => 3], $mapping->sh('cz-202'));

        // Explicitní vyloučení: záznam existuje, všechny výstupy null.
        $this->assertSame(['dp3' => null, 'kh' => null, 'sh' => null], $mapping->forCode('cz-112'));
    }

    public function testUnknownCodeThrows(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Kód DPH 'cz-999'");
        $this->mapping()->forCode('cz-999');
    }

    public function testDp3RowLabels(): void
    {
        $mapping = $this->mapping();
        $this->assertNotNull($mapping->dp3RowLabel(64));
        $this->assertNotNull($mapping->dp3RowLabel(1));
        $this->assertNull($mapping->dp3RowLabel(99));
    }

    public function testMissingVatOutputsSectionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VatOutputsMapping(['dp3Rows' => []]);
    }

    // ── reportTypes.validFrom (#58) ──────────────────────────────────────

    public function testValidFromFromRealConfig(): void
    {
        $mapping = $this->mapping();
        $this->assertSame('2016-01-01', $mapping->validFrom('cs'), 'KH existuje od 1. 1. 2016');
        $this->assertNull($mapping->validFrom('return'));
        $this->assertNull($mapping->validFrom('rs'));
        $this->assertSame(['cs' => '2016-01-01'], $mapping->validFromByType());
    }

    public function testMissingReportTypesSectionMeansNoLimits(): void
    {
        $mapping = new VatOutputsMapping(['vatOutputs' => []]);
        $this->assertNull($mapping->validFrom('cs'));
        $this->assertNull($mapping->validFrom('return'));
        $this->assertNull($mapping->validFrom('rs'));
        $this->assertSame([], $mapping->validFromByType());
    }

    public function testUnknownReportTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown report type 'ks'");
        new VatOutputsMapping(['vatOutputs' => [], 'reportTypes' => ['ks' => ['validFrom' => '2016-01-01']]]);
    }

    public function testInvalidValidFromThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reportTypes.cs.validFrom');
        new VatOutputsMapping(['vatOutputs' => [], 'reportTypes' => ['cs' => ['validFrom' => '2016-13-01']]]);
    }

    public function testFromConfigWithoutCfgItemIsNull(): void
    {
        $this->assertNull(VatOutputsMapping::fromConfig(null));
    }

    // ── reportTypes: druhy podání a zaokrouhlení (#55 D16, D17) ──────────

    public function testFilingRulesFromRealConfig(): void
    {
        $mapping = $this->mapping();

        $this->assertSame(['regular', 'corrective', 'supplementary'], $mapping->filingKinds('return'));
        $this->assertSame(['regular', 'corrective', 'subsequent'], $mapping->filingKinds('cs'));
        $this->assertSame(['regular', 'subsequent'], $mapping->filingKinds('rs'));

        $this->assertSame('diff', $mapping->supplementaryMode('return'), 'DP3 dodatečné = rozdíly + ř. 66');
        $this->assertSame('full', $mapping->supplementaryMode('cs'), 'KH dodatečné nezná — default full');

        $this->assertSame(['subsequent'], $mapping->dateFoundRequiredFor('cs'));
        $this->assertSame([], $mapping->dateFoundRequiredFor('return'));

        $this->assertSame(1.0, $mapping->roundingUnit('return'));
        $this->assertSame(0.01, $mapping->roundingUnit('cs'), 'KH se podává na haléře');
        $this->assertSame(1.0, $mapping->roundingUnit('rs'));
    }

    public function testFilingRuleDefaultsWithoutReportTypesSection(): void
    {
        $mapping = new VatOutputsMapping(['vatOutputs' => []]);

        $this->assertSame([], $mapping->filingKinds('return'), 'bez configu se nedá podat nic');
        $this->assertSame('full', $mapping->supplementaryMode('return'));
        $this->assertSame([], $mapping->dateFoundRequiredFor('cs'));
        $this->assertSame(0.01, $mapping->roundingUnit('return'), 'bez configu se hodnoty neposouvají');
    }

    public function testUnknownFilingKindThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown filing kind 'additional' in reportTypes.return.filingKinds");
        new VatOutputsMapping([
            'vatOutputs'  => [],
            'reportTypes' => ['return' => ['filingKinds' => ['regular', 'additional']]],
        ]);
    }

    public function testFilingKindsMustBeAList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reportTypes.cs.filingKinds must be an array');
        new VatOutputsMapping([
            'vatOutputs'  => [],
            'reportTypes' => ['cs' => ['filingKinds' => ['first' => 'regular']]],
        ]);
    }

    public function testDateFoundRequiredForMustBeAmongFilingKinds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("dateFoundRequiredFor contains 'supplementary'");
        new VatOutputsMapping([
            'vatOutputs'  => [],
            'reportTypes' => ['cs' => [
                'filingKinds'          => ['regular', 'subsequent'],
                'dateFoundRequiredFor' => ['supplementary'],
            ]],
        ]);
    }

    public function testUnknownSupplementaryModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("reportTypes.return.supplementaryMode must be 'diff' or 'full'");
        new VatOutputsMapping([
            'vatOutputs'  => [],
            'reportTypes' => ['return' => ['supplementaryMode' => 'delta']],
        ]);
    }

    public function testNonPositiveRoundingUnitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reportTypes.rs.roundingUnit must be greater than zero');
        new VatOutputsMapping([
            'vatOutputs'  => [],
            'reportTypes' => ['rs' => ['roundingUnit' => 0]],
        ]);
    }

    public function testNonNumericRoundingUnitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reportTypes.rs.roundingUnit must be a number');
        new VatOutputsMapping([
            'vatOutputs'  => [],
            'reportTypes' => ['rs' => ['roundingUnit' => '1']],
        ]);
    }

    // ── reportTypes.accounting (#55 D29) ─────────────────────────────────

    public function testAccountingFromRealConfig(): void
    {
        $mapping = $this->mapping();
        $this->assertSame([
            'payableDueDays'       => 25,
            'refundDueDays'        => 60,
            'specificSymbolPrefix' => '705',
            'constantSymbol'       => '1148',
            'excludeAccounts'      => ['343801', '343802'],
        ], $mapping->accounting('return'));
        // Hlášení se neúčtují.
        $this->assertNull($mapping->accounting('cs'));
        $this->assertNull($mapping->accounting('rs'));
    }

    public function testAccountingDefaultsSymbolsAndExcludeList(): void
    {
        $mapping = new VatOutputsMapping([
            'vatOutputs'  => [],
            'reportTypes' => ['return' => ['accounting' => ['payableDueDays' => 0, 'refundDueDays' => 30]]],
        ]);
        $this->assertSame([
            'payableDueDays'       => 0,
            'refundDueDays'        => 30,
            'specificSymbolPrefix' => '',
            'constantSymbol'       => '',
            'excludeAccounts'      => [],
        ], $mapping->accounting('return'));
    }

    public function testAccountingDueDaysMustBeNonNegativeInt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reportTypes.return.accounting.refundDueDays must be a non-negative integer');
        new VatOutputsMapping([
            'vatOutputs'  => [],
            'reportTypes' => ['return' => ['accounting' => ['payableDueDays' => 25, 'refundDueDays' => -1]]],
        ]);
    }

    public function testAccountingSymbolsMustBeDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reportTypes.return.accounting.constantSymbol must be a string of at most 10 digits');
        new VatOutputsMapping([
            'vatOutputs'  => [],
            'reportTypes' => ['return' => ['accounting' => [
                'payableDueDays' => 25, 'refundDueDays' => 60, 'constantSymbol' => 'KS-1148',
            ]]],
        ]);
    }

    public function testAccountingExcludeAccountsMustBeStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('excludeAccounts must contain account numbers as strings');
        new VatOutputsMapping([
            'vatOutputs'  => [],
            'reportTypes' => ['return' => ['accounting' => [
                'payableDueDays' => 25, 'refundDueDays' => 60, 'excludeAccounts' => [343801],
            ]]],
        ]);
    }
}
