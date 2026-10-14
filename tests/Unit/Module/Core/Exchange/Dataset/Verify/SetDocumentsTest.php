<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Verify;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\Verify\SetDocument;
use Shipard\Module\Core\Exchange\Dataset\Verify\SetDocuments;

class SetDocumentsTest extends TestCase
{
    private static function doc(string $id, ?string $currency, ?int $docId = null, ?int $resolution = null, ?int $docState = 40): SetDocument
    {
        return new SetDocument($id, crc32($id), '2026-08-26 08:00:00', 5, $resolution,
            $currency === null ? null : ['currency' => $currency], $docId, $docId !== null ? $docState : null, null);
    }

    public function testStates(): void
    {
        $open = self::doc('A', 'CZK');
        $this->assertTrue($open->hasOpenProposal());
        $this->assertFalse($open->isApplied());
        $this->assertSame('CZK', $open->currency());

        $applied = self::doc('B', 'czk ', 42, 40);
        $this->assertFalse($applied->hasOpenProposal());
        $this->assertTrue($applied->isApplied());
        $this->assertSame('CZK', $applied->currency(), 'měna velkými bez mezer');

        $rejected = self::doc('C', 'CZK', null, 50);
        $this->assertFalse($rejected->hasOpenProposal());

        $trashed = self::doc('D', 'CZK', 43, 40, 90);
        $this->assertFalse($trashed->isApplied(), 'doklad v Koši není použitý');

        $noProposal = new SetDocument('E', 1, '2026-08-26 08:00:00', null, null, null, null, null, null);
        $this->assertFalse($noProposal->hasOpenProposal());
        $this->assertNull($noProposal->currency());
    }

    public function testSkippedForCurrency(): void
    {
        $docs = [
            self::doc('CZ', 'CZK'),
            self::doc('EUR', 'EUR'),
            self::doc('USD-APPLIED', 'USD', 7),
            self::doc('NONE', null),
        ];
        $this->assertSame(['EUR' => SetDocuments::SKIP_FOREIGN_CURRENCY], SetDocuments::skippedForCurrency($docs, 'czk'));
        $this->assertSame(['CZ' => SetDocuments::SKIP_FOREIGN_CURRENCY], SetDocuments::skippedForCurrency($docs, 'EUR'));
    }
}
