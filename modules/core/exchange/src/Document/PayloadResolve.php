<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Docs\Core\VatRateDates;

/**
 * Dohledání hodnot, které payload **říká** — společné kroky resolve pokynu
 * i návrhu (#166 D51, etapa 5): kód DPH řádku ověřený proti číselníku,
 * předmět plnění u tuzemského přenesení, řádek převzaté rekapitulace.
 * Nic neodvozuje; odvození a volby uživatele drží `Interpret\`.
 */
final class PayloadResolve
{
    public function __construct(
        private readonly PayloadContext $payload,
        private readonly VatCodeResolver $vatCodeResolver,
        private readonly VatCodeDerivation $vatCodeDerivation,
    ) {
    }

    /**
     * `_resolve.rows[].vatCode` řádku, jehož kód **nese payload**: jen se
     * ověří proti číselníku země registrace k datu sazby řádku (#159 U3);
     * neznámý je chyba `vat_code_unknown`. Null = řádek kód nenese.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $vatCtx kontext DPH dokladu (`rateHead`)
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @param float|null $fallbackPct procento z dokladu jako záloha, když kód
     *        k datu sazbu nemá — jen návrh; pokyn `vat.pct` nepoužije (#166 D20)
     * @return array<string, mixed>|null
     */
    public function rowVatCode(
        int $idx,
        array $row,
        array $vatCtx,
        string $vatCountry,
        string $supplierCountry,
        array &$issues,
        ?float $fallbackPct = null,
    ): ?array {
        $rowVat = is_array($row['vat'] ?? null) ? $row['vat'] : [];
        $code = trim((string) ($rowVat['code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $date = $this->payload->lineRateDate($vatCtx, $rowVat['rateDate'] ?? null);
        $country = $this->payload->vatCountryForCode($code, $vatCountry, $supplierCountry);
        $vatR = $this->vatCodeResolver->resolve(
            $code,
            $country !== '' ? $country : null,
            $date,
            $fallbackPct,
        );
        if ($vatR->status === ResolveStatus::NotFound) {
            $issues[] = [
                'severity' => 'error',
                'path'     => "rows.{$idx}.vat.code",
                'code'     => 'vat_code_unknown',
                'message'  => "Neznámý kód DPH „{$code}\".",
            ];
        }
        return $vatR->toArray();
    }

    /**
     * `_resolve.rows[].reverseChargeCode` jednoho řádku (#89 D10): předmět
     * plnění u řádku, na kterém skončí kód tuzemského přenesení — ať kód
     * vznikl derivací, z canonicalu, z historie řádků nebo volbou. Null =
     * řádek kód přenesení nemá (předmět se pak ignoruje) nebo doklad vznikne
     * Bez DPH.
     *
     * Předmět se bere z `rows[].vat.reverseChargeCode` a ověřuje proti
     * číselníku k datu sazby řádku; chybějící (`reverse_charge_code_missing`) ani
     * neznámý (`reverse_charge_code_unknown`) se nedoplňuje — error, který
     * blokuje apply i v importním módu.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $vatResolve {@see rowVatCode()}
     * @param array<string, mixed> $vatCtx {@see PayloadContext::vatContext()}
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * Volba uživatele (`_resolve.rows[].reverseChargeCode.userAction:
     * "useValue:<kód>"`, D12) má přednost; mimo nabídku je error
     * `reverse_charge_code_pin_invalid`. U řádku bez kódu přenesení se
     * ignoruje i volba.
     *
     * @return array{value: ?string, source: ?string, status: string}|null
     */
    public function resolveRowReverseChargeCode(
        int $idx,
        array $row,
        ?array $vatResolve,
        array $vatCtx,
        string $vatCountry,
        string $supplierCountry,
        array &$issues,
    ): ?array {
        if ($vatResolve === null || ($vatResolve['status'] ?? null) !== 'matched' || $vatCtx['withoutVat']) {
            return null;
        }
        $code = trim((string) ($vatResolve['createPayload']['code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $country = $this->payload->vatCountryForCode($code, $vatCountry, $supplierCountry);
        $country = $country !== '' ? $country : $vatCountry;
        // Platnost předmětu k datu sazby řádku (#159 U3).
        $date = $this->payload->lineRateDate($vatCtx, $row['vat']['rateDate'] ?? null);

        // D12: volba uživatele má přednost před dokladem. Platí jen předmět
        // z číselníku platný k datu sazby řádku (klient dostává nabídku
        // `reverseChargeOptions` k datu sazby dokladu); volba mimo je error
        // a předmět z dokladu ji nenahradí.
        $pinned = $vatCtx['pins']['subjects'][$idx] ?? null;
        if ($pinned !== null) {
            $decision = $this->vatCodeDerivation->reverseChargeSubject($country, $code, $date, $pinned);
            if (!$decision['applies']) {
                return null; // řádek kód přenesení nemá — volba se ignoruje
            }
            if ($decision['status'] !== 'matched') {
                $issues[] = [
                    'severity' => 'error',
                    'path'     => "rows.{$idx}.vat.reverseChargeCode",
                    'code'     => 'reverse_charge_code_pin_invalid',
                    'message'  => "Zvolený předmět plnění „{$pinned}“ není v nabídce"
                        . ($date !== null ? " platné k datu {$date}" : '') . ' — vyber jiný.',
                ];
            }
            return [
                'value'  => $decision['value'],
                'source' => 'user',
                'status' => $decision['status'] === 'matched' ? 'matched' : 'notFound',
            ];
        }

        $input = trim((string) ($row['vat']['reverseChargeCode'] ?? ''));
        $decision = $this->vatCodeDerivation->reverseChargeSubject($country, $code, $date, $input);
        if (!$decision['applies']) {
            return null;
        }
        if ($decision['status'] !== 'matched') {
            $issues[] = self::reverseChargeCodeIssue("rows.{$idx}.vat.reverseChargeCode", $decision, $date);
        }
        return [
            'value'  => $decision['value'],
            'source' => $decision['value'] !== null ? 'document' : null,
            'status' => $decision['status'] === 'matched' ? 'matched' : 'notFound',
        ];
    }

    /**
     * Issue chybějícího / neznámého předmětu plnění — jeden text pro řádek
     * i řádek převzaté rekapitulace.
     *
     * @param array{status: ?string, value: ?string} $decision {@see VatCodeDerivation::reverseChargeSubject()}
     * @return array{severity: string, path: string, code: string, message: string}
     */
    public static function reverseChargeCodeIssue(string $path, array $decision, ?string $date, string $prefix = ''): array
    {
        if ($decision['status'] === 'missing') {
            return [
                'severity' => 'error',
                'path'     => $path,
                'code'     => 'reverse_charge_code_missing',
                'message'  => $prefix . 'U přenesení daňové povinnosti chybí předmět plnění'
                    . ' (kód pro kontrolní hlášení, např. 4 stavební práce, 5 zboží z přílohy č. 5).',
            ];
        }
        return [
            'severity' => 'error',
            'path'     => $path,
            'code'     => 'reverse_charge_code_unknown',
            'message'  => $prefix . "Předmět plnění „{$decision['value']}“ není v číselníku"
                . ($date !== null ? " nebo k datu {$date} neplatí." : '.'),
        ];
    }

    /**
     * Předmět plnění řádku pro doklad: jen ověřený (`matched`) — neznámý se
     * na doklad nepropíše, apply stejně zablokuje error.
     *
     * @param array<string, mixed> $rowResolve
     */
    public static function matchedReverseChargeCode(array $rowResolve): ?string
    {
        $block = $rowResolve['reverseChargeCode'] ?? null;
        return is_array($block) && ($block['status'] ?? null) === 'matched' && ($block['value'] ?? null) !== null
            ? (string) $block['value']
            : null;
    }

    /**
     * Jeden řádek převzaté rekapitulace do tvaru dokladu — společné pro
     * návrh i pokyn. Kód už je ověřený proti číselníku země `$country`.
     *
     * @param array<int, array<string, mixed>> $recap
     * @param list<array{severity: string, path: string, code: string, message: string}> $subjectIssues
     * @param array<string, mixed> $entry řádek `vatRecap`
     */
    public function appendRecapEntry(
        array &$recap,
        array &$subjectIssues,
        array $entry,
        int|string $position,
        string $code,
        float $pct,
        ?string $rateDate,
        string $country,
    ): void {
        $recap[] = [
            'vat_code'        => $code,
            'vat_pct'         => $pct,
            'base'            => round((float) ($entry['base'] ?? 0), 2),
            'tax'             => round((float) ($entry['tax'] ?? 0), 2),
            'total'           => round((float) ($entry['total'] ?? 0), 2),
            'is_reverse_pair' => ($entry['isReversePair'] ?? null) === true ? 1 : 0,
        ];
        $last = array_key_last($recap);
        // Vlastní datum sazby řádku (#159 U4) se jen předá — mimo režim
        // „na řádcích“ ho dokument při uložení smaže.
        $ownRateDate = PayloadContext::dateText($entry['rateDate'] ?? null);
        if ($ownRateDate !== null) {
            $recap[$last][VatRateDates::COLUMN] = $ownRateDate;
        }
        // Předmět plnění tuzemského přenesení (#89 D10): přebírá se ze
        // vstupu, nic se nedoplňuje — chybějící nebo neznámý je chyba
        // i v importním módu. Oddaňovací pár nese předmět skupiny, sám
        // se neověřuje.
        $subject = trim((string) ($entry['reverseChargeCode'] ?? ''));
        if (($entry['isReversePair'] ?? null) === true) {
            $recap[$last]['reverse_charge_code'] = $subject !== '' ? $subject : null;
            return;
        }
        $decision = $this->vatCodeDerivation->reverseChargeSubject($country, $code, $rateDate, $subject);
        if (!$decision['applies']) {
            return;
        }
        $recap[$last]['reverse_charge_code'] = $decision['value'];
        if ($decision['status'] !== 'matched') {
            $subjectIssues[] = self::reverseChargeCodeIssue(
                'vatRecap.' . $position . '.reverseChargeCode',
                $decision,
                $rateDate,
                "Rekapitulace DPH, řádek {$code}: ",
            );
        }
    }
}
