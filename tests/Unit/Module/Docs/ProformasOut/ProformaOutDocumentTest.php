<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\ProformasOut;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\ProformasOut\ProformaOutDocument;

/**
 * Per-typ validace zálohové faktury vydané (#79 D1): náš bankovní účet
 * povinný při Potvrzení, jinak dědí DocsHeadsDocument.
 */
class ProformaOutDocumentTest extends TestCase
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
            'bank_account'     => 7,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function bankAccountErrors(ProformaOutDocument $doc, array $data): array
    {
        return array_values(array_filter(
            $doc->validate($data)->toArray(),
            static fn (array $e): bool => $e['column'] === 'bank_account',
        ));
    }

    public function testConfirmedRequiresBankAccount(): void
    {
        $doc = new ProformaOutDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        unset($data['bank_account']);

        $errors = $this->bankAccountErrors($doc, $data);
        $this->assertNotEmpty($errors, 'Potvrzení bez bankovního účtu musí hlásit chybu u pole');
        $this->assertSame('required', $errors[0]['code']);
    }

    public function testConfirmedAcceptsBankAccount(): void
    {
        $doc = new ProformaOutDocument();
        $doc->setDb($this->dbWithOwn());

        $this->assertSame([], $this->bankAccountErrors($doc, $this->confirmedData()));
    }

    public function testKonceptDoesNotRequireBankAccount(): void
    {
        $doc = new ProformaOutDocument();
        $doc->setDb($this->dbWithOwn());

        $data = [
            'docState'        => 10,
            'number_series'   => 1,
            'issue_date'      => '2026-05-06',
            'accounting_date' => '2026-05-06',
        ];

        $this->assertSame([], $this->bankAccountErrors($doc, $data));
    }

    public function testInheritsParentValidation(): void
    {
        $doc = new ProformaOutDocument();
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
