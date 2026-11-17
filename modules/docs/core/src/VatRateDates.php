<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\DisplayDate;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Datum sazby DPH (#159) — jedno místo pravidel, k jakému datu se hledá
 * sazba kódu DPH řádku dokladu a řádku rekapitulace. Procento se nezadává:
 * vždy se odvodí z kódu a data sazby ({@see pct()}).
 *
 * Datum sazby určuje přepínač hlavičky `vat_rate_source`
 * (cfgItem `docs.core.vatRateSources`):
 *  - 0 automaticky — opravný doklad k datu plnění původního dokladu (#92 D4),
 *    jinak DPPD, bez něj DUZP, bez něj datum vystavení ({@see automaticDate()});
 *  - 1 vlastní datum dokladu — `vat_rate_date` hlavičky pro všechny řádky
 *    i řádky převzaté rekapitulace; nevyplněné = do potvrzení automatika;
 *  - 2 vlastní datum na řádcích — `vat_rate_date` řádku, prázdné = automatika.
 *
 * Nedaňový doklad a doklad bez DPH má vždy zdroj 0 ({@see source()}); data
 * mimo svůj režim se při uložení mažou ({@see normalizeHead()},
 * {@see normalizeLine()}).
 *
 * `$head` je hlavička z DB, data k uložení, nebo kontext
 * {@see DocHeadVatContext::load()} — ten nese tytéž sloupce. Funkce čtou jen
 * data: `validate()` dokumentu běží před `beforeSave()`, takže nesmí
 * spoléhat na už normalizovanou hlavičku.
 *
 * Zařazení do období DPH, kontrolního hlášení a zámky období datum sazby
 * nečtou — řídí se dál `vat_duzp` / `vat_dppd`.
 */
final class VatRateDates
{
    public const SOURCE_AUTO = 0;
    public const SOURCE_DOCUMENT = 1;
    public const SOURCE_ROWS = 2;

    /** Sloupec přepínače na hlavičce. */
    public const SOURCE_COLUMN = 'vat_rate_source';
    /** Sloupec vlastního data — na hlavičce, řádku dokladu i řádku rekapitulace. */
    public const COLUMN = 'vat_rate_date';

    public const CODE_MISSING = 'vat_rate_missing';
    public const CODE_LATE = 'vat_rate_date_late';
    public const CODE_REQUIRED = 'vat_rate_date_required';

    /**
     * Platný zdroj data sazby: hodnota přepínače, u nedaňového dokladu,
     * dokladu bez DPH a u neznámé hodnoty vždy 0.
     *
     * @param array<string, mixed> $head
     */
    public static function source(array $head, ?ConfigRuntime $config = null): int
    {
        $source = (int) ($head[self::SOURCE_COLUMN] ?? self::SOURCE_AUTO);
        if ($source !== self::SOURCE_DOCUMENT && $source !== self::SOURCE_ROWS) {
            return self::SOURCE_AUTO;
        }
        if ((int) ($head['vat_mode'] ?? 1) === 0) {
            return self::SOURCE_AUTO;
        }
        if (!DocTypes::isTaxDocument($config, (string) ($head['doc_type'] ?? ''))) {
            return self::SOURCE_AUTO;
        }
        return $source;
    }

    /**
     * Automatické datum sazby dokladu (D3): opravný doklad s vyplněným datem
     * plnění původního dokladu toto datum, jinak DPPD, bez něj DUZP, bez něj
     * datum vystavení (nedaňový doklad DUZP ani DPPD nemá, #79 D1).
     *
     * @param array<string, mixed> $head
     */
    public static function automaticDate(array $head): ?string
    {
        if (!empty($head['corrective'])) {
            $corrected = self::dateString($head['corrected_doc_date'] ?? null);
            if ($corrected !== null) {
                return $corrected;
            }
        }
        return self::dateString($head['vat_dppd'] ?? null)
            ?? self::dateString($head['vat_duzp'] ?? null)
            ?? self::dateString($head['issue_date'] ?? null);
    }

    /**
     * Datum sazby dokladu (D4): při zdroji 1 vyplněné vlastní datum
     * hlavičky, jinak automatika.
     *
     * @param array<string, mixed> $head
     */
    public static function headDate(array $head, ?ConfigRuntime $config = null): ?string
    {
        if (self::source($head, $config) === self::SOURCE_DOCUMENT) {
            $own = self::dateString($head[self::COLUMN] ?? null);
            if ($own !== null) {
                return $own;
            }
        }
        return self::automaticDate($head);
    }

    /**
     * Datum sazby řádku dokladu nebo řádku rekapitulace (D5): při zdroji 2
     * datum řádku, prázdné = automatika; jinak datum sazby dokladu.
     *
     * @param array<string, mixed> $head
     * @param array<string, mixed> $line
     */
    public static function lineDate(array $head, array $line, ?ConfigRuntime $config = null): ?string
    {
        if (self::source($head, $config) === self::SOURCE_ROWS) {
            return self::dateString($line[self::COLUMN] ?? null) ?? self::automaticDate($head);
        }
        return self::headDate($head, $config);
    }

    /**
     * Hlavička k uložení (D6): zdroj podle {@see source()}, vlastní datum jen
     * při zdroji 1 — jinak NULL.
     *
     * @param array<string, mixed> $head
     */
    public static function normalizeHead(array &$head, ?ConfigRuntime $config = null): void
    {
        $source = self::source($head, $config);
        $head[self::SOURCE_COLUMN] = $source;
        $head[self::COLUMN] = $source === self::SOURCE_DOCUMENT
            ? self::dateString($head[self::COLUMN] ?? null)
            : null;
    }

    /**
     * Řádek dokladu nebo rekapitulace k uložení (D6): vlastní datum jen při
     * zdroji 2 hlavičky — jinak NULL.
     *
     * @param array<string, mixed> $head
     * @param array<string, mixed> $line
     */
    public static function normalizeLine(array $head, array &$line, ?ConfigRuntime $config = null): void
    {
        $line[self::COLUMN] = self::source($head, $config) === self::SOURCE_ROWS
            ? self::dateString($line[self::COLUMN] ?? null)
            : null;
    }

    /**
     * DPPD následuje změnu DUZP (#159 U13) — pro doklad, jehož formulář
     * DPPD neukazuje (směr prodej: faktura vydaná, prodejka, příjmový
     * pokladní doklad; typ a směr hlídá volající). `applyDateDefaults`
     * doplňuje DPPD jen prázdné, takže po změně DUZP by zůstalo staré
     * a automatika by brala sazbu k datu, které uživatel nevidí.
     *
     * Následuje se jen DPPD, které DUZP sledovalo (v uloženém dokladu byla
     * obě data shodná) a které payload sám nemění — odlišné DPPD z importu
     * nebo výměnného formátu je fakt a zůstává.
     *
     * @param array<string, mixed> $head data k uložení
     * @param array<string, mixed> $original uložený stav hlavičky
     */
    public static function followObligationDate(array &$head, array $original): void
    {
        $originalDuzp = self::dateString($original['vat_duzp'] ?? null);
        if ($originalDuzp === null || self::dateString($original['vat_dppd'] ?? null) !== $originalDuzp) {
            return;
        }
        $duzp = self::dateString($head['vat_duzp'] ?? null);
        if ($duzp === null || $duzp === $originalDuzp) {
            return;
        }
        $dppd = self::dateString($head['vat_dppd'] ?? null);
        if ($dppd !== null && $dppd !== $originalDuzp) {
            return;
        }
        $head['vat_dppd'] = $head['vat_duzp'];
    }

    /**
     * Procento kódu DPH k datu sazby (D1). null = bez kódu, bez data, kód
     * mimo číselník nebo bez sazby k datu — volající pak nechává uložené
     * procento (U4, U8).
     */
    public static function pct(VatRateResolver $resolver, string $country, mixed $vatCode, ?string $date): ?float
    {
        $code = trim((string) ($vatCode ?? ''));
        if ($code === '' || $country === '' || $date === null || $date === '') {
            return null;
        }
        try {
            return $resolver->resolveVatPct($country, $code, $date);
        } catch (\LogicException) {
            return null;
        }
    }

    /**
     * Nálezy hlavičky (D9, D15, U11):
     *  - `vat_rate_date_required` — zdroj 1 bez vyplněného data; hlásí se jen
     *    v přísném režimu (potvrzení dokladu, importní mód) jako chyba, do té
     *    doby platí automatika;
     *  - `vat_rate_date_late` — vlastní datum dokladu pozdější než
     *    automatické; varování.
     *
     * @param array<string, mixed> $head
     * @return list<array{column: string, message: string, code: string, warning: bool}>
     */
    public static function headProblems(array $head, ?ConfigRuntime $config, bool $strict): array
    {
        if (self::source($head, $config) !== self::SOURCE_DOCUMENT) {
            return [];
        }
        $own = self::dateString($head[self::COLUMN] ?? null);
        if ($own === null) {
            return $strict
                ? [[
                    'column'  => self::COLUMN,
                    'message' => 'Datum sazby DPH je při volbě „Vlastní datum dokladu“ povinné',
                    'code'    => self::CODE_REQUIRED,
                    'warning' => false,
                ]]
                : [];
        }
        $late = self::lateProblem($head, $own);
        return $late !== null ? [$late] : [];
    }

    /**
     * Nálezy jednoho řádku dokladu nebo řádku rekapitulace (D9, D15, U11):
     *  - `vat_rate_missing` na `vat_code` — kód nemá k datu sazby řádku
     *    sazbu; v přísném režimu (potvrzení dokladu, importní mód, uložení
     *    řádku ze sub-formuláře) chyba, jinak varování;
     *  - `vat_rate_date_late` na `vat_rate_date` — vlastní datum řádku
     *    pozdější než automatické datum dokladu; varování.
     *
     * Řádek bez kódu DPH nálezy nemá. Které řádky sem patří (položkový řádek,
     * řádek rekapitulace mimo oddaňovací pár), rozhoduje volající.
     *
     * @param array<string, mixed> $head
     * @param array<string, mixed> $line
     * @return list<array{column: string, message: string, code: string, warning: bool}>
     */
    public static function lineProblems(
        VatRateResolver $resolver,
        string $country,
        array $head,
        array $line,
        ?ConfigRuntime $config,
        bool $strict,
    ): array {
        $code = trim((string) ($line['vat_code'] ?? ''));
        if ($code === '') {
            return [];
        }
        $problems = [];
        $date = self::lineDate($head, $line, $config);
        if ($date !== null && self::pct($resolver, $country, $code, $date) === null) {
            $problems[] = [
                'column'  => 'vat_code',
                'message' => "Kód DPH „{$code}“ nemá k datu sazby " . self::displayDate($date) . ' sazbu',
                'code'    => self::CODE_MISSING,
                'warning' => !$strict,
            ];
        }
        if (self::source($head, $config) === self::SOURCE_ROWS) {
            $own = self::dateString($line[self::COLUMN] ?? null);
            $late = $own !== null ? self::lateProblem($head, $own) : null;
            if ($late !== null) {
                $problems[] = $late;
            }
        }
        return $problems;
    }

    /**
     * Varování U11: vlastní datum sazby pozdější než automatické datum
     * dokladu — sazba „z budoucnosti“ bývá překlep.
     *
     * @param array<string, mixed> $head
     * @return array{column: string, message: string, code: string, warning: bool}|null
     */
    private static function lateProblem(array $head, string $own): ?array
    {
        $automatic = self::automaticDate($head);
        if ($automatic === null || $own <= $automatic) {
            return null;
        }
        return [
            'column'  => self::COLUMN,
            'message' => 'Datum sazby DPH ' . self::displayDate($own) . ' je pozdější než datum, ke kterému'
                . ' by se sazby určily automaticky (' . self::displayDate($automatic) . ') — zkontroluj ho.',
            'code'    => self::CODE_LATE,
            'warning' => true,
        ];
    }

    /** Datum jako `YYYY-MM-DD`; prázdná hodnota null. */
    public static function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? substr($text, 0, 10) : null;
    }

    /** Datum v české hlášce (validace nemá jazyk požadavku). */
    private static function displayDate(string $date): string
    {
        return DisplayDate::date($date, 'cs') ?? $date;
    }
}
