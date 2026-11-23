<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Shipard\Module\Core\Exchange\Document\CanonicalReferences;
use Shipard\Module\Core\Exchange\Document\CanonicalRowText;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Docs\Core\VatRateDates;

/**
 * Skladba **hotového dokladu** (#166 D52, U4) — payloadu platného jako pokyn
 * — z papírové podoby a z toho, co o ní interpretace rozhodla. Čistá funkce:
 * nic nedohledává ani neodvozuje, jen zapisuje rozhodnutí do polí formátu.
 *
 * Pravidla:
 *
 *  - každý odkaz na záznam je reference číslem (id); strana nebo položka,
 *    která se teprve založí, zůstává objektem s daty bez `ref`;
 *  - co má v pokynu výchozí hodnotu, je uvedené **výslovně**, i jako `null`
 *    (měna, datum vystavení, úhrada, vlastní účet, registrace DPH, pohyb,
 *    text, cena, jednotka, množství a kód DPH řádku) — výchozí hodnoty
 *    pokynu se na doklad z papíru neuplatní;
 *  - `totals` nesou jen zaokrouhlení, **žádné částky** — pokyn by je bral
 *    jako kontrolní součet a doklad z papíru s haléřovým rozdílem odmítl;
 *  - převzatá rekapitulace jde s kódy DPH, přepočítaná se nepředává;
 *  - vynechané řádky v dokladu nejsou;
 *  - bez signálů návrhu (`item.ourCode`, účet holým číslem,
 *    `vat.registrationCountry`), bez `_resolve`, bez `applyOptions.interpret`
 *    a `autoCreateMode`.
 */
final class FinishedDocument
{
    /**
     * @param array<string, mixed> $canonical papírová podoba po normalizaci hodnot
     * @param array{
     *   rowIndices: list<int>,
     *   plan: array<string, mixed>,
     *   resolved: array<string, mixed>,
     *   skippedParties: list<string>,
     *   headPartnerAllowed: bool,
     *   vatMode: string,
     *   vatPlace: string,
     *   vatRegistration: ?int,
     *   withoutVat: bool,
     *   recap: array{source: int, recap: array<int, array<string, mixed>>},
     *   grossTotals: array<int, float>,
     *   totalRoundingAuto: bool,
     *   totalRoundingMode: ?string,
     *   paymentMethod: ?string,
     *   units: array<int, int|string>,
     *   correctionOriginalId: ?int,
     *   references: array{numberSeries: ?int, cashDesk: ?int, ownBankAccount: ?int},
     * } $facts rozhodnutí interpretace: `rowIndices` řádky, které na dokladu
     *   zůstanou (indexy papírové podoby), `plan` z {@see RecordMatching::reconcile()}
     *   včetně `rowOperationDefaults`, `resolved` bloky `_resolve`,
     *   `skippedParties` strany s volbou „bez osoby“
     * @return array<string, mixed>
     */
    public static function build(array $canonical, array $facts): array
    {
        $plan = $facts['plan'];
        $doc = $canonical;
        unset($doc['_resolve']);

        if (is_array($doc['applyOptions'] ?? null)) {
            unset($doc['applyOptions']['interpret'], $doc['applyOptions']['autoCreateMode']);
            if ($doc['applyOptions'] === []) {
                unset($doc['applyOptions']);
            }
        }

        foreach (['supplier', 'customer', 'balanceParty'] as $key) {
            if (isset($facts['resolved'][$key]) && ($canonical['selfParty'] ?? null) !== $key) {
                $doc[$key] = self::party(
                    is_array($canonical[$key] ?? null) ? $canonical[$key] : [],
                    $plan['resolved' . ucfirst($key)] ?? null,
                    in_array($key, $facts['skippedParties'], true),
                );
            }
        }
        $doc = self::headPartner($doc, $canonical, $plan['resolvedHeadPartner'] ?? null, $facts['headPartnerAllowed']);
        $doc = self::withoutPartyOverriddenByPartner($doc, $canonical, $plan, $facts['headPartnerAllowed']);
        $doc = self::partnerBank($doc, $facts['resolved']['supplierBank'] ?? null, $plan['resolvedSupplierBank'] ?? null);

        foreach ($plan['resolvedHeadDimensions'] ?? [] as $dimension => $recordId) {
            $doc['dimensions'][$dimension] = (int) $recordId;
        }
        foreach (['numberSeries', 'cashDesk'] as $key) {
            if ($facts['references'][$key] !== null) {
                $doc[$key] = $facts['references'][$key];
            }
        }
        if ($facts['correctionOriginalId'] !== null && is_array($doc['correction'] ?? null)) {
            $doc['correction']['originalDoc'] = $facts['correctionOriginalId'];
        }

        if (!array_key_exists('currency', $doc)) {
            $doc['currency'] = null;
        }
        $doc['dates'] = (is_array($doc['dates'] ?? null) ? $doc['dates'] : []) + ['issueDate' => null];

        $payment = is_array($doc['payment'] ?? null) ? $doc['payment'] : [];
        $payment['method'] = $facts['paymentMethod'];
        $payment['ownBankAccount'] = $facts['references']['ownBankAccount'] ?? ($payment['ownBankAccount'] ?? null);
        $doc['payment'] = $payment;

        $vat = is_array($doc['vat'] ?? null) ? $doc['vat'] : [];
        unset($vat['registrationCountry']);
        $vat['mode'] = $facts['vatMode'];
        $vat['place'] = $facts['vatPlace'];
        $vat['registration'] = $facts['vatRegistration'];
        $declared = $facts['recap']['source'] === 1;
        $vat['recapSource'] = $declared ? 'declared' : 'computed';
        $doc['vat'] = $vat;
        if ($declared) {
            $doc['vatRecap'] = array_map(self::recapEntry(...), array_values($facts['recap']['recap']));
        } else {
            unset($doc['vatRecap']);
        }

        // Jen zaokrouhlení — částky z papíru se jako kontrolní součet nepředávají.
        $doc['totals'] = [
            'totalRoundingAuto' => $facts['totalRoundingAuto'],
            'totalRoundingMode' => $facts['totalRoundingMode'],
        ];

        $rows = [];
        foreach ($facts['rowIndices'] as $i) {
            $rows[] = self::row($canonical['rows'][$i], $i, $facts);
        }
        $doc['rows'] = $rows;

        return $doc;
    }

    /**
     * Strana dokladu: spárovaná nebo zvolená osoba → `ref`; strana „bez
     * osoby“ ztrácí údaje, podle kterých by ji pokyn chtěl určit; jinak
     * zůstává objektem s daty bez `ref` (založí se, nebo čeká na rozhodnutí).
     *
     * @param array<string, mixed> $party
     * @return array<string, mixed>
     */
    private static function party(array $party, ?int $personId, bool $skipped): array
    {
        if ($personId !== null) {
            return ['ref' => $personId] + $party;
        }
        if ($skipped) {
            unset($party['ref']);
            return CanonicalReferences::withoutPartyIdentity($party);
        }
        return $party;
    }

    /**
     * Pole `partner` hlavičky (#166 D30): nese ho jen účetní doklad a typy
     * vázané na pokladnu — z pole, nebo z volby uživatele. U faktur je
     * partnerem strana a pole do pokynu nepatří. Reference, která se nenašla,
     * zůstává (chybu nese nález).
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private static function headPartner(array $doc, array $canonical, ?int $partnerId, bool $allowed): array
    {
        if ($allowed && $partnerId !== null) {
            $doc[CanonicalReferences::PARTNER] = ['ref' => $partnerId];
            return $doc;
        }
        $partner = $canonical[CanonicalReferences::PARTNER] ?? null;
        if (!($allowed && is_array($partner) && CanonicalReferences::isSet($partner['ref'] ?? null))) {
            unset($doc[CanonicalReferences::PARTNER]);
        }
        return $doc;
    }

    /**
     * Pokladní doklad s polem `partner` a se stranou, která určuje **jinou**
     * osobu: v návrhu má pole přednost a strana se jako partner nepoužije
     * (#166 D30). Pokyn by dvě různé osoby odmítl (`partner_conflict`), proto
     * strana v hotovém dokladu ztrácí referenci i údaje osoby — stejně jako
     * strana „bez osoby“.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function withoutPartyOverriddenByPartner(array $doc, array $canonical, array $plan, bool $headPartnerAllowed): array
    {
        $partnerId = $headPartnerAllowed ? ($plan['resolvedHeadPartner'] ?? null) : null;
        if ($partnerId === null) {
            return $doc;
        }
        // Strana, která by jinak partnera dokladu určila — jako v pokynu.
        $key = match ($canonical['selfParty'] ?? null) {
            'customer' => 'supplier',
            'supplier' => 'customer',
            default    => ($plan['resolvedSupplier'] ?? null) !== null ? 'supplier' : 'customer',
        };
        $partyId = $plan['resolved' . ucfirst($key)] ?? null;
        if ($partyId !== null && (int) $partyId !== (int) $partnerId && is_array($doc[$key] ?? null)) {
            unset($doc[$key]['ref']);
            $doc[$key] = CanonicalReferences::withoutPartyIdentity($doc[$key]);
        }
        return $doc;
    }

    /**
     * Bankovní účet partnera (#166 D61): interpretace o vazbě na účet osoby
     * rozhodla, pokyn ji proto dostane **výslovně** — id účtu (shoda nebo
     * volba uživatele), nebo `null` (bez vazby: účet se teprve založí
     * a referenci doplní {@see InterpretedDocument::withCreated()}, nebo ho
     * uživatel vynechal). Bez klíče by pokyn účet vázal přesnou shodou sám.
     * Reference z předlohy, která se nenašla, zůstává (chybu nese nález).
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed>|null $block `_resolve.supplierBank`
     * @return array<string, mixed>
     */
    private static function partnerBank(array $doc, ?array $block, ?int $accountId): array
    {
        if ($block === null || !is_array($doc['supplier']['bankAccount'] ?? null) || !empty($block['referenceError'])) {
            return $doc;
        }
        $doc['supplier']['bankAccount']['ref'] = $accountId;
        return $doc;
    }

    /**
     * Řádek převzaté rekapitulace z tvaru dokladu zpět do formátu — s kódem
     * DPH, který interpretace dohledala.
     *
     * @param array<string, mixed> $entry řádek `docs_core_vat_recap`
     * @return array<string, mixed>
     */
    private static function recapEntry(array $entry): array
    {
        $out = [
            'vatCode'       => $entry['vat_code'],
            'vatPct'        => $entry['vat_pct'],
            'base'          => $entry['base'],
            'tax'           => $entry['tax'],
            'total'         => $entry['total'],
            'isReversePair' => !empty($entry['is_reverse_pair']),
        ];
        if (isset($entry[VatRateDates::COLUMN])) {
            $out['rateDate'] = $entry[VatRateDates::COLUMN];
        }
        if (isset($entry['reverse_charge_code'])) {
            $out['reverseChargeCode'] = $entry['reverse_charge_code'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row řádek papírové podoby
     * @param array<string, mixed> $facts {@see build()}
     * @return array<string, mixed>
     */
    private static function row(array $row, int $i, array $facts): array
    {
        $plan = $facts['plan'];
        $out = $row;

        // Položka: id, nebo objekt s daty bez `ref` (zakládaná / nerozhodnutá).
        if (is_array($row['item'] ?? null) && $row['item'] !== []) {
            $itemId = $plan['resolvedRowItems'][$i] ?? null;
            if ($itemId !== null) {
                $out['item'] = ['ref' => (int) $itemId];
            } elseif (isset($plan['rowItemCreates'][$i])) {
                $item = $row['item'];
                unset($item['ourCode'], $item['ref']);
                if (!CanonicalReferences::itemIdentifies($item)) {
                    // Položka určená jen naším kódem — bez jména by pokyn
                    // řádek uložil potichu bez položky.
                    $item['name'] = (string) ($plan['rowItemCreates'][$i]['name'] ?? ($row['item']['ourCode'] ?? ''));
                }
                $out['item'] = $item;
            }
        }
        // Text řádku, jak skončí na dokladu — pokyn ho nedoplňuje z karty položky.
        $out['description'] = CanonicalRowText::compose($row);
        $out['operation'] = ($row['operation'] ?? null) ?: ($plan['rowOperationDefaults'][$i] ?? null);
        $out['unit'] = $facts['units'][$i] ?? null;
        $out['quantity'] = $row['quantity'] ?? null;

        $gross = $facts['grossTotals'][$i] ?? null;
        if ($gross !== null) {
            // Cena včetně daně dodavatele (#97, #89): řádek jde z celkové
            // ceny, bez jednotkové ceny bez daně a bez slev, které cena obsahuje.
            $out['totalPrice'] = $gross;
            $out['unitPrice'] = null;
            $out['priceCalcMode'] = 'fromTotal';
            unset($out['discountPct'], $out['discountAmount']);
        } else {
            $out['unitPrice'] = $row['unitPrice'] ?? null;
        }

        // Kód DPH: rozhodnutý, nebo výslovně žádný (doklad Bez DPH, řádek bez kódu).
        $vatBlock = $plan['resolvedRowVatCodes'][$i] ?? null;
        $code = !$facts['withoutVat'] && is_array($vatBlock) && ($vatBlock['status'] ?? null) === ResolveStatus::Matched->value
            ? ($vatBlock['createPayload']['code'] ?? null)
            : null;
        $vat = ['code' => $code];
        $rateDate = is_array($row['vat'] ?? null) ? ($row['vat']['rateDate'] ?? null) : null;
        if ($rateDate !== null) {
            $vat['rateDate'] = $rateDate;
        }
        $subject = $code !== null ? ($plan['resolvedRowReverseChargeCodes'][$i] ?? null) : null;
        if ($subject !== null) {
            $vat['reverseChargeCode'] = $subject;
        }
        $out['vat'] = $vat;

        // Účet: id; holé číslo (signál návrhu) do pokynu nepatří. Účet, který
        // se našel, ale interpretace ho na řádek nedala (účet navržený
        // historií u ručně zvolené položky, #111 D7b), v dokladu není;
        // reference, která se nenašla, zůstává (chybu nese nález).
        $accountId = $plan['resolvedRowAccounts'][$i] ?? null;
        $accountFound = ($facts['resolved']['rows'][$i]['account']['status'] ?? null) === ResolveStatus::Matched->value;
        if ($accountId !== null) {
            $out['account'] = (int) $accountId;
        } elseif ($accountFound
            || !CanonicalReferences::isSet($row['account'] ?? null)
            || CanonicalReferences::isAccountSignal($row['account'])
        ) {
            unset($out['account']);
        }

        $partnerId = $plan['resolvedRowPartners'][$i] ?? null;
        $partner = $row[CanonicalReferences::PARTNER] ?? null;
        if ($partnerId !== null) {
            $out[CanonicalReferences::PARTNER] = ['ref' => (int) $partnerId];
        } elseif (!(is_array($partner) && CanonicalReferences::isSet($partner['ref'] ?? null))) {
            unset($out[CanonicalReferences::PARTNER]);
        }

        foreach ($plan['resolvedRowDimensions'][$i] ?? [] as $dimension => $recordId) {
            $out['dimensions'][$dimension] = (int) $recordId;
        }

        return $out;
    }
}
