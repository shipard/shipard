<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Mail\IncomingMessageCode;

/**
 * Krátký tvar kódu došlé zprávy (tasks/mail-source-message-link.md D8):
 * `MSG-YYYYMMDD-NNNN` → `YYMMDD-NNNN`, cokoli jiného beze změny.
 */
final class IncomingMessageCodeTest extends TestCase
{
    public function testFullCodeDropsPrefixAndCentury(): void
    {
        $this->assertSame('260905-0012', IncomingMessageCode::short('MSG-20260905-0012'));
    }

    public function testLongerSequenceIsKeptWhole(): void
    {
        $this->assertSame('260905-10001', IncomingMessageCode::short('MSG-20260905-10001'));
    }

    public function testSeedCodeIsReturnedUnchanged(): void
    {
        $this->assertSame('TEST-MSG-0001', IncomingMessageCode::short('TEST-MSG-0001'));
    }

    public function testExportFallbackIsReturnedUnchanged(): void
    {
        $this->assertSame('MSG-17', IncomingMessageCode::short('MSG-17'));
    }

    public function testShortSequenceDoesNotMatchPattern(): void
    {
        $this->assertSame('MSG-20260905-012', IncomingMessageCode::short('MSG-20260905-012'));
    }

    public function testEmptyStringStaysEmpty(): void
    {
        $this->assertSame('', IncomingMessageCode::short(''));
    }
}
