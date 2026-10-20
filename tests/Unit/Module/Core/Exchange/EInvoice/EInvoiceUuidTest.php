<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\EInvoice;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceUuid;

/** UUIDv5 e-faktury (#119 D7): známý vektor RFC, verze a varianta, determinismus. */
class EInvoiceUuidTest extends TestCase
{
    private const DNS_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    public function testKnownVector(): void
    {
        $this->assertSame(
            '2ed6657d-e927-568b-95e1-2665a8aea6a2',
            EInvoiceUuid::fromName('www.example.com', self::DNS_NAMESPACE),
        );
    }

    public function testVersionAndVariant(): void
    {
        $uuid = EInvoiceUuid::fromName('corpus/cz/vat-payer/0102-domestic-goods');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        // Shoda s referenční implementací (python uuid.uuid5 nad jmenným prostorem Shipardu).
        $this->assertSame('03f8260d-4689-5cee-abab-e361a124160c', $uuid);
    }

    public function testDeterministicAndDistinct(): void
    {
        $this->assertSame(EInvoiceUuid::fromName('a'), EInvoiceUuid::fromName('a'));
        $this->assertNotSame(EInvoiceUuid::fromName('a'), EInvoiceUuid::fromName('b'));
        $this->assertNotSame(EInvoiceUuid::fromName('a'), EInvoiceUuid::fromName('a', self::DNS_NAMESPACE));
    }

    public function testRejectsInvalidNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EInvoiceUuid::fromName('a', 'not-a-uuid');
    }
}
