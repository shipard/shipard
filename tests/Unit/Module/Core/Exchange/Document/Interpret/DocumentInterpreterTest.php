<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document\Interpret;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\Interpret\InterpretedDocument;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\InterpreterFixture;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\OutOfScopeHarness;

/**
 * Hotový doklad z interpretace (#166 D52, U3, U4): tvar a úplnost payloadu,
 * `pending`, `rowMap`, `learn`. Že pokyn nad hotovým dokladem uloží totéž
 * co dnešní cesta návrhu, hlídá stínový test.
 */
final class DocumentInterpreterTest extends TestCase
{
    use OutOfScopeHarness;
    use InterpreterFixture;

    private function interpret(array $payload, mixed ...$applierArgs): InterpretedDocument
    {
        return self::interpreterOf($this->buildApplier(...$applierArgs))->interpret($payload);
    }

    public function testDomesticInvoiceBecomesCompleteInstruction(): void
    {
        $result = $this->interpret($this->happyPayload());
        $doc = $result->document;

        // Reference číslem; data strany zůstávají vedle `ref`.
        $this->assertSame(42, $doc['supplier']['ref']);
        $this->assertSame('Dodavatel s.r.o.', $doc['supplier']['name']);
        $this->assertSame(['ref' => 18], $doc['rows'][0]['item']);

        // Hodnoty DPH uvedené, signál země registrace pryč.
        $this->assertSame('fromBase', $doc['vat']['mode']);
        $this->assertSame('domestic', $doc['vat']['place']);
        $this->assertSame(5, $doc['vat']['registration']);
        $this->assertArrayNotHasKey('registrationCountry', $doc['vat']);
        $this->assertSame('declared', $doc['vat']['recapSource']);
        $this->assertSame('cz-110', $doc['vatRecap'][0]['vatCode']);

        // `totals` jen zaokrouhlení — žádné částky jako kontrolní součet.
        $this->assertSame(['totalRoundingAuto' => false, 'totalRoundingMode' => 'cent'], $doc['totals']);

        // Co má v pokynu výchozí hodnotu, je uvedené výslovně.
        $this->assertSame('bankTransfer', $doc['payment']['method']);
        $this->assertArrayHasKey('ownBankAccount', $doc['payment']);
        $this->assertNull($doc['payment']['ownBankAccount']);
        $row = $doc['rows'][0];
        foreach (['operation', 'unit', 'quantity', 'unitPrice', 'description'] as $key) {
            $this->assertArrayHasKey($key, $row, $key);
        }
        $this->assertSame('hr', $row['unit']);
        $this->assertSame('Konzultace — Hodinová sazba senior konzultanta', $row['description']);
        $this->assertSame(['code' => 'cz-110'], $row['vat']);

        $this->assertArrayNotHasKey('_resolve', $doc);
        $this->assertSame([], $result->pending);
        $this->assertSame([0], $result->rowMap);
        $this->assertNull($result->decisionError);
        $this->assertFalse($result->hasErrors());
    }

    public function testInstructionOverFinishedDocumentFillsNoDefaults(): void
    {
        $applier = $this->buildApplier();
        $result = self::interpreterOf($applier)->interpret($this->happyPayload());

        $preview = $applier->preview($result->document);

        $this->assertTrue($preview->success);
        // D52: hotový doklad je úplný — výchozí hodnoty pokynu se neuplatní.
        $this->assertSame([], $preview->canonical['_resolve']['defaults']);
        $errors = array_filter(
            $preview->canonical['_resolve']['issues'],
            static fn (array $issue): bool => $issue['severity'] === 'error',
        );
        $this->assertSame([], array_values($errors));
    }

    public function testReportCarriesProposalResolveBlocksAndIssues(): void
    {
        $result = $this->interpret($this->happyPayload());

        $this->assertSame('matched', $result->report['supplier']['status']);
        $this->assertSame(0, $result->report['rows'][0]['index']);
        $this->assertArrayHasKey('vatCode', $result->report['rows'][0]);
        $this->assertSame('fromBase', $result->report['vat']['mode']['value']);
        $this->assertArrayHasKey('issues', $result->report);
        $this->assertArrayHasKey('summary', $result->report);
        // `computed` počítá pokyn nad hotovým dokladem, ne interpretace.
        $this->assertArrayNotHasKey('computed', $result->report);
    }

    public function testForeignVatDocumentCarriesGrossPricesAndNoVat(): void
    {
        $result = $this->interpret($this->hotelPayload());
        $doc = $result->document;

        $this->assertSame('none', $doc['vat']['mode']);
        $this->assertSame('intracom', $doc['vat']['place']);
        $this->assertSame('computed', $doc['vat']['recapSource']);
        $this->assertArrayNotHasKey('vatRecap', $doc);
        // Ceny řádků včetně daně dodavatele, z celkové ceny.
        $this->assertSame(214.0, $doc['rows'][0]['totalPrice']);
        $this->assertNull($doc['rows'][0]['unitPrice']);
        $this->assertSame('fromTotal', $doc['rows'][0]['priceCalcMode']);
        $this->assertSame(35.7, $doc['rows'][1]['totalPrice']);
        // Doklad Bez DPH kódy nenese — výslovně.
        $this->assertSame(['code' => null], $doc['rows'][0]['vat']);
    }

    public function testNonPayerDocumentHasNoRegistration(): void
    {
        $result = $this->interpret($this->happyPayload(), db: $this->dbWithoutVatRegistration());

        $this->assertSame('none', $result->document['vat']['mode']);
        $this->assertNull($result->document['vat']['registration']);
        $this->assertArrayNotHasKey('vatRecap', $result->document);
    }

    public function testVatChoicesEndUpAsValues(): void
    {
        $payload = $this->withPins($this->happyPayload(), ['rows[0].vatCode' => 'useCode:cz-111']);

        $result = $this->interpret($payload);

        $this->assertSame('cz-111', $result->document['rows'][0]['vat']['code']);
        $this->assertArrayNotHasKey('_resolve', $result->document);
    }

    public function testSkippedRowIsLeftOutAndRowMapPointsToOriginal(): void
    {
        $payload = $this->hotelPayload();
        $payload['_resolve']['rows'][0]['userAction'] = 'skip';

        $result = $this->interpret($payload);

        $this->assertCount(1, $result->document['rows']);
        $this->assertSame(35.7, $result->document['rows'][0]['totalPrice']);
        $this->assertSame([1], $result->rowMap);
        // Zpráva míří do původního payloadu — nese oba řádky.
        $this->assertCount(2, $result->report['rows']);
    }

    public function testNewSupplierAndItemArePendingAndGetReferencesAfterCreation(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate(['full_name' => 'Dodavatel s.r.o.', 'company_id' => '12345678']));
        $party->method('resolveSelfParty')->willReturn(ResolveResult::matched(1, 'self'));
        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::canCreate(['name' => 'Konzultace']));
        $payload = $this->happyPayload();
        $payload['applyOptions'] = ['autoCreateMode' => 'liberal', 'targetDocState' => 10, 'interpret' => true];

        $result = $this->interpret($payload, party: $party, item: $item);

        // Zakládaný záznam zůstává objektem s daty bez `ref`.
        $this->assertArrayNotHasKey('ref', $result->document['supplier']);
        $this->assertSame('Dodavatel s.r.o.', $result->document['supplier']['name']);
        $this->assertSame('Konzultace', $result->document['rows'][0]['item']['name']);
        $this->assertArrayNotHasKey('ref', $result->document['rows'][0]['item']);
        $this->assertArrayNotHasKey('ourCode', $result->document['rows'][0]['item']);
        // Volby interpretace do pokynu nepatří, cílový stav ano.
        $this->assertSame(['targetDocState' => 10], $result->document['applyOptions']);

        $pending = array_column($result->pending, null, 'key');
        $this->assertSame(['supplier', 'rows.0.item'], array_keys($pending));
        $this->assertSame(InterpretedDocument::PENDING_PERSON, $pending['supplier']['kind']);
        $this->assertSame('supplier.ref', $pending['supplier']['ref']);
        $this->assertSame(['full_name' => 'Dodavatel s.r.o.', 'company_id' => '12345678'], $pending['supplier']['payload']);
        $this->assertSame('rows.0.item.ref', $pending['rows.0.item']['ref']);
        $this->assertSame(0, $pending['rows.0.item']['row']);
        $this->assertNull($result->decisionError);

        // Kód dodavatele se naučí, až oba záznamy vzniknou.
        $this->assertNull($result->learn['supplier']);
        $this->assertSame('KONZ-001', $result->learn['codes'][0]['supplierCode']);
        $this->assertNull($result->learn['codes'][0]['item']);

        $completed = $result->withCreated(['supplier' => 501, 'rowItems' => [0 => 902]]);
        $this->assertSame(501, $completed['supplier']['ref']);
        $this->assertSame(902, $completed['rows'][0]['item']['ref']);
    }

    public function testCreatedItemReferenceFollowsShiftedRows(): void
    {
        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::canCreate(['name' => 'Snídaně']));
        $payload = $this->hotelPayload();
        $payload['applyOptions'] = ['autoCreateMode' => 'liberal'];
        $payload['_resolve']['rows'][0]['userAction'] = 'skip';

        $result = $this->interpret($payload, item: $item);

        // Původní řádek 1 je v hotovém dokladu na pozici 0.
        $this->assertSame('rows.0.item.ref', $result->pending[0]['ref']);
        $this->assertSame(1, $result->pending[0]['row']);
        $completed = $result->withCreated(['rowItems' => [1 => 77]]);
        $this->assertSame(77, $completed['rows'][0]['item']['ref']);
    }

    public function testUndecidedReferenceIsDecisionError(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate(['full_name' => 'Neznámý']));
        $party->method('resolveSelfParty')->willReturn(ResolveResult::matched(1, 'self'));

        $result = $this->interpret($this->happyPayload(), party: $party);

        $this->assertSame('unresolved_required', $result->decisionError['code']);
        $this->assertSame([], $result->pending);
        $this->assertTrue($result->hasErrors());
        // Náhled rozhodnutí nehlásí — nález patří až uložení.
        $this->assertNotContains('unresolved_required', array_column($result->report['issues'], 'code'));
        $this->assertContains('unresolved_required', array_column($result->decisionIssues, 'code'));
    }

    public function testSkippedPartyLosesIdentity(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate(['full_name' => 'Neznámý']));
        $party->method('resolveSelfParty')->willReturn(ResolveResult::matched(1, 'self'));
        $payload = $this->happyPayload();
        $payload['_resolve']['supplier']['userAction'] = 'skip';

        $result = $this->interpret($payload, party: $party);

        $this->assertNull($result->decisionError);
        $this->assertArrayNotHasKey('name', $result->document['supplier']);
        $this->assertArrayNotHasKey('companyId', $result->document['supplier']);
        // Bankovní spojení dokladu zůstává — jde do sloupců hlavičky.
        $this->assertArrayHasKey('bankAccount', $result->document['supplier']);
    }

    /** D60: jednotka bez systémového kódu jde do hotového dokladu číslem. */
    public function testUnitWithoutSystemCodeGoesById(): void
    {
        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(9, 'shortcut'));
        $code = null;
        $unit->method('systemCodeById')->willReturnCallback(static function () use (&$code): ?string {
            return $code;
        });

        $this->assertSame(9, $this->interpret($this->happyPayload(), unit: $unit)->document['rows'][0]['unit']);

        $code = 'box';
        $this->assertSame('box', $this->interpret($this->happyPayload(), unit: $unit)->document['rows'][0]['unit']);
    }

    /** D61: o vazbě na účet partnera rozhodla interpretace — pokyn ji dostane výslovně. */
    public function testPartnerBankAccountGoesByReference(): void
    {
        // Účet nalezený podle čísla (účet zvolený uživatelem jde týmž polem plánu).
        $this->assertSame(7, $this->interpret($this->happyPayload())->document['supplier']['bankAccount']['ref']);

        // Účet, který se založí: reference přijde po založení.
        $bank = $this->bankAccountResolverMock();
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::canCreate(['iban' => 'CZ6508000000001234567890', 'person' => 42]));
        $payload = $this->happyPayload();
        $payload['applyOptions'] = ['autoCreateMode' => 'liberal'];
        $result = $this->interpret($payload, bank: $bank);
        $this->assertArrayHasKey('ref', $result->document['supplier']['bankAccount']);
        $this->assertNull($result->document['supplier']['bankAccount']['ref']);
        $pending = array_column($result->pending, null, 'key');
        $this->assertSame('supplier.bankAccount.ref', $pending['supplierBank']['ref']);
        $this->assertSame(55, $result->withCreated(['supplierBank' => 55])['supplier']['bankAccount']['ref']);

        // Účet vynechaný uživatelem: výslovně bez vazby.
        $payload = $this->happyPayload();
        $payload['_resolve']['supplierBank']['userAction'] = 'skip';
        $skipped = $this->interpret($payload)->document['supplier']['bankAccount'];
        $this->assertArrayHasKey('ref', $skipped);
        $this->assertNull($skipped['ref']);
    }
}
