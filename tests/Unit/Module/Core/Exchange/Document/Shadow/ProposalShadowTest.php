<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document\Shadow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\DocumentResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\InterpreterFixture;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\OutOfScopeHarness;

/**
 * Stínový test (#166 D57, U5) nad fixturami unit testů návrhu: pro každý
 * vstup dá dnešní cesta návrhu a nová cesta „pokyn nad hotovým dokladem“
 * totéž — odpověď náhledu i data předaná k uložení ({@see ShadowRunner}).
 *
 * Dočasný strážce přepnutí: zaniká se starou cestou v
 * `tasks/doc-interpretation-switch.md`.
 */
final class ProposalShadowTest extends TestCase
{
    use OutOfScopeHarness;
    use InterpreterFixture;

    /**
     * Známý rozdíl (poznámky tasku): návrh v režimu Bez DPH, který applier
     * za doklad mimo DPH ani za doklad neplátce **nepovažuje**, dnes uloží
     * řádky s kódem DPH; pokyn doklad Bez DPH ukládá bez kódů (#166 D20).
     * **Přijatý** (rozhodnuto 2026-11-23) — po přepnutí návrhu na cestu
     * interpretace → pokyn je správně chování nové cesty.
     */
    private const GAP_NONE_WITH_CODES = 'známý rozdíl: režim Bez DPH s kódy řádků';

    private const KNOWN_GAPS = [
        self::GAP_NONE_WITH_CODES => [
            'preview' => [
                'náhled._resolve.computed.vatRecap.0: dnes {"vatCode":"cz-114","vatPct":0.0,"base":10330.6,"tax":0.0,"total":10330.6,"isReversePair":false} × nově chybí',
            ],
            'apply' => [
                'doklad.rows.0.vat_code: dnes "cz-114" × nově chybí',
                'doklad.rows.0.vat_pct: dnes 0.0 × nově chybí',
            ],
        ],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        foreach ([
            'tuzemská faktura s převzatou rekapitulací',
            'rekapitulace bez kódů — kód z řádků',
            'rekapitulace nekonzistentní — přepočet z řádků',
            'doklad mimo české DPH — daň dodavatele',
            'zvláštní místo plnění ze štítku',
            'služba z EU — samovyměření',
            'zdroj dat neplátce',
            'zdroj dat neplátce — doklad s daní dodavatele',
            'dodavatel neplátce v tuzemsku',
            self::GAP_NONE_WITH_CODES,
            'volba kódu DPH řádku',
            'volba režimu Bez DPH',
            'volba místa plnění',
            'vynechaný řádek',
            'odhad zaokrouhlení na koruny',
            'výslovný mód zaokrouhlení',
            'cizí měna s kurzem',
            'bez způsobu úhrady a bez jednotky',
            'řádek s výslovným pohybem a kódem bez sazby',
            'zakládaný dodavatel',
            'nerozhodnutý dodavatel',
            'dodavatel bez osoby',
            'chyba validace — bez data vystavení',
            'vydaná faktura — registrace podle země',
            'vydaná faktura bez částek a rekapitulace',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('cases')]
    public function testProposalAndInstructionOverFinishedDocumentAgree(string $case): void
    {
        [$payload, $applier] = $this->scenario($case);
        $shadow = self::shadowOf(
            $applier,
            fn (): array => $this->transformedHeadsData,
            function (): void {
                $this->transformedHeadsData = [];
            },
        );

        $gap = self::KNOWN_GAPS[$case] ?? ['preview' => [], 'apply' => []];
        $this->assertSame($gap['preview'], $shadow->preview($payload), 'náhled');
        $this->assertSame($gap['apply'], $shadow->apply($payload)['differences'], 'uložení');
    }

    /**
     * @return array{array<string, mixed>, \Shipard\Module\Core\Exchange\Document\DocumentApplier}
     */
    private function scenario(string $case): array
    {
        $happy = $this->happyPayload();
        switch ($case) {
            case 'tuzemská faktura s převzatou rekapitulací':
                return [$happy, $this->buildApplier()];

            case 'rekapitulace bez kódů — kód z řádků':
                $happy['vatRecap'][0]['vatCode'] = null;
                unset($happy['rows'][0]['vat']['code']);
                return [$happy, $this->buildApplier()];

            case 'rekapitulace nekonzistentní — přepočet z řádků':
                $happy['vatRecap'][0]['tax'] = 1000.00;
                return [$happy, $this->buildApplier()];

            case 'doklad mimo české DPH — daň dodavatele':
                return [$this->hotelPayload(), $this->buildApplier()];

            case 'zvláštní místo plnění ze štítku':
                return [$this->specialPlacePayload(), $this->buildApplier()];

            case 'služba z EU — samovyměření':
                return [$this->specialPlacePayload(tag: null), $this->buildApplier()];

            case 'zdroj dat neplátce':
                unset($happy['rows'][0]['computed']);
                $happy['rows'][0]['vat'] = ['code' => null, 'pct' => 0];
                $happy['vat']['mode'] = 'none';
                $happy['vatRecap'] = [];
                $happy['totals'] = ['totalAmount' => 10330.58];
                return [$happy, $this->buildApplier(db: $this->dbWithoutVatRegistration())];

            case 'zdroj dat neplátce — doklad s daní dodavatele':
                return [$happy, $this->buildApplier(db: $this->dbWithoutVatRegistration())];

            case 'dodavatel neplátce v tuzemsku':
                unset($happy['rows'][0]['computed']);
                $happy['rows'][0]['vat'] = ['code' => null, 'pct' => 0];
                $happy['vat'] = ['mode' => 'none', 'place' => 'domestic'];
                $happy['vatRecap'] = [];
                $happy['totals'] = ['totalAmount' => 10330.58];
                return [$happy, $this->buildApplier()];

            case self::GAP_NONE_WITH_CODES:
                // Předloha říká Bez DPH, ale řádek nese daň dodavatele — není
                // to doklad od neplátce, režim zůstane, jak ho říká předloha.
                $happy['rows'][0]['vat'] = ['code' => null, 'pct' => 0];
                $happy['vat'] = ['mode' => 'none', 'place' => 'domestic'];
                $happy['vatRecap'] = [];
                $happy['totals'] = ['totalAmount' => 10330.58];
                return [$happy, $this->buildApplier()];

            case 'volba kódu DPH řádku':
                return [$this->withPins($happy, ['rows[0].vatCode' => 'useCode:cz-111']), $this->buildApplier()];

            case 'volba režimu Bez DPH':
                return [$this->withPins($this->specialPlacePayload(tag: null), ['vat.mode' => 'useValue:none']), $this->buildApplier()];

            case 'volba místa plnění':
                return [$this->withPins($this->specialPlacePayload(tag: null), ['vat.place' => 'useValue:thirdCountry']), $this->buildApplier()];

            case 'vynechaný řádek':
                $hotel = $this->hotelPayload();
                $hotel['_resolve']['rows'][0]['userAction'] = 'skip';
                return [$hotel, $this->buildApplier()];

            case 'odhad zaokrouhlení na koruny':
                $happy['totals']['totalAmount'] = 12500.00;
                $happy['rows'][0]['totalPrice'] = 10330.40;
                $happy['rows'][0]['unitPrice'] = 1033.04;
                $happy['vatRecap'][0] = ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 10330.40, 'tax' => 2169.38, 'total' => 12499.78, 'isReversePair' => false];
                $happy['totals']['totalBase'] = 10330.40;
                $happy['totals']['totalVat'] = 2169.38;
                return [$happy, $this->buildApplier()];

            case 'výslovný mód zaokrouhlení':
                $happy['totals']['totalRoundingMode'] = 'unitUp';
                return [$happy, $this->buildApplier()];

            case 'cizí měna s kurzem':
                $happy['currency'] = 'EUR';
                $happy['exchangeRate'] = 25.125;
                return [$happy, $this->buildApplier()];

            case 'bez způsobu úhrady a bez jednotky':
                unset($happy['payment']['method'], $happy['rows'][0]['unit'], $happy['rows'][0]['quantity']);
                return [$happy, $this->buildApplier()];

            case 'řádek s výslovným pohybem a kódem bez sazby':
                $happy['rows'][0]['operation'] = 'purchase.services';
                $happy['rows'][] = ['rowKind' => 'text', 'description' => 'Poznámka pod čarou'];
                return [$happy, $this->buildApplier()];

            case 'zakládaný dodavatel':
                $happy['applyOptions'] = ['autoCreateMode' => 'liberal'];
                $persons = $this->createMock(TransactionlessTableGateway::class);
                $persons->method('saveDocument')->willReturn(DocumentResult::ok(['id' => 501]));
                return [$happy, $this->buildApplier(
                    records: array_replace_recursive(self::REFERENCE_RECORDS, ['base_persons_persons' => ['person_id=F00501' => 501]]),
                    party: $this->newSupplierResolver(),
                    persons: $persons,
                )];

            case 'nerozhodnutý dodavatel':
                return [$happy, $this->buildApplier(party: $this->newSupplierResolver())];

            case 'dodavatel bez osoby':
                $happy['_resolve']['supplier']['userAction'] = 'skip';
                return [$happy, $this->buildApplier(party: $this->newSupplierResolver())];

            case 'chyba validace — bez data vystavení':
                unset($happy['dates']['issueDate']);
                return [$happy, $this->buildApplier()];

            case 'vydaná faktura — registrace podle země':
                return [self::issued($happy), $this->buildApplier()];

            case 'vydaná faktura bez částek a rekapitulace':
                $issued = self::issued($happy);
                unset($issued['totals'], $issued['vatRecap']);
                return [$issued, $this->buildApplier()];
        }
        throw new \LogicException($case);
    }

    /**
     * Vydaná faktura z přijaté: vlastní firma je dodavatel, protistrana
     * odběratel — návrh mimo větev odvozování (REST s `interpret: true`).
     *
     * @param array<string, mixed> $received
     * @return array<string, mixed>
     */
    private static function issued(array $received): array
    {
        $received['docType'] = 'invoiceIssued';
        $received['selfParty'] = 'supplier';
        $received['customer'] = array_diff_key($received['supplier'], ['bankAccount' => true]);
        $received['supplier'] = null;
        return $received;
    }

    private function newSupplierResolver(): PartyResolver
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::canCreate(['full_name' => 'Dodavatel s.r.o.', 'company_id' => '12345678']));
        $party->method('resolveSelfParty')->willReturn(ResolveResult::matched(1, 'self'));
        return $party;
    }
}
