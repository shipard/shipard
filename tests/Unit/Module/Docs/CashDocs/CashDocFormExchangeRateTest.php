<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\CashDocs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\CashDocs\CashDocForm;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\FakeExchangeRateResolver;

/**
 * Pokladna v cizí měně (#121 D6, §6.1): nový doklad dostane měnu pokladny
 * před parent:: a kurz k účetnímu datu hned; změna řady (= pokladny)
 * přepne měnu i kurz.
 */
class CashDocFormExchangeRateTest extends TestCase
{
    private function form(string $deskCurrency): CashDocForm
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(string $sql, mixed ...$params): ?array => str_contains($sql, 'economy_codebooks_cash_desks')
                ? ['id' => 7, 'code' => 'EP', 'name' => 'Eurová pokladna', 'currency' => $deskCurrency]
                : null,
        );
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchSingle')->willReturn(null);

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => match ($id) {
            'docs.core.docTypes' => ['cash' => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk']],
            default => null,
        });

        $form = new class(new FakeExchangeRateResolver(['eur|2026-10-16' => 24.325])) extends CashDocForm {
            public function __construct(private readonly ExchangeRateResolver $resolver)
            {
                parent::__construct('docs_core_heads');
            }

            protected function exchangeRateResolver(): ?ExchangeRateResolver
            {
                return $this->resolver;
            }
        };
        $form->setDb($db);
        $form->setConfig($config);
        return $form;
    }

    public function testNewDocumentInEurCashDeskGetsRate(): void
    {
        $data = ['doc_type' => 'cash', 'number_series' => 3, 'vat_mode' => 0, 'payment_method' => 1, 'issue_date' => '2026-10-16'];
        $this->form('eur')->applyNewRecordDefaults($data);

        $this->assertSame('eur', $data['doc_currency']);
        $this->assertSame(24.325, $data['exchange_rate']);
    }

    public function testNewDocumentInHomeCurrencyCashDeskHasRateOne(): void
    {
        $data = ['doc_type' => 'cash', 'number_series' => 3, 'vat_mode' => 0, 'issue_date' => '2026-10-16'];
        $this->form('czk')->applyNewRecordDefaults($data);

        $this->assertSame('czk', $data['doc_currency']);
        $this->assertSame(1, $data['exchange_rate']);
    }

    public function testSeriesChangeSwitchesCurrencyAndRate(): void
    {
        $result = $this->form('eur')->recalculate('number_series', ['doc_type' => 'cash', 'number_series' => 3, 'doc_currency' => 'czk', 'exchange_rate' => 1, 'accounting_date' => '2026-10-16']);

        $this->assertSame('eur', $result->data['doc_currency']);
        $this->assertSame(24.325, $result->data['exchange_rate']);
    }
}
