<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Vat\DocsHeadsVatPeriodHandler;

/**
 * Nedaňový typ dokladu (docTypes[].tax_document: false, #79 D1) do tvrzení
 * nepatří — handler nuluje všechna tři období včetně ruční hodnoty, ještě
 * před jakýmkoli DB přístupem. Daňový typ bez DB nechá beze změny (dnešek).
 */
final class DocsHeadsVatPeriodHandlerTest extends TestCase
{
    private function handler(): DocsHeadsVatPeriodHandler
    {
        $docTypes = [
            'invno' => ['trade_dir' => 1],
            'invpo' => ['trade_dir' => 1, 'tax_document' => false],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        $handler = new DocsHeadsVatPeriodHandler();
        $handler->setConfig($config);
        return $handler;
    }

    public function testNonTaxTypeClearsAllPeriodsIncludingManualValue(): void
    {
        $data = [
            'doc_type'         => 'invpo',
            'vat_registration' => 5,
            'vat_duzp'         => '2026-01-15',
            'vat_period'       => 7,
            'cs_period'        => '3',
        ];
        $this->handler()->onBeforeSave('docs_core_heads', $data, null);

        $this->assertNull($data['vat_period'], 'ruční hodnota u nedaňového dokladu neplatí');
        $this->assertNull($data['cs_period']);
        $this->assertNull($data['rs_period']);
        $this->assertSame('2026-01-15', $data['vat_duzp'], 'DUZP handler neřeší (nuluje DocDocument)');
    }

    public function testNonTaxTypeFallsBackToOriginalRowForDocType(): void
    {
        // Částečný save bez number_series doc_type v payloadu nenese.
        $data = ['docState' => 40, 'vat_period' => 7];
        $original = ['id' => 1, 'doc_type' => 'invpo', 'vat_period' => 7];
        $this->handler()->onBeforeSave('docs_core_heads', $data, $original);

        $this->assertNull($data['vat_period']);
    }

    public function testTaxTypeWithoutDbIsLeftUntouched(): void
    {
        $data = ['doc_type' => 'invno', 'vat_registration' => 5, 'vat_duzp' => '2026-01-15', 'vat_period' => 7];
        $this->handler()->onBeforeSave('docs_core_heads', $data, null);

        $this->assertSame(7, $data['vat_period'], 'bez DB handler daňový doklad nemění (regrese)');
        $this->assertArrayNotHasKey('cs_period', $data);
    }
}
