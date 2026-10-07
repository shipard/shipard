<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\ProformasIn;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\ReceivedInvoiceDocumentBase;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceDocument;
use Shipard\Module\Docs\ProformasIn\ProformaInDocument;

/**
 * Per-typ validace zálohové faktury přijaté (#106 D1): doporučení
 * bankovního spojení dodavatele při Potvrzení (warning, uložení projde)
 * sdílené s FPB přes `ReceivedInvoiceDocumentBase`; jinak dědí
 * DocsHeadsDocument.
 */
class ProformaInDocumentTest extends TestCase
{
    private function dbWithOwn(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 1])); // own person exists
        return $db;
    }

    /**
     * Minimální data, která projdou validací rodiče pro stav 40 (V pořádku).
     *
     * @return array<string, mixed>
     */
    private function confirmedData(): array
    {
        return [
            'docState'         => 40,
            'number_series'    => 1,
            'issue_date'       => '2026-05-06',
            'accounting_date'  => '2026-05-06',
            'partner'          => 50,
            'vat_registration' => 1,
            'vat_mode'         => 1,
            'rows'             => [['row_kind' => 1, 'total_price' => 100]],
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
        ];
    }

    /**
     * @param list<array{column: string, message: string, code: string}> $warnings
     * @return list<array{column: string, message: string, code: string}>
     */
    private function bankWarnings(array $warnings): array
    {
        return array_values(array_filter(
            $warnings,
            static fn (array $w): bool => $w['code'] === 'partner_bank_recommended',
        ));
    }

    public function testSharesValidationBaseWithReceivedInvoice(): void
    {
        $this->assertInstanceOf(ReceivedInvoiceDocumentBase::class, new ProformaInDocument());
        $this->assertInstanceOf(ReceivedInvoiceDocumentBase::class, new ReceivedInvoiceDocument());
    }

    public function testConfirmedWithoutPartnerBankInfoWarnsButSaves(): void
    {
        $doc = new ProformaInDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $result = $doc->validate($data);

        $this->assertTrue($result->isValid(), 'chybějící spojení dodavatele neblokuje uložení');
        $this->assertEmpty($result->toArray());
        $matched = $this->bankWarnings($result->warningsToArray());
        $this->assertNotEmpty($matched, 'výzva k platbě bez spojení dodavatele musí varovat');
        $this->assertSame('partner_bank', $matched[0]['column']);
    }

    public function testPartnerBankSatisfiesRecommendation(): void
    {
        $doc = new ProformaInDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['partner_bank_iban'] = 'CZ6508000000192000145399';

        $this->assertEmpty($this->bankWarnings($doc->validate($data)->warningsToArray()));
    }

    public function testCashPaymentDoesNotWarn(): void
    {
        $doc = new ProformaInDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['payment_method'] = 0;

        $this->assertEmpty($this->bankWarnings($doc->validate($data)->warningsToArray()));
    }

    public function testKonceptDoesNotWarn(): void
    {
        $doc = new ProformaInDocument();
        $doc->setDb($this->dbWithOwn());

        $data = [
            'docState'        => 10,
            'number_series'   => 1,
            'issue_date'      => '2026-05-06',
            'accounting_date' => '2026-05-06',
        ];

        $this->assertEmpty($this->bankWarnings($doc->validate($data)->warningsToArray()));
    }

    public function testDoesNotRequireOwnBankAccount(): void
    {
        $doc = new ProformaInDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $errors = array_filter(
            $doc->validate($data)->toArray(),
            static fn (array $e): bool => $e['column'] === 'bank_account',
        );
        $this->assertSame([], array_values($errors), 'přijatý doklad náš účet nevyžaduje (na rozdíl od FVZ)');
    }

    public function testInheritsParentValidation(): void
    {
        $doc = new ProformaInDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        unset($data['partner']);

        $matched = array_filter(
            $doc->validate($data)->toArray(),
            static fn (array $e): bool => $e['column'] === 'partner' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched, 'Per-typ subclass musí dědit validaci rodiče');
    }
}
