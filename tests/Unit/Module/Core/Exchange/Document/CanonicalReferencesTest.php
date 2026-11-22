<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\CanonicalReferences;

/**
 * Tvar referencí v kanonickém dokladu (#166 D30–D33, D39): strana a položka
 * jen referencí, identifikující údaje, signál účtu, volby `_resolve`,
 * které pokyn nečte.
 */
final class CanonicalReferencesTest extends TestCase
{
    public function testExpandTurnsScalarPartyAndItemIntoObjectWithRef(): void
    {
        $canonical = CanonicalReferences::expand([
            'supplier'     => '@F00001',
            'customer'     => ['ref' => 7, 'name' => 'Odběratel'],
            'balanceParty' => 42,
            'rows'         => [
                ['item' => '@K-001'],
                ['item' => 18],
                ['item' => ['name' => 'Text']],
                ['description' => 'bez položky'],
            ],
        ]);

        $this->assertSame(['ref' => '@F00001'], $canonical['supplier']);
        $this->assertSame(['ref' => 7, 'name' => 'Odběratel'], $canonical['customer']);
        $this->assertSame(['ref' => 42], $canonical['balanceParty']);
        $this->assertSame([['ref' => '@K-001'], ['ref' => 18], ['name' => 'Text'], null], array_map(
            static fn (array $row): ?array => $row['item'] ?? null,
            $canonical['rows'],
        ));
        $this->assertSame($canonical, CanonicalReferences::expand($canonical), 'idempotentní');
    }

    public function testIdentifyingDataOfPartyAndItem(): void
    {
        $this->assertTrue(CanonicalReferences::partyIdentifies(['name' => 'X']));
        $this->assertTrue(CanonicalReferences::partyIdentifies(['companyId' => '12345678']));
        $this->assertFalse(CanonicalReferences::partyIdentifies(['bankAccount' => ['iban' => 'CZ…'], 'name' => ' ']));
        $this->assertFalse(CanonicalReferences::partyIdentifies([]));

        $this->assertTrue(CanonicalReferences::itemIdentifies(['supplierCode' => 'A1']));
        $this->assertTrue(CanonicalReferences::itemIdentifies(['name' => 'Služba']));
        $this->assertFalse(CanonicalReferences::itemIdentifies(['description' => 'jen popis', 'ourCode' => 'K']), 'ourCode hlídá validátor jako signál');
    }

    public function testPartyDataBesideReference(): void
    {
        $this->assertFalse(CanonicalReferences::partyHasData(['ref' => 42]));
        $this->assertFalse(CanonicalReferences::partyHasData(['ref' => 42, 'name' => null, 'address' => []]));
        $this->assertTrue(CanonicalReferences::partyHasData(['ref' => 42, 'name' => 'X']));
    }

    public function testAccountSignalIsABareNumber(): void
    {
        $this->assertTrue(CanonicalReferences::isAccountSignal('518100'));
        $this->assertFalse(CanonicalReferences::isAccountSignal('@518100'));
        $this->assertFalse(CanonicalReferences::isAccountSignal(9));
        $this->assertFalse(CanonicalReferences::isAccountSignal(''));
        $this->assertFalse(CanonicalReferences::isAccountSignal(null));
    }

    /** D32: pokyn nečte volby, které určují referenci; ostatní bloky `_resolve` zůstávají. */
    public function testReferencePinsAreRemovedAndOtherBlocksKept(): void
    {
        $canonical = CanonicalReferences::withoutReferencePins(['_resolve' => [
            'supplier'     => ['userAction' => 'useExisting:42'],
            'supplierBank' => ['userAction' => 'create'],
            'correction'   => ['userAction' => 'useExisting:77'],
            'partner'      => ['userAction' => 'useExisting:5'],
            'vat'          => ['mode' => ['userAction' => 'none']],
            'contentTag'   => ['tag' => 'it.software'],
            'rows'         => [
                ['index' => 0, 'item' => ['userAction' => 'useExisting:18'], 'partner' => ['userAction' => 'useExisting:5'], 'enrichment' => ['source' => 'history']],
            ],
        ]]);

        $this->assertSame([
            'partner'    => ['userAction' => 'useExisting:5'],
            'vat'        => ['mode' => ['userAction' => 'none']],
            'contentTag' => ['tag' => 'it.software'],
            'rows'       => [
                ['index' => 0, 'partner' => ['userAction' => 'useExisting:5'], 'enrichment' => ['source' => 'history']],
            ],
        ], $canonical['_resolve']);
        $this->assertSame(['docType' => 'x'], CanonicalReferences::withoutReferencePins(['docType' => 'x']));
    }
}
