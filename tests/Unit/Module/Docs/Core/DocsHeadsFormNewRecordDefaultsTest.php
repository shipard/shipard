<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceForm;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;
use Shipard\Module\Docs\ProformasOut\ProformaOutForm;

/**
 * Defaulty nového dokladu v applyNewRecordDefaults (issue #60, #24 A.1):
 * datum vystavení, registrace DPH (první z roletky, jen s DPH), výchozí
 * bankovní účet. Hook dostává data tak, jak je FormController předvyplní
 * ze schématu (vat_mode 1, payment_method 1, doc_currency czk).
 */
class DocsHeadsFormNewRecordDefaultsTest extends TestCase
{
    private const REGISTRATIONS = [
        ['id' => 7, 'country' => 'cz', 'vat_id' => 'CZ11111111'],
        ['id' => 9, 'country' => 'sk', 'vat_id' => 'SK2222222222'],
    ];

    /**
     * @param list<array<string, mixed>> $registrations
     * @param list<string>|null $queries zachytávané SQL dotazy
     */
    private function db(
        array $registrations = self::REGISTRATIONS,
        ?int $defaultBankAccount = 3,
        ?bool $vatAgenda = null,
        ?array &$queries = null,
    ): DataSourceConnection {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($vatAgenda): mixed {
                return ($args[1] ?? null) === 'economy.vatAgenda' && $vatAgenda !== null
                    ? json_encode($vatAgenda)
                    : null;
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($registrations, &$queries): array {
                if ($queries !== null) {
                    $queries[] = $sql;
                }
                return str_contains($sql, 'economy_codebooks_vat_registrations') ? $registrations : [];
            },
        );
        $db->method('fetchRow')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($defaultBankAccount, &$queries): ?array {
                if ($queries !== null) {
                    $queries[] = $sql;
                }
                if (str_contains($sql, 'economy_codebooks_bank_accounts')) {
                    return $defaultBankAccount !== null ? ['id' => $defaultBankAccount] : null;
                }
                return null;
            },
        );
        return $db;
    }

    private function form(?DataSourceConnection $db = null, ?DocsHeadsFormBase $form = null): DocsHeadsFormBase
    {
        $form ??= new DocsHeadsForm('docs_core_heads');
        $form->setDb($db ?? $this->db());
        return $form;
    }

    /** Data, jak je FormController předvyplní ze schématu před hookem. */
    private function schemaData(array $overrides = []): array
    {
        return array_merge(
            ['doc_type' => 'invno', 'vat_mode' => 1, 'payment_method' => 1, 'doc_currency' => 'czk'],
            $overrides,
        );
    }

    private function findElement(FormDefinition $def, string $column): ?FormElement
    {
        foreach ($def->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            return $el;
                        }
                    }
                }
            }
        }
        return null;
    }

    // ── Datum vystavení (#24 A.1) ────────────────────────────────────────────

    public function testIssueDateDefaultsToToday(): void
    {
        $data = $this->schemaData();
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame(date('Y-m-d'), $data['issue_date']);
    }

    public function testExplicitIssueDateWins(): void
    {
        $data = $this->schemaData(['issue_date' => '2026-01-15']);
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame('2026-01-15', $data['issue_date']);
    }

    /** Účetní datum a DUZP se odvozují z Data vystavení (#24 D6). */
    public function testAccountingDateAndDuzpFollowIssueDate(): void
    {
        $data = $this->schemaData(['issue_date' => '2026-01-15']);
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame('2026-01-15', $data['accounting_date']);
        $this->assertSame('2026-01-15', $data['vat_duzp']);
    }

    public function testExplicitAccountingDateAndDuzpWin(): void
    {
        $data = $this->schemaData([
            'issue_date'      => '2026-01-15',
            'accounting_date' => '2026-01-31',
            'vat_duzp'        => '2026-01-10',
        ]);
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame('2026-01-31', $data['accounting_date']);
        $this->assertSame('2026-01-10', $data['vat_duzp']);
    }

    // ── Registrace DPH ───────────────────────────────────────────────────────

    public function testVatRegistrationDefaultsToFirstOption(): void
    {
        $data = $this->schemaData();
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame(7, $data['vat_registration'], 'první podle country, id');
    }

    public function testVatRegistrationSkippedForDocumentWithoutVat(): void
    {
        $queries = [];
        $data = $this->schemaData(['vat_mode' => 0]);
        $this->form($this->db(queries: $queries))->applyNewRecordDefaults($data);

        $this->assertArrayNotHasKey('vat_registration', $data);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('vat_registrations', $sql, 'bez DPH se registrace ani nedotazují');
        }
    }

    public function testExplicitVatRegistrationWins(): void
    {
        $data = $this->schemaData(['vat_registration' => 9]);
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame(9, $data['vat_registration']);
    }

    public function testNoRegistrationsLeaveFieldEmpty(): void
    {
        $data = $this->schemaData();
        $this->form($this->db(registrations: []))->applyNewRecordDefaults($data);

        $this->assertArrayNotHasKey('vat_registration', $data);
    }

    public function testNonPayerGetsNoVatModeNorRegistration(): void
    {
        // Neplátce: vat_mode 1 ze schématu → 0, registrace se pak neuplatní.
        $data = $this->schemaData();
        $this->form($this->db(vatAgenda: false))->applyNewRecordDefaults($data);

        $this->assertSame(0, $data['vat_mode']);
        $this->assertArrayNotHasKey('vat_registration', $data);
    }

    // ── Náš bankovní účet ────────────────────────────────────────────────────

    public function testBankAccountDefaultsToIsDefaultAccount(): void
    {
        $data = $this->schemaData();
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame(3, $data['bank_account']);
    }

    public function testBankAccountIgnoresDocumentCurrency(): void
    {
        // FPB v EUR z českého účtu je běžná — měnový filtr se neuplatňuje.
        $data = $this->schemaData(['doc_currency' => 'eur']);
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame(3, $data['bank_account']);
    }

    public function testNoDefaultBankAccountLeavesFieldEmpty(): void
    {
        $data = $this->schemaData();
        $this->form($this->db(defaultBankAccount: null))->applyNewRecordDefaults($data);

        $this->assertArrayNotHasKey('bank_account', $data);
    }

    public function testExplicitBankAccountWins(): void
    {
        $data = $this->schemaData(['bank_account' => 11]);
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame(11, $data['bank_account']);
    }

    public function testWithoutDbOnlyIssueDateIsSet(): void
    {
        $form = new DocsHeadsForm('docs_core_heads');
        $data = $this->schemaData();
        $form->applyNewRecordDefaults($data);

        $this->assertSame(date('Y-m-d'), $data['issue_date']);
        $this->assertArrayNotHasKey('vat_registration', $data);
        $this->assertArrayNotHasKey('bank_account', $data);
    }

    // ── Per-typ formuláře faktur dědí hook beze změny ────────────────────────

    /** @return array<string, array{0: DocsHeadsFormBase}> */
    public static function invoiceForms(): array
    {
        return [
            'generic' => [new DocsHeadsForm('docs_core_heads')],
            'invno'   => [new IssuedInvoiceForm('docs_core_heads')],
            'invpo'   => [new ProformaOutForm('docs_core_heads')],
            'invni'   => [new ReceivedInvoiceForm('docs_core_heads')],
        ];
    }

    #[DataProvider('invoiceForms')]
    public function testInvoiceFormsGetRegistrationAndBankAccount(DocsHeadsFormBase $form): void
    {
        $data = $this->schemaData();
        $this->form(form: $form)->applyNewRecordDefaults($data);

        $this->assertSame(7, $data['vat_registration']);
        $this->assertSame(3, $data['bank_account']);
        $this->assertSame(date('Y-m-d'), $data['issue_date']);
    }

    #[DataProvider('invoiceForms')]
    public function testVatRegistrationRequiredFollowsVatMode(DocsHeadsFormBase $form): void
    {
        $withVat = $this->findElement($form->buildFormDefinition($this->schemaData(), true), 'vat_registration');
        $this->assertNotNull($withVat);
        $this->assertTrue($withVat->required, 's DPH: povinná, bez prázdné možnosti');

        $noVat = $this->findElement($form->buildFormDefinition($this->schemaData(['vat_mode' => 0]), true), 'vat_registration');
        $this->assertNotNull($noVat);
        $this->assertFalse($noVat->required, 'bez DPH: nepovinná');
    }
}
