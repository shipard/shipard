<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Defaults;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\Defaults\DocHeadDefaults;

/**
 * Výchozí hodnoty hlavičky dokladu na jednom místě (#166 D40, D41, D48, D50):
 * doplňuje se jen chybějící klíč, přítomný klíč vyhrává i s `null`; rozdíly
 * mezi typy dokladu řídí atributy typu.
 */
class DocHeadDefaultsTest extends TestCase
{
    use DefaultsFixtures;

    private function defaults(mixed ...$db): DocHeadDefaults
    {
        return new DocHeadDefaults($this->db(...$db), $this->realConfig());
    }

    public function testIssuedInvoiceGetsAllDefaults(): void
    {
        $head = ['doc_type' => 'invno'];
        $filled = $this->defaults()->apply($head);

        $this->assertSame(
            ['doc_currency', 'payment_method', 'vat_mode', 'issue_date', 'vat_registration', 'bank_account', 'total_rounding_auto'],
            $filled,
        );
        $this->assertSame('czk', $head['doc_currency']);
        $this->assertSame(1, $head['payment_method'], 'Převodem');
        $this->assertSame(1, $head['vat_mode'], 'ze základu');
        $this->assertSame(date('Y-m-d'), $head['issue_date']);
        $this->assertSame(7, $head['vat_registration']);
        $this->assertSame(3, $head['bank_account']);
        $this->assertSame(1, $head['total_rounding_auto'], 'prodejní typ má automatiku');
    }

    public function testReceivedInvoiceHasNoRoundingAutomatic(): void
    {
        $head = ['doc_type' => 'invni'];
        $this->defaults()->apply($head);

        $this->assertSame(0, $head['total_rounding_auto']);
        $this->assertSame(3, $head['bank_account'], 'přijatá faktura vlastní účet nese');
    }

    /** D50: přítomný klíč vyhrává — hodnota i výslovné `null` („záměrně prázdné“). */
    public function testPresentKeysWinIncludingNull(): void
    {
        $head = [
            'doc_type'         => 'invno',
            'doc_currency'     => 'eur',
            'payment_method'   => 2,
            'vat_mode'         => 2,
            'issue_date'       => '2026-03-01',
            'vat_registration' => null,
            'bank_account'     => null,
            'total_rounding_auto' => 0,
        ];
        $before = $head;
        $filled = $this->defaults()->apply($head);

        $this->assertSame([], $filled);
        $this->assertSame($before, $head);
        $this->assertSame([], $this->queriesOn('economy_codebooks_vat_registrations'));
        $this->assertSame([], $this->queriesOn('economy_codebooks_bank_accounts'));
    }

    /** Neplátce (`economy.vatAgenda === false`): Bez DPH a žádná registrace. */
    public function testNonPayerGetsNoVat(): void
    {
        $head = ['doc_type' => 'invno'];
        $filled = $this->defaults(settings: ['economy.vatAgenda' => false])->apply($head);

        $this->assertSame(0, $head['vat_mode']);
        $this->assertArrayNotHasKey('vat_registration', $head);
        $this->assertNotContains('vat_registration', $filled);
        $this->assertSame([], $this->queriesOn('economy_codebooks_vat_registrations'));
    }

    /** Nerozhodnutý klíč agendy se chová jako plátce. */
    public function testUndecidedVatAgendaBehavesAsPayer(): void
    {
        $this->assertSame(1, $this->defaults()->vatMode('invno'));
        $this->assertSame(1, $this->defaults(settings: ['economy.vatAgenda' => true])->vatMode('invno'));
    }

    /** Účetní doklad: atributy `vat: false` a `own_bank_account: false`. */
    public function testAccountingDocumentHasNoVatNorBankAccount(): void
    {
        $head = ['doc_type' => 'cmnbkp'];
        $filled = $this->defaults()->apply($head);

        $this->assertSame(0, $head['vat_mode']);
        $this->assertArrayNotHasKey('vat_registration', $head);
        $this->assertArrayNotHasKey('bank_account', $head);
        $this->assertSame(['doc_currency', 'payment_method', 'vat_mode', 'issue_date', 'total_rounding_auto'], $filled);
        $this->assertSame([], $this->queriesOn('economy_codebooks_bank_accounts'));
    }

    /** Typ vázaný na pokladnu: Hotově, měna pokladny z řady, bez vlastního účtu. */
    public function testCashDocumentTakesCashPaymentAndDeskCurrency(): void
    {
        $head = ['doc_type' => 'cash', 'number_series' => 12, 'cash_dir' => 1];
        $this->defaults(cashDesk: ['id' => 4, 'code' => 'EUR1', 'name' => 'Pokladna EUR', 'currency' => 'EUR'])
            ->apply($head);

        $this->assertSame(0, $head['payment_method'], 'Hotově');
        $this->assertSame('eur', $head['doc_currency']);
        $this->assertArrayNotHasKey('bank_account', $head);
        $this->assertSame(7, $head['vat_registration'], 'pokladní doklad DPH má');
        $this->assertSame(1, $head['total_rounding_auto']);
    }

    /** Bez dohledané pokladny domácí měna zdroje dat. */
    public function testCashDocumentWithoutDeskFallsBackToHomeCurrency(): void
    {
        $head = ['doc_type' => 'cashreg'];
        $this->defaults(settings: ['economy.homeCurrency' => 'eur'])->apply($head);

        $this->assertSame('eur', $head['doc_currency']);
    }

    public function testNonBoundTypeIgnoresCashDesk(): void
    {
        $defaults = $this->defaults(cashDesk: ['id' => 4, 'code' => 'EUR1', 'name' => 'x', 'currency' => 'eur']);

        $this->assertNull($defaults->cashDeskCurrency(['doc_type' => 'invno', 'cash_desk' => 4]));
        $this->assertSame('czk', $defaults->currency(['doc_type' => 'invno', 'cash_desk' => 4]));
    }

    /** Registrace musí platit k datu dokladu: DUZP, bez něj datum vystavení. */
    public function testRegistrationIsValidAtTaxPointDate(): void
    {
        $head = ['doc_type' => 'invni', 'issue_date' => '2026-03-05', 'vat_duzp' => '2026-02-27'];
        $this->defaults()->apply($head);

        $queries = array_values(array_filter(
            $this->queries,
            static fn (array $q): bool => str_contains($q['sql'], 'economy_codebooks_vat_registrations'),
        ));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('`valid_from` IS NULL OR `valid_from` <= %s', $queries[0]['sql']);
        $this->assertStringContainsString('ORDER BY `country`, `id` LIMIT 1', $queries[0]['sql']);
        $this->assertSame(['2026-02-27', '2026-02-27'], array_slice($queries[0]['params'], -2));
    }

    public function testRegistrationDateFallsBackToIssueDateThenToday(): void
    {
        $defaults = $this->defaults();

        $this->assertSame('2026-03-05', $defaults->registrationDate(['issue_date' => '2026-03-05']));
        $this->assertSame(date('Y-m-d'), $defaults->registrationDate([]));
    }

    /** Import (D48): datum vystavení se nedoplňuje, registrace bez ohledu na platnost. */
    public function testImportSwitchesSkipIssueDateAndRegistrationValidity(): void
    {
        $head = ['doc_type' => 'invno'];
        $filled = $this->defaults()->apply($head, issueDate: false, registrationValidity: false);

        $this->assertArrayNotHasKey('issue_date', $head);
        $this->assertNotContains('issue_date', $filled);
        $this->assertSame(7, $head['vat_registration']);
        $this->assertStringNotContainsString('valid_from', $this->queriesOn('economy_codebooks_vat_registrations')[0]);
    }

    public function testRegistrationWithoutCountryIsNotUsed(): void
    {
        $head = ['doc_type' => 'invno'];
        $this->defaults(registration: ['id' => 7, 'country' => ''])->apply($head);

        $this->assertArrayNotHasKey('vat_registration', $head);
    }

    public function testMissingRegistrationAndBankAccountLeaveColumnsOut(): void
    {
        $head = ['doc_type' => 'invno'];
        $filled = $this->defaults(registration: null, bankAccount: null)->apply($head);

        $this->assertArrayNotHasKey('vat_registration', $head);
        $this->assertArrayNotHasKey('bank_account', $head);
        $this->assertNotContains('vat_registration', $filled);
        $this->assertNotContains('bank_account', $filled);
    }

    /** Bez databáze (unit testy formuláře) jen pravidla, která ji nepotřebují. */
    public function testWithoutDatabaseOnlyStaticRulesApply(): void
    {
        $head = ['doc_type' => 'invno'];
        $filled = (new DocHeadDefaults(null, $this->realConfig()))->apply($head);

        $this->assertSame(['doc_currency', 'payment_method', 'vat_mode', 'issue_date', 'total_rounding_auto'], $filled);
        $this->assertSame('czk', $head['doc_currency']);
    }
}
