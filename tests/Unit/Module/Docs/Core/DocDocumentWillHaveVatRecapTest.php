<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\DocDocument;

/**
 * DocDocument::willHaveVatRecap — čisté pravidlo „bude mít doklad po
 * uložení rekapitulaci DPH" pro VatPeriodLockProvider (#55 D23). Zrcadlí
 * buildVatRecapitulation / useDeclaredRecap bez výpočtu částek.
 */
final class DocDocumentWillHaveVatRecapTest extends TestCase
{
    private function base(array $override = []): array
    {
        return array_merge(['vat_registration' => 5, 'vat_mode' => 1, 'vat_recap_source' => 0], $override);
    }

    public function testVatModeZeroNeverHasRecap(): void
    {
        $data = $this->base(['vat_mode' => 0, 'rows' => [['row_kind' => 1, 'vat_code' => 'cz-210']]]);
        $this->assertFalse(DocDocument::willHaveVatRecap($data, true));
    }

    public function testMissingRegistrationNeverHasRecap(): void
    {
        $data = $this->base(['vat_registration' => null, 'rows' => [['row_kind' => 1, 'vat_code' => 'cz-210']]]);
        $this->assertFalse(DocDocument::willHaveVatRecap($data, true));
    }

    public function testCodedItemRowMeansRecap(): void
    {
        $data = $this->base(['rows' => [['row_kind' => 1, 'vat_code' => ''], ['row_kind' => 1, 'vat_code' => 'cz-210']]]);
        $this->assertTrue(DocDocument::willHaveVatRecap($data, false));
    }

    public function testRowsWithoutCodesMeanNoRecapEvenIfStored(): void
    {
        $data = $this->base(['rows' => [['row_kind' => 1, 'vat_code' => null]]]);
        $this->assertFalse(DocDocument::willHaveVatRecap($data, true));
    }

    public function testNonItemRowsAreIgnored(): void
    {
        $data = $this->base(['rows' => [['row_kind' => 2, 'vat_code' => 'cz-210']]]);
        $this->assertFalse(DocDocument::willHaveVatRecap($data, false));
    }

    public function testWithoutRowsKeyStoredStateDecides(): void
    {
        $this->assertTrue(DocDocument::willHaveVatRecap($this->base(), true));
        $this->assertFalse(DocDocument::willHaveVatRecap($this->base(), false));
    }

    public function testDeclaredRecapInPayloadMeansRecap(): void
    {
        $data = $this->base(['vat_recap_source' => 1, 'vatRecap' => [['vat_code' => 'cz-210', 'base' => 100]], 'rows' => []]);
        $this->assertTrue(DocDocument::willHaveVatRecap($data, false));
    }

    public function testDeclaredRecapStoredInDbSurvivesUncodedRows(): void
    {
        $data = $this->base(['vat_recap_source' => 1, 'rows' => [['row_kind' => 1, 'vat_code' => null]]]);
        $this->assertTrue(DocDocument::willHaveVatRecap($data, true));
        $this->assertFalse(DocDocument::willHaveVatRecap($data, false));
    }

    public function testDefaultVatModeIsOne(): void
    {
        $data = ['vat_registration' => 5, 'rows' => [['vat_code' => 'cz-210']]];
        $this->assertTrue(DocDocument::willHaveVatRecap($data, false));
    }

    /** Doklad jen s řádky „Mimo DPH“ rekapitulaci nemá (#164 U6). */
    public function testOutOfScopeRowsAloneGiveNoRecap(): void
    {
        $codes = [
            'cz-210' => ['category' => 'standard', 'place' => 'domestic', 'direction' => 'input'],
            'cz-000' => ['category' => 'outOfScope'],
        ];
        $outOfScope = ['row_kind' => 1, 'vat_code' => 'cz-000'];

        $this->assertFalse(DocDocument::willHaveVatRecap($this->base(['rows' => [$outOfScope]]), true, $codes));
        $this->assertTrue(DocDocument::willHaveVatRecap(
            $this->base(['rows' => [$outOfScope, ['row_kind' => 1, 'vat_code' => 'cz-210']]]),
            false,
            $codes,
        ));
        // Bez definic kódů se „Mimo DPH“ nepozná — řádek s kódem je řádek rekapitulace.
        $this->assertTrue(DocDocument::willHaveVatRecap($this->base(['rows' => [$outOfScope]]), false));
        // Převzatá rekapitulace uloženého dokladu řádky „Mimo DPH“ nezruší.
        $this->assertTrue(DocDocument::willHaveVatRecap(
            $this->base(['vat_recap_source' => 1, 'rows' => [$outOfScope]]),
            true,
            $codes,
        ));
    }
}
