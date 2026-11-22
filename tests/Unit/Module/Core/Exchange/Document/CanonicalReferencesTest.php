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

    /** D33, D39: položku řádku návrhu čtou obohacení, pásmo karty i zvláštní místo plnění z obou tvarů. */
    public function testRowItemIsReadFromReferenceAndFromStoredSignal(): void
    {
        $this->assertTrue(CanonicalReferences::rowItemIsSet(['item' => ['ref' => '@K-001']]));
        $this->assertTrue(CanonicalReferences::rowItemIsSet(['item' => ['ref' => 18]]));
        $this->assertTrue(CanonicalReferences::rowItemIsSet(['item' => ['ourCode' => 'K-001']]));
        $this->assertFalse(CanonicalReferences::rowItemIsSet(['item' => ['ref' => null, 'ourCode' => ' ', 'name' => 'X']]));
        $this->assertFalse(CanonicalReferences::rowItemIsSet(['description' => 'bez položky']));

        $this->assertSame('K-001', CanonicalReferences::rowItemCode(['item' => ['ref' => '@K-001']]));
        $this->assertSame('K-001', CanonicalReferences::rowItemCode(['item' => ['ref' => '@code:K-001']]));
        $this->assertSame('K-001', CanonicalReferences::rowItemCode(['item' => ['ourCode' => ' K-001 ']]));
        $this->assertNull(CanonicalReferences::rowItemCode(['item' => ['ref' => 18, 'ourCode' => 'JINY']]), 'reference id kód nedává a má přednost');
        $this->assertNull(CanonicalReferences::rowItemCode(['item' => ['ref' => '@ean:8590000000017']]));
        $this->assertNull(CanonicalReferences::rowItemCode(['item' => ['name' => 'X']]));
    }

    public function testSuggestedItemAndAccountAreWrittenAsReferencesAndRevertedInBothShapes(): void
    {
        $row = CanonicalReferences::suggestItem(['item' => ['name' => 'Internet', 'ourCode' => null]], 'NET500');
        $row = CanonicalReferences::suggestAccount($row, '518001');
        $this->assertSame(['item' => ['name' => 'Internet', 'ourCode' => null, 'ref' => '@NET500'], 'account' => '@518001'], $row);
        $this->assertTrue(CanonicalReferences::rowAccountIsSet($row));

        $reverted = CanonicalReferences::revertSuggestedAccount(CanonicalReferences::revertSuggestedItem($row, 'NET500'), '518001');
        $this->assertNull($reverted['item']['ref']);
        $this->assertNull($reverted['account']);
        $this->assertFalse(CanonicalReferences::rowItemIsSet($reverted));

        // Uložený návrh starým tvarem.
        $old = ['item' => ['ourCode' => 'NET500'], 'account' => '518001'];
        $reverted = CanonicalReferences::revertSuggestedAccount(CanonicalReferences::revertSuggestedItem($old, 'NET500'), '518001');
        $this->assertSame(['item' => ['ourCode' => null], 'account' => null], $reverted);

        // Hodnota, kterou mezitím někdo změnil, zůstává.
        $changed = ['item' => ['ref' => '@JINA'], 'account' => '@501000'];
        $this->assertSame($changed, CanonicalReferences::revertSuggestedAccount(CanonicalReferences::revertSuggestedItem($changed, 'NET500'), '518001'));
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
