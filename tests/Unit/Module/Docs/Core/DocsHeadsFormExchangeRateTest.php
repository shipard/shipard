<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\FakeExchangeRateResolver;

/**
 * Kurz ve formuláři dokladu (#121 D5, D6, U3; tasks/exchange-rates-core.md
 * §6.1): změna měny i účetního data doplní kurz z lístku (přepíše i ruční),
 * domácí měna → 1, nenalezeno → hodnota zůstane a pole dostane upozornění,
 * nový doklad v cizí měně dostane kurz hned.
 */
class DocsHeadsFormExchangeRateTest extends TestCase
{
    private const RATES = ['eur|2026-10-16' => 24.325, 'eur|2026-10-20' => 24.5];

    private function form(?array $rates = self::RATES, bool $withDb = false): ExchangeRateTestForm
    {
        $form = new ExchangeRateTestForm($rates === null ? null : new FakeExchangeRateResolver($rates));
        if ($withDb) {
            $db = $this->createMock(DataSourceConnection::class);
            $db->method('fetchSingle')->willReturn(null);
            $db->method('fetchAll')->willReturn([]);
            $db->method('fetchRow')->willReturn(null);
            $form->setDb($db);
        }
        return $form;
    }

    private function element(FormDefinition $def, string $column): ?FormElement
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

    public function testCurrencyChangeFillsRateFromListOverridingManualValue(): void
    {
        $form = $this->form();
        $result = $form->recalculate('doc_currency', ['doc_currency' => 'eur', 'accounting_date' => '2026-10-16', 'exchange_rate' => 20]);

        $this->assertSame(24.325, $result->data['exchange_rate']);
        $this->assertArrayNotHasKey(DocsHeadsFormBase::EXCHANGE_RATE_HINT_KEY, $result->data);
        $this->assertSame([['eur', '2026-10-16']], $form->resolver?->lookups);
        $el = $this->element($result->formDefinition, 'exchange_rate');
        $this->assertFalse($el->hidden);
        $this->assertNull($el->hint);
    }

    public function testAccountingDateChangeUsesTheNewDate(): void
    {
        $result = $this->form()->recalculate('accounting_date', ['doc_currency' => 'eur', 'accounting_date' => '2026-10-20', 'exchange_rate' => 24.325]);

        $this->assertSame(24.5, $result->data['exchange_rate']);
        $this->assertSame('reload', $this->element($result->formDefinition, 'accounting_date')->triggers);
    }

    public function testSwitchingToHomeCurrencyResetsRateToOne(): void
    {
        $form = $this->form();
        $result = $form->recalculate('doc_currency', ['doc_currency' => 'czk', 'accounting_date' => '2026-10-16', 'exchange_rate' => 24.325]);

        $this->assertSame(1, $result->data['exchange_rate']);
        $this->assertSame([], $form->resolver?->lookups, 'domácí měna bez dotazu');
        $this->assertTrue($this->element($result->formDefinition, 'exchange_rate')->hidden);
    }

    public function testMissingRateKeepsValueAndShowsHintOnlyInThatResponse(): void
    {
        $result = $this->form([])->recalculate('doc_currency', ['doc_currency' => 'eur', 'accounting_date' => '2026-10-16', 'exchange_rate' => 23.5]);

        $this->assertSame(23.5, $result->data['exchange_rate']);
        $hint = 'Kurz EUR k 16. 10. 2026 není v kurzovním lístku — zadej ho ručně.';
        $this->assertSame($hint, $result->data[DocsHeadsFormBase::EXCHANGE_RATE_HINT_KEY]);
        $this->assertSame($hint, $this->element($result->formDefinition, 'exchange_rate')->hint);

        // Klient klíč vrací s daty; další odpověď s nalezeným kurzem ho smaže.
        $next = $this->form()->recalculate('accounting_date', $result->data);
        $this->assertSame(24.325, $next->data['exchange_rate']);
        $this->assertArrayNotHasKey(DocsHeadsFormBase::EXCHANGE_RATE_HINT_KEY, $next->data);
        $this->assertNull($this->element($next->formDefinition, 'exchange_rate')->hint);
    }

    public function testIssueDateMovesAccountingDateAndRateOnNewDocumentOnly(): void
    {
        $form = $this->form();
        $result = $form->recalculate('issue_date', ['issue_date' => '2026-10-20', 'accounting_date' => '2026-10-16', 'doc_currency' => 'eur', 'exchange_rate' => 24.325]);
        $this->assertSame('2026-10-20', $result->data['accounting_date']);
        $this->assertSame(24.5, $result->data['exchange_rate']);

        $form = $this->form();
        $result = $form->recalculate('issue_date', ['id' => 5, 'issue_date' => '2026-10-20', 'accounting_date' => '2026-10-16', 'doc_currency' => 'eur', 'exchange_rate' => 24.325]);
        $this->assertSame('2026-10-16', $result->data['accounting_date']);
        $this->assertSame(24.325, $result->data['exchange_rate']);
        $this->assertSame([], $form->resolver?->lookups, 'uložený doklad s účetním datem: beze změny');
    }

    public function testWithoutAccountingDateIssueDateIsUsed(): void
    {
        $form = $this->form();
        $result = $form->recalculate('doc_currency', ['doc_currency' => 'eur', 'issue_date' => '2026-10-20']);

        $this->assertSame(24.5, $result->data['exchange_rate']);
        $this->assertSame([['eur', '2026-10-20']], $form->resolver?->lookups);
    }

    public function testNewRecordDefaultsFillRateForForeignCurrencyAndOneForHome(): void
    {
        $data = ['doc_type' => 'invno', 'vat_mode' => 0, 'payment_method' => 1, 'doc_currency' => 'eur', 'issue_date' => '2026-10-16'];
        $this->form(withDb: true)->applyNewRecordDefaults($data);
        $this->assertSame('2026-10-16', $data['accounting_date']);
        $this->assertSame(24.325, $data['exchange_rate']);

        $data = ['doc_type' => 'invno', 'vat_mode' => 0, 'payment_method' => 1, 'doc_currency' => 'czk', 'issue_date' => '2026-10-16'];
        $this->form(withDb: true)->applyNewRecordDefaults($data);
        $this->assertSame(1, $data['exchange_rate']);
    }

    public function testWithoutResolverNothingChangesAndNoHint(): void
    {
        $result = $this->form(null)->recalculate('doc_currency', ['doc_currency' => 'eur', 'accounting_date' => '2026-10-16', 'exchange_rate' => 23.5]);

        $this->assertSame(23.5, $result->data['exchange_rate']);
        $this->assertArrayNotHasKey(DocsHeadsFormBase::EXCHANGE_RATE_HINT_KEY, $result->data);
    }

    public function testForeignCurrencyFlagFallsBackToSettingsWhenHomeMissing(): void
    {
        $form = $this->form();
        $this->assertTrue($this->element($form->buildFormDefinition(['doc_currency' => 'czk'], false), 'exchange_rate')->hidden);
        $this->assertFalse($this->element($form->buildFormDefinition(['doc_currency' => 'eur'], false), 'exchange_rate')->hidden);
    }

    public function testBaseFormWithoutDbHasNoResolver(): void
    {
        $data = ['doc_currency' => 'eur', 'accounting_date' => '2026-10-16', 'exchange_rate' => 23.5];
        $result = (new DocsHeadsForm('docs_core_heads'))->recalculate('doc_currency', $data);
        $this->assertSame(23.5, $result->data['exchange_rate']);
    }
}

/** Formulář s podstrčeným resolverem (nebo bez něj). */
class ExchangeRateTestForm extends DocsHeadsForm
{
    public function __construct(public readonly ?FakeExchangeRateResolver $resolver)
    {
        parent::__construct('docs_core_heads');
    }

    protected function exchangeRateResolver(): ?ExchangeRateResolver
    {
        return $this->resolver;
    }
}
