<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Bank\BankTransactionForm;

/**
 * Formulář transakce jako třída (#121 D7): pole Kurz jen u cizí měny
 * a editovatelné, ostatní kontext jen ke čtení, operation / partner /
 * message editovatelné, registrace v module.jsonc.
 */
class BankTransactionFormTest extends TestCase
{
    private function form(?string $home = 'czk'): BankTransactionForm
    {
        $form = new BankTransactionForm(BankTransactionForm::TABLE);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static fn(mixed ...$args): mixed => ($args[1] ?? null) === 'economy.homeCurrency' && $home !== null ? json_encode($home) : null,
        );
        $form->setDb($db);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => match ($id) {
            'economy.bank.txDirections' => ['1' => ['name' => 'Příjem'], '2' => ['name' => 'Výdaj']],
            'economy.bank.txOperations' => ['payment.in' => ['name' => 'Příjem', 'direction' => 1]],
            'world.base.currencies'     => ['czk' => ['name' => 'Koruna', 'alpha3' => 'CZK'], 'eur' => ['name' => 'Euro', 'alpha3' => 'EUR']],
            default => null,
        });
        $form->setConfig($config);
        return $form;
    }

    private function element(FormDefinition $def, string $column): ?FormElement
    {
        foreach ($def->tabs[0]->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    if ($el->column === $column) {
                        return $el;
                    }
                }
            }
        }
        return null;
    }

    public function testRateFieldOnlyForForeignCurrencyAndEditable(): void
    {
        $def = $this->form()->buildFormDefinition(['id' => 5, 'currency' => 'eur', 'exchange_rate' => '24.325000'], false);
        $rate = $this->element($def, 'exchange_rate');
        $this->assertNotNull($rate);
        $this->assertFalse($rate->hidden);
        $this->assertFalse($rate->readOnly);
        $this->assertSame('number', $rate->inputType);
        $this->assertNotNull($rate->hint);

        $def = $this->form()->buildFormDefinition(['id' => 6, 'currency' => 'czk'], false);
        $this->assertTrue($this->element($def, 'exchange_rate')->hidden);

        $def = $this->form('eur')->buildFormDefinition(['id' => 7, 'currency' => 'eur'], false);
        $this->assertTrue($this->element($def, 'exchange_rate')->hidden, 'EUR jako domácí měna');
    }

    public function testContextIsReadOnlyAndAccountingFieldsEditable(): void
    {
        $def = $this->form()->buildFormDefinition(['id' => 5, 'currency' => 'eur'], false);

        foreach (['date_transaction', 'date_value', 'direction', 'amount', 'currency', 'amount_dom', 'counterparty_account', 'counterparty_name', 'payment_reference', 'specific_symbol', 'constant_symbol'] as $column) {
            $this->assertTrue($this->element($def, $column)?->readOnly ?? false, $column);
        }
        foreach (['operation', 'partner', 'message'] as $column) {
            $this->assertFalse($this->element($def, $column)?->readOnly ?? true, $column);
        }
        $this->assertSame('lookup', $this->element($def, 'partner')->type);
        $this->assertSame(['1', '2'], array_map(static fn(array $o): string => (string) $o['value'], $this->element($def, 'direction')->options));
        $this->assertSame('Bankovní transakce', $def->title);
        $this->assertSame('basic', $def->tabs[0]->id);
    }

    public function testIsRegisteredAsFormClass(): void
    {
        $module = JsoncParser::parseFile(__DIR__ . '/../../../../../modules/economy/bank/module.jsonc');
        $forms = array_column($module['forms'], null, 'table');
        $this->assertSame(BankTransactionForm::class, $forms[BankTransactionForm::TABLE]['class']);
        $this->assertSame('economy.bank.transactions', $forms[BankTransactionForm::TABLE]['id']);
        $this->assertFileDoesNotExist(__DIR__ . '/../../../../../modules/economy/bank/forms/economy_bank_transactions.jsonc');
    }
}
