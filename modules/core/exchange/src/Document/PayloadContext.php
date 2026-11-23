<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Resolve\ReferenceException;
use Shipard\Module\Core\Exchange\Resolve\ReferenceResolver;
use Shipard\Module\Docs\Core\AccountingDateRule;
use Shipard\Module\Docs\Core\Defaults\DocHeadDefaults;
use Shipard\Module\Docs\Core\DocTypes;
use Shipard\Module\Docs\Core\VatRateDates;
use Shipard\Module\Economy\ExchangeRates\ExchangeRate;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;

/**
 * Kontext dokladu **z payloadu** (#166 D51, etapa 5): co o dokladu říká
 * payload sám a co se k němu dohledá referencí — typ dokladu, importní mód,
 * registrace DPH z `vat.registration`, hlavička a datum sazby DPH, původní
 * doklad opravného dokladu, kurz z lístku, země číselníku kódů DPH.
 *
 * Nic neodvozuje ze signálů papírové podoby — to je práce interpretace
 * (`Interpret\`). Čte ho {@see DocumentApplier} (pokyn) i interpretace
 * (návrh), která na něm staví své odvození; proto je mimo obě.
 *
 * Dohledání opravného dokladu a registrace DPH se pamatují per canonical
 * (jeden dotaz na doklad), stejně jako kontext DPH pokynu.
 */
final class PayloadContext
{
    /** @var array{key: string, decision: array<string, mixed>}|null {@see correctionDecision()} */
    private ?array $correctionCache = null;

    /** @var array{key: string, decision: array<string, mixed>}|null {@see vatRegistrationReference()} */
    private ?array $vatRegistrationCache = null;

    /** @var array{key: string, ctx: array<string, mixed>}|null {@see vatContext()} */
    private ?array $vatContextCache = null;

    /** Společná pravidla výchozích hodnot hlavičky (docs.core) — viz headDefaults(). */
    private ?DocHeadDefaults $headDefaults = null;

    public function __construct(
        private readonly Connection $db,
        private readonly ConfigRuntime $config,
        /** Null = bez definic tabulek (testy): každá reference skončí `reference_table_unknown`. */
        private ?ReferenceResolver $references,
        /** Null = zdroj dat bez modulu kurzů: kurz se nedohledává. */
        private readonly ?ExchangeRateResolver $exchangeRateResolver,
    ) {
    }

    public function references(): ReferenceResolver
    {
        return $this->references ??= new ReferenceResolver($this->db, []);
    }

    /** Výchozí hodnoty hlavičky — tatáž třída jako ve formuláři dokladu. */
    public function headDefaults(): DocHeadDefaults
    {
        return $this->headDefaults ??= new DocHeadDefaults(new DataSourceConnection($this->db), $this->config);
    }

    /**
     * Nález z chyby resolveru: kód resolveru (`reference_not_found`,
     * `reference_ambiguous`, `reference_invalid`, …) a cesta pole.
     *
     * @return array{severity: string, path: string, code: string, message: string}
     */
    public static function referenceIssue(ReferenceException $e, string $path, string $prefix = ''): array
    {
        return [
            'severity' => 'error',
            'path'     => $path,
            'code'     => $e->errorCode,
            'message'  => $prefix . $e->getMessage(),
        ];
    }

    /**
     * Země pro resolve DPH kódu — kaskáda: explicitní `vat.registrationCountry`
     * → prefix z kódu (konvence „{země}-{číslo}“, např. cz-110; nese
     * registraci přímo, proto vyhrává nad zemí dodavatele) → `supplier.country`.
     * Model smí top-level "vat" vynechat (nullable od v2.3.0), bez fallbacku
     * by pak KAŽDÝ řádek skončil `vat_code_unknown`. Sdílí řádky i
     * rekapitulace, aby se kód rekapitulace hledal ve stejném číselníku
     * jako kódy řádků. Prázdný řetězec = země neznámá. U přijatého dokladu
     * na zdroji s registrací DPH sem už přichází země naší registrace
     * ({@see vatContext()}, D2) a kaskáda se neuplatní.
     */
    public function vatCountryForCode(string $code, string $vatCountry, string $supplierCountry): string
    {
        if ($vatCountry !== '') {
            return $vatCountry;
        }
        if (preg_match('/^([a-z]{2})-/i', $code, $m) === 1) {
            return strtolower($m[1]);
        }
        return $supplierCountry;
    }

    /**
     * Importní mód (`applyOptions.importNumber`, #166 D5, D22): nese jen
     * historii — vnucené číslo, dobový snapshot partnera, otevírací
     * a uzávěrkové období, archivní řady, registraci DPH bez ohledu na
     * platnost k datu; kurz z lístku nedohledává.
     *
     * @param array<string, mixed> $canonical
     */
    public static function isImportMode(array $canonical): bool
    {
        return is_array($canonical['applyOptions']['importNumber'] ?? null);
    }

    /**
     * Země číselníku, ve kterém se hledají kódy DPH řádků a rekapitulace:
     * návrh u přijatého dokladu (`derive`) a pokyn s registrací → země naší
     * registrace; jinak registrace z reference `vat.registration`, signál
     * `vat.registrationCountry` z dokladu a dál kaskáda
     * {@see vatCountryForCode()}. Prázdný řetězec = země neznámá.
     *
     * @param array<string, mixed> $vatCtx {@see vatContext()}
     * @param array<string, mixed> $canonical
     */
    public function vatCodeCountry(array $vatCtx, array $canonical): string
    {
        if ($vatCtx['derive'] || (!$vatCtx['mode']->derives() && $vatCtx['ownCountry'] !== null)) {
            return (string) $vatCtx['ownCountry'];
        }
        // Návrh: reference `vat.registration` má přednost před signálem země.
        $country = $this->vatRegistrationReference($canonical)['country'] ?? null;
        return $country ?? strtolower((string) ($canonical['vat']['registrationCountry'] ?? ''));
    }

    /**
     * Registrace DPH dokladu v pokynu (#166 D20, D36): reference
     * `vat.registration` ({@see vatRegistrationReference()}). Výchozí
     * registraci zdroje dat (první podle `country`, `id`, mimo importní mód
     * platná k datu dokladu, jen u dokladu s DPH) pokyn dostává mezi
     * výchozími hodnotami jako referenci ({@see DocumentApplier::applyInstructionDefaults()},
     * společné pravidlo `DocHeadDefaults`) — vlastní kopii pravidla tu
     * applier nemá. Null = doklad registraci nedostane (pole je záměrně
     * prázdné, nebo zdroj dat k datu registraci nemá); u dokladu s DPH to
     * při potvrzení zastaví validace dokladu.
     *
     * @param array<string, mixed> $canonical
     * @return array{id: int, country: string}|null
     */
    public function instructionVatRegistration(array $canonical): ?array
    {
        $reference = $this->vatRegistrationReference($canonical);
        return $reference !== null && $reference['id'] !== null
            ? ['id' => $reference['id'], 'country' => (string) $reference['country']]
            : null;
    }

    /**
     * Registrace DPH z reference `vat.registration` (#166 D36) — jednou per
     * canonical. Nenalezená reference je chyba s kódem resolveru; mimo
     * importní mód je chybou i registrace, která k datu dokladu neplatí
     * (`vat_registration_not_valid` — výslovně určená registrace nesmí
     * tiše vypadnout). Null = pole není vyplněné.
     *
     * @param array<string, mixed> $canonical
     * @return array{id: ?int, country: ?string,
     *     issue: ?array{severity: string, path: string, code: string, message: string}}|null
     */
    public function vatRegistrationReference(array $canonical): ?array
    {
        $reference = is_array($canonical['vat'] ?? null) ? ($canonical['vat']['registration'] ?? null) : null;
        if (!CanonicalReferences::isSet($reference)) {
            return null;
        }
        $date = self::isImportMode($canonical) ? null : $this->registrationDate($canonical);
        $key = md5((string) json_encode([$reference, $date]));
        if ($this->vatRegistrationCache !== null && $this->vatRegistrationCache['key'] === $key) {
            return $this->vatRegistrationCache['decision'];
        }
        $decision = ['id' => null, 'country' => null, 'issue' => null];
        try {
            $id = $this->references()->resolve('economy_codebooks_vat_registrations', $reference);
            $row = $this->db->fetch(
                'SELECT [id], [country], [valid_from], [valid_to]
                 FROM [economy_codebooks_vat_registrations] WHERE [id] = %i',
                $id,
            );
            $from = self::dateText($row['valid_from'] ?? null);
            $to = self::dateText($row['valid_to'] ?? null);
            if ($date !== null && (($from !== null && $from > $date) || ($to !== null && $to < $date))) {
                $decision['issue'] = [
                    'severity' => 'error',
                    'path'     => 'vat.registration',
                    'code'     => 'vat_registration_not_valid',
                    'message'  => "Registrace DPH k datu dokladu ({$date}) neplatí.",
                ];
            } else {
                $decision['id'] = $id;
                $decision['country'] = strtolower((string) ($row['country'] ?? ''));
            }
        } catch (ReferenceException $e) {
            $decision['issue'] = self::referenceIssue($e, 'vat.registration');
        }
        $this->vatRegistrationCache = ['key' => $key, 'decision' => $decision];
        return $decision;
    }

    /**
     * Nález k referenci `vat.registration` — náhled i apply, oba režimy.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function appendVatRegistrationIssue(array $canonical, array &$issues): void
    {
        $issue = $this->vatRegistrationReference($canonical)['issue'] ?? null;
        if ($issue !== null) {
            $issues[] = $issue;
        }
    }

    /**
     * Datum, ke kterému se posuzuje plátcovství přijatého dokladu (#97 D2):
     * DUZP, bez něj datum vystavení, bez obou dnešek. Jen část `YYYY-MM-DD`
     * — registrace se porovnává jako datum, ne jako řetězec s časem.
     *
     * @param array<string, mixed> $canonical
     */
    public function registrationDate(array $canonical): string
    {
        foreach (['taxPointDate', 'issueDate'] as $key) {
            $value = $canonical['dates'][$key] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m) === 1) {
                return $m[0];
            }
        }
        return date('Y-m-d');
    }

    /**
     * Hlavička dokladu ve tvaru, který čte {@see VatRateDates} — applier
     * datum sazby nepočítá vlastním kódem (U2). DUZP a DPPD jen u daňového
     * typu a příznak s datem opravy z {@see correctionColumns()}, tedy tytéž
     * hodnoty, které do dokladu pošle {@see DocumentApplier::transform()}.
     *
     * `vat_mode` rozhoduje jen o tom, zda přepínač platí (doklad bez DPH má
     * vždy automatiku): volba uživatele, jinak režim dokladu
     * ({@see Interpret\VatInterpretation::documentVatMode()}). Konečný režim ({@see Interpret\VatInterpretation::effectiveVatMode()})
     * závisí na rozhodnutích o kódech řádků a ta na datu sazby — proto odhad.
     * Rozejde se jen u dokladu „Bez DPH“, který ochrana samovyměření přepne
     * na výpočet zdola a který zároveň nese vlastní data sazby: applier tam
     * hledá k automatickému datu, dokument k vlastnímu.
     *
     * @param array<string, mixed> $canonical
     * @param string $vatMode režim DPH dokladu (klíč `VAT_MODE_MAP`): pokyn
     *        {@see declaredVatMode()}, návrh volba uživatele nebo režim ověřený proti číslům
     * @return array<string, mixed>
     */
    public function vatRateHead(array $canonical, string $vatMode): array
    {
        $docType = $this->mapDocType($canonical);
        $taxDocument = DocTypes::isTaxDocument($this->config, $docType);
        $dates = is_array($canonical['dates'] ?? null) ? $canonical['dates'] : [];
        $vat = is_array($canonical['vat'] ?? null) ? $canonical['vat'] : [];
        $source = $vat['rateSource'] ?? null;
        $correction = $this->correctionColumns($canonical);
        return [
            'doc_type'                  => $docType,
            'vat_mode'                  => DocumentApplier::VAT_MODE_MAP[$vatMode],
            VatRateDates::SOURCE_COLUMN => is_string($source)
                ? (DocumentApplier::VAT_RATE_SOURCE_MAP[$source] ?? VatRateDates::SOURCE_AUTO)
                : VatRateDates::SOURCE_AUTO,
            VatRateDates::COLUMN        => self::dateText($dates['vatRateDate'] ?? null),
            'vat_dppd'                  => $taxDocument ? self::dateText($dates['vatObligationDate'] ?? null) : null,
            'vat_duzp'                  => $taxDocument ? self::dateText($dates['taxPointDate'] ?? null) : null,
            'issue_date'                => self::dateText($dates['issueDate'] ?? null),
            'corrective'                => $correction['corrective'] ?? 0,
            'corrected_doc_date'        => $correction['corrected_doc_date'] ?? null,
        ];
    }

    /**
     * Datum sazby řádku dokladu nebo řádku rekapitulace
     * ({@see VatRateDates::lineDate()}): při zdroji „na řádcích“ vlastní
     * datum řádku, prázdné = automatika; jinak datum sazby dokladu. Datum
     * mimo režim pravidlo samo ignoruje (U5).
     *
     * @param array<string, mixed> $vatCtx {@see vatContext()} — stačí `rateHead`
     * @param mixed $ownDate `rows[].vat.rateDate` / `vatRecap[].rateDate`
     */
    public function lineRateDate(array $vatCtx, mixed $ownDate): ?string
    {
        return VatRateDates::lineDate(
            $vatCtx['rateHead'],
            [VatRateDates::COLUMN => self::dateText($ownDate)],
            $this->config,
        );
    }

    /**
     * U5: vlastní datum sazby mimo svůj režim — `dates.vatRateDate` při
     * `vat.rateSource` ≠ `document`, `rows[].vat.rateDate` nebo
     * `vatRecap[].rateDate` při `rateSource` ≠ `rows`. Jedno info na doklad
     * s cestou k prvnímu výskytu; applier s datem nepočítá a dokument ho při
     * uložení smaže. Posuzuje se hodnota z canonicalu — přepínač, který
     * dokument vrátí na automatiku sám (nedaňový doklad, doklad bez DPH),
     * se nehlásí.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function appendVatRateDateIssue(array $canonical, array &$issues): void
    {
        $source = $canonical['vat']['rateSource'] ?? null;
        $source = is_string($source) && isset(DocumentApplier::VAT_RATE_SOURCE_MAP[$source]) ? $source : 'auto';
        $path = null;
        if ($source !== 'document' && self::dateText($canonical['dates']['vatRateDate'] ?? null) !== null) {
            $path = 'dates.vatRateDate';
        }
        if ($path === null && $source !== 'rows') {
            foreach ((array) ($canonical['rows'] ?? []) as $idx => $row) {
                if (is_array($row) && is_array($row['vat'] ?? null) && self::dateText($row['vat']['rateDate'] ?? null) !== null) {
                    $path = "rows.{$idx}.vat.rateDate";
                    break;
                }
            }
        }
        if ($path === null && $source !== 'rows') {
            foreach ((array) ($canonical['vatRecap'] ?? []) as $idx => $entry) {
                if (is_array($entry) && self::dateText($entry['rateDate'] ?? null) !== null) {
                    $path = "vatRecap.{$idx}.rateDate";
                    break;
                }
            }
        }
        if ($path === null) {
            return;
        }
        $label = ['auto' => 'automaticky', 'document' => 'vlastní datum dokladu', 'rows' => 'vlastní datum na řádcích'][$source];
        $issues[] = [
            'severity' => 'info',
            'path'     => $path,
            'code'     => 'vat_rate_date_ignored',
            'message'  => "Datum sazby DPH se nepoužije — doklad má sazby DPH podle „{$label}“ (vat.rateSource).",
        ];
    }

    /**
     * Rozhodnutí o kurzu z lístku: doklad v cizí měně bez `exchangeRate`
     * mimo import mód dostane kurz k účetnímu datu, bez něj k datu vystavení
     * (D5). Kurz uvedený na dokladu (ISDOC, AI) má přednost; import ze
     * starého Shipardu (`applyOptions.importNumber`) nedohledává — chybějící
     * kurz zůstane chybějící a v cílovém stavu V pořádku ho zastaví validace
     * dokladu. Náhled i apply jdou přes transform(), takže kurz vzniká na
     * jednom místě; resolver memoizuje, dotaz do lístků je jeden.
     *
     * Opravný doklad (#92 D4, task B U4): lístek se nečte — s odkazem se
     * převezme kurz původního dokladu (jen při shodné měně, jako formulář),
     * bez odkazu zůstane prázdný a potvrzení zastaví validace dokladu.
     *
     * @param array<string, mixed> $canonical
     * @return array{currency: string, date: ?string, source: string, value: ?float, rate: ?ExchangeRate, originalNumber: ?string}|null
     *   null = nic nedohledávat; `source`: `list` (kurz z lístku, `rate` nese
     *   lístek), `original` (kurz původního dokladu), `correctiveManual`
     *   (opravný doklad bez odkazu — `value` null)
     */
    public function exchangeRateDecision(array $canonical): ?array
    {
        if (($canonical['exchangeRate'] ?? null) !== null) {
            return null;
        }
        if (self::isImportMode($canonical)) {
            return null;
        }
        $currency = strtolower((string) ($canonical['currency'] ?? ''));
        if ($currency === '') {
            return null;
        }
        $homeCurrency = $this->exchangeRateResolver?->homeCurrency();
        if ($homeCurrency !== null && $currency === $homeCurrency) {
            return null;
        }
        $correction = $this->correctionDecision($canonical);
        if ($correction !== null && $correction['supported']) {
            $fromOriginal = $correction['originalId'] !== null
                && $correction['originalRate'] !== null
                && $correction['originalCurrency'] === $currency;
            // Bez resolveru (zdroj dat bez lístků) není známá domácí měna —
            // hlásí se jen převzatý kurz, ne chybějící.
            if (!$fromOriginal && $homeCurrency === null) {
                return null;
            }
            return [
                'currency'       => $currency,
                'date'           => null,
                'source'         => $fromOriginal ? 'original' : 'correctiveManual',
                'value'          => $fromOriginal ? $correction['originalRate'] : null,
                'rate'           => null,
                'originalNumber' => $correction['originalNumber'],
            ];
        }
        if ($this->exchangeRateResolver === null) {
            return null;
        }
        $date = AccountingDateRule::fromCanonical($canonical); // účetní datum (D5, D78)
        $rate = $date !== null ? $this->exchangeRateResolver->rateFor($currency, $date) : null;
        return [
            'currency'       => $currency,
            'date'           => $date,
            'source'         => 'list',
            'value'          => $rate?->rate,
            'rate'           => $rate,
            'originalNumber' => null,
        ];
    }

    /**
     * Náhled i apply (U5): doplněný kurz = info `exchange_rate_from_list`,
     * nenalezený = warning `exchange_rate_missing` — doklad se uloží,
     * potvrzení zastaví validace dokladu.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function appendExchangeRateIssue(array $canonical, array &$issues): void
    {
        $decision = $this->exchangeRateDecision($canonical);
        if ($decision === null) {
            return;
        }
        if ($decision['source'] === 'original') {
            $issues[] = [
                'severity' => 'info',
                'path'     => 'exchangeRate',
                'code'     => 'exchange_rate_from_original',
                'message'  => sprintf(
                    'Kurz %s převzat z původního dokladu %s.',
                    self::exchangeRateText((float) $decision['value']),
                    (string) ($decision['originalNumber'] ?? ''),
                ),
            ];
            return;
        }
        if ($decision['source'] === 'correctiveManual') {
            $issues[] = [
                'severity' => 'info',
                'path'     => 'exchangeRate',
                'code'     => 'exchange_rate_corrective_manual',
                'message'  => sprintf(
                    'Opravný doklad v měně %s bez původního dokladu — kurz z lístku se nepřebírá, doplňte kurz původního plnění ručně; bez něj doklad nepůjde potvrdit.',
                    strtoupper($decision['currency']),
                ),
            ];
            return;
        }
        $rate = $decision['rate'];
        if ($rate !== null) {
            $issues[] = [
                'severity' => 'info',
                'path'     => 'exchangeRate',
                'code'     => 'exchange_rate_from_list',
                'message'  => sprintf(
                    'Kurz %s z lístku %s platného od %s.',
                    self::exchangeRateText($rate->rate),
                    $this->exchangeRateResolver?->types()->labelOf((string) $rate->listType) ?? (string) $rate->listType,
                    self::exchangeRateDateText((string) $rate->validFrom),
                ),
            ];
            return;
        }
        $issues[] = [
            'severity' => 'warning',
            'path'     => 'exchangeRate',
            'code'     => 'exchange_rate_missing',
            'message'  => sprintf(
                'Kurz %s k %s není v kurzovním lístku — doklad nepůjde potvrdit bez ručního kurzu.',
                strtoupper($decision['currency']),
                $decision['date'] !== null ? self::exchangeRateDateText($decision['date']) : '(bez data)',
            ),
        ];
    }

    /**
     * Blok `correction` canonicalu a dohledání původního dokladu — jednou per
     * canonical (memoizace), čtou ho vatContext() (datum sazby),
     * exchangeRateDecision() (kurz), resolveAll() (blok `_resolve.correction`
     * náhledu), transform() (sloupce) i appendCorrectionIssues(). Blok se
     * bere jen u typu dokladu s příznakem `docTypes[].corrective`
     * (`supported`); jinde se ignoruje s varováním.
     *
     * Původní doklad (#166 D30): reference `correction.originalDoc` — id,
     * nebo `"@číslo dokladu"`; nenalezená, nejednoznačná nebo na doklad
     * typu bez opravného dokladu je chyba (`issue`). Bez ní v návrhu volba
     * `_resolve.correction.userAction = useExisting:<id>` (pokyn volby
     * nečte — {@see CanonicalReferences::withoutResolve()}); neplatná
     * volba = `pinMissing`. Stav původního dokladu se nehlídá, při potvrzení
     * ho zkontroluje DocDocument::validateCorrective. Podle
     * `originalDocNumber` se nic nedohledává — je to jen text.
     * `originalDate` = DUZP původního dokladu, bez něj datum vystavení
     * (DocDocument::applyCorrectiveColumns).
     *
     * @param array<string, mixed> $canonical
     * @return array{supported: bool, status: string, matchedBy: ?string, pinMissing: ?int,
     *     issue: ?array{severity: string, path: string, code: string, message: string},
     *     originalId: ?int, originalNumber: ?string, originalDate: ?string,
     *     originalRate: ?float, originalCurrency: ?string}|null  null = blok chybí
     */
    public function correctionDecision(array $canonical): ?array
    {
        if (!is_array($canonical['correction'] ?? null)) {
            return null;
        }
        $correction = $canonical['correction'];
        $userAction = $canonical['_resolve']['correction']['userAction'] ?? null;
        $docType = $this->mapDocType($canonical);
        $key = md5((string) json_encode([$docType, $correction, $userAction]));
        if ($this->correctionCache !== null && $this->correctionCache['key'] === $key) {
            return $this->correctionCache['decision'];
        }

        $decision = [
            'supported'        => DocTypes::isCorrective($this->config, $docType),
            'status'           => 'none',
            'matchedBy'        => null,
            'pinMissing'       => null,
            'issue'            => null,
            'originalId'       => null,
            'originalNumber'   => null,
            'originalDate'     => null,
            'originalRate'     => null,
            'originalCurrency' => null,
        ];
        if ($decision['supported']) {
            $originalId = null;
            $reference = $correction['originalDoc'] ?? null;
            if (CanonicalReferences::isSet($reference)) {
                try {
                    $originalId = $this->references()->resolve('docs_core_heads', $reference);
                    $decision['matchedBy'] = 'ref';
                } catch (ReferenceException $e) {
                    $decision['status'] = 'notFound';
                    $decision['issue'] = self::referenceIssue($e, 'correction.originalDoc');
                }
            } elseif (($pinId = self::useExistingId($userAction)) !== null) {
                $originalId = $pinId;
                $decision['matchedBy'] = 'pin';
            }
            $original = $originalId === null ? null : $this->db->fetch(
                'SELECT [id], [doc_type], [doc_number], [vat_duzp], [issue_date], [exchange_rate], [doc_currency]
                 FROM [docs_core_heads] WHERE [id] = %i',
                $originalId,
            );
            if ($original !== null && !DocTypes::isCorrective($this->config, (string) $original['doc_type'])) {
                $original = null;
            }
            if ($originalId !== null && $original === null) {
                if ($decision['matchedBy'] === 'ref') {
                    $decision['status'] = 'notFound';
                    $decision['issue'] = [
                        'severity' => 'error',
                        'path'     => 'correction.originalDoc',
                        'code'     => 'correction_original_not_supported',
                        'message'  => 'Odkazovaný doklad není typu, ke kterému jde vystavit opravný doklad.',
                    ];
                } else {
                    $decision['pinMissing'] = $originalId;
                }
                $decision['matchedBy'] = null;
            }
            if ($original !== null) {
                $decision['status'] = 'matched';
                $decision['originalId'] = (int) $original['id'];
                $decision['originalNumber'] = self::nullableText($original['doc_number'] ?? null);
                $decision['originalDate'] = self::dateText($original['vat_duzp'] ?? null)
                    ?? self::dateText($original['issue_date'] ?? null);
                $decision['originalRate'] = isset($original['exchange_rate']) && is_numeric((string) $original['exchange_rate'])
                    ? (float) $original['exchange_rate']
                    : null;
                $decision['originalCurrency'] = self::nullableText(
                    isset($original['doc_currency']) ? strtolower((string) $original['doc_currency']) : null,
                );
            }
        }
        $this->correctionCache = ['key' => $key, 'decision' => $decision];
        return $decision;
    }

    /**
     * Sloupce skupiny `correction` pro transform(): jen u podporovaného typu;
     * null hodnoty vypadnou přes array_filter volajícího (default sloupců).
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public function correctionColumns(array $canonical): array
    {
        $decision = $this->correctionDecision($canonical);
        if ($decision === null || !$decision['supported']) {
            return [];
        }
        $correction = $canonical['correction'];
        return [
            'corrective'           => 1,
            'corrected_doc'        => $decision['originalId'],
            'corrected_doc_number' => $decision['originalNumber']
                ?? self::nullableText($correction['originalDocNumber'] ?? null),
            'corrected_doc_date'   => $decision['originalDate']
                ?? self::dateText($correction['originalTaxPointDate'] ?? null),
            'correction_reason'    => self::nullableText($correction['reason'] ?? null),
        ];
    }

    /**
     * Náhled i apply: blok na typu bez příznaku = warning
     * `correction_not_supported` (doklad vznikne jako běžný); reference
     * `correction.originalDoc`, která se nenašla = chyba s kódem resolveru;
     * neplatná volba v návrhu = warning `pin_target_missing`.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function appendCorrectionIssues(array $canonical, array &$issues): void
    {
        $decision = $this->correctionDecision($canonical);
        if ($decision === null) {
            return;
        }
        if (!$decision['supported']) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'correction',
                'code'     => 'correction_not_supported',
                'message'  => 'Údaje opravy platí jen u typu dokladu, ke kterému jde vystavit opravný doklad — ignorovány, doklad vznikne jako běžný.',
            ];
            return;
        }
        if ($decision['issue'] !== null) {
            $issues[] = $decision['issue'];
        }
        if ($decision['pinMissing'] !== null) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => '_resolve.correction',
                'code'     => 'pin_target_missing',
                'message'  => "Cílový doklad docs_core_heads#{$decision['pinMissing']} pro „_resolve.correction\" neexistuje nebo není typu s opravným dokladem — odkaz na původní doklad nebyl nastaven.",
            ];
        }
    }

    /** `useExisting:<id>` → id, jinak null (bez hlášení — tvar hlídá DocumentValidator). */
    public static function useExistingId(mixed $userAction): ?int
    {
        if (!is_string($userAction) || !str_starts_with($userAction, 'useExisting:')) {
            return null;
        }
        $idStr = substr($userAction, strlen('useExisting:'));
        return ctype_digit($idStr) && (int) $idStr > 0 ? (int) $idStr : null;
    }

    public static function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text !== '' ? $text : null;
    }

    /** Datum z DB (DateTime) nebo canonicalu (string) → `Y-m-d`, prázdné → null. */
    public static function dateText(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? substr($text, 0, 10) : null;
    }

    public static function exchangeRateText(float $rate): string
    {
        $text = rtrim(rtrim(number_format($rate, 6, ',', ''), '0'), ',');
        return str_contains($text, ',') ? $text : $text . ',0';
    }

    public static function exchangeRateDateText(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false ? $parsed->format('j. n. Y') : $date;
    }

    /**
     * `applyOptions.author` (#93 D9): přítomný klíč určuje autora dokladu
     * („Vystavil“), `null` = bez autora. Hodnota musí být id existujícího
     * uživatele — aktivního i neaktivního (import historie nese autory,
     * kteří se už nepřihlašují). Chybějící klíč se nekontroluje: autora
     * doplní `DocAuthorResolver` při uložení.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function appendAuthorIssue(array $canonical, array &$issues): void
    {
        $options = $canonical['applyOptions'] ?? null;
        if (!is_array($options) || ($options['author'] ?? null) === null) {
            return;
        }
        $author = $options['author'];
        $row = is_int($author) && $author > 0
            ? $this->db->fetch('SELECT [id] FROM [core_system_users] WHERE [id] = %i', $author)
            : null;
        if ($row === null || $row === false) {
            $issues[] = [
                'severity' => 'error',
                'path'     => 'applyOptions.author',
                'code'     => 'author_not_found',
                'message'  => 'Autor dokladu (applyOptions.author) není id existujícího uživatele.',
            ];
        }
    }

    /** Má typ dokladu řadu vázanou na pokladnu (`docTypes[].series_binding = cash_desk`)? */
    public function isCashDeskBoundDocType(string $docType): bool
    {
        return DocTypes::isCashDeskBound($this->config, $docType);
    }

    /**
     * Map canonical docType (descriptive name or short code) → the short code
     * stored in docs_core_heads.doc_type. Passthrough when no alias matches.
     *
     * @param array<string, mixed> $canonical
     */
    public function mapDocType(array $canonical): string
    {
        return DocumentApplier::mapDocTypeValue((string) ($canonical['docType'] ?? ''));
    }

    // ── Režim a kontext DPH pokynu (#166 D19, D20) ──────────────────────────

    /**
     * Režim výpočtu DPH, jak ho **říká payload**: `vat.mode`, chybí nebo
     * neznámý = `fromBase`. Proti číslům ho ověřuje jen interpretace
     * (`Interpret\VatInterpretation::documentVatMode()`).
     *
     * @param array<string, mixed> $canonical
     * @return array{mode: string, source: string, reason: ?string} source ai | default
     */
    public function declaredVatMode(array $canonical): array
    {
        $declared = $canonical['vat']['mode'] ?? null;
        $known = is_string($declared) && isset(DocumentApplier::VAT_MODE_MAP[$declared]);
        return ['mode' => $known ? $declared : 'fromBase', 'source' => $known ? 'ai' : 'default', 'reason' => null];
    }

    /**
     * Základ kontextu DPH dokladu — klíče, které čtou applier i interpretace;
     * hodnoty jsou „nic se neodvozuje“. Interpretace na něm staví odvození
     * (`derive`, `nonPayer`, `outOfScope`, volby uživatele, rozhodnutí řádků).
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public function baseVatContext(array $canonical, ApplyMode $mode): array
    {
        // Dvě data (#159 D11): `taxPointDate` (DUZP, fallback datum vystavení)
        // řídí místo plnění, registraci k datu, neplátce a období; kód DPH,
        // sazbu a předmět plnění řídí datum sazby — `rateHead` je hlavička
        // pro VatRateDates, `rateDate` datum sazby dokladu, datum řádku dává
        // {@see lineRateDate()}.
        $taxPointDate = $canonical['dates']['taxPointDate'] ?? ($canonical['dates']['issueDate'] ?? null);
        $vat = is_array($canonical['vat'] ?? null) ? $canonical['vat'] : [];
        $inputPlace = is_string($vat['place'] ?? null) ? $vat['place'] : null;
        return [
            'mode'              => $mode,
            'withoutVat'        => false,
            'derive'            => false,
            'nonPayer'          => false,
            'outOfScope'        => null,
            'autoOutOfScope'    => null,
            'autoReverseCharge' => false,
            'ownCountry'        => null,
            'ownRegistrationId' => null,
            'taxPointDate'      => is_string($taxPointDate) && $taxPointDate !== '' ? $taxPointDate : null,
            'rateDate'          => null,
            'rateHead'          => [],
            'place'             => $inputPlace,
            'placeSource'       => $inputPlace !== null ? 'ai' : null,
            'placePrefix'       => null,
            // Místo bez volby uživatele — náhled ho ukazuje jako „Automaticky (…)“.
            'autoPlace'         => $inputPlace,
            'pins'              => ['place' => null, 'mode' => null, 'rows' => [], 'subjects' => [], 'invalid' => []],
            'codeOptions'       => [],
            'rows'              => [],
            'special'           => [],
        ];
    }

    /**
     * Kontext DPH **pokynu** (#166 D19, D20): nic se neodvozuje. Doklad Bez
     * DPH je ten, který to říká (`vat.mode` none) — kódy řádků se na něj
     * nepropisují, stejně jako je nenabízí formulář. Místo plnění z payloadu,
     * registrace z reference `vat.registration` ({@see instructionVatRegistration()};
     * `ownCountry` je pak země číselníku kódů), datum sazby podle
     * {@see vatRateHead()}. Pamatuje se per canonical.
     *
     * @param array<string, mixed> $canonical pracovní kopie pokynu (bez `_resolve`)
     * @return array<string, mixed> tvar {@see baseVatContext()}
     */
    public function vatContext(array $canonical): array
    {
        $key = md5((string) json_encode([
            $canonical['docType'] ?? null,
            $canonical['vat'] ?? null,
            $canonical['dates'] ?? null,
            $canonical['correction'] ?? null,
            self::isImportMode($canonical),
        ]));
        if ($this->vatContextCache !== null && $this->vatContextCache['key'] === $key) {
            return $this->vatContextCache['ctx'];
        }
        $ctx = $this->baseVatContext($canonical, ApplyMode::Instruction);
        $ctx['withoutVat'] = ($canonical['vat']['mode'] ?? null) === 'none';
        $own = $this->instructionVatRegistration($canonical);
        if ($own !== null) {
            $ctx['ownCountry'] = $own['country'];
            $ctx['ownRegistrationId'] = $own['id'];
        }
        $ctx['rateHead'] = $this->vatRateHead($canonical, $this->declaredVatMode($canonical)['mode']);
        $ctx['rateDate'] = VatRateDates::headDate($ctx['rateHead'], $this->config);
        $this->vatContextCache = ['key' => $key, 'ctx' => $ctx];
        return $ctx;
    }

    /**
     * D5: neznámé `vat.mode` → warning místo tichého fallbacku (doklad dál
     * padá na fromBase). Schéma má pole jako enum, takže sem takový payload
     * dojde jen mimo schema validaci — pojistka, ne hlavní cesta.
     *
     * @param array<string, mixed> $canonical
     * @return array{severity: string, path: string, code: string, message: string}|null
     */
    public static function unknownVatModeIssue(array $canonical): ?array
    {
        $mode = is_array($canonical['vat'] ?? null) ? ($canonical['vat']['mode'] ?? null) : null;
        if ($mode === null || (is_string($mode) && isset(DocumentApplier::VAT_MODE_MAP[$mode]))) {
            return null;
        }
        $label = is_scalar($mode) ? (string) $mode : (string) json_encode($mode);
        return [
            'severity' => 'warning',
            'path'     => 'vat.mode',
            'code'     => 'vat_mode_unknown',
            'message'  => "Neznámý režim výpočtu DPH „{$label}“ — použije se výpočet zdola (fromBase).",
        ];
    }

    /**
     * D5: neznámé `vat.place` → warning, použije se tuzemsko.
     *
     * @param array<string, mixed> $canonical
     * @return array{severity: string, path: string, code: string, message: string}|null
     */
    public static function unknownVatPlaceIssue(array $canonical): ?array
    {
        $place = is_array($canonical['vat'] ?? null) ? ($canonical['vat']['place'] ?? null) : null;
        if ($place === null || (is_string($place) && isset(DocumentApplier::VAT_PLACE_MAP[$place]))) {
            return null;
        }
        $label = is_scalar($place) ? (string) $place : (string) json_encode($place);
        return [
            'severity' => 'warning',
            'path'     => 'vat.place',
            'code'     => 'vat_place_unknown',
            'message'  => "Neznámé místo plnění „{$label}“ — použije se tuzemsko.",
        ];
    }
}
