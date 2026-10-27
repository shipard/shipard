<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Values;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Values\CanonicalValueNormalizer;
use Shipard\Module\Core\Exchange\Values\CanonicalValueValidator;

/**
 * Validace hodnot canonicalu (#118 D3, D13, D14): každý kód jako warning
 * na cestě pole se zprávou s hodnotou; tiskový tvar není nález; opravy
 * z `_resolve.valueRepairs` → `bank_account_repaired`.
 */
class CanonicalValueValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function cleanCanonical(): array
    {
        return [
            'docType'  => 'invoiceReceived',
            'supplier' => [
                'name' => 'Dřevomat Hana s.r.o.', 'country' => 'cz',
                'companyId' => '52654150', 'taxId' => 'CZ52654150', 'vatId' => 'CZ52654150',
                'address' => ['zip' => '77900', 'country' => 'cz'],
                'contact' => ['email' => 'x@y.example', 'phone' => '+420577123456', 'web' => 'www.y.example'],
                'bankAccount' => ['accountNumber' => '2308417557/2010', 'iban' => 'CZ8520100000002308417557', 'bic' => 'FIOBCZPP'],
            ],
            'customer' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function withSupplier(array $overrides): array
    {
        $canonical = $this->cleanCanonical();
        $canonical['supplier'] = array_replace_recursive($canonical['supplier'], $overrides);
        return $canonical;
    }

    /** @return list<string> */
    private function codes(array $canonical): array
    {
        return array_column(CanonicalValueValidator::validate($canonical), 'code');
    }

    /** @return array{severity: string, path: string, code: string, message: string} */
    private function issue(array $canonical, string $code): array
    {
        foreach (CanonicalValueValidator::validate($canonical) as $issue) {
            if ($issue['code'] === $code) {
                return $issue;
            }
        }
        $this->fail("chybí nález {$code}");
    }

    public function testCleanCanonicalHasNoFindings(): void
    {
        $this->assertSame([], CanonicalValueValidator::validate($this->cleanCanonical()));
        $this->assertSame([], CanonicalValueValidator::validate(['docType' => 'x', 'supplier' => null]));
    }

    public function testPrintShapesAreNotFindings(): void
    {
        // Persistované analýzy před nasazením: mezery a malá písmena nejsou nález.
        $canonical = $this->withSupplier([
            'country' => 'CZ', 'companyId' => '526 541 50', 'vatId' => 'cz 5265 4150',
            'address' => ['zip' => '779 00'],
            'bankAccount' => ['accountNumber' => '0002308417557/2010', 'iban' => 'cz85 2010 0000 0023 0841 7557', 'bic' => 'fiobczpp'],
        ]);
        $this->assertSame([], CanonicalValueValidator::validate($canonical));
        $this->assertSame('526 541 50', $canonical['supplier']['companyId'], 'validace hodnotu nemění');
    }

    public function testCompanyIdInvalid(): void
    {
        $issue = $this->issue($this->withSupplier(['companyId' => '12345678']), CanonicalValueValidator::COMPANY_ID_INVALID);
        $this->assertSame(['warning', 'supplier.companyId'], [$issue['severity'], $issue['path']]);
        $this->assertStringContainsString('„12345678“', $issue['message']);
        $this->assertStringContainsString('kontrolní součet', $issue['message']);

        $issue = $this->issue($this->withSupplier(['companyId' => '1234567A']), CanonicalValueValidator::COMPANY_ID_INVALID);
        $this->assertStringContainsString('osmi číslic', $issue['message']);

        $this->assertNotContains(CanonicalValueValidator::COMPANY_ID_INVALID, $this->codes($this->withSupplier(['country' => 'de', 'companyId' => 'HRB 12345'])));
        $this->assertNotContains(CanonicalValueValidator::COMPANY_ID_INVALID, $this->codes($this->withSupplier(['companyId' => null])));
    }

    public function testVatIdInvalidAndMismatch(): void
    {
        $issue = $this->issue($this->withSupplier(['vatId' => 'CZ526541']), CanonicalValueValidator::VAT_ID_INVALID);
        $this->assertSame('supplier.vatId', $issue['path']);
        $this->assertStringContainsString('pravidlu státu CZ (8–10 číslic)', $issue['message']);

        // U české strany se DIČ jen z číslic doplní prefixem CZ a je platné; bez země zůstává bez prefixu.
        $this->assertSame([], $this->codes($this->withSupplier(['vatId' => '52654150', 'taxId' => '52654150'])));
        $issue = $this->issue(['supplier' => ['vatId' => '52654150']], CanonicalValueValidator::VAT_ID_INVALID);
        $this->assertStringContainsString('prefix státu', $issue['message']);

        $issue = $this->issue($this->withSupplier(['country' => 'de', 'companyId' => 'HRB 1', 'vatId' => 'DE136695975']), CanonicalValueValidator::VAT_ID_INVALID);
        $this->assertStringContainsString('DE (9 číslic s kontrolní číslicí)', $issue['message']);

        $issue = $this->issue($this->withSupplier(['vatId' => 'CZ07123451', 'taxId' => 'CZ07123451']), CanonicalValueValidator::VAT_ID_COMPANY_ID_MISMATCH);
        $this->assertSame('supplier.vatId', $issue['path']);
        $this->assertStringContainsString('„CZ07123451“ neodpovídá IČO „52654150“', $issue['message']);

        // DIČ fyzické osoby (10 číslic) vazbu na IČO nemá; cizí DIČ u české strany také ne.
        $this->assertSame([], $this->codes($this->withSupplier(['vatId' => 'CZ5265415012', 'taxId' => 'CZ5265415012'])));
        $this->assertSame([], $this->codes($this->withSupplier(['vatId' => 'SK2020000001', 'taxId' => null])));
        // Neplatné IČO → jen company_id_invalid, bez mismatch.
        $this->assertSame([CanonicalValueValidator::COMPANY_ID_INVALID], $this->codes($this->withSupplier(['companyId' => '12345678'])));
    }

    public function testTaxIdPhoneEmailWebProduceNoFindings(): void
    {
        $canonical = $this->withSupplier([
            'taxId' => 'nesmysl',
            'contact' => ['email' => 'not an email', 'phone' => 'volejte', 'web' => '???'],
        ]);
        $this->assertSame([], CanonicalValueValidator::validate($canonical));
    }

    public function testZipInvalidOnlyForCountriesWithRule(): void
    {
        $issue = $this->issue($this->withSupplier(['address' => ['zip' => '7790']]), CanonicalValueValidator::ZIP_INVALID);
        $this->assertSame('supplier.address.zip', $issue['path']);
        $this->assertStringContainsString('„7790“ nemá pět číslic (cz)', $issue['message']);

        // Země adresy má přednost před zemí strany.
        $this->assertSame([], $this->codes($this->withSupplier(['address' => ['zip' => '94105-1234', 'country' => 'us']])));
        $this->assertContains(CanonicalValueValidator::ZIP_INVALID, $this->codes($this->withSupplier(['country' => 'us', 'address' => ['zip' => '7790', 'country' => 'sk']])));
    }

    public function testAccountNumberInvalid(): void
    {
        $issue = $this->issue($this->withSupplier(['bankAccount' => ['accountNumber' => '2308417558/2010', 'iban' => null]]), CanonicalValueValidator::ACCOUNT_NUMBER_CHECKSUM);
        $this->assertSame('supplier.bankAccount.accountNumber', $issue['path']);
        $this->assertSame('warning', $issue['severity']);
        $this->assertStringContainsString('neprojde kontrolou mod 11 — ověřte ho', $issue['message'], 'mod 11 je podezření (D30)');

        $issue = $this->issue($this->withSupplier(['bankAccount' => ['accountNumber' => '2308417557', 'iban' => null]]), CanonicalValueValidator::ACCOUNT_NUMBER_INVALID);
        $this->assertStringContainsString('nemá kód banky', $issue['message'], 'důvod podle příčiny (D27)');
        $issue = $this->issue($this->withSupplier(['bankAccount' => ['accountNumber' => '0/0100', 'iban' => null]]), CanonicalValueValidator::ACCOUNT_NUMBER_INVALID);
        $this->assertStringContainsString('ze samých nul', $issue['message']);

        // Tvar …/kód se hlídá i bez země; jiný tvar bez země ne.
        $this->assertContains(CanonicalValueValidator::ACCOUNT_NUMBER_CHECKSUM, $this->codes(['supplier' => ['bankAccount' => ['accountNumber' => '2308417558/2010']]]));
        $this->assertSame([], $this->codes(['supplier' => ['bankAccount' => ['accountNumber' => '532013000']]]));
        $this->assertSame([], $this->codes($this->withSupplier(['country' => 'de', 'companyId' => 'HRB 1', 'vatId' => 'DE136695976', 'taxId' => null, 'address' => ['zip' => '10115'], 'bankAccount' => ['accountNumber' => '532013000', 'iban' => 'DE72100700000532013000', 'bic' => null]])));
    }

    public function testIbanInvalidReasons(): void
    {
        $bank = static fn(string $iban): array => ['bankAccount' => ['accountNumber' => null, 'iban' => $iban, 'bic' => null]];

        $issue = $this->issue($this->withSupplier($bank('CZ85')), CanonicalValueValidator::IBAN_INVALID);
        $this->assertSame('supplier.bankAccount.iban', $issue['path']);
        $this->assertStringContainsString('nemá tvar IBAN', $issue['message']);

        $issue = $this->issue($this->withSupplier($bank('CZ8520100000002308417558')), CanonicalValueValidator::IBAN_INVALID);
        $this->assertStringContainsString('kontrolní číslici', $issue['message']);

        $issue = $this->issue($this->withSupplier($bank('CZ4701000000001234567890')), CanonicalValueValidator::ACCOUNT_NUMBER_CHECKSUM);
        $this->assertSame('supplier.bankAccount.iban', $issue['path'], 'jen IBAN: podezření u IBAN (D30)');
        $this->assertSame('warning', $issue['severity']);
        $this->assertStringContainsString('číslo účtu uvnitř „1234567890/0100“ neprojde kontrolou mod 11 — ověřte ho', $issue['message'], 'důvod podle příčiny (D27)');
        $this->assertSame([CanonicalValueValidator::ACCOUNT_NUMBER_CHECKSUM], $this->codes($this->withSupplier($bank('CZ4701000000001234567890'))), 'IBAN sám je platný');

        $this->assertSame([], $this->codes($this->withSupplier($bank('GB82WEST12345698765432'))), 'země bez třídy: jen tvar a mod 97');
    }

    public function testBicInvalid(): void
    {
        $issue = $this->issue($this->withSupplier(['bankAccount' => ['bic' => 'GIBACZ']]), CanonicalValueValidator::BIC_INVALID);
        $this->assertSame('supplier.bankAccount.bic', $issue['path']);
        $this->assertStringContainsString('„GIBACZ“', $issue['message']);
    }

    public function testIbanAccountMismatchOnlyWhenBothValid(): void
    {
        $issue = $this->issue($this->withSupplier(['bankAccount' => ['accountNumber' => '19-2000145399/0800']]), CanonicalValueValidator::IBAN_ACCOUNT_MISMATCH);
        $this->assertSame('supplier.bankAccount.iban', $issue['path']);
        $this->assertStringContainsString('podle IBAN je účet „2308417557/2010“', $issue['message']);

        $codes = $this->codes($this->withSupplier(['bankAccount' => ['accountNumber' => '19-2000145398/0800']]));
        $this->assertSame(
            [CanonicalValueValidator::ACCOUNT_NUMBER_CHECKSUM, CanonicalValueValidator::IBAN_ACCOUNT_MISMATCH],
            $codes,
            'podezřelé číslo (mod 11, D30): podezření u čísla a nesoulad s IBAN jiného účtu',
        );
        $codes = $this->codes($this->withSupplier(['bankAccount' => ['accountNumber' => '1234567890/0100', 'iban' => 'CZ4701000000001234567890']]));
        $this->assertSame([CanonicalValueValidator::ACCOUNT_NUMBER_CHECKSUM], $codes, 'číslo i IBAN téhož podezřelého účtu: jeden nález');
        $this->assertSame('supplier.bankAccount.accountNumber', $this->issue($this->withSupplier(['bankAccount' => ['accountNumber' => '1234567890/0100', 'iban' => 'CZ4701000000001234567890']]), CanonicalValueValidator::ACCOUNT_NUMBER_CHECKSUM)['path'], 'u čísla účtu');
    }

    public function testRepairsBecomeFindingsFromAuditBlock(): void
    {
        // Normalizátor opravil IBAN: canonical je validní, nález žije z auditního bloku.
        $canonical = $this->withSupplier(['bankAccount' => ['accountNumber' => '8360405366/0600', 'iban' => 'CZ0606000000000836040536', 'bic' => null]]);
        $normalized = CanonicalValueNormalizer::normalize($canonical);

        $issues = CanonicalValueValidator::validate($normalized);
        $this->assertSame([CanonicalValueValidator::BANK_ACCOUNT_REPAIRED], array_column($issues, 'code'));
        $this->assertSame('supplier.bankAccount.iban', $issues[0]['path']);
        $this->assertSame('warning', $issues[0]['severity']);
        $this->assertStringContainsString('„CZ0606000000000836040536“ nebyl platný — doplněn podle čísla účtu: „CZ0606000000008360405366“', $issues[0]['message']);

        $accountRepair = ['_resolve' => ['valueRepairs' => [['path' => 'customer.bankAccount.accountNumber', 'from' => '1/0100', 'to' => '2308417557/2010', 'basis' => 'iban']]]];
        $issue = $this->issue($accountRepair, CanonicalValueValidator::BANK_ACCOUNT_REPAIRED);
        $this->assertStringContainsString('Číslo účtu odběratele „1/0100“ nebylo platné — doplněno podle IBAN', $issue['message']);
    }

    public function testEveryCodeIsListedAndWarning(): void
    {
        $canonical = $this->withSupplier([
            'companyId' => '12345678', 'vatId' => 'CZ1', 'address' => ['zip' => '1'],
            'bankAccount' => ['accountNumber' => '0/0100', 'iban' => 'CZ1', 'bic' => 'X'],
        ]);
        $canonical['customer'] = ['name' => 'Y', 'country' => 'cz', 'companyId' => '52654150', 'vatId' => 'CZ07123451', 'bankAccount' => ['accountNumber' => '19-2000145399/0800', 'iban' => 'CZ8520100000002308417557']];
        $canonical['balanceParty'] = ['name' => 'Z', 'country' => 'cz', 'bankAccount' => ['accountNumber' => '2308417558/2010']];
        $canonical['_resolve'] = ['valueRepairs' => [['path' => 'balanceParty.bankAccount.iban', 'from' => 'a', 'to' => 'b', 'basis' => 'accountNumber']]];

        $issues = CanonicalValueValidator::validate($canonical);
        $codes = array_column($issues, 'code');
        sort($codes);
        $expected = CanonicalValueValidator::CODES;
        sort($expected);
        $this->assertSame($expected, $codes);
        $this->assertSame(['warning'], array_values(array_unique(array_column($issues, 'severity'))));
        $this->assertStringStartsWith('customer.', $this->issue($canonical, CanonicalValueValidator::IBAN_ACCOUNT_MISMATCH)['path']);
    }
}
