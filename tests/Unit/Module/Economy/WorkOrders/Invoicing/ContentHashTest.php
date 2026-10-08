<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Invoicing;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\WorkOrders\Invoicing\ContentHash;

/** Otisk obsahu konceptu (D10): stabilní vůči formátu hodnot, citlivý na obsah. */
class ContentHashTest extends TestCase
{
    private const HEAD = ['id' => 100, 'doc_text' => 'Nájem říjen 2026', 'partner' => 2, 'issue_date' => '2026-10-01', 'due_date' => '2026-10-15', 'vat_duzp' => '2026-10-01', 'doc_currency' => 'czk', 'payment_reference' => '20260001', 'total_amount' => '14000.00', 'docState' => 10];
    private const ROWS = [
        ['id' => 1, 'order_pos' => 1, 'description' => 'Nájem', 'quantity' => '1.0000', 'unit_price' => '12000.0000', 'vat_code' => 'cz-110'],
        ['id' => 2, 'order_pos' => 2, 'description' => 'Služby', 'quantity' => '1.0000', 'unit_price' => '2000.0000', 'vat_code' => 'cz-110'],
    ];

    public function testSameContentInDifferentShapesHashesEqually(): void
    {
        $a = ContentHash::ofDocument(self::HEAD, self::ROWS);
        $head = self::HEAD;
        $head['issue_date'] = new \DateTimeImmutable('2026-10-01');
        $head['total_amount'] = '99999.00';   // součty do otisku nepatří
        $head['docState'] = 80;
        $rows = array_reverse(self::ROWS);
        $rows[0]['quantity'] = 1.0;
        $rows[1]['unit_price'] = 12000;
        $this->assertSame($a, ContentHash::ofDocument($head, $rows));
        $this->assertSame(64, strlen($a));
    }

    public function testEditedContentChangesHash(): void
    {
        $a = ContentHash::ofDocument(self::HEAD, self::ROWS);
        $rows = self::ROWS;
        $rows[1]['unit_price'] = '2500.0000';
        $this->assertNotSame($a, ContentHash::ofDocument(self::HEAD, $rows));
        $head = self::HEAD;
        $head['doc_text'] = 'Upraveno';
        $this->assertNotSame($a, ContentHash::ofDocument($head, self::ROWS));
        $this->assertNotSame($a, ContentHash::ofDocument(self::HEAD, [self::ROWS[0]]));
    }
}
