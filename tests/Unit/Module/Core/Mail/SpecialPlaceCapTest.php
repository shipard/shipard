<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\SpecialPlaceSupply;
use Shipard\Module\Core\Exchange\Enrich\ItemContentTags;
use Shipard\Module\Core\Mail\AnalysisConfidenceResolver;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\OutOfScopeHarness;

/**
 * Strop pásma u zvláštního místa plnění (#89 D17) proti rozhodnutí applieru:
 * podmínka stropu smí být širší, **nikdy užší**. Nad canonicaly z
 * {@see \Shipard\Tests\Unit\Module\Core\Exchange\Document\DocumentApplierOutOfScopeTest}
 * (skutečný applier, skutečná taxonomie štítků) — každý, u kterého applier
 * rozhodne `specialPlace`, musí strop dostat. Platí i pro `special` ze
 * štítků položky pokrytého řádku (D19, D20): applier i strop čtou štítky
 * položek z téže databáze.
 */
final class SpecialPlaceCapTest extends TestCase
{
    use OutOfScopeHarness;

    /**
     * Varianty dokladu: payload a očekávaný strop. `true` = strop platí,
     * `false` = neplatí; co rozhodne applier, zjišťuje test sám.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    private function variants(): array
    {
        $special = $this->specialPlacePayload();

        $reverseCharge = $special;
        $reverseCharge['vat']['reverseCharge'] = true;

        // AI dala místo plnění špatně — applier ho odvodí z prefixu DIČ.
        $placeDomestic = $special;
        $placeDomestic['vat']['place'] = 'domestic';

        $placeMissing = $special;
        unset($placeMissing['vat']['place']);

        $withCustomer = $placeDomestic;
        $withCustomer['customer'] = ['name' => 'Odběratel s.r.o.', 'country' => 'CZ', 'vatId' => 'CZ12345678'];

        $thirdCountry = $special;
        unset($thirdCountry['supplier']['vatId']);
        $thirdCountry['supplier']['country'] = 'CH';
        $thirdCountry['supplier']['address']['country'] = 'CH';
        $thirdCountry['vat']['place'] = 'thirdCountry';

        $domesticTagged = $this->happyPayload();
        $domesticTagged['_resolve']['contentTag'] = ['tag' => 'travel.accommodation', 'tagSource' => 'llm', 'rowExceptions' => []];

        $combined = $this->coveredPayload(['CEST', 'CEST']);
        unset($combined['rows'][0]['item']['ourCode']);
        $combined['_resolve']['contentTag'] = [
            'tag'           => null,
            'rowExceptions' => [['rowIndex' => 0, 'tag' => 'travel.accommodation']],
        ];

        $coveredPlaceDomestic = $this->coveredPayload(['CEST', 'CEST']);
        $coveredPlaceDomestic['vat']['place'] = 'domestic';

        $domesticCovered = $this->happyPayload();
        $domesticCovered['rows'][0]['item']['ourCode'] = 'CEST';
        $domesticCovered['rows'][0]['vat'] = ['code' => null, 'pct' => 0];

        return [
            'zvláštní místo plnění'                    => [$special, true],
            'i s příznakem přenesení'                  => [$reverseCharge, true],
            'místo z AI tuzemsko, DIČ dodavatele DE'   => [$placeDomestic, true],
            'bez místa plnění, DIČ dodavatele DE'      => [$placeMissing, true],
            'odběratel s českým DIČ'                   => [$withCustomer, true],
            'třetí země bez DIČ'                       => [$thirdCountry, true],
            'část řádků special'                       => [
                $this->specialPlacePayload(rowExceptions: [['rowIndex' => 1, 'tag' => 'it.software']]),
                false,
            ],
            'běžná služba z EU'                        => [$this->specialPlacePayload(tag: 'it.software'), false],
            'bez štítku'                               => [$this->specialPlacePayload(tag: null), false],
            'zahraniční DPH'                           => [$this->hotelPayload(), false],
            'tuzemský doklad se štítkem special'       => [$domesticTagged, false],
            // Opakovaný dodavatel: řádky pokryté položkami, bez bloku štítku (D19).
            'special ze štítků položky'                => [$this->coveredPayload(['CEST', 'CEST']), true],
            'položka se štítkem bez určení'            => [$this->coveredPayload(['CEST', 'MYTO']), true],
            'štítek z bloku i štítky položky'          => [$combined, true],
            'položka special, místo z AI tuzemsko'     => [$coveredPlaceDomestic, true],
            'nejednoznačná položka'                    => [$this->coveredPayload(['CEST', 'OSTAT']), false],
            'část řádků special z položky'             => [$this->coveredPayload(['CEST', 'SOFT']), false],
            'položka bez štítků'                       => [$this->coveredPayload(['NEZNAMA', 'NEZNAMA']), false],
            'tuzemský doklad s položkou special'       => [$domesticCovered, false],
        ];
    }

    public function testCapIsNeverNarrowerThanApplierDecision(): void
    {
        $db = $this->db(self::TAGGED_ITEMS);
        $applier = $this->buildApplier($db);
        $taxonomy = $this->contentTaxonomy();
        $specialPlace = 0;

        foreach ($this->variants() as $name => [$payload, $expectedCap]) {
            $reason = $applier->preview($payload)->canonical['_resolve']['vat']['mode']['reason'] ?? null;
            $itemTags = ItemContentTags::forCodes($db, SpecialPlaceSupply::itemCodes($payload));
            $cap = AnalysisConfidenceResolver::capsSpecialPlace($payload, $taxonomy, $itemTags);

            if ($reason === 'specialPlace') {
                $specialPlace++;
                $this->assertTrue($cap, "{$name}: applier rozhodl specialPlace, strop chybí");
            }
            $this->assertSame($expectedCap, $cap, $name);
        }

        // Pojistka proti prázdnému testu: applier v deseti variantách rozhoduje
        // specialPlace — šest štítkem z bloku, čtyři (i) štítky položky.
        $this->assertSame(10, $specialPlace);
    }

    public function testEmptyTaxonomyMeansNoCapAndNoApplierDecision(): void
    {
        // Bez taxonomie applier zvláštní místo plnění neohlásí — strop ho
        // proto nehlídá; obě strany čtou tentýž cfgItem.
        $this->assertFalse(AnalysisConfidenceResolver::capsSpecialPlace($this->specialPlacePayload(), [], []));
    }

    /**
     * Strop D21 proti applieru: řádek s nejednoznačnou položkou a prázdným
     * kódem dostane od applieru varování `vat_special_place_ambiguous`
     * a karta strop. Opačný směr záměrně neplatí vždy — řádek s kódem
     * z historie strop nemá, i kdyby applier kód nahradil odvozeným.
     */
    public function testAmbiguousItemCapMatchesApplierWarning(): void
    {
        $db = $this->db(self::TAGGED_ITEMS);
        $applier = $this->buildApplier($db);
        $taxonomy = $this->contentTaxonomy();

        $keptCode = $this->coveredPayload(['OSTAT', 'OSTAT']);
        $keptCode['rows'][0]['vat']['code'] = 'cz-217';
        $keptCode['rows'][1]['vat']['code'] = 'cz-217';
        $withSupplierVat = $this->hotelPayload();
        $withSupplierVat['rows'][0]['item']['ourCode'] = 'OSTAT';
        $domestic = $this->happyPayload();
        $domestic['rows'][0]['item']['ourCode'] = 'OSTAT';
        $domestic['rows'][0]['vat'] = ['code' => null, 'pct' => 21];

        $variants = [
            'nejednoznačná položka bez kódu'  => [$this->coveredPayload(['OSTAT', 'SOFT']), true],
            'nejednoznačná i special položka' => [$this->coveredPayload(['CEST', 'OSTAT']), true],
            'kód ponechaný z historie'        => [$keptCode, false],
            'položka jen special'             => [$this->coveredPayload(['CEST', 'CEST']), false],
            'běžná položka'                   => [$this->coveredPayload(['SOFT', 'SOFT']), false],
            'doklad s daní dodavatele'        => [$withSupplierVat, false],
            'tuzemský doklad'                 => [$domestic, false],
        ];
        foreach ($variants as $name => [$payload, $expected]) {
            $issues = $applier->preview($payload)->canonical['_resolve']['issues'] ?? [];
            $warned = in_array('vat_special_place_ambiguous', array_column($issues, 'code'), true);
            $itemTags = ItemContentTags::forCodes($db, SpecialPlaceSupply::itemCodes($payload));

            $this->assertSame($expected, $warned, "{$name}: varování applieru");
            $this->assertSame(
                $expected,
                AnalysisConfidenceResolver::capsAmbiguousItem($payload, $taxonomy, $itemTags),
                "{$name}: strop",
            );
        }
    }
}
