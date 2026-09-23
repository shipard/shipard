<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentDefaultsTest extends TestCase
{
    public function testApplyDateDefaultsFillsMissingFields(): void
    {
        $doc = new TestableDocsHeadsDocument();
        // No DB needed — partner null → fallback 14 days
        $data = [
            'issue_date' => '2026-05-06',
        ];
        $doc->applyDateDefaultsPub($data);

        $this->assertSame('2026-05-06', $data['accounting_date']);
        $this->assertSame('2026-05-06', $data['vat_duzp']);
        $this->assertSame('2026-05-06', $data['vat_dppd']);
        $this->assertSame('2026-05-20', $data['due_date']);
    }

    public function testApplyDateDefaultsUsesPartnerPaymentTerm(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['payment_term_days' => 30]));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = [
            'issue_date' => '2026-05-06',
            'partner'    => 42,
        ];
        $doc->applyDateDefaultsPub($data);

        $this->assertSame('2026-06-05', $data['due_date']); // +30 days
    }

    public function testApplyDateDefaultsRespectsExistingValues(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = [
            'issue_date'      => '2026-05-06',
            'accounting_date' => '2026-04-01',
            'vat_duzp'        => '2026-04-15',
            'vat_dppd'        => '2026-04-20',
            'due_date'        => '2026-06-01',
        ];
        $doc->applyDateDefaultsPub($data);

        $this->assertSame('2026-04-01', $data['accounting_date']);
        $this->assertSame('2026-04-15', $data['vat_duzp']);
        $this->assertSame('2026-04-20', $data['vat_dppd']);
        $this->assertSame('2026-06-01', $data['due_date']);
    }

    public function testApplyDateDefaultsNullPartnerFallback(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = [
            'issue_date' => '2026-01-15',
            'partner'    => null,
        ];
        $doc->applyDateDefaultsPub($data);

        $this->assertSame('2026-01-29', $data['due_date']); // +14 days fallback
    }

    // ── Nedaňový typ (docTypes[].tax_document: false, #79 D1) ───────────────

    private function configWithProforma(): ConfigRuntime
    {
        $docTypes = [
            'invno' => ['trade_dir' => 1],
            'invpo' => ['trade_dir' => 1, 'tax_document' => false],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        return $config;
    }

    public function testNonTaxDocumentGetsNoDuzpNorDppdEvenFromPayload(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setConfig($this->configWithProforma());
        $data = [
            'doc_type'   => 'invpo',
            'issue_date' => '2026-05-06',
            'vat_duzp'   => '2026-05-06',
            'vat_dppd'   => '2026-05-10',
        ];
        $doc->applyDateDefaultsPub($data);

        $this->assertNull($data['vat_duzp'], 'DUZP z payloadu se u nedaňového dokladu nuluje');
        $this->assertNull($data['vat_dppd']);
        $this->assertSame('2026-05-06', $data['accounting_date']);
        $this->assertSame('2026-05-20', $data['due_date']);
    }

    public function testTaxDocumentKeepsDuzpDefaultsWithConfig(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setConfig($this->configWithProforma());
        $data = ['doc_type' => 'invno', 'issue_date' => '2026-05-06'];
        $doc->applyDateDefaultsPub($data);

        $this->assertSame('2026-05-06', $data['vat_duzp']);
        $this->assertSame('2026-05-06', $data['vat_dppd']);
    }

    public function testApplyHomeCurrencyFromSettings(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setSettings($this->createSettingsWithHomeCurrency('eur'));

        $data = [];
        $doc->applyHomeCurrencyPub($data);

        $this->assertSame('eur', $data['home_currency']);
        $this->assertSame('eur', $data['doc_currency']);
    }

    public function testApplyHomeCurrencyFallbackCzkWithoutSettings(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = [];
        $doc->applyHomeCurrencyPub($data);

        $this->assertSame('czk', $data['home_currency']);
        $this->assertSame('czk', $data['doc_currency']);
    }

    public function testApplyHomeCurrencyFallbackCzkWhenUndecided(): void
    {
        // Nerozhodnutý klíč (null) = dnešní chování.
        $doc = new TestableDocsHeadsDocument();
        $doc->setSettings($this->createSettingsWithHomeCurrency(null));

        $data = [];
        $doc->applyHomeCurrencyPub($data);

        $this->assertSame('czk', $data['home_currency']);
        $this->assertSame('czk', $data['doc_currency']);
    }

    public function testApplyHomeCurrencyDoesNotOverrideExisting(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setSettings($this->createSettingsWithHomeCurrency('czk'));

        $data = ['home_currency' => 'usd', 'doc_currency' => 'eur'];
        $doc->applyHomeCurrencyPub($data);

        $this->assertSame('usd', $data['home_currency']);
        $this->assertSame('eur', $data['doc_currency']);
    }

    private function createSettingsWithHomeCurrency(?string $currency): SettingsStore
    {
        $settings = $this->createMock(SettingsStore::class);
        $settings->method('get')->willReturnCallback(
            static fn(string $key): mixed => $key === 'economy.homeCurrency' ? $currency : null,
        );
        return $settings;
    }

    public function testResolveFiscalYearIdReturnsNullWithoutDb(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $this->assertNull($doc->resolveFiscalYearIdPub('2026-05-06'));
    }

    public function testResolveFiscalYearIdReturnsIdWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 13]));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $this->assertSame(13, $doc->resolveFiscalYearIdPub('2026-05-06'));
    }

    public function testResolveFiscalYearIdReturnsNullWhenAbsent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $this->assertNull($doc->resolveFiscalYearIdPub('2026-05-06'));
    }

    public function testResolveFiscalMonthIdReturnsId(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 105]));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $this->assertSame(105, $doc->resolveFiscalMonthIdPub('2026-05-15'));
    }

    public function testResolveAccountingPeriodsPopulatesFiscalYearAndMonth(): void
    {
        $db = $this->createMock(Connection::class);
        $callCount = 0;
        $db->method('fetch')->willReturnCallback(
            function () use (&$callCount): ?Row {
                $callCount++;
                return match ($callCount) {
                    1 => new Row(['id' => 100]),  // fiscal year
                    2 => new Row(['id' => 200]),  // fiscal month
                    default => null,
                };
            }
        );

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = [
            'accounting_date'  => '2026-05-06',
            'vat_duzp'         => '2026-05-06',
            'vat_registration' => 7,
        ];
        $doc->resolveAccountingPeriodsPub($data);

        $this->assertSame(100, $data['fiscal_year']);
        $this->assertSame(200, $data['fiscal_month']);
        // vat_period už docs.core neřeší — plní economy.vat handler (beforeSave event)
        $this->assertArrayNotHasKey('vat_period', $data);
    }

    public function testClosingDocumentGoesToClosingMonthOfItsYear(): void
    {
        // #69 D20: fiscal_period_type = closing → měsíc Uzavření roku
        // účetního data (dotaz rokem + typem), ne běžný prosinec.
        $db = $this->createMock(Connection::class);
        $queries = [];
        $db->method('fetch')->willReturnCallback(
            function (string $sql, mixed ...$params) use (&$queries): ?Row {
                $queries[] = ['sql' => $sql, 'params' => $params];
                return count($queries) === 1 ? new Row(['id' => 100]) : new Row(['id' => 214]);
            }
        );

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $data = ['accounting_date' => '2026-12-31', 'fiscal_period_type' => 'closing'];
        $doc->resolveAccountingPeriodsPub($data);

        $this->assertSame(100, $data['fiscal_year']);
        $this->assertSame(214, $data['fiscal_month']);
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('[fiscal_year] = %i AND [period_type] = %i', $queries[1]['sql']);
        $this->assertSame([100, 2], $queries[1]['params']);
        $this->assertStringNotContainsString('[date_begin]', $queries[1]['sql'], 'jednodenní měsíc se hledá rokem, ne datem');
    }

    public function testUnknownPeriodTypeFallsBackToRegularMonth(): void
    {
        $db = $this->createMock(Connection::class);
        $queries = [];
        $db->method('fetch')->willReturnCallback(
            function (string $sql, mixed ...$params) use (&$queries): ?Row {
                $queries[] = ['sql' => $sql, 'params' => $params];
                return new Row(['id' => count($queries) === 1 ? 100 : 200]);
            }
        );

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $data = ['accounting_date' => '2026-05-06', 'fiscal_period_type' => 'garbage'];
        $doc->resolveAccountingPeriodsPub($data);

        $this->assertSame(200, $data['fiscal_month']);
        $this->assertStringContainsString('[date_begin] <= %d', $queries[1]['sql'], 'běžný měsíc podle data');
    }
}
