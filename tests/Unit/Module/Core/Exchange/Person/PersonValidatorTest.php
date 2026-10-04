<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Person;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Person\PersonValidator;

class PersonValidatorTest extends TestCase
{
    private PersonValidator $v;

    protected function setUp(): void
    {
        $this->v = new PersonValidator();
    }

    // ── Company ────────────────────────────────────────────────────────────

    public function testCompanyHappyPath(): void
    {
        $issues = $this->v->validate([
            'personType' => 'company',
            'companyId'  => '12345678',
            'name'       => ['fullName' => 'Acme s.r.o.'],
        ]);
        $this->assertSame([], $issues);
    }

    public function testCompanyWithoutFullNameProducesError(): void
    {
        $issues = $this->v->validate([
            'personType' => 'company',
            'companyId'  => '12345678',
            'name'       => [],
        ]);
        $issue = $this->findByPath($issues, 'name.fullName');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
        $this->assertSame('required', $issue['code']);
    }

    public function testCompanyWithoutCompanyIdEmitsWarning(): void
    {
        $issues = $this->v->validate([
            'personType' => 'company',
            'name'       => ['fullName' => 'Foreign Vendor Inc.'],
        ]);
        $issue = $this->findByPath($issues, 'companyId');
        $this->assertNotNull($issue, 'expected companyId warning');
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame('company_id_missing', $issue['code']);
    }

    public function testCompanyWithPersonalEmitsWrongForTypeWarning(): void
    {
        $issues = $this->v->validate([
            'personType' => 'company',
            'companyId'  => '12345678',
            'name'       => ['fullName' => 'Acme s.r.o.'],
            'personal'   => ['birthDate' => '1980-01-01'],
        ]);
        $issue = $this->findByPath($issues, 'personal');
        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame('wrong_for_type', $issue['code']);
    }

    // ── Person (FO) ───────────────────────────────────────────────────────

    public function testPersonHappyPath(): void
    {
        $issues = $this->v->validate([
            'personType' => 'person',
            'name'       => ['firstName' => 'Jan', 'lastName' => 'Novák'],
        ]);
        $this->assertSame([], $issues);
    }

    public function testPersonWithoutFirstNameAndLastNameEmitsTwoErrors(): void
    {
        $issues = $this->v->validate([
            'personType' => 'person',
            'name'       => [],
        ]);
        $this->assertNotNull($this->findByPath($issues, 'name.firstName'));
        $this->assertNotNull($this->findByPath($issues, 'name.lastName'));
        $this->assertSame('error', $this->findByPath($issues, 'name.firstName')['severity']);
        $this->assertSame('error', $this->findByPath($issues, 'name.lastName')['severity']);
    }

    public function testPersonCanCarryPersonalDataWithoutWarning(): void
    {
        $issues = $this->v->validate([
            'personType' => 'person',
            'name'       => ['firstName' => 'Jan', 'lastName' => 'Novák'],
            'personal'   => ['birthDate' => '1980-01-01', 'nationalId' => '8001011234'],
        ]);
        $this->assertNull($this->findByPath($issues, 'personal'));
    }

    // ── Addresses ─────────────────────────────────────────────────────────

    public function testAddressType3RequiresPlaceRegTypeAndId(): void
    {
        $issues = $this->v->validate([
            'personType' => 'company',
            'companyId'  => '12345678',
            'name'       => ['fullName' => 'Acme s.r.o.'],
            'addresses'  => [['addressType' => 3]],
        ]);
        $regType = $this->findByPath($issues, 'addresses.0.placeRegType');
        $regId = $this->findByPath($issues, 'addresses.0.placeRegId');
        $this->assertNotNull($regType, 'expected placeRegType required');
        $this->assertNotNull($regId, 'expected placeRegId required');
        $this->assertSame('place_reg_required', $regType['code']);
        $this->assertSame('place_reg_required', $regId['code']);
    }

    public function testAddressType4ExpectsICZ(): void
    {
        $issues = $this->v->validate([
            'personType' => 'company',
            'companyId'  => '12345678',
            'name'       => ['fullName' => 'Acme s.r.o.'],
            'addresses'  => [[
                'addressType' => 4,
                'placeRegType' => 'ICP',  // wrong — should be ICZ
                'placeRegId'   => 'X123',
            ]],
        ]);
        $issue = $this->findByPath($issues, 'addresses.0.placeRegType');
        $this->assertNotNull($issue);
        $this->assertSame('place_reg_mismatch', $issue['code']);
    }

    public function testAddressType1WithPlaceRegEmitsWarning(): void
    {
        $issues = $this->v->validate([
            'personType' => 'company',
            'companyId'  => '12345678',
            'name'       => ['fullName' => 'Acme s.r.o.'],
            'addresses'  => [[
                'addressType' => 1,
                'placeRegType' => 'ICP',
                'placeRegId'   => 'X',
            ]],
        ]);
        $issue = $this->findByPath($issues, 'addresses.0.placeRegType');
        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame('place_reg_unexpected', $issue['code']);
    }

    public function testAddressType3HappyPath(): void
    {
        $issues = $this->v->validate([
            'personType' => 'company',
            'companyId'  => '12345678',
            'name'       => ['fullName' => 'Acme s.r.o.'],
            'addresses'  => [[
                'addressType'  => 3,
                'placeRegType' => 'ICP',
                'placeRegId'   => '1234567890',
            ]],
        ]);
        $this->assertSame([], $issues);
    }

    // ── BankAccounts ──────────────────────────────────────────────────────

    public function testBankAccountWithoutIbanOrAccountNumberEmitsError(): void
    {
        $issues = $this->v->validate([
            'personType'   => 'company',
            'companyId'    => '12345678',
            'name'         => ['fullName' => 'Acme s.r.o.'],
            'bankAccounts' => [['currency' => 'CZK']],
        ]);
        $issue = $this->findByPath($issues, 'bankAccounts.0');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
        $this->assertSame('bank_account_id_missing', $issue['code']);
    }

    public function testBankAccountWithIbanOnlyIsValid(): void
    {
        $issues = $this->v->validate([
            'personType'   => 'company',
            'companyId'    => '12345678',
            'name'         => ['fullName' => 'Acme s.r.o.'],
            'bankAccounts' => [['iban' => 'CZ6508000000001234567890']],
        ]);
        $this->assertSame([], $issues);
    }

    public function testBankAccountWithAccountNumberOnlyIsValid(): void
    {
        $issues = $this->v->validate([
            'personType'   => 'company',
            'companyId'    => '12345678',
            'name'         => ['fullName' => 'Acme s.r.o.'],
            'bankAccounts' => [['accountNumber' => '1234567890/0100']],
        ]);
        $this->assertSame([], $issues);
    }

    // ── Own company + targetDocState=40 ───────────────────────────────────

    public function testIsOwnTargetState40RequiresCompanyId(): void
    {
        $issues = $this->v->validate([
            'personType'   => 'company',
            'name'         => ['fullName' => 'Acme s.r.o.'],
            'status'       => ['isOwn' => true],
            'applyOptions' => ['targetDocState' => 40],
        ]);
        $own = $this->findByCode($issues, 'own_company_id_required');
        $this->assertNotNull($own, 'expected own_company_id_required error');
        $this->assertSame('error', $own['severity']);
    }

    public function testIsOwnTargetState10DoesNotRequireCompanyId(): void
    {
        $issues = $this->v->validate([
            'personType'   => 'company',
            'name'         => ['fullName' => 'Acme s.r.o.'],
            'status'       => ['isOwn' => true],
            'applyOptions' => ['targetDocState' => 10],
        ]);
        // companyId-missing is still a warning (generic), but no hard error
        // about own_company_id_required.
        $this->assertNull($this->findByCode($issues, 'own_company_id_required'));
    }

    public function testNonOwnTargetState40DoesNotRequireCompanyId(): void
    {
        $issues = $this->v->validate([
            'personType'   => 'company',
            'name'         => ['fullName' => 'Acme s.r.o.'],
            'status'       => ['isOwn' => false],
            'applyOptions' => ['targetDocState' => 40],
        ]);
        $this->assertNull($this->findByCode($issues, 'own_company_id_required'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return ?array{severity: string, path: string, code: string, message: string}
     */
    private function findByPath(array $issues, string $path): ?array
    {
        foreach ($issues as $i) {
            if ($i['path'] === $path) return $i;
        }
        return null;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return ?array{severity: string, path: string, code: string, message: string}
     */
    private function findByCode(array $issues, string $code): ?array
    {
        foreach ($issues as $i) {
            if ($i['code'] === $code) return $i;
        }
        return null;
    }

    // ── documents.language (#94) ───────────────────────────────────────────

    /** @param array<string, mixed> $documents */
    private function companyWithDocuments(?array $documents): array
    {
        return [
            'personType' => 'company',
            'companyId'  => '12345678',
            'name'       => ['fullName' => 'Acme s.r.o.'],
            'documents'  => $documents,
        ];
    }

    public function testDocumentLanguageAmongDocumentLanguagesPasses(): void
    {
        $v = new PersonValidator(['cs', 'en', 'sk', 'de']);

        $this->assertSame([], $v->validate($this->companyWithDocuments(['language' => 'de'])));
        $this->assertSame([], $v->validate($this->companyWithDocuments(['language' => null])));
        $this->assertSame([], $v->validate($this->companyWithDocuments(null)));
    }

    public function testDocumentLanguageOutsideDocumentLanguagesIsError(): void
    {
        $issues = (new PersonValidator(['cs', 'en', 'sk', 'de']))
            ->validate($this->companyWithDocuments(['language' => 'fr']));

        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]['severity']);
        $this->assertSame('documents.language', $issues[0]['path']);
        $this->assertSame('invalid_document_language', $issues[0]['code']);
    }

    public function testDocumentLanguageIsNotCheckedWithoutLanguageList(): void
    {
        // Preflight datové sady konfiguraci nemá — jazyk ověří až applier.
        $this->assertSame([], $this->v->validate($this->companyWithDocuments(['language' => 'fr'])));
    }

    // ── contacts[].sendPurposes (#90 D34) ───────────────────────────────

    /** @return array<string, mixed> */
    private function companyWithContactPurposes(mixed $purposes): array
    {
        return $this->companyWithDocuments(null) + [
            'contacts' => [['name' => 'Účtárna', 'email' => 'ucetni@example.test', 'sendPurposes' => $purposes]],
        ];
    }

    public function testKnownSendPurposesPass(): void
    {
        $v = new PersonValidator(null, ['invoices', 'reminders']);

        $this->assertSame([], $v->validate($this->companyWithContactPurposes(['invoices', 'reminders'])));
        $this->assertSame([], $v->validate($this->companyWithContactPurposes([])));
        $this->assertSame([], $v->validate($this->companyWithContactPurposes(null)));
    }

    public function testUnknownSendPurposeIsError(): void
    {
        $issues = (new PersonValidator(null, ['invoices', 'reminders']))
            ->validate($this->companyWithContactPurposes(['invoices', 'newsletter']));

        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]['severity']);
        $this->assertSame('contacts.0.sendPurposes', $issues[0]['path']);
        $this->assertSame('invalid_send_purpose', $issues[0]['code']);
    }

    public function testSendPurposesAreNotCheckedWithoutConfiguration(): void
    {
        $this->assertSame([], (new PersonValidator())->validate($this->companyWithContactPurposes(['newsletter'])));
    }
}
