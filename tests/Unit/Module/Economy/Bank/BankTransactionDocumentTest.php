<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Bank\BankTransactionDocument;

class BankTransactionDocumentTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir === null) {
            return;
        }
        foreach (glob($this->tmpDir . '/config/configuration/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir . '/config/configuration');
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
        $this->tmpDir = null;
    }

    private function doc(): BankTransactionDocument
    {
        return new BankTransactionDocument();
    }

    /** Document s compiled configem txOperations (směr per pohyb). */
    private function docWithOperations(): BankTransactionDocument
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_banktx_doc_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode(['_meta' => ['language' => 'cs'], 'items' => [
                'economy.bank.txOperations' => [
                    'payment.in'   => ['name' => 'Příjem', 'direction' => 1, 'cat' => 'bank.unmatched.in'],
                    'payment.out'  => ['name' => 'Výdaj', 'direction' => 2, 'cat' => 'bank.unmatched.out'],
                    'transfer.in'  => ['name' => 'Příjem z převodu peněz', 'direction' => 1, 'cat' => 'cash.transit'],
                    'transfer.out' => ['name' => 'Výdej pro převod peněz', 'direction' => 2, 'cat' => 'cash.transit'],
                ],
            ]]),
        );
        $doc = new BankTransactionDocument();
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'bank_account'     => 1,
            'direction'        => 1,
            'amount'           => 1210.0,
            'amount_dom'       => 1210.0,
            'currency'         => 'czk',
            'date_transaction' => '2026-06-10',
        ];
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function hasError(array $errors, string $column, ?string $code = null): bool
    {
        foreach ($errors as $e) {
            if ($e['column'] === $column && ($code === null || $e['code'] === $code)) {
                return true;
            }
        }
        return false;
    }

    // --- validate -----------------------------------------------------------

    public function testValidIsValid(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testDirectionOutOfRangeFails(): void
    {
        foreach ([0, 3, -1] as $dir) {
            $data = $this->validData();
            $data['direction'] = $dir;
            $result = $this->doc()->validate($data);
            $this->assertFalse($result->isValid(), "direction {$dir} mělo selhat");
            $this->assertTrue($this->hasError($result->toArray(), 'direction', 'invalid'));
        }
    }

    public function testMissingDirectionFails(): void
    {
        $data = $this->validData();
        unset($data['direction']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'direction'));
    }

    public function testNonPositiveAmountFails(): void
    {
        foreach ([0, -5.0] as $amount) {
            $data = $this->validData();
            $data['amount'] = $amount;
            $result = $this->doc()->validate($data);
            $this->assertFalse($result->isValid(), "amount {$amount} mělo selhat");
            $this->assertTrue($this->hasError($result->toArray(), 'amount', 'invalid'));
        }
    }

    public function testMissingBankAccountFails(): void
    {
        $data = $this->validData();
        unset($data['bank_account']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'bank_account', 'required'));
    }

    public function testMissingCurrencyFails(): void
    {
        $data = $this->validData();
        unset($data['currency']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'currency', 'required'));
    }

    public function testMissingDateFails(): void
    {
        $data = $this->validData();
        unset($data['date_transaction']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'date_transaction', 'required'));
    }

    public function testNegativeAmountDomFails(): void
    {
        $data = $this->validData();
        $data['amount_dom'] = -1.0;
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'amount_dom'));
    }

    public function testMissingAmountDomIsValidBecauseDerived(): void
    {
        // amount_dom dopočítá beforeSave, takže jeho absence validaci nebrání.
        $data = $this->validData();
        unset($data['amount_dom']);
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    // --- operation × direction (#59 Task D) ---------------------------------

    public function testOperationMatchingDirectionPasses(): void
    {
        $doc = $this->docWithOperations();

        $in = $this->validData() + ['operation' => 'transfer.in'];
        $this->assertTrue($doc->validate($in)->isValid());

        $out = array_merge($this->validData(), ['direction' => 2, 'operation' => 'transfer.out']);
        $this->assertTrue($doc->validate($out)->isValid());
    }

    public function testOperationAgainstDirectionFails(): void
    {
        $doc = $this->docWithOperations();

        $data = array_merge($this->validData(), ['direction' => 2, 'operation' => 'transfer.in']);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'operation', 'operation_direction_mismatch'));

        $data = $this->validData() + ['operation' => 'payment.out'];
        $this->assertTrue($this->hasError($doc->validate($data)->toArray(), 'operation', 'operation_direction_mismatch'));
    }

    public function testUnknownOperationOrMissingConfigSkipsDirectionCheck(): void
    {
        // neznámý pohyb hlídá enum sloupce, ne tahle kontrola
        $data = array_merge($this->validData(), ['direction' => 2, 'operation' => 'stock.in']);
        $this->assertTrue($this->docWithOperations()->validate($data)->isValid());

        // bez configu (CLI / test bez DS) degradovaně projde
        $data = array_merge($this->validData(), ['direction' => 2, 'operation' => 'transfer.in']);
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveComputesAmountDomFromRate(): void
    {
        $data = ['amount' => 100.0, 'exchange_rate' => 1.5];
        $this->doc()->beforeSave($data);
        $this->assertEqualsWithDelta(150.0, $data['amount_dom'], 0.001);
    }

    public function testBeforeSaveDefaultsRateToOne(): void
    {
        $data = ['amount' => 100.0];
        $this->doc()->beforeSave($data);
        $this->assertEqualsWithDelta(100.0, $data['amount_dom'], 0.001);
    }

    public function testBeforeSaveKeepsProvidedAmountDom(): void
    {
        $data = ['amount' => 100.0, 'exchange_rate' => 1.5, 'amount_dom' => 142.0];
        $this->doc()->beforeSave($data);
        $this->assertEqualsWithDelta(142.0, $data['amount_dom'], 0.001);
    }

    public function testBeforeSaveLowercasesCurrencyAndTrims(): void
    {
        $data = ['amount' => 10.0, 'currency' => ' CZK ', 'message' => '  pozn  '];
        $this->doc()->beforeSave($data);
        $this->assertSame('czk', $data['currency']);
        $this->assertSame('pozn', $data['message']);
    }
}
