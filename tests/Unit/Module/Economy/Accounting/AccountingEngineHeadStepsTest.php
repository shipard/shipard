<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Accounting\AccountingEngine;

/**
 * Hlavičkové kroky předpisu pro pokladnu (#59 D8): `headQuery` filtruje
 * krok nad hlavičkou pro libovolný src, `matchesQuery` umí operátory
 * `$ne` / `$in`, `accountSrc: "cashDesk"` bere účet z pokladny hlavičky.
 * Engine je final — privátní metody se volají reflexí (konvence projektu).
 */
class AccountingEngineHeadStepsTest extends TestCase
{
    private const HEAD = [
        'cash_desk'          => 7,
        'cash_dir'           => '1',
        'payment_method'     => '0',
        'doc_text'           => 'PD test',
        'total_amount_dom'   => 1210.0,
        'total_amount'       => 1210.0,
        'total_rounding_dom' => 0.0,
        'total_rounding'     => 0.0,
        'partner'            => null,
        'payment_reference'  => null,
        'specific_symbol'    => null,
        'constant_symbol'    => null,
        'due_date'           => null,
    ];

    /**
     * @param array<string, mixed>|null $desk    řádek pokladny (accounting_account)
     * @param array<string, mixed>|null $account řádek účtu rozvrhu
     */
    private function engine(?array $desk, ?array $account): AccountingEngine
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (...$args) use ($desk, $account): ?\Dibi\Row {
                $sql = (string) ($args[0] ?? '');
                if (str_contains($sql, 'economy_codebooks_cash_desks')) {
                    return $desk !== null ? new \Dibi\Row($desk) : null;
                }
                if (str_contains($sql, 'economy_accounting_accounts')) {
                    return $account !== null ? new \Dibi\Row($account) : null;
                }
                return null;
            },
        );
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturn(null);
        return new AccountingEngine($db, $config);
    }

    private function invoke(AccountingEngine $engine, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(AccountingEngine::class, $method))->invoke($engine, ...$args);
    }

    /** @return list<array{code: string, message: string, rowId: int|null}> */
    private function messagesOf(AccountingEngine $engine): array
    {
        return (new \ReflectionProperty(AccountingEngine::class, 'messages'))->getValue($engine);
    }

    // ── partnerSrc: balance (#72 D3) ────────────────────────────────────────

    public function testHeadStepIdentityBalanceUsesBalancePartnerWithHeadFallback(): void
    {
        $engine = $this->engine(null, null);
        $head = array_merge(self::HEAD, ['partner' => 5, 'partner_balance' => 33, 'payment_reference' => '2026000042']);

        $this->assertNull($this->invoke($engine, 'headStepIdentity', ['cat' => 'receivables'], $head), 'bez partnerSrc identita hlavičky');

        $identity = $this->invoke($engine, 'headStepIdentity', ['partnerSrc' => 'balance'], $head);
        $this->assertSame(33, $identity['partner'], 'plátce místo partnera');
        $this->assertSame('2026000042', $identity['payment_reference'], 'VS zůstává z hlavičky');

        $head['partner_balance'] = null;
        $identity = $this->invoke($engine, 'headStepIdentity', ['partnerSrc' => 'balance'], $head);
        $this->assertSame(5, $identity['partner'], 'bez plátce partner hlavičky (DS bez terminálů)');
    }

    public function testHeadStepUnknownPartnerSrcThrows(): void
    {
        $engine = $this->engine(null, null);
        $this->expectException(\LogicException::class);
        $this->invoke($engine, 'headStepIdentity', ['partnerSrc' => 'row'], self::HEAD);
    }

    public function testBuildHeadLinesCarriesBalancePartnerOnLine(): void
    {
        $engine = $this->engine(
            ['accounting_account' => 11],
            ['id' => 11, 'number' => '211100'],
        );
        $step = ['accountSrc' => 'cashDesk', 'partnerSrc' => 'balance', 'src' => 'head', 'col' => 'total', 'side' => 0];
        $head = array_merge(self::HEAD, ['partner' => 5, 'partner_balance' => 33]);

        $lines = $this->invoke($engine, 'buildHeadLines', $step, $head);

        $this->assertCount(1, $lines);
        $this->assertSame(33, $lines[0]['partner']);
        $this->assertEqualsWithDelta(1210.0, $lines[0]['money_dr'], 0.001);
    }

    // ── matchesQuery ────────────────────────────────────────────────────────

    public function testMatchesQueryScalarStaysLoose(): void
    {
        $engine = $this->engine(null, null);
        $this->assertTrue($this->invoke($engine, 'matchesQuery', ['query' => ['payment_method' => 0]], ['payment_method' => '0']));
        $this->assertFalse($this->invoke($engine, 'matchesQuery', ['query' => ['payment_method' => 0]], ['payment_method' => '1']));
        $this->assertTrue($this->invoke($engine, 'matchesQuery', [], ['payment_method' => '1']), 'bez query projde vše');
    }

    public function testMatchesQueryNotEqualOperator(): void
    {
        $engine = $this->engine(null, null);
        $step = ['query' => ['payment_method' => ['$ne' => 0]]];

        $this->assertFalse($this->invoke($engine, 'matchesQuery', $step, ['payment_method' => '0']));
        $this->assertFalse($this->invoke($engine, 'matchesQuery', $step, ['payment_method' => 0]));
        $this->assertTrue($this->invoke($engine, 'matchesQuery', $step, ['payment_method' => '1']));
        $this->assertTrue($this->invoke($engine, 'matchesQuery', $step, ['payment_method' => 2]));
    }

    public function testMatchesQueryInOperator(): void
    {
        $engine = $this->engine(null, null);
        $step = ['query' => ['payment_method' => ['$in' => [0, 2]]]];

        $this->assertTrue($this->invoke($engine, 'matchesQuery', $step, ['payment_method' => '2']));
        $this->assertFalse($this->invoke($engine, 'matchesQuery', $step, ['payment_method' => '1']));
    }

    public function testMatchesQueryUnknownOperatorThrows(): void
    {
        $engine = $this->engine(null, null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('$gt');
        $this->invoke($engine, 'matchesQuery', ['query' => ['x' => ['$gt' => 1]]], ['x' => 5]);
    }

    // ── headQuery ───────────────────────────────────────────────────────────

    public function testHeadQueryFiltersStepForAnySource(): void
    {
        $engine = $this->engine(['accounting_account' => 42], ['id' => 42, 'number' => '211100']);
        $step = [
            'headQuery' => ['cash_dir' => 1],
            'accountSrc' => 'cashDesk', 'src' => 'head', 'col' => 'total', 'side' => 0,
        ];

        $lines = $this->invoke($engine, 'buildStepLines', $step, self::HEAD, [], []);
        $this->assertCount(1, $lines, 'cash_dir 1 odpovídá');

        $lines = $this->invoke($engine, 'buildStepLines', $step, ['cash_dir' => '2'] + self::HEAD, [], []);
        $this->assertSame([], $lines, 'cash_dir 2 krok vynechá');

        // řádkový krok: headQuery nad hlavičkou, ne nad řádkem
        $rowStep = ['headQuery' => ['cash_dir' => 2], 'src' => 'rows', 'side' => 0, 'operation' => 'purchase.goods'];
        $rows = [['id' => 1, 'operation' => 'purchase.goods', 'vat_base_dom' => 500.0, 'vat_base' => 500.0]];
        $this->assertSame([], $this->invoke($engine, 'buildStepLines', $rowStep, self::HEAD, $rows, []));
    }

    // ── accountSrc: cashDesk ────────────────────────────────────────────────

    public function testCashDeskAccountFromHeadCashDesk(): void
    {
        $engine = $this->engine(['accounting_account' => 42], ['id' => 42, 'number' => '211100']);
        $step = ['accountSrc' => 'cashDesk', 'src' => 'head', 'col' => 'total', 'side' => 0, 'query' => ['payment_method' => 0]];

        $lines = $this->invoke($engine, 'buildHeadLines', $step, self::HEAD);

        $this->assertCount(1, $lines);
        $this->assertSame('211100', $lines[0]['account_number']);
        $this->assertSame(42, $lines[0]['account']);
        $this->assertSame(0, $lines[0]['side']);
        $this->assertEqualsWithDelta(1210.0, $lines[0]['money_dr'], 0.001);
        $this->assertFalse($lines[0]['is_error']);
        $this->assertSame([], $this->messagesOf($engine));
    }

    public function testCashDeskWithoutAccountProducesErrorLine(): void
    {
        $engine = $this->engine(['accounting_account' => null], null);
        $step = ['accountSrc' => 'cashDesk', 'src' => 'head', 'col' => 'total', 'side' => 0];

        $lines = $this->invoke($engine, 'buildHeadLines', $step, self::HEAD);

        $this->assertCount(1, $lines);
        $this->assertSame('211???', $lines[0]['account_number']);
        $this->assertTrue($lines[0]['is_error']);
        $messages = $this->messagesOf($engine);
        $this->assertCount(1, $messages);
        $this->assertSame('cash_desk_account_missing', $messages[0]['code']);
        $this->assertStringContainsString('211xxx', $messages[0]['message']);
    }

    public function testArchivedCashDeskAccountIsStillLinkable(): void
    {
        // účet ve stavu 70 se dohledá (LINKABLE_STATES) — historické doklady
        $engine = $this->engine(['accounting_account' => 42], ['id' => 42, 'number' => '211100']);
        $step = ['accountSrc' => 'cashDesk', 'src' => 'head', 'col' => 'total', 'side' => 1];
        $lines = $this->invoke($engine, 'buildHeadLines', $step, self::HEAD);
        $this->assertEqualsWithDelta(1210.0, $lines[0]['money_cr'], 0.001);
    }

    public function testHeadWithoutCashDeskProducesErrorLineWithDistinctMessage(): void
    {
        // faktura s Hotovostí bez pokladny (D8): měkká chyba, uživatel doplní
        $engine = $this->engine(['accounting_account' => 42], ['id' => 42, 'number' => '211100']);
        $step = ['accountSrc' => 'cashDesk', 'src' => 'head', 'col' => 'total', 'side' => 0];

        $lines = $this->invoke($engine, 'buildHeadLines', $step, ['cash_desk' => null] + self::HEAD);

        $this->assertSame('211???', $lines[0]['account_number']);
        $messages = $this->messagesOf($engine);
        $this->assertSame('cash_desk_account_missing', $messages[0]['code']);
        $this->assertStringContainsString('nemá pokladnu', $messages[0]['message']);
    }

    public function testQueryOnHeadStepStillApplies(): void
    {
        $engine = $this->engine(['accounting_account' => 42], ['id' => 42, 'number' => '211100']);
        $step = ['accountSrc' => 'cashDesk', 'src' => 'head', 'col' => 'total', 'side' => 0, 'query' => ['payment_method' => 0]];

        $lines = $this->invoke($engine, 'buildHeadLines', $step, ['payment_method' => '2'] + self::HEAD);
        $this->assertSame([], $lines, 'kartou → krok pokladny se nepoužije');
    }
}
