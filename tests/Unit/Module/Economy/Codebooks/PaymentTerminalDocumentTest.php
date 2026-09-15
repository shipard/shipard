<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\PaymentTerminalDocument;

class PaymentTerminalDocumentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function terminal(): array
    {
        return [
            'code'      => 'T-HP1',
            'name'      => 'Terminál hlavní pokladny',
            'kind'      => PaymentTerminalDocument::KIND_TERMINAL,
            'cash_desk' => 7,
            'partner'   => 101,
            'docState'  => 10,
        ];
    }

    /** @return array<string, mixed> */
    private function gateway(): array
    {
        return [
            'code'      => 'GOPAY',
            'name'      => 'Platební brána',
            'kind'      => PaymentTerminalDocument::KIND_GATEWAY,
            'cash_desk' => null,
            'partner'   => 102,
            'docState'  => 10,
        ];
    }

    /** @return list<array{column: string, message: string, code: string}> */
    private function errorsFor(array $errors, string $column): array
    {
        return array_values(array_filter($errors, static fn(array $e): bool => $e['column'] === $column));
    }

    // --- validate -----------------------------------------------------------

    public function testTerminalAndGatewayAreValidWithoutDb(): void
    {
        $doc = new PaymentTerminalDocument();
        $t = $this->terminal();
        $g = $this->gateway();
        $this->assertTrue($doc->validate($t)->isValid());
        $this->assertTrue($doc->validate($g)->isValid());
    }

    public function testCodeAndNameRequired(): void
    {
        $data = $this->terminal();
        unset($data['code'], $data['name']);
        $errors = (new PaymentTerminalDocument())->validate($data)->toArray();

        $this->assertSame('required', $this->errorsFor($errors, 'code')[0]['code']);
        $this->assertSame('required', $this->errorsFor($errors, 'name')[0]['code']);
    }

    public function testTerminalRequiresCashDesk(): void
    {
        $data = $this->terminal();
        $data['cash_desk'] = null;
        $errors = (new PaymentTerminalDocument())->validate($data)->toArray();

        $this->assertSame('cash_desk_required', $this->errorsFor($errors, 'cash_desk')[0]['code']);
    }

    public function testGatewayRejectsCashDesk(): void
    {
        $data = $this->gateway();
        $data['cash_desk'] = 7;
        $errors = (new PaymentTerminalDocument())->validate($data)->toArray();

        $this->assertSame('cash_desk_not_allowed', $this->errorsFor($errors, 'cash_desk')[0]['code']);
    }

    public function testUnknownKindFails(): void
    {
        $data = $this->terminal();
        $data['kind'] = 5;
        $errors = (new PaymentTerminalDocument())->validate($data)->toArray();

        $this->assertSame('invalid_value', $this->errorsFor($errors, 'kind')[0]['code']);
    }

    public function testPartnerRequiredOnlyInConfirmedStates(): void
    {
        $doc = new PaymentTerminalDocument();

        $concept = $this->terminal();
        $concept['partner'] = null;
        $this->assertTrue($doc->validate($concept)->isValid(), 'koncept bez protistrany projde');

        foreach ([40, 80] as $state) {
            $data = $this->terminal();
            $data['partner'] = null;
            $data['docState'] = $state;
            $errors = $doc->validate($data)->toArray();
            $this->assertSame('partner_required', $this->errorsFor($errors, 'partner')[0]['code'], "stav {$state}");
        }
    }

    public function testCashDeskMustExistWhenDbAvailable(): void
    {
        $data = $this->terminal();

        $missing = $this->createMock(\Dibi\Connection::class);
        $missing->method('fetch')->willReturn(null);
        $doc = new PaymentTerminalDocument();
        $doc->setDb($missing);
        $errors = $doc->validate($data)->toArray();
        $this->assertSame('invalid', $this->errorsFor($errors, 'cash_desk')[0]['code']);

        $found = $this->createMock(\Dibi\Connection::class);
        $found->method('fetch')->willReturnCallback(
            function (string $sql, mixed ...$params): ?\Dibi\Row {
                $this->assertStringContainsString('economy_codebooks_cash_desks', $sql);
                $this->assertSame(7, $params[0]);
                return new \Dibi\Row(['id' => 7]);
            },
        );
        $doc = new PaymentTerminalDocument();
        $doc->setDb($found);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testInvalidValidityRangeFails(): void
    {
        $data = $this->terminal();
        $data['valid_from'] = '2026-12-31';
        $data['valid_to']   = '2026-01-01';
        $errors = (new PaymentTerminalDocument())->validate($data)->toArray();

        $this->assertSame('invalid_range', $this->errorsFor($errors, 'valid_to')[0]['code']);
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveTrimsAndClearsCashDeskForGateway(): void
    {
        $doc = new PaymentTerminalDocument();
        $data = [
            'code'      => '  GOPAY ',
            'name'      => " Brána\n",
            'notice'    => '  pozn ',
            'kind'      => PaymentTerminalDocument::KIND_GATEWAY,
            'cash_desk' => 7,
        ];
        $doc->beforeSave($data);

        $this->assertSame('GOPAY', $data['code']);
        $this->assertSame('Brána', $data['name']);
        $this->assertSame('pozn', $data['notice']);
        $this->assertNull($data['cash_desk']);
    }

    public function testBeforeSaveKeepsCashDeskForTerminal(): void
    {
        $doc = new PaymentTerminalDocument();
        $data = ['code' => 'T', 'name' => 'T', 'kind' => PaymentTerminalDocument::KIND_TERMINAL, 'cash_desk' => 7];
        $doc->beforeSave($data);

        $this->assertSame(7, $data['cash_desk']);
    }

    // --- afterPersist -------------------------------------------------------

    public function testAfterPersistSkipsWhenNotDefault(): void
    {
        $doc = new TestablePaymentTerminalDocument();
        $doc->afterPersist(['id' => 1, 'kind' => 0, 'cash_desk' => 7, 'is_default' => 0]);

        $this->assertSame(0, $doc->clearCalls);
    }

    public function testAfterPersistScopesTerminalDefaultToCashDesk(): void
    {
        $doc = new TestablePaymentTerminalDocument();
        $doc->afterPersist(['id' => 42, 'kind' => 0, 'cash_desk' => 7, 'is_default' => 1]);

        $this->assertSame(1, $doc->clearCalls);
        $this->assertSame([0, 7, 42], $doc->lastArgs);
    }

    public function testAfterPersistScopesGatewayDefaultAmongGateways(): void
    {
        $doc = new TestablePaymentTerminalDocument();
        $doc->afterPersist(['id' => 43, 'kind' => 1, 'cash_desk' => null, 'is_default' => 1]);

        $this->assertSame(1, $doc->clearCalls);
        $this->assertSame([1, null, 43], $doc->lastArgs);
    }

    public function testAfterPersistSkipsTerminalWithoutCashDeskOrId(): void
    {
        $doc = new TestablePaymentTerminalDocument();
        $doc->afterPersist(['id' => 44, 'kind' => 0, 'cash_desk' => null, 'is_default' => 1]);
        $doc->afterPersist(['kind' => 1, 'is_default' => 1]);

        $this->assertSame(0, $doc->clearCalls);
    }
}

/** Spy nad protected clearOtherDefaults — Dibi\Connection::query() je final. */
class TestablePaymentTerminalDocument extends PaymentTerminalDocument
{
    public int $clearCalls = 0;
    /** @var array{0: int, 1: ?int, 2: int}|null */
    public ?array $lastArgs = null;

    protected function clearOtherDefaults(int $kind, ?int $cashDesk, int $id): void
    {
        $this->clearCalls++;
        $this->lastArgs = [$kind, $cashDesk, $id];
    }
}
