<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\AnalysisConfidenceResolver;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\OutOfScopeHarness;

/**
 * Strop pásma u zvláštního místa plnění (#89 D17) proti rozhodnutí applieru:
 * podmínka stropu smí být širší, **nikdy užší**. Nad canonicaly z
 * {@see \Shipard\Tests\Unit\Module\Core\Exchange\Document\DocumentApplierOutOfScopeTest}
 * (skutečný applier, skutečná taxonomie štítků) — každý, u kterého applier
 * rozhodne `specialPlace`, musí strop dostat.
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
        ];
    }

    public function testCapIsNeverNarrowerThanApplierDecision(): void
    {
        $applier = $this->buildApplier();
        $taxonomy = $this->contentTaxonomy();
        $specialPlace = 0;

        foreach ($this->variants() as $name => [$payload, $expectedCap]) {
            $reason = $applier->preview($payload)->canonical['_resolve']['vat']['mode']['reason'] ?? null;
            $cap = AnalysisConfidenceResolver::capsSpecialPlace($payload, $taxonomy);

            if ($reason === 'specialPlace') {
                $specialPlace++;
                $this->assertTrue($cap, "{$name}: applier rozhodl specialPlace, strop chybí");
            }
            $this->assertSame($expectedCap, $cap, $name);
        }

        // Pojistka proti prázdnému testu: applier v šesti variantách rozhoduje specialPlace.
        $this->assertSame(6, $specialPlace);
    }

    public function testEmptyTaxonomyMeansNoCapAndNoApplierDecision(): void
    {
        // Bez taxonomie applier zvláštní místo plnění neohlásí — strop ho
        // proto nehlídá; obě strany čtou tentýž cfgItem.
        $this->assertFalse(AnalysisConfidenceResolver::capsSpecialPlace($this->specialPlacePayload(), []));
    }
}
