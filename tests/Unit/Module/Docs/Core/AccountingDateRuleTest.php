<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\AccountingDateRule;

/** Výchozí účetní datum (#103 D78): přijatá faktura DUZP, jinak datum vystavení. */
class AccountingDateRuleTest extends TestCase
{
    public function testOnlyReceivedInvoiceUsesDuzp(): void
    {
        $this->assertTrue(AccountingDateRule::usesDuzp('invni'));
        foreach (['invno', 'invpi', 'invpo', 'cash', 'cashreg', 'cmnbkp', ''] as $type) {
            $this->assertFalse(AccountingDateRule::usesDuzp($type), $type);
        }
    }

    public function testExplicitAccountingDateWins(): void
    {
        $canonical = ['docType' => 'invoiceReceived', 'dates' => [
            'accountingDate' => '2026-09-05', 'taxPointDate' => '2026-08-31', 'issueDate' => '2026-09-02',
        ]];
        $this->assertSame('2026-09-05', AccountingDateRule::fromCanonical($canonical));
    }

    public function testReceivedInvoiceFallsBackToTaxPointDateThenIssueDate(): void
    {
        $dates = ['taxPointDate' => '2026-08-31', 'issueDate' => '2026-09-02'];
        $this->assertSame('2026-08-31', AccountingDateRule::fromCanonical(['docType' => 'invoiceReceived', 'dates' => $dates]));
        $this->assertSame('2026-09-02', AccountingDateRule::fromCanonical(['docType' => 'invoiceReceived', 'dates' => ['taxPointDate' => null, 'issueDate' => '2026-09-02']]));
        $this->assertSame('2026-09-02', AccountingDateRule::fromCanonical(['docType' => 'invoiceReceived', 'dates' => ['taxPointDate' => '', 'issueDate' => '2026-09-02']]));
    }

    public function testOtherDocTypesIgnoreTaxPointDate(): void
    {
        $dates = ['taxPointDate' => '2026-08-31', 'issueDate' => '2026-09-02'];
        foreach (['invoiceIssued', 'proformaReceived', 'receipt', ''] as $type) {
            $this->assertSame('2026-09-02', AccountingDateRule::fromCanonical(['docType' => $type, 'dates' => $dates]), $type);
        }
        $this->assertSame('2026-09-02', AccountingDateRule::fromCanonical(['dates' => $dates]));
    }

    public function testMissingDatesGiveNull(): void
    {
        $this->assertNull(AccountingDateRule::fromCanonical([]));
        $this->assertNull(AccountingDateRule::fromCanonical(['docType' => 'invoiceReceived', 'dates' => ['issueDate' => '']]));
        $this->assertNull(AccountingDateRule::fromCanonical(['dates' => 'not an array']));
    }

    public function testDateObjectsAreFormatted(): void
    {
        $canonical = ['dates' => ['accountingDate' => new \DateTimeImmutable('2026-09-05 14:00')]];
        $this->assertSame('2026-09-05', AccountingDateRule::fromCanonical($canonical));
    }
}
