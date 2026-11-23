<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Document\CanonicalReferences;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\PayloadContext;
use Shipard\Module\Core\Exchange\Document\PayloadResolve;
use Shipard\Module\Core\Exchange\Document\SpecialPlaceSupply;
use Shipard\Module\Core\Exchange\Document\SupplierVatPrices;
use Shipard\Module\Core\Exchange\Document\VatCodeDerivation;
use Shipard\Module\Core\Exchange\Document\VatModeDerivation;
use Shipard\Module\Core\Exchange\Document\VatPlaceDerivation;
use Shipard\Module\Core\Exchange\Enrich\ItemContentTags;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Docs\Core\VatRateDates;

/**
 * DPH dokladu z **papírové podoby** (#166 D51, etapa 5) — odvozování, které
 * pokyn nedělá (D19): místo plnění z DIČ, druh plnění ze štítků, kód DPH
 * řádku a jeho soulad se signály, doklad neplátce a doklad mimo české DPH,
 * režim výpočtu ověřený proti číslům, ceny řádků s daní dodavatele, volby
 * uživatele z `_resolve` (místo, režim, kód, předmět plnění).
 *
 * Staví na kontextu z payloadu ({@see PayloadContext}); výsledkem je
 * {@see context()} — kontext DPH dokladu, který čtou resolve řádků,
 * rekapitulace ({@see RecapInterpretation}), nálezy i skladba dokladu.
 * Popis pravidel: `docs/document-interpretation.md` § 6.
 */
final class VatInterpretation
{
    private const ACTIVE_STATES = [10, 40, 80];

    /** Doklad mimo české DPH z volby uživatele (režim Bez DPH v náhledu, #89 D4). */
    public const OUT_OF_SCOPE_USER = 'user';

    /**
     * Přijatý doklad od neplátce DPH v tuzemsku (#164 D5a, U10) — další druh
     * dokladu mimo české DPH: vznikne celý Bez DPH s řádky bez kódu a bez
     * rekapitulace, ceny řádků se nemění (neplátce daň neúčtuje).
     */
    public const OUT_OF_SCOPE_SUPPLIER_NON_PAYER = 'supplierNonPayer';

    /** Řádek bez informace o druhu plnění ({@see effectiveSupplyKinds()}). */
    private const NO_SUPPLY_KIND = [
        'supplyKind' => null, 'source' => null, 'tag' => null, 'tagSupply' => null,
        'specialSource' => null, 'item' => null, 'ambiguous' => false,
    ];

    /**
     * Blok `_resolve.rows[].vatCode` řádku dokladu mimo české DPH (#89):
     * vyřešený, bez kódu — na řádek se nic nepropíše (`transformRows()` čte
     * kód z `createPayload`), souhrn ho počítá jako spárovaný a brána
     * náhledu na něj nečeká.
     */
    private const OUT_OF_SCOPE_VAT_CODE = ['status' => 'matched', 'matchedBy' => 'outOfScope'];

    /** @var array{key: string, ctx: array<string, mixed>}|null {@see context()} */
    private ?array $vatContextCache = null;

    /**
     * Štítky položek pokrytých řádků ({@see itemTagsFor()}, #89 D19) —
     * jeden dotaz na doklad; klíč = sada kódů položek.
     *
     * @var array{key: string, tags: array<string, list<string>>}|null
     */
    private ?array $itemTagsCache = null;

    /**
     * @param array<string, array<string, mixed>> $contentTags taxonomie
     *        `core.exchange.contentTags` (compiled, `name` lokalizované)
     */
    public function __construct(
        private readonly Connection $db,
        private readonly ConfigRuntime $config,
        private readonly PayloadContext $payload,
        private readonly PayloadResolve $resolve,
        private readonly VatCodeResolver $vatCodeResolver,
        private readonly VatCodeDerivation $vatCodeDerivation,
        private readonly VatPlaceDerivation $vatPlaceDerivation,
        private readonly array $contentTags = [],
    ) {
    }

    /**
     * DPH kontext dokladu — naše registrace (D2) a rozhodnutí o kódu DPH
     * každého položkového řádku (D1), tasks/exchange-received-reverse-charge.md;
     * efektivní místo plnění (tasks/exchange-received-vat-place.md D1).
     * Počítá se líně a cachuje per canonical ({@see $vatContextCache}).
     *
     * `derive` je true jen u přijatého dokladu (`selfParty: customer`) na
     * zdroji s registrací DPH platnou k datu dokladu; jinak jde vše dnešní
     * cestou (vystavené a účetní doklady).
     *
     * `nonPayer` (#97 D2): přijatý doklad, ke kterému žádná registrace
     * k datu neplatí — zdroj tehdy nebyl plátcem. Doklad vznikne Bez DPH
     * s daní dodavatele v cenách řádků ({@see supplierVatGrossTotals()}).
     * Plátcovství je registrace k datu, ne příznak `economy.vatAgenda`
     * (docs/ds-setup.md D5): bývalý plátce má příznak vypnutý, ale starší
     * doklady s DPH. Import (`applyOptions.importNumber` — starý Shipard,
     * datové sady) přenáší hotové doklady **pokynem** (#166 D22): do této
     * větve se nedostane, registraci bere bez ohledu na platnost k datu
     * ({@see PayloadContext::instructionVatRegistration()}).
     *
     * `outOfScope` (#89 D2–D4): doklad od dodavatele mimo tuzemsko, který
     * je **mimo české DPH** a vznikne celý Bez DPH — `"foreignVat"`
     * (dodavatel účtuje vlastní daň; skončí v cenách řádků jako u neplátce),
     * `"specialPlace"` (služba se zvláštním místem plnění, bez daně na
     * dokladu) nebo `"user"` (uživatel v náhledu zvolil režim Bez DPH);
     * v tuzemsku `"supplierNonPayer"` (#164 D5a — doklad od neplátce: režim
     * Bez DPH z dokladu nebo z volby a žádná daň ani přenesení na dokladu);
     * jinak null. `autoOutOfScope` a `autoReverseCharge` říkají, jak by
     * doklad dopadl **bez volby režimu** — z nich je hodnota „Automaticky“
     * v náhledu, protože rozhodnutí o řádcích se u dokladu mimo DPH zahodí. Rozhoduje {@see outOfScopeKind()} jednou na
     * doklad až po konečném místě plnění (tedy po pinu místa). `derive`
     * i `codeOptions` zůstávají — volbou kódu řádku se uživatel vrátí
     * k samovyměření —, `rows` jsou prázdné: kódy se nerozhodují, stejně
     * jako u neplátce.
     *
     * `place` je místo, které na dokladu skončí: u přijatého dokladu
     * odvozené z prefixu DIČ dodavatele ({@see VatPlaceDerivation},
     * `placeSource: "vatId"`, `placePrefix`), jinak `vat.place`
     * z canonicalu (`placeSource: "ai"`), nebo null. Čtou ho tři místa —
     * derivace kódu řádků, transform `vat_place` a hlavičkové issues.
     *
     * Druh plnění řádku je efektivní ({@see effectiveSupplyKinds()},
     * tasks/exchange-received-supply-kind.md D2): z canonicalu, jinak mimo
     * tuzemsko ze štítku řádku (`supplyKindSource: "tag"`, warning
     * `supply_kind_derived`). Pořadí: místo z DIČ s druhy z canonicalu →
     * fallback druhů → jediný doplňkový průchod místa, když fallback něco
     * doplnil a první průchod selhal na prefixu (XI jen zboží).
     *
     * @param array<string, mixed> $canonical
     * @return array{
     *   derive: bool,
     *   nonPayer: bool,
     *   outOfScope: ?string,
     *   autoOutOfScope: ?string,
     *   autoReverseCharge: bool,
     *   ownCountry: ?string,
     *   ownRegistrationId: ?int,
     *   taxPointDate: ?string,
     *   rateDate: ?string,
     *   rateHead: array<string, mixed>,
     *   place: ?string,
     *   placeSource: ?string,
     *   placePrefix: ?string,
     *   autoPlace: ?string,
     *   pins: array{place: ?string, mode: ?string, rows: array<int, string>, invalid: list<array{severity: string, path: string, code: string, message: string}>},
     *   codeOptions: list<array{code: string, label: string, pct: float, reverseCharge: bool, reducedDeduction: bool, supplyKind: ?string}>,
     *   rows: array<int, array{
     *     input: string, derived: ?string, reason: ?string,
     *     effective: ?string, matchedBy: ?string,
     *     issue: ?array{severity: string, code: string, message: string},
     *     supplyKind: ?string, supplyKindSource: ?string, tag: ?string, reasonKind: ?string,
     *     specialSource: ?string, specialItem: ?string, ambiguousItem: ?string
     *   }>,
     *   special: array<int, array{source: string, item: ?string}>
     * }
     *   `special` = řádky se zvláštním místem plnění a zdroj (`tag` = štítek
     *   z bloku, `item` = štítky položky s jejím kódem, #89 D19) — přežije
     *   i doklad mimo české DPH, kde se `rows` zahodí.
     *
     * **Režim** (#166 D18–D20): tohle je kontext **návrhu**. Pokyn nic
     * neodvozuje a má kontext vlastní ({@see PayloadContext::vatContext()})
     * — stejné klíče, hodnoty jen z payloadu. Kontext nese `mode`
     * a `withoutVat` („doklad vznikne Bez DPH“ — neplátce nebo doklad mimo
     * české DPH), aby se čtenáři nevětvili sami.
     */
    public function context(array $canonical): array
    {
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $supplierVatId = self::partyVatId($canonical['supplier'] ?? null);
        $customerVatId = self::partyVatId($canonical['customer'] ?? null);
        $pins = $this->vatPins($canonical);
        $importMode = PayloadContext::isImportMode($canonical);
        // Klíč cache: strany dokladu (DIČ) rozhodují o místě plnění, štítek
        // dokladu a výjimky řádků o druhu plnění (D2), volby uživatele
        // (#87 B) o místě i kódech, data a import mód o registraci k datu
        // (#97), daň dodavatele (rekapitulace a `computed` řádků) o dokladu
        // mimo české DPH (#89), položka řádku a volba položky o zvláštním
        // místě plnění ze štítků položky (D19) — bez nich by cache vrátila
        // kontext jiného dokladu / bez fallbacku / bez pinu.
        $key = md5((string) json_encode([
            $canonical['selfParty'] ?? null,
            // Typ dokladu (#159): nedaňový typ má vždy automatické datum sazby
            // k datu vystavení — zálohová faktura a faktura se stejnými daty
            // a řádky by jinak sdílely kontext.
            $canonical['docType'] ?? null,
            $canonical['vat'] ?? null,
            $canonical['dates'] ?? null,
            // Opravný doklad (#92): datum sazby závisí na bloku a pinu.
            $canonical['correction'] ?? null,
            $canonical['_resolve']['correction']['userAction'] ?? null,
            $importMode,
            SupplierVatPrices::carriesSupplierVat($canonical),
            $supplierVatId,
            $customerVatId,
            $canonical['_resolve']['contentTag']['tag'] ?? null,
            $canonical['_resolve']['contentTag']['rowExceptions'] ?? null,
            [$pins['place'], $pins['mode'], $pins['rows'], $pins['subjects']],
            array_map(
                static fn (mixed $row): ?array => is_array($row)
                    ? [$row['rowKind'] ?? null, $row['accSide'] ?? null, $row['vat'] ?? null, CanonicalReferences::rowItemCode($row)]
                    : null,
                $rows,
            ),
            array_map(
                static fn (mixed $rowResolve): mixed => is_array($rowResolve) ? ($rowResolve['item']['userAction'] ?? null) : null,
                is_array($canonical['_resolve']['rows'] ?? null) ? $canonical['_resolve']['rows'] : [],
            ),
        ]));
        if ($this->vatContextCache !== null && $this->vatContextCache['key'] === $key) {
            return $this->vatContextCache['ctx'];
        }

        $vat = is_array($canonical['vat'] ?? null) ? $canonical['vat'] : [];
        $ctx = ['pins' => $pins] + $this->payload->baseVatContext($canonical, interpreted: true);

        // Návrh je vždy mimo importní mód (#166 D22 — `importNumber` v návrhu
        // odmítá validátor): plátcovství se posuzuje k datu dokladu.
        if (($canonical['selfParty'] ?? null) === 'customer') {
            $own = $this->ownVatRegistration($this->payload->registrationDate($canonical));
            if ($own !== null) {
                $ctx['derive'] = true;
                $ctx['ownCountry'] = $own['country'];
                $ctx['ownRegistrationId'] = $own['id'];
            } else {
                $ctx['nonPayer'] = true;
            }
        }

        // Volba režimu platí jen ve větvi derive (D9, #87 B) — proto až tady.
        $ctx['rateHead'] = $this->payload->vatRateHead(
            $canonical,
            ($ctx['derive'] ? $pins['mode'] : null) ?? $this->documentVatMode($canonical)['mode'],
        );
        $ctx['rateDate'] = VatRateDates::headDate($ctx['rateHead'], $this->config);

        if ($ctx['derive']) {
            // Místo plnění před kódem — kód na místu závisí (cz-217 vs cz-417)
            // a kontrola souladu existujícího kódu musí dostat totéž místo.
            // První průchod s druhy plnění z canonicalu (XI jen zboží).
            $derivedPlace = $this->vatPlaceDerivation->derive(
                (string) $ctx['ownCountry'],
                $ctx['taxPointDate'],
                $supplierVatId,
                $customerVatId,
                self::rowSupplyKinds($rows),
            );
            if ($derivedPlace['place'] !== null) {
                $ctx['place'] = $derivedPlace['place'];
                $ctx['placeSource'] = 'vatId';
                $ctx['placePrefix'] = $derivedPlace['prefix'];
            }
            // D2: chybějící druh plnění ze štítku řádku potřebuje efektivní
            // místo (jen mimo tuzemsko); místo u XI potřebuje druhy. Jediný
            // doplňkový průchod derivace místa — když fallback něco doplnil
            // a první průchod selhal na prefixu (XI bez druhů); žádný cyklus.
            $kinds = $this->effectiveSupplyKinds($canonical, $rows, $ctx['place']);
            if ($kinds['changed'] && $derivedPlace['place'] === null && $derivedPlace['prefix'] !== null) {
                $secondPlace = $this->vatPlaceDerivation->derive(
                    (string) $ctx['ownCountry'],
                    $ctx['taxPointDate'],
                    $supplierVatId,
                    $customerVatId,
                    array_values(array_map(static fn (array $k): ?string => $k['supplyKind'], $kinds['rows'])),
                );
                if ($secondPlace['place'] !== null) {
                    $ctx['place'] = $secondPlace['place'];
                    $ctx['placeSource'] = 'vatId';
                    $ctx['placePrefix'] = $secondPlace['prefix'];
                    if ($secondPlace['place'] === 'domestic') {
                        // Pojistka: v tuzemsku fallback neplatí — zahodit, třetí průchod není.
                        $kinds = $this->effectiveSupplyKinds($canonical, $rows, 'domestic');
                    }
                }
            }
            $ctx['autoPlace'] = $ctx['place'];
            if ($pins['place'] !== null) {
                // D10 (#87 task B): místo z volby uživatele přebíjí DIČ i AI.
                // Derivace proběhla jen kvůli hodnotě „Automaticky“ (autoPlace);
                // druh plnění ze štítku i kódy dostanou zvolené místo.
                $ctx['place'] = $pins['place'];
                $ctx['placeSource'] = 'user';
                $ctx['placePrefix'] = null;
                $kinds = $this->effectiveSupplyKinds($canonical, $rows, $ctx['place']);
            }
        }

        if ($ctx['derive']) {
            // Nabídka kódů pro ruční volbu (D13) — až po konečném místě;
            // táž funkce validuje piny v decideRowVatCode() (jinak by UI
            // nabídlo a server odmítl). Klient dostává nabídku k datu sazby
            // dokladu, pin řádku se ověřuje proti nabídce k datu sazby
            // **řádku** (#159 U3) — nabídky per datum. Bez data není z čeho.
            $place = $ctx['place'];
            $ownCountry = (string) $ctx['ownCountry'];
            $optionsByDate = [];
            $optionsAt = function (?string $date) use (&$optionsByDate, $ownCountry, $place): array {
                if ($date === null) {
                    return [];
                }
                return $optionsByDate[$date] ??= $this->vatCodeDerivation->options($ownCountry, $date, $place);
            };
            $ctx['codeOptions'] = $optionsAt($ctx['rateDate']);
            $reverseCharge = is_bool($vat['reverseCharge'] ?? null) ? $vat['reverseCharge'] : null;
            // Doklad od neplátce v tuzemsku (`vat.mode` none, řádky bez
            // sazby) kódy nedostává — vznikne celý Bez DPH (#164 D5a,
            // {@see outOfScopeKind()}); rozhodnutí níž slouží jen k jeho
            // vyhodnocení. Mimo tuzemsko zůstává samovyměření (služba ze
            // třetí země bez DPH).
            $decisions = [];
            foreach ($rows as $idx => $row) {
                if (!self::rowHasVatDecision($row)) {
                    continue;
                }
                $rowVat = $row['vat'];
                // Odvození, soulad i pin k datu sazby řádku (#159 U3).
                $rowRateDate = $this->payload->lineRateDate($ctx, $rowVat['rateDate'] ?? null);
                $decisions[(int) $idx] = $this->decideRowVatCode(
                    $ownCountry,
                    $rowRateDate,
                    $place,
                    $reverseCharge,
                    $rowVat,
                    $kinds['rows'][(int) $idx] ?? self::NO_SUPPLY_KIND,
                    $pins['rows'][(int) $idx] ?? null,
                    $optionsAt($rowRateDate),
                );
            }
            // #89 D2–D4: doklad mimo české DPH — kódy řádků se nerozhodují
            // (rozhodnutí výše sloužila jen k vyhodnocení), doklad je Bez DPH.
            $ctx['outOfScope'] = $this->outOfScopeKind(
                $canonical,
                $place,
                $pins,
                $reverseCharge,
                $decisions,
                $ownCountry,
                $ctx['taxPointDate'],
            );
            if ($ctx['outOfScope'] !== null && $pins['mode'] === 'none') {
                // Režim Bez DPH zvolil uživatel (mimo tuzemsko druh `user`,
                // v tuzemsku doklad od neplátce). Hodnota „Automaticky“ =
                // výsledek bez volby režimu; řádky se zahodí, proto se
                // samovyměření zjistí tady.
                $ctx['autoOutOfScope'] = $this->outOfScopeKind(
                    $canonical,
                    $place,
                    ['mode' => null] + $pins,
                    $reverseCharge,
                    $decisions,
                    $ownCountry,
                    $ctx['taxPointDate'],
                );
                $ctx['autoReverseCharge'] = $ctx['autoOutOfScope'] === null
                    && $this->decisionsCarryReverseCharge($decisions, (string) $ctx['ownCountry'], $ctx['taxPointDate']);
            } else {
                $ctx['autoOutOfScope'] = $ctx['outOfScope'];
            }
            // D21: nejednoznačná položka varuje jen u řádku s odvozeným kódem
            // (mimo tuzemsko je každý odvozený kód samovyměření) na dokladu
            // bez daně dodavatele a bez volby režimu. Ponechaný kód i volba
            // uživatele varování nedostanou; v tuzemsku nejednoznačný řádek
            // nevznikne (štítky položek se čtou jen mimo tuzemsko).
            $warnAmbiguous = $pins['mode'] === null && !SupplierVatPrices::carriesSupplierVat($canonical);
            foreach ($decisions as $idx => $decision) {
                if (!$warnAmbiguous || $decision['matchedBy'] !== 'derived') {
                    $decisions[$idx]['ambiguousItem'] = null;
                }
            }
            $ctx['rows'] = $ctx['outOfScope'] === null ? $decisions : [];
            foreach ($kinds['rows'] as $idx => $kind) {
                if ($kind['specialSource'] !== null) {
                    $ctx['special'][$idx] = ['source' => $kind['specialSource'], 'item' => $kind['item']];
                }
            }
        }

        $ctx['withoutVat'] = $ctx['nonPayer'] || $ctx['outOfScope'] !== null;
        $this->vatContextCache = ['key' => $key, 'ctx' => $ctx];
        return $ctx;
    }

    /**
     * Je doklad **mimo české DPH** (#89 D2–D4, #164 D5a)? Vyhodnocuje se
     * jednou na doklad, jen ve větvi derivace (přijatý doklad na zdroji
     * s registrací DPH k datu, mimo import). S efektivním místem
     * **v tuzemsku** jde jen o doklad od neplátce
     * ({@see isSupplierNonPayer()}); mimo tuzemsko:
     *
     *   volba režimu Bez DPH                             → user (D4)
     *   volba režimu s DPH, nebo kódu na některém řádku  → null (jako dřív)
     *   řádek s ponechaným kódem z canonicalu / historie → null (jako dřív)
     *   doklad nese daň dodavatele, bez `reverseCharge`  → foreignVat
     *   doklad nese daň i `reverseCharge: true`          → null (smíšený, D5)
     *   bez daně, každý položkový řádek `special`        → specialPlace
     *   cokoli jiného                                    → null
     *
     * Ponechaný kód (`matchedBy: "input"`) je kód, který derivace ani
     * kontrola souladu nezpochybnily — typicky z historie řádků, tedy
     * potvrzený člověkem. Takový doklad dřív prošel se samovyměřením
     * a projde dál; režim mimo DPH nahrazuje jen dřívější chybu.
     *
     * Volba Bez DPH (D4) má přednost před vším — i před volbou kódu řádku
     * (ta se pak ignoruje s info `vat_pin_ignored`). V tuzemsku neplatí:
     * tam se Bez DPH se samovyměřením dál tiše přepíná na „Ze základu“
     * ({@see effectiveVatMode()}, D11 z #87).
     *
     * Pravidlo „každý položkový řádek je `special`“ — štítkem z bloku
     * `_resolve.contentTag`, nebo štítky položky pokrytého řádku (D19, D20)
     * — drží {@see SpecialPlaceSupply::allItemRowsSpecial()}; sdílí ho strop
     * pásma karty návrhu (#89 D17), který smí být širší, nikdy užší.
     *
     * @param array<string, mixed> $canonical
     * @param array{place: ?string, mode: ?string, rows: array<int, string>, invalid: list<array<string, string>>} $pins
     * @param array<int, array<string, mixed>> $decisions rozhodnutí o kódech ({@see decideRowVatCode()})
     * @param string $country země naší registrace DPH
     * @param string|null $date DUZP (fallback datum vystavení)
     * @return string|null foreignVat | specialPlace | user | supplierNonPayer
     */
    public function outOfScopeKind(
        array $canonical,
        ?string $place,
        array $pins,
        ?bool $reverseCharge,
        array $decisions,
        string $country,
        ?string $date,
    ): ?string {
        if (!in_array($place, ['intracom', 'thirdCountry'], true)) {
            return $this->isSupplierNonPayer($canonical, $pins, $reverseCharge, $decisions, $country, $date)
                ? self::OUT_OF_SCOPE_SUPPLIER_NON_PAYER
                : null;
        }
        if ($pins['mode'] === 'none') {
            return self::OUT_OF_SCOPE_USER;
        }
        if ($pins['mode'] !== null || $pins['rows'] !== []) {
            return null;
        }
        foreach ($decisions as $decision) {
            if ($decision['matchedBy'] === 'input') {
                return null;
            }
        }
        if (SupplierVatPrices::carriesSupplierVat($canonical)) {
            return $reverseCharge === true ? null : VatCodeDerivation::FAIL_FOREIGN_VAT;
        }
        return SpecialPlaceSupply::allItemRowsSpecial($canonical, $this->contentTags, $this->itemTagsFor($canonical))
            ? VatCodeDerivation::FAIL_SPECIAL_PLACE
            : null;
    }

    /**
     * Je to přijatý doklad **od neplátce DPH v tuzemsku** (#164 D5a, U10)?
     * Režim Bez DPH — z dokladu, nebo volbou uživatele — a na dokladu žádná
     * daň ani přenesení daňové povinnosti:
     *
     *   volba režimu s DPH                                → ne
     *   režim není Bez DPH (ani z dokladu, ani z volby)   → ne
     *   doklad nese daň dodavatele nebo řádek se sazbou   → ne (`none` u dokladu
     *                                                       s daní řeší dál
     *                                                       režim dokladu)
     *   přenesení daňové povinnosti (hlavička, předmět
     *   plnění řádku, kód řádku se samovyměřením)         → ne (Bez DPH se
     *                                                       samovyměřením se dál
     *                                                       přepíná na „Ze základu“,
     *                                                       {@see effectiveVatMode()})
     *   bez volby režimu: volba kódu na některém řádku,
     *   nebo řádek s ponechaným kódem                     → ne (jako dřív —
     *                                                       volbou kódu se
     *                                                       uživatel vrací
     *                                                       k dokladu s DPH)
     *   cokoli jiného                                     → ano
     *
     * Volba Bez DPH má — stejně jako mimo tuzemsko — přednost před volbou
     * kódu řádku (ta se ignoruje s info `vat_pin_ignored`).
     *
     * @param array<string, mixed> $canonical
     * @param array{place: ?string, mode: ?string, rows: array<int, string>, invalid: list<array<string, string>>} $pins
     * @param array<int, array<string, mixed>> $decisions
     */
    public function isSupplierNonPayer(
        array $canonical,
        array $pins,
        ?bool $reverseCharge,
        array $decisions,
        string $country,
        ?string $date,
    ): bool {
        $declared = $pins['mode'] ?? ($canonical['vat']['mode'] ?? null);
        if ($declared !== 'none') {
            return false;
        }
        if ($reverseCharge === true || SupplierVatPrices::carriesSupplierVat($canonical)) {
            return false;
        }
        foreach ((array) ($canonical['rows'] ?? []) as $row) {
            if (!self::rowHasVatDecision($row)) {
                continue;
            }
            $pct = $row['vat']['pct'] ?? null;
            if (is_numeric($pct) && abs((float) $pct) > 0.001) {
                return false;
            }
            if (trim((string) ($row['vat']['reverseChargeCode'] ?? '')) !== '') {
                return false;
            }
        }
        if ($this->decisionsCarryReverseCharge($decisions, $country, $date)) {
            return false;
        }
        if ($pins['mode'] === 'none') {
            return true;
        }
        if ($pins['rows'] !== []) {
            return false;
        }
        foreach ($decisions as $decision) {
            if ($decision['matchedBy'] === 'input') {
                return false;
            }
        }
        return true;
    }

    /**
     * Řádek, o jehož kódu DPH se ve větvi derivace rozhoduje: položkový,
     * bez kontace, s objektem `vat`. Stejné řádky nesou v náhledu blok
     * `_resolve.rows[].vatCode` s volbou kódu.
     */
    public static function rowHasVatDecision(mixed $row): bool
    {
        return is_array($row)
            && (string) ($row['rowKind'] ?? 'item') === 'item'
            && !isset($row['accSide'])
            && is_array($row['vat'] ?? null);
    }

    /**
     * Volby uživatele k DPH z `_resolve` (#87 task B, D8): místo plnění
     * a režim hlavičky (`useValue:<hodnota>`), kód DPH řádku
     * (`useCode:<klíč číselníku>`) a předmět plnění řádku u tuzemského
     * přenesení (`useValue:<kód předmětu>`, #89 D12 — `subjects`). Jediné
     * místo, kde se prefixy parsují.
     * Neplatná hodnota nebo akce → `invalid` (error `vat_pin_invalid`, D11)
     * a do voleb se nedostane. Zda volby platí (jen větev derive, D9),
     * rozhoduje {@see context()}; řádky jsou klíčované indexem canonicalu
     * jako v {@see DocumentApplier::reconcile()}.
     *
     *
     * Pokyn volby DPH nečte (#166 D19, D32): blok `_resolve` se v pokynu
     * zahodí celý ještě před zpracováním ({@see DocumentApplier::normalizeValues()}), volby
     * jsou tu proto vždy prázdné a payload dostane info `resolve_ignored`.
     *
     * @param array<string, mixed> $canonical
     * @return array{place: ?string, mode: ?string, rows: array<int, string>, subjects: array<int, string>, invalid: list<array{severity: string, path: string, code: string, message: string}>}
     */
    public function vatPins(array $canonical): array
    {
        $pins = ['place' => null, 'mode' => null, 'rows' => [], 'subjects' => [], 'invalid' => []];
        $resolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $invalid = static function (string $path, string $action) use (&$pins): void {
            $pins['invalid'][] = [
                'severity' => 'error',
                'path'     => $path,
                'code'     => 'vat_pin_invalid',
                'message'  => "Neplatná volba „{$action}“ — u místa plnění, režimu a předmětu plnění řádku"
                    . ' se čeká useValue:<hodnota>, u kódu DPH řádku useCode:<kód>.',
            ];
        };

        foreach (['place' => DocumentApplier::VAT_PLACE_MAP, 'mode' => DocumentApplier::VAT_MODE_MAP] as $field => $allowed) {
            $action = $resolve['vat'][$field]['userAction'] ?? null;
            if (!is_string($action) || $action === '') {
                continue;
            }
            $value = str_starts_with($action, 'useValue:') ? substr($action, strlen('useValue:')) : '';
            if ($value !== '' && isset($allowed[$value])) {
                $pins[$field] = $value;
            } else {
                $invalid("vat.{$field}", $action);
            }
        }

        foreach ((is_array($resolve['rows'] ?? null) ? $resolve['rows'] : []) as $idx => $rowResolve) {
            $action = is_array($rowResolve) ? ($rowResolve['vatCode']['userAction'] ?? null) : null;
            if (!is_string($action) || $action === '') {
                continue;
            }
            $code = str_starts_with($action, 'useCode:') ? trim(substr($action, strlen('useCode:'))) : '';
            if ($code !== '') {
                $pins['rows'][(int) $idx] = $code;
            } else {
                $invalid("rows.{$idx}.vat.code", $action);
            }
        }
        // Předmět plnění řádku (#89 D12). Zda je hodnota v číselníku
        // a platí k datu, ověřuje až resolveRowReverseChargeCode() — proti
        // téže množině, kterou klient dostal v `reverseChargeOptions`.
        foreach ((is_array($resolve['rows'] ?? null) ? $resolve['rows'] : []) as $idx => $rowResolve) {
            $action = is_array($rowResolve) ? ($rowResolve['reverseChargeCode']['userAction'] ?? null) : null;
            if (!is_string($action) || $action === '') {
                continue;
            }
            $value = str_starts_with($action, 'useValue:') ? trim(substr($action, strlen('useValue:'))) : '';
            if ($value !== '') {
                $pins['subjects'][(int) $idx] = $value;
            } else {
                $invalid("rows.{$idx}.vat.reverseChargeCode", $action);
            }
        }
        return $pins;
    }

    /** Canonical `supplier.vatId` / `customer.vatId` jako řetězec; chybí → null. */
    public static function partyVatId(mixed $party): ?string
    {
        if (!is_array($party) || !is_scalar($party['vatId'] ?? null)) {
            return null;
        }
        $vatId = trim((string) $party['vatId']);
        return $vatId !== '' ? $vatId : null;
    }

    /**
     * `rows[].vat.supplyKind` položkových řádků (`rowKind` item, bez kontace)
     * pro omezení prefixu DIČ na druh plnění ({@see VatPlaceDerivation});
     * řádek bez druhu = null.
     *
     * @param array<int|string, mixed> $rows
     * @return list<string|null>
     */
    public static function rowSupplyKinds(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || (string) ($row['rowKind'] ?? 'item') !== 'item'
                || isset($row['accSide'])) {
                continue;
            }
            $kind = $row['vat']['supplyKind'] ?? null;
            $out[] = is_string($kind) && trim($kind) !== '' ? trim($kind) : null;
        }
        return $out;
    }

    /**
     * Efektivní druh plnění položkových řádků (tasks/exchange-received-supply-kind.md
     * D2): `rows[].vat.supplyKind` z canonicalu (`source: "ai"`), jinak — jen
     * když efektivní místo ≠ tuzemsko — `crossBorderSupply` štítku řádku
     * (`source: "tag"`; výjimka řádku před štítkem dokladu,
     * {@see RowEnrichmentPipeline::rowContentTagOf()}). `tagSupply` nese
     * hodnotu štítku vždy — veto `special` (D4) kontroluje derivace sama,
     * i proti druhu z AI. Bez bloku `_resolve.contentTag` (pokrytý doklad,
     * žádný LLM běh) fallback druhu plnění není. Canonical se nemění;
     * `changed` = fallback aspoň jeden druh doplnil.
     *
     * Řádek bez štítku z bloku, pokrytý položkou, se mimo tuzemsko
     * klasifikuje podle štítků své položky (#89 D19–D21,
     * {@see SpecialPlaceSupply::classifyRow()}): `special` z položky se
     * chová jako štítek řádku (`tagSupply: "special"`, `specialSource:
     * "item"`) — veto i doklad mimo české DPH fungují beze změny logiky;
     * nejednoznačná položka nese `ambiguous` (chování se nemění, jen
     * varování). `item` = kód položky, ze které klasifikace plyne. Druh
     * plnění se z položky neodvozuje; v tuzemsku se štítky položek nečtou.
     *
     * @param array<string, mixed> $canonical
     * @param array<int|string, mixed> $rows
     * @return array{
     *   rows: array<int, array{
     *     supplyKind: ?string, source: ?string, tag: ?string, tagSupply: ?string,
     *     specialSource: ?string, item: ?string, ambiguous: bool
     *   }>,
     *   changed: bool
     * }
     */
    public function effectiveSupplyKinds(array $canonical, array $rows, ?string $place): array
    {
        $crossBorder = $place !== null && $place !== 'domestic';
        $itemTags = $crossBorder ? $this->itemTagsFor($canonical) : [];
        $out = ['rows' => [], 'changed' => false];
        foreach ($rows as $idx => $row) {
            if (!is_array($row)
                || (string) ($row['rowKind'] ?? 'item') !== 'item'
                || isset($row['accSide'])) {
                continue;
            }
            $idx = (int) $idx;
            $ai = $row['vat']['supplyKind'] ?? null;
            $ai = is_string($ai) && trim($ai) !== '' ? trim($ai) : null;
            $class = SpecialPlaceSupply::classifyRow($canonical, $idx, $this->contentTags, $itemTags);
            $tag = $class['tag'];
            $special = $class['state'] === SpecialPlaceSupply::ROW_SPECIAL;
            $tagSupply = $tag !== null
                ? $this->tagSupplyOf($tag)
                : ($special ? VatCodeDerivation::TAG_SUPPLY_SPECIAL : null);

            $entry = [
                'supplyKind'    => $ai,
                'source'        => $ai !== null ? 'ai' : null,
                'tag'           => $tag,
                'tagSupply'     => $tagSupply,
                'specialSource' => $special ? $class['source'] : null,
                'item'          => $class['item'],
                'ambiguous'     => $class['state'] === SpecialPlaceSupply::ROW_AMBIGUOUS,
            ];
            if ($ai === null && $crossBorder
                && in_array($tagSupply, [VatCodeDerivation::TAG_SUPPLY_GOODS, VatCodeDerivation::TAG_SUPPLY_SERVICES], true)
            ) {
                $entry['supplyKind'] = $tagSupply;
                $entry['source'] = 'tag';
                $out['changed'] = true;
            }
            $out['rows'][$idx] = $entry;
        }
        return $out;
    }

    /**
     * Štítky položek řádků, které se klasifikují podle položky (#89 D19) —
     * jeden dotaz na doklad ({@see ItemContentTags}), memoizovaný podle sady
     * kódů. Bez taxonomie nebo bez takového řádku žádný dotaz.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, list<string>>
     */
    public function itemTagsFor(array $canonical): array
    {
        if ($this->contentTags === []) {
            return [];
        }
        $codes = SpecialPlaceSupply::itemCodes($canonical);
        if ($codes === []) {
            return [];
        }
        sort($codes);
        $key = implode("\n", $codes);
        if ($this->itemTagsCache === null || $this->itemTagsCache['key'] !== $key) {
            $this->itemTagsCache = ['key' => $key, 'tags' => ItemContentTags::forCodes($this->db, $codes)];
        }
        return $this->itemTagsCache['tags'];
    }

    /** `crossBorderSupply` štítku z taxonomie; neznámá hodnota nebo štítek = null. */
    public function tagSupplyOf(string $tag): ?string
    {
        return SpecialPlaceSupply::tagSupplyOf($this->contentTags, $tag);
    }

    /** Lokalizovaný `name` štítku z taxonomie (compiled config jazyka requestu), fallback klíč. */
    public function contentTagLabel(string $tag): string
    {
        $name = $this->contentTags[$tag]['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : $tag;
    }

    /**
     * Naše registrace DPH pro přijatý doklad v návrhu (D2): první aktivní
     * podle `country`, `id`, s datem jen registrace k němu platná — pravidlo
     * drží `DocHeadDefaults::vatRegistration()`, stejné pro formulář, pokyn
     * i návrh. Null s datem znamená, že zdroj k datu plátcem nebyl
     * (docs/ds-setup.md D5, #97 D2).
     *
     * @param string|null $date `YYYY-MM-DD`, null = bez ohledu na platnost
     * @return array{id: int, country: string}|null
     */
    public function ownVatRegistration(?string $date): ?array
    {
        return $this->payload->headDefaults()->vatRegistration($date);
    }

    /**
     * Rozhodnutí o kódu DPH jednoho řádku (tabulka chování v tasku):
     *
     *   prázdný kód + derivace          → odvozený kód, bez issue
     *   platný kód v souladu se signály → beze změny (dnešní chování)
     *   neznámý / v rozporu + derivace  → odvozený kód, warning vat_code_derived
     *   neznámý / prázdný bez derivace  → error vat_code_unknown s důvodem
     *   prázdný kód bez signálů         → nic (jako dnes)
     *   volba uživatele (useCode, #87 B) → zvolený kód, matchedBy user; mimo
     *                                      nabídku error vat_code_pin_invalid,
     *                                      v rozporu se signály warning
     *                                      vat_code_pin_conflict (volba platí)
     *
     * Kód z historie řádků (RowHistoryEnricher) sem přijde jako vyplněný —
     * proto kontrola souladu, jinak by stará chybná historie přebila derivaci.
     * Kontrola souladu dostane jen druh plnění z canonicalu, ne fallback ze
     * štítku — kód potvrzený člověkem štítek nezpochybňuje.
     *
     * @param array<string, mixed> $rowVat canonical `rows[].vat`
     * @param array{supplyKind: ?string, source: ?string, tag: ?string, tagSupply: ?string, specialSource: ?string, item: ?string, ambiguous: bool} $kind
     *        efektivní druh plnění řádku ({@see effectiveSupplyKinds()})
     * @param string|null $date    datum sazby řádku ({@see PayloadContext::lineRateDate()}, #159 U3) — ne DUZP
     * @param string|null $pinned  kód zvolený uživatelem (`useCode:`), {@see vatPins()}
     * @param list<array{code: string, label: string, pct: float, reverseCharge: bool, reducedDeduction: bool, supplyKind: ?string}> $options
     *        nabídka kódů k efektivnímu místu a datu sazby řádku (D13) — jediná platná množina pinů
     * @return array{
     *   input: string, derived: ?string, reason: ?string,
     *   effective: ?string, matchedBy: ?string,
     *   issue: ?array{severity: string, code: string, message: string},
     *   supplyKind: ?string, supplyKindSource: ?string, tag: ?string, reasonKind: ?string,
     *   specialSource: ?string, specialItem: ?string, ambiguousItem: ?string
     * }
     *   `specialSource` tag | item = odkud je `special` řádku, `specialItem`
     *   kód položky u zdroje item; `ambiguousItem` = kód nejednoznačné
     *   položky (#89 D21) — zda se varuje, rozhoduje {@see context()}.
     */
    public function decideRowVatCode(
        string $country,
        ?string $date,
        ?string $place,
        ?bool $reverseCharge,
        array $rowVat,
        array $kind,
        ?string $pinned = null,
        array $options = [],
    ): array {
        $input = trim((string) ($rowVat['code'] ?? ''));
        $pct = isset($rowVat['pct']) && is_numeric($rowVat['pct']) ? (float) $rowVat['pct'] : null;
        $supplyKind = $kind['supplyKind'];
        $reverseChargeCode = isset($rowVat['reverseChargeCode']) && (string) $rowVat['reverseChargeCode'] !== ''
            ? (string) $rowVat['reverseChargeCode']
            : null;
        $hasSignals = $pct !== null || $supplyKind !== null || $reverseChargeCode !== null
            || $place !== null || $reverseCharge !== null;

        $derived = $date !== null
            ? $this->vatCodeDerivation->derive(
                $country,
                $date,
                $place,
                $reverseCharge,
                $pct,
                $supplyKind,
                $kind['tagSupply'],
            )
            : ['code' => null, 'reason' => 'doklad nemá datum, ke kterému by se určila sazba DPH', 'kind' => null];

        $out = [
            'input'            => $input,
            'derived'          => $derived['code'],
            'reason'           => $derived['reason'],
            'effective'        => null,
            'matchedBy'        => null,
            'issue'            => null,
            'supplyKind'       => $supplyKind,
            'supplyKindSource' => $kind['source'],
            'tag'              => $kind['tag'],
            // Druh důvodu selhání derivace (zahraniční DPH / zvláštní místo), #89.
            'reasonKind'       => $derived['kind'],
            'specialSource'    => $kind['specialSource'],
            'specialItem'      => $kind['specialSource'] === SpecialPlaceSupply::SOURCE_ITEM ? $kind['item'] : null,
            'ambiguousItem'    => $kind['ambiguous'] ? $kind['item'] : null,
        ];
        $useDerived = static function (array $out, string $code, ?array $issue): array {
            $out['effective'] = $code;
            $out['matchedBy'] = 'derived';
            $out['issue'] = $issue;
            return $out;
        };

        // #87 task B (D10–D12): volba uživatele má nejvyšší prioritu. Platí
        // jen kód z nabídky (táž množina, kterou dostal klient — D13);
        // rozpor se signály dokladu volbu neruší, jen se ohlásí. Derivace
        // se spočítala i tak — odvozený kód patří do zprávy konfliktu.
        if ($pinned !== null) {
            if (!in_array($pinned, array_column($options, 'code'), true)) {
                $out['issue'] = [
                    'severity' => 'error',
                    'code'     => 'vat_code_pin_invalid',
                    'message'  => "Zvolený kód DPH „{$pinned}“ není v nabídce pro toto místo plnění a datum — vyber jiný.",
                ];
                return $out;
            }
            $out['effective'] = $pinned;
            $out['matchedBy'] = 'user';
            $conflict = $date !== null
                ? $this->vatCodeDerivation->conflict(
                    $country,
                    $pinned,
                    $date,
                    $place,
                    $reverseCharge,
                    $pct,
                    $kind['source'] === 'ai' ? $supplyKind : null,
                )
                : null;
            if ($conflict !== null) {
                $hint = $derived['code'] !== null && $derived['code'] !== $pinned
                    ? "; odvozený by byl „{$derived['code']}“"
                    : '';
                $out['issue'] = [
                    'severity' => 'warning',
                    'code'     => 'vat_code_pin_conflict',
                    'message'  => "Zvolený kód DPH „{$pinned}“ neodpovídá dokladu ({$conflict}{$hint}) — platí volba.",
                ];
            }
            return $out;
        }

        if ($input === '') {
            if ($derived['code'] !== null) {
                return $useDerived($out, $derived['code'], null);
            }
            if (!$hasSignals) {
                return $out;
            }
            $out['issue'] = [
                'severity' => 'error',
                'code'     => 'vat_code_unknown',
                'message'  => "Kód DPH řádku nejde odvodit ({$derived['reason']}) — " . self::unknownVatCodeAdvice($derived['kind']),
            ];
            return $out;
        }

        $known = $this->vatCodeResolver->resolve($input, $country, $date, $pct)->status === ResolveStatus::Matched;
        if ($known) {
            $conflict = $date !== null
                ? $this->vatCodeDerivation->conflict(
                    $country,
                    $input,
                    $date,
                    $place,
                    $reverseCharge,
                    $pct,
                    $kind['source'] === 'ai' ? $supplyKind : null,
                )
                : null;
            if ($conflict === null || $derived['code'] === $input) {
                $out['effective'] = $input;
                $out['matchedBy'] = 'input';
                return $out;
            }
            if ($derived['code'] !== null) {
                return $useDerived($out, $derived['code'], [
                    'severity' => 'warning',
                    'code'     => 'vat_code_derived',
                    'message'  => "Kód DPH „{$input}“ neodpovídá dokladu ({$conflict}) — nahrazen odvozeným „{$derived['code']}“.",
                ]);
            }
            $out['issue'] = [
                'severity' => 'error',
                'code'     => 'vat_code_unknown',
                'message'  => "Kód DPH „{$input}“ neodpovídá dokladu ({$conflict}) a jiný nejde odvodit"
                    . " ({$derived['reason']}) — " . self::unknownVatCodeAdvice($derived['kind']),
            ];
            return $out;
        }

        if ($derived['code'] !== null) {
            return $useDerived($out, $derived['code'], [
                'severity' => 'warning',
                'code'     => 'vat_code_derived',
                'message'  => "Neznámý kód DPH „{$input}“ — nahrazen odvozeným „{$derived['code']}“.",
            ]);
        }
        $out['issue'] = [
            'severity' => 'error',
            'code'     => 'vat_code_unknown',
            'message'  => "Neznámý kód DPH „{$input}“; kód nejde odvodit ({$derived['reason']}) — "
                . self::unknownVatCodeAdvice($derived['kind']),
        ];
        return $out;
    }

    /**
     * Rada na konci zprávy `vat_code_unknown`. U zahraniční DPH a zvláštního
     * místa plnění, které nevedly k dokladu mimo české DPH (smíšený doklad,
     * zvolený režim s DPH), má uživatel dvě cesty v náhledu (#89 D4).
     */
    public static function unknownVatCodeAdvice(?string $kind): string
    {
        return $kind !== null
            ? 'vyber kód DPH u řádku, nebo zvol režim Bez DPH.'
            : 'doklad založ ručně.';
    }

    /**
     * `_resolve.rows[].vatCode` jednoho řádku dokladu z papíru: rozhodnutí
     * interpretace ({@see rowVatCode()}), a kde nerozhoduje, kód z payloadu
     * ověřený proti číselníku — s procentem z dokladu jako zálohou, když kód
     * k datu sazbu nemá. Null = řádek blok nemá.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $vatCtx {@see context()}
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>|null
     */
    public function resolveRowVatCode(
        int $idx,
        array $row,
        array $vatCtx,
        string $vatCountry,
        string $supplierCountry,
        array &$issues,
    ): ?array {
        $decided = $this->rowVatCode($idx, $row, $vatCtx, $vatCountry, $issues);
        if ($decided !== null) {
            return $decided['block'];
        }
        $pct = is_array($row['vat'] ?? null) ? ($row['vat']['pct'] ?? null) : null;
        return $this->resolve->rowVatCode(
            $idx,
            $row,
            $vatCtx,
            $vatCountry,
            $supplierCountry,
            $issues,
            is_numeric($pct) ? (float) $pct : null,
        );
    }

    /**
     * Rozhodnutí interpretace o `_resolve.rows[].vatCode` jednoho řádku:
     * odvozený, ponechaný nebo zvolený kód, řádek dokladu Bez DPH, nálezy.
     * `block` null = řádek blok nemá (bez kódu a není z čeho odvozovat).
     *
     * Vrací **null**, když o řádku nerozhoduje (vystavený a účetní doklad,
     * zdroj bez registrace DPH, řádek bez objektu `vat`) — kód z payloadu
     * pak volající jen ověří (`PayloadResolve::rowVatCode()`).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $vatCtx {@see context()}
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{block: array<string, mixed>|null}|null
     */
    public function rowVatCode(
        int $idx,
        array $row,
        array $vatCtx,
        string $vatCountry,
        array &$issues,
    ): ?array {
        $rowVat = is_array($row['vat'] ?? null) ? $row['vat'] : [];
        $pct = isset($rowVat['pct']) && is_numeric($rowVat['pct']) ? (float) $rowVat['pct'] : null;
        // Sazba k datu sazby řádku (#159 U3; opravný doklad = původní plnění,
        // #92 D4) — totéž datum, ke kterému procento odvodí dokument (D1).
        $date = $this->payload->lineRateDate($vatCtx, $rowVat['rateDate'] ?? null);
        $decision = $vatCtx['rows'][$idx] ?? null;

        if ($decision === null) {
            // Vystavené a účetní doklady, zdroj bez registrace DPH, řádek bez
            // objektu vat. Volba kódu tu neplatí (D9, #87 B).
            if (isset($vatCtx['pins']['rows'][$idx])) {
                $issues[] = [
                    'severity' => 'info',
                    'path'     => "rows.{$idx}.vat.code",
                    'code'     => 'vat_pin_ignored',
                    'message'  => $vatCtx['outOfScope'] !== null
                        ? 'Doklad má zvolený režim Bez DPH — volba kódu DPH řádku se nepoužije.'
                        : 'Volba kódu DPH platí jen u přijatého dokladu na zdroji s registrací DPH — ignorována.',
                ];
            }
            if ($vatCtx['withoutVat']) {
                // #97: na doklad Bez DPH se kód nepropisuje, proto se ani
                // neresolvuje — neznámý kód z historie řádků by jinak
                // zablokoval apply kvůli hodnotě, která se zahodí.
                // #89: doklad mimo české DPH má blok bez kódu — náhled na něm
                // drží volbu kódu, kterou se uživatel vrátí k samovyměření.
                if ($vatCtx['outOfScope'] === null || !self::rowHasVatDecision($row)) {
                    return ['block' => null];
                }
                // Odkud je `special` řádku (štítek z bloku / štítky položky, D19).
                $source = $vatCtx['outOfScope'] === VatCodeDerivation::FAIL_SPECIAL_PLACE
                    ? ($vatCtx['special'][$idx]['source'] ?? null)
                    : null;
                return ['block' => self::OUT_OF_SCOPE_VAT_CODE + ($source !== null ? ['specialSource' => $source] : [])];
            }
            // Kód z payloadu se jen ověří — {@see PayloadResolve::rowVatCode()}.
            return null;
        }

        // D2: druh plnění doplněný ze štítku — hlásí se, jen když na něm
        // výsledek stojí (odvozený kód, nebo derivace selhala); ponechaný
        // kód z canonicalu / historie fallback nepotřeboval → bez šumu.
        $fromTag = $decision['supplyKindSource'] === 'tag'
            && !in_array($decision['matchedBy'], ['input', 'user'], true);
        if ($fromTag) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => "rows.{$idx}.vat.supplyKind",
                'code'     => 'supply_kind_derived',
                'message'  => sprintf(
                    'Druh plnění řádku doplněn podle štítku „%s“ (%s).',
                    $this->contentTagLabel((string) $decision['tag']),
                    $decision['supplyKind'] === VatCodeDerivation::TAG_SUPPLY_GOODS ? 'zboží' : 'služby',
                ),
            ];
        }
        // Veto zvláštního místa plnění (D4) ze štítků položky (D20) — zpráva
        // řekne, odkud se vzalo; u štítku z bloku ho náhled ukazuje sám.
        $vetoFromItem = $decision['reasonKind'] === VatCodeDerivation::FAIL_SPECIAL_PLACE
            && $decision['specialItem'] !== null;
        if ($decision['issue'] !== null) {
            $issues[] = [
                'severity' => $decision['issue']['severity'],
                'path'     => "rows.{$idx}.vat.code",
                'code'     => $decision['issue']['code'],
                'message'  => $decision['issue']['message']
                    . ($vetoFromItem && $decision['effective'] === null
                        ? " Zvláštní místo plnění plyne ze štítků položky „{$decision['specialItem']}“."
                        : ''),
            ];
        }
        if ($decision['ambiguousItem'] !== null) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => "rows.{$idx}.vat.code",
                'code'     => 'vat_special_place_ambiguous',
                'message'  => "Položka „{$decision['ambiguousItem']}“ nese štítky s různým místem plnění — nedá se poznat,"
                    . ' jestli jde o službu se zvláštním místem plnění (ubytování, doprava osob, parkování, mýto …).'
                    . ' Návrh počítá se samovyměřením; pokud se daň v ČR nepřiznává, přepni režim DPH na Bez DPH.',
            ];
        }
        if ($decision['effective'] === null) {
            if ($decision['issue'] === null) {
                return ['block' => null];
            }
            $notFound = ResolveResult::notFound()->toArray();
            if ($fromTag) {
                $notFound['supplyKindSource'] = 'tag';
            }
            if ($decision['reasonKind'] === VatCodeDerivation::FAIL_SPECIAL_PLACE && $decision['specialSource'] !== null) {
                $notFound['specialSource'] = $decision['specialSource'];
            }
            return ['block' => $notFound];
        }
        // Odvozený i zvolený kód dostane sazbu z číselníku k datu sazby řádku
        // (D4; #87 B D10) — `pct` z dokladu dodavatele (u samovyměření 0) se
        // u nich nepoužije ani jako fallback.
        $fromCodebook = in_array($decision['matchedBy'], ['derived', 'user'], true);
        $resolved = $this->vatCodeResolver
            ->resolve($decision['effective'], $vatCountry, $date, $fromCodebook ? null : $pct)
            ->toArray();
        if ($fromCodebook) {
            $resolved['matchedBy'] = $decision['matchedBy'];
            if ($fromTag) {
                $resolved['supplyKindSource'] = 'tag';
            }
        }
        return ['block' => $resolved];
    }

    /**
     * Kód, který na položkovém řádku skončí: rozhodnutí z kontextu (odvozený
     * nebo ponechaný), mimo derivaci kód z canonicalu. Prázdný = žádný.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $vatCtx {@see context()}
     */
    public function effectiveRowVatCode(int $idx, array $row, array $vatCtx): string
    {
        if (isset($vatCtx['rows'][$idx])) {
            return (string) ($vatCtx['rows'][$idx]['effective'] ?? '');
        }
        return trim((string) ($row['vat']['code'] ?? ''));
    }

    /**
     * D3: má některý položkový řádek (po derivaci) kód s `reverseVatCode`?
     * Jen u přijatého dokladu na zdroji s registrací DPH — jinde se
     * rekapitulace řídí dnešními pravidly.
     *
     * @param array<string, mixed> $vatCtx {@see context()}
     */
    public function rowsCarryReverseCharge(array $vatCtx): bool
    {
        if (!$vatCtx['derive']) {
            return false;
        }
        return $this->decisionsCarryReverseCharge(
            $vatCtx['rows'],
            (string) $vatCtx['ownCountry'],
            $vatCtx['taxPointDate'],
        );
    }

    /**
     * Má některé rozhodnutí o kódu řádku ({@see decideRowVatCode()}) kód
     * s `reverseVatCode`?
     *
     * @param array<int, array<string, mixed>> $decisions
     */
    public function decisionsCarryReverseCharge(array $decisions, string $country, ?string $date): bool
    {
        foreach ($decisions as $decision) {
            if ($decision['effective'] === null) {
                continue;
            }
            $codeR = $this->vatCodeResolver->resolve(
                $decision['effective'],
                $country,
                $date,
                null,
            );
            if ($codeR->status === ResolveStatus::Matched && !empty($codeR->createPayload['reverseVatCode'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Hlavičkové DPH issues, které transform() hlásit nemůže — běží až
     * v transakci apply po sestavení `_resolve` a preview ho nevolá:
     *
     * - D5: neznámé `vat.mode` / `vat.place` → warning místo tichého
     *   fallbacku (transform dál padá na fromBase / tuzemsko). Schéma má
     *   obě pole jako enum, takže sem takový payload dojde jen mimo
     *   schema validaci — pojistka, ne hlavní cesta.
     * - `vat.place` přijatého dokladu odvozené z DIČ dodavatele
     *   (tasks/exchange-received-vat-place.md D1) v rozporu s neprázdnou
     *   hodnotou z AI → warning `vat_place_derived`; neznámou hodnotu
     *   derivace nahradila, `vat_place_unknown` se pak nehlásí.
     * - D2: `vat.registrationCountry` z AI / ISDOC v rozporu s naší
     *   registrací → info, hodnota se nepoužije.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function appendVatHeaderIssues(array $canonical, array &$issues): void
    {
        $vat = is_array($canonical['vat'] ?? null) ? $canonical['vat'] : [];
        $vatCtx = $this->context($canonical);

        // Volby DPH (#87 B): neplatná hodnota = error (D11, všechny cesty);
        // mimo větev derive se volby ignorují s info issue (D9) — hlavičkové
        // tady, řádkové hlásí resolveRowVatCode().
        foreach ($vatCtx['pins']['invalid'] as $invalid) {
            $issues[] = $invalid;
        }
        if (!$vatCtx['derive']) {
            foreach (['place' => 'místa plnění', 'mode' => 'režimu DPH'] as $field => $label) {
                if ($vatCtx['pins'][$field] !== null) {
                    $issues[] = [
                        'severity' => 'info',
                        'path'     => "vat.{$field}",
                        'code'     => 'vat_pin_ignored',
                        'message'  => "Volba {$label} platí jen u přijatého dokladu na zdroji s registrací DPH — ignorována.",
                    ];
                }
            }
        }

        $unknownMode = PayloadContext::unknownVatModeIssue($canonical);
        if ($unknownMode !== null) {
            $issues[] = $unknownMode;
        }
        $place = $vat['place'] ?? null;
        if ($vatCtx['placeSource'] === 'vatId') {
            if ($place !== null && $place !== $vatCtx['place']) {
                $label = is_scalar($place) ? (string) $place : (string) json_encode($place);
                $issues[] = [
                    'severity' => 'warning',
                    'path'     => 'vat.place',
                    'code'     => 'vat_place_derived',
                    'message'  => sprintf(
                        'Místo plnění „%s“ z návrhu nahrazeno „%s“ podle DIČ dodavatele (prefix %s).',
                        $this->vatPlaceLabel($label),
                        $this->vatPlaceLabel((string) $vatCtx['place']),
                        (string) $vatCtx['placePrefix'],
                    ),
                ];
            }
        } elseif ($vatCtx['placeSource'] !== 'user') {
            // Zvolené místo (D10) neznámou hodnotu z AI přebíjí — hláška
            // „použije se tuzemsko“ by lhala.
            $unknownPlace = PayloadContext::unknownVatPlaceIssue($canonical);
            if ($unknownPlace !== null) {
                $issues[] = $unknownPlace;
            }
        }

        $declared = strtolower(trim((string) ($vat['registrationCountry'] ?? '')));
        if ($vatCtx['derive'] && $declared !== '' && $declared !== $vatCtx['ownCountry']) {
            $issues[] = [
                'severity' => 'info',
                'path'     => 'vat.registrationCountry',
                'code'     => 'vat_registration_country_derived',
                'message'  => sprintf(
                    'Země registrace DPH „%s“ z dokladu se u přijatého dokladu nepoužije'
                        . ' — kódy DPH i registrace jdou podle naší registrace (%s).',
                    strtoupper($declared),
                    strtoupper((string) $vatCtx['ownCountry']),
                ),
            ];
        }
    }

    /**
     * Název místa plnění pro zprávu issue z cfgItem `docs.core.vatPlaces`
     * (`name:cs` v surové, `name` v kompilované konfiguraci — zprávy
     * applieru jsou česky). Neznámý klíč nebo chybějící cfgItem → klíč,
     * jak přišel.
     */
    public function vatPlaceLabel(string $canonicalPlace): string
    {
        $idx = DocumentApplier::VAT_PLACE_MAP[$canonicalPlace] ?? null;
        if ($idx === null) {
            return $canonicalPlace;
        }
        $places = $this->config->cfgItem('docs.core.vatPlaces');
        $def = is_array($places) ? ($places[(string) $idx] ?? null) : null;
        if (!is_array($def)) {
            return $canonicalPlace;
        }
        $name = $def['name:cs'] ?? $def['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : $canonicalPlace;
    }

    /**
     * Když derivace ({@see VatModeDerivation}) přebije deklarovaný
     * `vat.mode`, přidá do `_resolve.issues` warning `vat_mode_derived`,
     * aby korekce byla viditelná v review modalu. Volá se z preview()
     * i apply() — transform() běží až v transakci, po sestavení
     * `_resolve` bloku (a preview transform vůbec nevolá).
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function appendVatModeIssue(array $canonical, array &$issues): void
    {
        $vatCtx = $this->context($canonical);
        if ($vatCtx['nonPayer']) {
            $this->appendNonPayerIssues($canonical, $issues);
            return;
        }
        if ($vatCtx['outOfScope'] !== null) {
            $this->appendOutOfScopeIssues($vatCtx['outOfScope'], $vatCtx['special'], $issues);
            return;
        }
        $effective = $this->effectiveVatMode($canonical, $vatCtx);
        if ($effective['pinned']) {
            // D10/D11 (#87 B): zvolený režim se nehlásí jako odvozený (ani
            // tiché přepnutí none → fromBase u samovyměření) a podezření
            // validátoru — odhad bez znalosti volby — by jen mátlo.
            $issues = array_values(array_filter(
                $issues,
                static fn (array $issue): bool => ($issue['code'] ?? null) !== 'vat_mode_suspect',
            ));
            return;
        }
        if ($effective['source'] !== 'derived') {
            return;
        }
        $issues[] = [
            'severity' => 'warning',
            'path'     => 'vat.mode',
            'code'     => 'vat_mode_derived',
            'message'  => match ($effective['reason']) {
                'reverseCharge' => 'Doklad uvádí režim bez DPH, ale řádky jsou v přenesení daňové povinnosti'
                    . ' — režim výpočtu odvozen zdola (fromBase).',
                'rowsWithVat'   => 'Řádky jsou v cenách s DPH — režim výpočtu odvozen shora (fromTotal).',
                default         => 'Řádky jsou v cenách bez DPH — režim výpočtu odvozen zdola (fromBase).',
            },
        ];
    }

    /**
     * Issues přijatého dokladu neplátce DPH (#97 D7, D9) — místo hlášek
     * o režimu a rekapitulaci, které jsou u něj dané a jen by mátly
     * (`vat_mode_derived`, podezření validátoru `vat_mode_suspect`,
     * `recap_source_computed_fallback`):
     *
     * - info `vat_non_payer`, když doklad nese daň dodavatele — vysvětlí,
     *   proč jsou ceny řádků jiné než na předloze. Doklad od neplátce nic
     *   nehlásí, nic se na něm nemění.
     * - warning `non_payer_reverse_charge`, když doklad nese přenesení
     *   daňové povinnosti: neplátce, který je identifikovanou osobou, daň
     *   přiznat musí, applier ji ale nevyměří (D9, mimo rozsah).
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function appendNonPayerIssues(array $canonical, array &$issues): void
    {
        $issues = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['code'] ?? null) !== 'vat_mode_suspect',
        ));
        if (SupplierVatPrices::carriesSupplierVat($canonical)) {
            $issues[] = [
                'severity' => 'info',
                'path'     => 'vat.mode',
                'code'     => 'vat_non_payer',
                'message'  => 'K datu dokladu neplatí žádná registrace DPH (neplátce) — doklad vznikne bez DPH'
                    . ' a daň dodavatele je součástí cen řádků.',
            ];
        }

        $reverseCharge = ($canonical['vat']['reverseCharge'] ?? null) === true;
        foreach ((array) ($canonical['rows'] ?? []) as $row) {
            if (is_array($row) && trim((string) ($row['vat']['reverseChargeCode'] ?? '')) !== '') {
                $reverseCharge = true;
                break;
            }
        }
        if ($reverseCharge) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'vat.reverseCharge',
                'code'     => 'non_payer_reverse_charge',
                'message'  => 'Doklad je v přenesení daňové povinnosti, ale k datu dokladu neplatí žádná registrace DPH'
                    . ' — daň se na dokladu nevyměří. Identifikovaná osoba ji musí přiznat mimo tento doklad.',
            ];
        }
    }

    /**
     * Issues dokladu mimo české DPH (#89 D2–D4) — místo hlášek o režimu,
     * kódech a rekapitulaci, které u dokladu Bez DPH nedávají smysl
     * (`vat_mode_derived`, `vat_mode_suspect`; `vat_code_unknown`,
     * `vat_code_derived` a `recap_source_computed_fallback` nevzniknou,
     * protože se kódy nerozhodují a rekapitulace nepřebírá):
     *
     * - info `vat_foreign_tax` — dodavatel účtuje vlastní daň, je v cenách
     *   řádků; vysvětlí, proč jsou ceny jiné než na předloze.
     * - warning `vat_special_place` — služba se zvláštním místem plnění
     *   bez daně na dokladu; rozhodnutí stojí na štítku, proto varování
     *   s cestou zpět. Když `special` plyne ze štítků položky pokrytého
     *   řádku (D19, D20), zpráva položku jmenuje.
     * - warning `vat_out_of_scope_user` (D4) — režim Bez DPH zvolil
     *   uživatel; připomene, že se daň v ČR nepřizná.
     * - info `vat_supplier_non_payer` (#164 D5a) — doklad od neplátce
     *   v tuzemsku; vysvětlí, proč řádky nemají kód a doklad rekapitulaci.
     *
     * @param array<int, array{source: string, item: ?string}> $special {@see context()} `special`
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function appendOutOfScopeIssues(string $kind, array $special, array &$issues): void
    {
        $items = array_values(array_unique(array_filter(array_column($special, 'item'))));
        $fromItems = match (count($items)) {
            0       => '',
            1       => " Místo plnění plyne ze štítků položky „{$items[0]}“.",
            default => ' Místo plnění plyne ze štítků položek „' . implode('“, „', $items) . '“.',
        };
        $issues = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['code'] ?? null) !== 'vat_mode_suspect',
        ));
        $issues[] = match ($kind) {
            self::OUT_OF_SCOPE_USER => [
                'severity' => 'warning',
                'path'     => 'vat.mode',
                'code'     => 'vat_out_of_scope_user',
                'message'  => 'Doklad od dodavatele mimo tuzemsko vznikne bez DPH — daň se v ČR nepřizná.',
            ],
            self::OUT_OF_SCOPE_SUPPLIER_NON_PAYER => [
                'severity' => 'info',
                'path'     => 'vat.mode',
                'code'     => 'vat_supplier_non_payer',
                'message'  => 'Doklad od neplátce DPH — vznikne bez DPH, řádky nemají kód DPH'
                    . ' a doklad nemá rekapitulaci. Pokud dodavatel plátcem je, zvol režim s DPH.',
            ],
            VatCodeDerivation::FAIL_SPECIAL_PLACE => [
                'severity' => 'warning',
                'path'     => 'vat.mode',
                'code'     => 'vat_special_place',
                'message'  => 'Služba se zvláštním místem plnění (ubytování, doprava osob, stravování, nemovitost,'
                    . ' mýto, parkování) — doklad vznikne bez DPH. Pokud daň v ČR přiznat máš, vyber kód DPH u řádku.'
                    . $fromItems,
            ],
            default => [
                'severity' => 'info',
                'path'     => 'vat.mode',
                'code'     => 'vat_foreign_tax',
                'message'  => 'Dodavatel mimo tuzemsko účtuje vlastní DPH — doklad vznikne bez DPH'
                    . ' a daň dodavatele je součástí cen řádků.',
            ],
        };
    }

    /**
     * Režim výpočtu, jak ho říká doklad: deklarovaný `vat.mode` (default
     * fromBase), u jiného než „Bez DPH“ ověřený proti číslům
     * ({@see VatModeDerivation}). Říká, **v jakých cenách jsou řádky** —
     * čte ho {@see effectiveVatMode()} i převod cen neplátce
     * ({@see supplierVatGrossTotals()}), který výsledný režim dokladu mění.
     *
     * Pokyn (#166 D20) režim neověřuje proti číslům: `vat.mode` je záměr,
     * chybí = `fromBase`.
     *
     * @param array<string, mixed> $canonical
     * @return array{mode: string, source: string, reason: ?string}
     *         source ai | default | derived; reason jen u derived.
     */
    public function documentVatMode(array $canonical): array
    {
        ['mode' => $vatMode, 'source' => $source] = $this->payload->declaredVatMode($canonical);
        $reason = null;
        $derived = $vatMode !== 'none' ? VatModeDerivation::derive($canonical) : null;
        if ($derived !== null && $derived !== DocumentApplier::VAT_MODE_MAP[$vatMode]) {
            $vatMode = $derived === 2 ? 'fromTotal' : 'fromBase';
            $source = 'derived';
            $reason = $derived === 2 ? 'rowsWithVat' : 'rowsWithoutVat';
        }
        return ['mode' => $vatMode, 'source' => $source, 'reason' => $reason];
    }

    /**
     * Efektivní režim výpočtu DPH dokladu — jediné místo pro transform(),
     * appendVatModeIssue() i blok `_resolve.vat.mode` (D14), aby se zrcadla
     * nerozešla. Priorita: neplátce DPH (#97 D3) → volba uživatele (jen ve
     * větvi derive, D9/D10) → VatModeDerivation (kromě „Bez DPH“) →
     * canonical → default fromBase.
     * „Bez DPH“ se samovyměřením se vždy přepne na fromBase — DocDocument
     * by při vat_mode 0 rekapitulaci nestavěl a nárok i oddanění by se
     * ztratily (D1 z #86); proti volbě tiše (D11).
     *
     * Přijatý doklad neplátce je vždy „Bez DPH“ (`source: "nonPayer"`),
     * před derivací i ochranou samovyměření — ta stojí na registraci
     * a neplátce by s `vat_mode` 1 padl na povinné registraci DPH.
     *
     * Doklad mimo české DPH (#89 D2–D4, #164 D5a) je „Bez DPH“ se zdrojem
     * `outOfScope` a důvodem `foreignVat` / `specialPlace` / `user` /
     * `supplierNonPayer` (doklad od neplátce v tuzemsku).
     * Důvod `user` (D4) mění D11 z #87 jen mimo tuzemsko: tam volba Bez DPH
     * platí; v tuzemsku a u `none` deklarovaného AI zůstává tiché přepnutí.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $vatCtx {@see context()}
     * @return array{value: string, source: string, reason: ?string, pinned: bool, auto: string}
     *         value klíč VAT_MODE_MAP; source user | derived | ai | default | nonPayer | outOfScope;
     *         reason u derived (reverseCharge | rowsWithVat | rowsWithoutVat)
     *         a u outOfScope (foreignVat | specialPlace | user | supplierNonPayer);
     *         auto = hodnota bez volby uživatele (náhled: „Automaticky (…)“).
     */
    public function effectiveVatMode(array $canonical, array $vatCtx): array
    {
        if ($vatCtx['nonPayer']) {
            return ['value' => 'none', 'source' => 'nonPayer', 'reason' => null, 'pinned' => false, 'auto' => 'none'];
        }
        // Režim Bez DPH zvolil uživatel: mimo tuzemsko druh `user` (D4),
        // v tuzemsku doklad od neplátce z volby (#164 U10).
        $userOutOfScope = $vatCtx['outOfScope'] !== null
            && $vatCtx['derive'] && $vatCtx['pins']['mode'] === 'none';
        if ($vatCtx['outOfScope'] !== null && !$userOutOfScope) {
            return [
                'value'  => 'none',
                'source' => 'outOfScope',
                'reason' => $vatCtx['outOfScope'],
                'pinned' => false,
                'auto'   => 'none',
            ];
        }
        $pin = $vatCtx['derive'] ? $vatCtx['pins']['mode'] : null;

        // Automatická hodnota se počítá vždy — i při volbě ji náhled ukazuje.
        ['mode' => $auto, 'source' => $autoSource, 'reason' => $reason] = $this->documentVatMode($canonical);
        if ($userOutOfScope) {
            // D4: volba Bez DPH mimo tuzemsko platí. „Automaticky“ = co by
            // vyšlo bez ní — doklad mimo DPH sám od sebe, nebo režim dokladu
            // s ochranou samovyměření (řádky se u dokladu mimo DPH nerozhodují,
            // proto příznak z kontextu).
            if ($vatCtx['autoOutOfScope'] !== null) {
                $auto = 'none';
            } elseif ($auto === 'none' && $vatCtx['autoReverseCharge']) {
                $auto = 'fromBase';
            }
            return [
                'value'  => 'none',
                'source' => 'outOfScope',
                'reason' => $vatCtx['outOfScope'],
                'pinned' => true,
                'auto'   => $auto,
            ];
        }
        if ($auto === 'none' && $this->rowsCarryReverseCharge($vatCtx)) {
            $auto = 'fromBase';
            $autoSource = 'derived';
            $reason = 'reverseCharge';
        }
        if ($pin === null) {
            return ['value' => $auto, 'source' => $autoSource, 'reason' => $reason, 'pinned' => false, 'auto' => $auto];
        }

        $value = $pin;
        $source = 'user';
        if ($value === 'none' && $this->rowsCarryReverseCharge($vatCtx)) {
            $value = 'fromBase';
            $source = 'derived';
        }
        return ['value' => $value, 'source' => $source, 'reason' => null, 'pinned' => true, 'auto' => $auto];
    }

    /**
     * Ceny položkových řádků **včetně daně dodavatele** ({@see SupplierVatPrices},
     * #97 D4, D5): index canonicalu → cena, která skončí na řádku jako
     * `total_price`. Jediné místo, kde applier o převodu rozhoduje — čte ho
     * `transform()` při apply i v náhledu.
     *
     * Prázdné pole = nic se nepřevádí: doklad nevzniká Bez DPH z rozhodnutí
     * applieru (`withoutVat` — neplátce, doklad mimo české
     * DPH), nebo daň dodavatele nenese
     * ({@see SupplierVatPrices::carriesSupplierVat()}).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $vatCtx {@see context()}
     * @return array<int, float>
     */
    public function supplierVatGrossTotals(array $canonical, array $vatCtx): array
    {
        if (!$vatCtx['withoutVat']
            || !SupplierVatPrices::carriesSupplierVat($canonical)
        ) {
            return [];
        }
        $cfgOps = $this->config->cfgItem('docs.core.rowOperations');
        return SupplierVatPrices::grossTotals(
            $canonical,
            $this->documentVatMode($canonical)['mode'] === 'fromTotal',
            is_array($cfgOps) ? $cfgOps : [],
        );
    }

    /**
     * Registrace DPH, se kterou doklad z papíru vznikne. Přijatý doklad na
     * zdroji s registrací k datu (D2) → naše registrace, stejná volba jako
     * výchozí hodnota formuláře; hodnota z AI / ISDOC se nepoužije. Přijatý
     * doklad neplátce (#97) registraci nenese. Jinak reference
     * `vat.registration` (#166 D36), a bez ní signál `vat.registrationCountry`
     * — první aktivní registrace země bez ohledu na platnost.
     *
     * @param array<string, mixed> $canonical
     */
    public function registrationId(array $canonical): ?int
    {
        $vatCtx = $this->context($canonical);
        if ($vatCtx['derive']) {
            return $vatCtx['ownRegistrationId'];
        }
        if ($vatCtx['nonPayer']) {
            // #97: doklad Bez DPH registraci nenese. Hledání podle země níže
            // platnost nezkoumá — bývalému plátci by vrátilo prošlou registraci.
            return null;
        }
        // Reference `vat.registration` (#166 D36) má přednost před signálem země.
        $reference = $this->payload->vatRegistrationReference($canonical);
        if ($reference !== null) {
            return $reference['id'];
        }
        $country = strtolower((string) ($canonical['vat']['registrationCountry'] ?? ''));
        if ($country === '') {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_vat_registrations]
             WHERE [country] = %s AND [docState] IN (%i, %i, %i)
             ORDER BY [id] LIMIT 1',
            $country,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? (int) $row['id'] : null;
    }
}
