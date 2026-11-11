<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Document\SpecialPlaceSupply;
use Shipard\Module\Core\Exchange\Document\SupplierVatPrices;
use Shipard\Module\Core\Exchange\Document\VatPlaceDerivation;
use Shipard\Module\Core\Exchange\Enrich\ItemContentTags;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Values\CanonicalValueNormalizer;
use Shipard\Module\Core\Exchange\Values\CanonicalValueValidator;

/**
 * Runtime resolver confidence pásem dokumentového návrhu: mapování
 * confidence na pásmo podle prahů profilu + strop podle pokrytí řádků
 * položkami (D7), podle nálezů hodnot (#118 D15), u dokladu se zvláštním
 * místem plnění (#89 D17, D20) a u řádku s nejednoznačnou položkou (#89
 * D21) — jedna metoda {@see capBand()} pro feed i detail zprávy. Pásmo NENÍ perzistentní stav
 * (D3 z mail-message-centric) — počítá se za běhu; používá dashboard
 * (kind karty), detail zprávy (badge) a preview. Nikam se nezapisuje.
 */
class AnalysisConfidenceResolver
{
    public const BAND_READY = 'ready';
    public const BAND_REVIEW = 'review';
    public const BAND_LOW = 'low';

    /**
     * Fallback prahy, když profil neexistuje nebo nemá confidence_thresholds.
     * ISDOC import tak funguje i v DS bez nakonfigurované AI.
     */
    public const DEFAULT_THRESHOLDS = ['ready' => 0.9, 'review' => 0.6];

    private const PROFILES_TABLE = 'core_mail_ai_profiles';

    /** cfgItem taxonomie štítků — tentýž, který čte `DocumentApplier`. */
    private const CONTENT_TAGS_CFG_ITEM = 'core.exchange.contentTags';

    /** `vat.place` mimo tuzemsko. */
    private const FOREIGN_PLACES = ['intracom', 'thirdCountry'];

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * Prahy daného profilu; null nebo profil bez prahů → defaulty.
     *
     * @return array{ready: float, review: float}
     */
    public function thresholdsForProfile(?int $profileNdx): array
    {
        if ($profileNdx === null) {
            return self::DEFAULT_THRESHOLDS;
        }

        $row = $this->db->fetchRow(
            'SELECT confidence_thresholds FROM %n WHERE id = %i',
            self::PROFILES_TABLE,
            $profileNdx,
        );
        if ($row === null || empty($row['confidence_thresholds'])) {
            return self::DEFAULT_THRESHOLDS;
        }

        $decoded = json_decode((string) $row['confidence_thresholds'], true);
        if (!is_array($decoded)) {
            return self::DEFAULT_THRESHOLDS;
        }

        return [
            'ready' => isset($decoded['ready']) ? (float) $decoded['ready'] : self::DEFAULT_THRESHOLDS['ready'],
            'review' => isset($decoded['review']) ? (float) $decoded['review'] : self::DEFAULT_THRESHOLDS['review'],
        ];
    }

    /**
     * Prahy default aktivního profilu DS — pro čtení pásma bez vazby na
     * konkrétní profil (dashboard, detail zprávy). Bez profilu → defaulty.
     *
     * @return array{ready: float, review: float}
     */
    public function thresholdsForDefaultProfile(): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM %n WHERE is_default = %i AND is_active = %i LIMIT 1',
            self::PROFILES_TABLE,
            1,
            1,
        );
        return $this->thresholdsForProfile($row !== null ? (int) $row['id'] : null);
    }

    /**
     * @param array{ready: float, review: float} $thresholds
     */
    public function bandFor(float $confidence, array $thresholds): string
    {
        if ($confidence >= $thresholds['ready']) {
            return self::BAND_READY;
        }
        if ($confidence >= $thresholds['review']) {
            return self::BAND_REVIEW;
        }
        return self::BAND_LOW;
    }

    /**
     * Taxonomie štítků pro {@see capBand()} z konfigurace zdroje dat —
     * stejný cfgItem jako applier, takže strop a rozhodnutí applieru čtou
     * totéž. Bez konfigurace prázdná: applier pak zvláštní místo plnění
     * neohlásí, strop ho proto nehlídá.
     *
     * @return array<string, mixed>
     */
    public static function contentTagsFrom(?ConfigRuntime $config): array
    {
        $tags = $config?->cfgItem(self::CONTENT_TAGS_CFG_ITEM);
        return is_array($tags) ? $tags : [];
    }

    /**
     * Štítky položek pro {@see capBand()} (#89 D19): mapa kód položky →
     * štítky pro řádky návrhů, které se klasifikují podle položky —
     * **jeden dotaz** na celou sadu návrhů (feed ji volá jednou na sběr
     * karet, detail zprávy jednou na zprávu). Čte tatáž místa jako applier
     * ({@see SpecialPlaceSupply::itemCodes()}, {@see ItemContentTags}).
     * Strop se týká jen přijatých dokladů — jiné návrhy kódy nedávají;
     * bez taxonomie nebo bez kódů žádný dotaz.
     *
     * @param array<string, mixed> $contentTags cfgItem `core.exchange.contentTags`
     * @param iterable<array<string, mixed>> $canonicals návrhy dokladů
     * @return array<string, list<string>>
     */
    public static function itemTagsFor(DataSourceConnection $db, array $contentTags, iterable $canonicals): array
    {
        if ($contentTags === []) {
            return [];
        }
        $codes = [];
        foreach ($canonicals as $canonical) {
            if (($canonical['selfParty'] ?? null) !== 'customer') {
                continue;
            }
            foreach (SpecialPlaceSupply::itemCodes($canonical) as $code) {
                $codes[ItemContentTags::key($code)] ??= $code;
            }
        }
        return $codes === [] ? [] : ItemContentTags::forCodes($db->getDibiConnection(), array_values($codes));
    }

    /**
     * Jediný strop pásma (D7 + #118 D15 + #89 D17, D21): `ready` smí zůstat,
     * jen když je každý item řádek pokrytý položkou, validace hodnot
     * canonicalu nevrátí žádný nález — nevalidní IČO, DIČ, PSČ, účet, IBAN,
     * BIC ani oprava IBAN ↔ číslo účtu (`_resolve.valueRepairs` →
     * `bank_account_repaired`) —, doklad není služba se zvláštním místem
     * plnění od dodavatele mimo tuzemsko ({@see capsSpecialPlace()}) a žádný
     * jeho řádek nemá nejednoznačnou položku bez kódu DPH
     * ({@see capsAmbiguousItem()}). Nižší pásma se nemění.
     *
     * Taxonomie i štítky položek jsou povinné — bez nich poslední dva
     * důvody vyhodnotit nejde ({@see contentTagsFrom()}, {@see itemTagsFor()}).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $contentTags cfgItem `core.exchange.contentTags`
     * @param array<string, list<string>> $itemTags {@see itemTagsFor()}
     */
    public function capBand(string $band, array $canonical, array $contentTags, array $itemTags): string
    {
        if ($band !== self::BAND_READY) {
            return $band;
        }
        if (!$this->rowsCoveredByItems($canonical)) {
            return self::BAND_REVIEW;
        }
        if (CanonicalValueValidator::validate($canonical) !== []) {
            return self::BAND_REVIEW;
        }
        if (self::capsSpecialPlace($canonical, $contentTags, $itemTags)) {
            return self::BAND_REVIEW;
        }
        if (self::capsAmbiguousItem($canonical, $contentTags, $itemTags)) {
            return self::BAND_REVIEW;
        }
        return $band;
    }

    /**
     * Strop D17 (#89): přijatý doklad, u kterého by applier ohlásil
     * varování `vat_special_place` — vznikne Bez DPH jen proto, že to říká
     * štítek řádků, ne údaj z faktury. Takové rozhodnutí má vidět člověk.
     *
     * Podmínka je záměrně **širší** než rozhodnutí applieru, nikdy užší:
     * doklad nenese daň dodavatele, každý položkový řádek má zvláštní místo
     * plnění — štítkem z bloku, nebo štítky položky pokrytého řádku (D19,
     * D20; {@see SpecialPlaceSupply}) — a dodavatel není tuzemský podle
     * hrubého signálu canonicalu
     * ({@see supplierNotDomestic()}). Applier místo plnění určuje přesně
     * (registrace, členství v unii k datu) — ta logika se tu neopakuje.
     * Doklad se zahraniční DPH strop nedostane: daň je údaj z faktury.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $contentTags cfgItem `core.exchange.contentTags`
     * @param array<string, list<string>> $itemTags {@see itemTagsFor()}
     */
    public static function capsSpecialPlace(array $canonical, array $contentTags, array $itemTags): bool
    {
        return ($canonical['selfParty'] ?? null) === 'customer'
            && !SupplierVatPrices::carriesSupplierVat($canonical)
            && SpecialPlaceSupply::allItemRowsSpecial($canonical, $contentTags, $itemTags)
            && self::supplierNotDomestic($canonical);
    }

    /**
     * Strop D21 (#89): přijatý doklad bez daně dodavatele, od dodavatele
     * mimo tuzemsko ({@see supplierNotDomestic()}), s položkovým řádkem,
     * jehož položka nese štítky s různým místem plnění
     * ({@see SpecialPlaceSupply::ROW_AMBIGUOUS}) a který v canonicalu nemá
     * kód DPH. Applier takovému řádku kód samovyměření odvodí a přidá
     * varování `vat_special_place_ambiguous` — jestli jde o zvláštní místo
     * plnění, musí rozhodnout člověk.
     *
     * Řádek s kódem (typicky z historie řádků, potvrzený člověkem) strop
     * nedává. Je to **užší** než varování applieru: když kontrola souladu
     * kód z historie zahodí a applier odvodí nový, varování přijde, karta
     * strop nemá — strop souladem neprochází, vědomě.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $contentTags cfgItem `core.exchange.contentTags`
     * @param array<string, list<string>> $itemTags {@see itemTagsFor()}
     */
    public static function capsAmbiguousItem(array $canonical, array $contentTags, array $itemTags): bool
    {
        if (($canonical['selfParty'] ?? null) !== 'customer'
            || SupplierVatPrices::carriesSupplierVat($canonical)
            || !self::supplierNotDomestic($canonical)) {
            return false;
        }
        foreach ((array) ($canonical['rows'] ?? []) as $idx => $row) {
            if (!is_array($row) || !RowHistoryEnricher::rowExpectsItem($row)
                || trim((string) ($row['vat']['code'] ?? '')) !== '') {
                continue;
            }
            $state = SpecialPlaceSupply::classifyRow($canonical, (int) $idx, $contentTags, $itemTags)['state'];
            if ($state === SpecialPlaceSupply::ROW_AMBIGUOUS) {
                return true;
            }
        }
        return false;
    }

    /**
     * Hrubý signál „dodavatel není tuzemský“ — kterýkoli z:
     *
     *  - `vat.place` je `intracom` / `thirdCountry`;
     *  - prefix DIČ dodavatele nebo jeho země se liší od odběratele (jeho
     *    prefix DIČ, jinak země);
     *  - odběratel nedává prefix ani zemi (návrh ho často nenese — účtenka,
     *    doklad bez našeho DIČ) a dodavatel má DIČ s písmenným prefixem.
     *    Applier místo plnění odvozuje právě z toho prefixu proti zemi naší
     *    registrace; bez srovnání se strop přikloní k opatrnosti.
     *
     * Dodavatel bez DIČ a bez odlišné země (tuzemský neplátce) signál nedá.
     *
     * @param array<string, mixed> $canonical
     */
    private static function supplierNotDomestic(array $canonical): bool
    {
        if (in_array($canonical['vat']['place'] ?? null, self::FOREIGN_PLACES, true)) {
            return true;
        }
        $supplier = is_array($canonical['supplier'] ?? null) ? $canonical['supplier'] : [];
        $customer = is_array($canonical['customer'] ?? null) ? $canonical['customer'] : [];
        $supplierPrefix = self::vatIdPrefix($supplier);
        $supplierCountry = self::partyCountryCode($supplier);

        $own = array_values(array_filter(
            [self::vatIdPrefix($customer), self::partyCountryCode($customer)],
            static fn (?string $code): bool => $code !== null,
        ));
        if ($own === []) {
            return $supplierPrefix !== null;
        }
        return ($supplierPrefix !== null && !in_array($supplierPrefix, $own, true))
            || ($supplierCountry !== null && !in_array($supplierCountry, $own, true));
    }

    /**
     * Písmenný prefix DIČ strany (`vatId`), velkými; bez DIČ nebo bez
     * písmenného prefixu null.
     *
     * @param array<string, mixed> $party
     */
    private static function vatIdPrefix(array $party): ?string
    {
        $vatId = $party['vatId'] ?? null;
        $prefix = substr(VatPlaceDerivation::normalize(is_scalar($vatId) ? (string) $vatId : null), 0, 2);
        return strlen($prefix) === 2 && ctype_alpha($prefix) ? $prefix : null;
    }

    /**
     * Země strany velkými (srovnatelná s prefixem DIČ); bez země null.
     *
     * @param array<string, mixed> $party
     */
    private static function partyCountryCode(array $party): ?string
    {
        $country = CanonicalValueNormalizer::partyCountry($party);
        return $country !== null && $country !== '' ? strtoupper($country) : null;
    }

    /**
     * Pokrytí řádků (D7): každý item řádek má položku — z extrakce, nebo
     * návrhem enrichmentu. Kontační řádky (acc.record) položku nemají
     * validně, stropu se netýkají.
     *
     * Řádek doplněný enrichmentem s confidence `low` (dominantní položka
     * partnera, bez textového signálu) se jako pokrytý nepočítá — návrh
     * zůstává review a uživatel ho potvrzuje
     * (`tasks/enrichment-dominant-item.md`, D5). Textové matche
     * (`high`/`medium`) beze změny; řádky s ourCode od AI mají
     * `confidence: null` → stropu se netýkají.
     *
     * Řádky doplněné obsahovou eskalací (`matchedBy: 'contentTag'`) stropují
     * vždy (D14, `tasks/content-tag-enrichment.md`) — obsahový návrh vždy
     * potvrzuje člověk, bez ohledu na zdroj štítku (rule/llm).
     *
     * @param array<string, mixed> $canonical
     */
    private function rowsCoveredByItems(array $canonical): bool
    {
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $enrichments = $this->enrichmentsByRowIndex($canonical);
        foreach ($rows as $idx => $row) {
            if (!is_array($row) || !RowHistoryEnricher::rowExpectsItem($row)) {
                continue;
            }
            if (trim((string) ($row['item']['ourCode'] ?? '')) === '') {
                return false;
            }
            if (($enrichments[$idx]['confidence'] ?? null) === 'low') {
                return false;
            }
            if (($enrichments[$idx]['matchedBy'] ?? null) === 'contentTag') {
                return false;
            }
        }
        return true;
    }

    /**
     * Pásmo návrhu z řádku analýzy: confidence + prahy profilu běhu
     * (fallback default profil) + strop {@see capBand()}. Convenience pro
     * čtecí místa (detail zprávy).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $contentTags cfgItem `core.exchange.contentTags`
     * @param array<string, list<string>> $itemTags {@see itemTagsFor()}
     */
    public function bandForAnalysis(
        ?float $confidence,
        ?int $profileNdx,
        array $canonical,
        array $contentTags,
        array $itemTags,
    ): string {
        $thresholds = $profileNdx !== null
            ? $this->thresholdsForProfile($profileNdx)
            : $this->thresholdsForDefaultProfile();
        $band = $this->bandFor($confidence ?? 0.0, $thresholds);
        return $this->capBand($band, $canonical, $contentTags, $itemTags);
    }

    /**
     * Enrichment audit bloky z `_resolve.rows` mapované na index řádku
     * canonical (jen array entries s array `enrichment`).
     *
     * @param array<string, mixed> $canonical
     * @return array<int, array<string, mixed>>
     */
    private function enrichmentsByRowIndex(array $canonical): array
    {
        $resolveRows = $canonical['_resolve']['rows'] ?? null;
        if (!is_array($resolveRows)) {
            return [];
        }
        $map = [];
        foreach ($resolveRows as $entry) {
            if (!is_array($entry) || !is_array($entry['enrichment'] ?? null)) {
                continue;
            }
            $idx = $entry['index'] ?? null;
            if (is_int($idx)) {
                $map[$idx] = $entry['enrichment'];
            }
        }
        return $map;
    }
}
