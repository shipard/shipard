<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Předmět plnění u tuzemského přenesení daňové povinnosti (#89 D7, D14) —
 * sdílená pravidla sloupce `reverse_charge_code` řádku dokladu a řádku
 * rekapitulace: kdy ho kód DPH vyžaduje, co je platná hodnota a nabídka
 * pro formulář. Číselník sám čte jen {@see VatRateResolver}.
 *
 * Pravidla (tasks/vat-reverse-charge-subjects.md → Chování):
 *  - kód DPH s příznakem `reverseCharge` předmět vyžaduje (při potvrzení
 *    dokladu), jiný kód ho mít nesmí — při uložení se vynuluje;
 *  - předmět mimo číselník nebo neplatný k datu sazby dokladu
 *    ({@see DocHeadVatContext::vatRateDate()}) je chyba vždy;
 *  - oddaňovací pár rekapitulace nese předmět své skupiny.
 */
final class ReverseChargeSubjects
{
    public const COLUMN = 'reverse_charge_code';

    /** Hodnota sloupce v kanonickém tvaru: oříznutý řetězec, prázdné = null. */
    public static function value(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $text = trim((string) $raw);
        return $text === '' ? null : $text;
    }

    /**
     * Nese kód DPH předmět plnění? Jen kód s příznakem `reverseCharge`;
     * `reverseVatCode` mají i kódy EU a dovozu, které předmět nemají.
     *
     * @param array<string, array<string, mixed>> $vatCodes definice kódů země
     */
    public static function appliesTo(array $vatCodes, mixed $vatCode): bool
    {
        $code = (string) ($vatCode ?? '');
        return $code !== '' && !empty($vatCodes[$code]['reverseCharge']);
    }

    /**
     * Předmět, který smí řádek (nebo řádek rekapitulace) nést: u kódu
     * přenesení jeho hodnota, u oddaňovacího páru rekapitulace předmět
     * skupiny, jinak null.
     *
     * @param array<string, mixed> $line řádek dokladu nebo rekapitulace
     * @param array<string, array<string, mixed>> $vatCodes
     */
    public static function normalized(array $line, array $vatCodes): ?string
    {
        if (!self::appliesTo($vatCodes, $line['vat_code'] ?? null) && empty($line['is_reverse_pair'])) {
            return null;
        }
        return self::value($line[self::COLUMN] ?? null);
    }

    /**
     * Chyba předmětu u jednoho řádku; null = v pořádku.
     *
     * @param array<string, mixed> $line řádek dokladu nebo rekapitulace
     * @param array<string, array<string, mixed>> $vatCodes
     * @param bool $requireFilled chybějící předmět je chyba (potvrzení dokladu)
     * @return array{message: string, code: string}|null
     */
    public static function problem(
        VatRateResolver $resolver,
        string $country,
        array $vatCodes,
        array $line,
        ?string $rateDate,
        bool $requireFilled,
    ): ?array {
        if (!self::appliesTo($vatCodes, $line['vat_code'] ?? null)) {
            return null;
        }
        $subject = self::value($line[self::COLUMN] ?? null);
        if ($subject === null) {
            return $requireFilled
                ? [
                    'message' => 'U přenesení daňové povinnosti je předmět plnění povinný',
                    'code'    => 'required',
                ]
                : null;
        }
        if ($resolver->isReverseChargeSubjectValid($country, $subject, $rateDate)) {
            return null;
        }
        $known = $resolver->isReverseChargeSubjectValid($country, $subject, null);
        return [
            'message' => $known && $rateDate !== null
                ? "Předmět plnění „{$subject}“ k datu {$rateDate} neplatí"
                : "Předmět plnění „{$subject}“ není v číselníku",
            'code'    => 'invalid_value',
        ];
    }

    /**
     * Nabídka pro select formuláře: předměty platné k datu sazby hlavičky.
     *
     * @param array<string, mixed>|null $context {@see DocHeadVatContext::load()}
     * @return list<array{value: string, label: string}>
     */
    public static function options(?array $context, ?ConfigRuntime $config): array
    {
        if ($context === null || empty($context['country']) || $config === null) {
            return [];
        }
        $date = $context['vat_rate_date'] ?? null;
        try {
            $subjects = (new VatRateResolver($config))->getReverseChargeSubjectsValidAt(
                (string) $context['country'],
                $date !== null && $date !== '' ? (string) $date : null,
            );
        } catch (\LogicException) {
            return [];
        }
        return array_map(
            static fn (array $subject): array => [
                'value' => $subject['code'],
                'label' => self::label($subject['code'], $subject['name']),
            ],
            $subjects,
        );
    }

    /**
     * Kontext hlavičky pro dětské dokumenty (řádek dokladu, řádek převzaté
     * rekapitulace), které se ukládají samostatně ze sub-formuláře: země
     * registrace DPH, definice kódů a datum sazby. null = bez DPH nebo bez
     * konfigurace — pravidla předmětu se pak neuplatní.
     *
     * @return array{country: string, codes: array<string, array<string, mixed>>, rateDate: ?string}|null
     */
    public static function headContext(?\Dibi\Connection $db, ?ConfigRuntime $config, mixed $headId): ?array
    {
        $headId = (int) ($headId ?? 0);
        if ($db === null || $config === null || $headId <= 0) {
            return null;
        }
        $head = $db->fetch(
            'SELECT [vat_registration], [vat_mode], [vat_duzp], [issue_date], [corrective], [corrected_doc_date]'
            . ' FROM [docs_core_heads] WHERE [id] = %i',
            $headId,
        );
        if ($head === null || $head === false) {
            return null;
        }
        $head = $head instanceof \Dibi\Row ? $head->toArray() : (array) $head;
        if ((int) ($head['vat_mode'] ?? 1) === 0 || empty($head['vat_registration'])) {
            return null;
        }
        $country = $db->fetchSingle(
            'SELECT [country] FROM [economy_codebooks_vat_registrations] WHERE [id] = %i',
            (int) $head['vat_registration'],
        );
        if ($country === null || $country === false || $country === '') {
            return null;
        }
        try {
            $codes = (new VatRateResolver($config))->getVatCodes((string) $country, null, null, true);
        } catch (\LogicException) {
            return null;
        }
        return [
            'country'  => (string) $country,
            'codes'    => $codes,
            'rateDate' => DocHeadVatContext::vatRateDate($head),
        ];
    }

    /**
     * Validace předmětu řádku ukládaného ze sub-formuláře: jen hodnota
     * (mimo číselník / neplatná k datu). Chybějící předmět tady chybou
     * není — hlídá ho potvrzení dokladu (`DocDocument::validate`).
     *
     * @param array<string, mixed> $line
     * @return array{message: string, code: string}|null
     */
    public static function lineProblem(?\Dibi\Connection $db, ?ConfigRuntime $config, array $line): ?array
    {
        $context = self::headContext($db, $config, $line['doc_head'] ?? null);
        if ($context === null || $config === null) {
            return null;
        }
        return self::problem(
            new VatRateResolver($config),
            $context['country'],
            $context['codes'],
            $line,
            $context['rateDate'],
            requireFilled: false,
        );
    }

    /**
     * Vynulování předmětu u řádku ukládaného ze sub-formuláře, jehož kód
     * DPH předmět nenese. Bez kontextu hlavičky se hodnota jen ořízne.
     *
     * @param array<string, mixed> $line
     */
    public static function normalizeLine(?\Dibi\Connection $db, ?ConfigRuntime $config, array &$line): void
    {
        $hasSubject = array_key_exists(self::COLUMN, $line);
        if (!$hasSubject && !array_key_exists('vat_code', $line)) {
            return;
        }
        $context = self::headContext($db, $config, $line['doc_head'] ?? null);
        if ($context === null) {
            if ($hasSubject) {
                $line[self::COLUMN] = self::value($line[self::COLUMN]);
            }
            return;
        }
        if ($hasSubject) {
            $line[self::COLUMN] = self::normalized($line, $context['codes']);
            return;
        }
        // Částečný zápis se změnou kódu DPH bez sloupce předmětu: uložený
        // předmět nesmí u kódu bez přenesení zůstat.
        if (!self::appliesTo($context['codes'], $line['vat_code']) && empty($line['is_reverse_pair'])) {
            $line[self::COLUMN] = null;
        }
    }

    /** Je kód DPH v kontextu hlavičky kódem přenesení? (viditelnost pole formuláře) */
    public static function fieldVisible(?array $context, ?ConfigRuntime $config, mixed $vatCode): bool
    {
        if ($context === null || empty($context['country']) || $config === null) {
            return false;
        }
        try {
            return (new VatRateResolver($config))->isReverseChargeCode(
                (string) $context['country'],
                $vatCode !== null ? (string) $vatCode : null,
            );
        } catch (\LogicException) {
            return false;
        }
    }

    /** Popisek předmětu: „4a — Stavební a montážní práce — poskytnutí pracovníků“. */
    public static function label(string $code, ?string $name): string
    {
        return $name !== null && $name !== '' ? "{$code} — {$name}" : $code;
    }
}
