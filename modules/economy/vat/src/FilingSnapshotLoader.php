<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Module\Economy\Accounting\VatAnalytics;

/**
 * Čtení snapshotu podání DPH (#55 D15) — řádky přiznání, dokladové součty
 * per kód a **kumulativní podaný stav** instance. Sdílené `FilingComposer`
 * (rozdíl dodatečného podání) a zaúčtováním přiznání
 * (`Accounting\VatReturnAccountingService`), aby obě strany měly jednu
 * pravdu o tom, co „bylo podáno".
 *
 * Kumulativní stav: řádné a opravné podání je plná náhrada, dodatečné se
 * přičítá k základu, ze kterého vzniklo (`previous_filing`). Dokladové
 * řádky (`filing_items`) i přesné hodnoty řádků jsou naopak vždy plný
 * obsah — řetěz se pro ně neskládá.
 */
final class FilingSnapshotLoader
{
    /** Pojistka proti zacyklenému řetězu `previous_filing`. */
    public const MAX_CHAIN_DEPTH = 50;

    public function __construct(private readonly \Dibi\Connection $db) {}

    /** @return ?array<string, mixed> */
    public function loadFiling(int $filingId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', FilingDocument::TABLE, $filingId);
        return $row !== null ? $row->toArray() : null;
    }

    /**
     * Podané hodnoty řádků přiznání per číslo řádku (u dodatečného rozdíl).
     *
     * @return array<int, array{base: float, taxFull: float, taxReduced: float}>
     */
    public function filedRows(int $filingId): array
    {
        return $this->rows($filingId, 'base_filed', 'tax_full_filed', 'tax_reduced_filed');
    }

    /**
     * Přesné hodnoty řádků přiznání per číslo řádku — vždy plný obsah.
     *
     * @return array<int, array{base: float, taxFull: float, taxReduced: float}>
     */
    public function exactRows(int $filingId): array
    {
        return $this->rows($filingId, 'base', 'tax_full', 'tax_reduced');
    }

    /**
     * Σ daně v domácí měně per kód DPH z dokladových řádků podání — jen
     * řádky, které jdou do přiznání (`dp3_row` není NULL): kód mimo přiznání
     * vypořádání analytik 343 nemění.
     *
     * Předmět plnění tuzemského přenesení dělí součet jen tam, kde ho
     * účtovací předpis rozlišuje vlastní analytikou (`$distinguished`,
     * {@see VatAnalytics::distinguishedSubjects()} — odpočet u předmětu 5
     * na 343117, #89 D9): klíč je pak `kód|předmět`, jinak jen kód.
     *
     * @param array<string, list<string>> $distinguished kód DPH → předměty s vlastní analytikou
     * @return array<string, float> klíč ({@see VatAnalytics::key()}) → daň
     */
    public function taxByCode(int $filingId, array $distinguished = []): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [vat_code], [kh_reverse_charge_code] AS [subject], SUM([tax_dom]) AS [tax]'
            . ' FROM [economy_vat_filing_items]'
            . ' WHERE [filing] = %i AND [dp3_row] IS NOT NULL'
            . ' GROUP BY [vat_code], [kh_reverse_charge_code] ORDER BY [vat_code], [kh_reverse_charge_code]',
            $filingId,
        );
        $out = [];
        foreach ($rows as $row) {
            $subject = trim((string) ($row['subject'] ?? ''));
            $key = VatAnalytics::key((string) $row['vat_code'], $subject !== '' ? $subject : null, $distinguished);
            $out[$key] = round(($out[$key] ?? 0.0) + (float) $row['tax'], 2);
        }
        ksort($out);
        return $out;
    }

    /**
     * Kumulativní podaný stav podání: opravné a řádné je plná náhrada,
     * dodatečné se přičítá k základu, ze kterého vzniklo.
     *
     * @return array<int, array{base: float, taxFull: float, taxReduced: float}>
     */
    public function cumulativeFiledRows(int $filingId, int $depth = 0): array
    {
        if ($depth > self::MAX_CHAIN_DEPTH) {
            throw new \RuntimeException("Řetěz dodatečných podání je zacyklený u podání #{$filingId}");
        }
        $filing = $this->loadFiling($filingId);
        if ($filing === null) {
            throw new \DomainException("Předchozí podání #{$filingId} nenalezeno");
        }

        $own = $this->filedRows($filingId);
        if ((string) $filing['filing_kind'] !== FilingDocument::KIND_SUPPLEMENTARY) {
            return $own;
        }
        $previousId = (int) ($filing['previous_filing'] ?? 0);
        if ($previousId <= 0) {
            return $own;
        }
        return self::addRows($this->cumulativeFiledRows($previousId, $depth + 1), $own);
    }

    /**
     * Součet dvou sad řádků per řádek a pole (dodatečné + jeho základ).
     *
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $base
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $add
     * @return array<int, array{base: float, taxFull: float, taxReduced: float}>
     */
    public static function addRows(array $base, array $add): array
    {
        foreach ($add as $row => $values) {
            foreach ($values as $field => $value) {
                $base[$row][$field] = round(($base[$row][$field] ?? 0.0) + $value, 2);
            }
        }
        return $base;
    }

    /**
     * Daňová povinnost sady řádků (ř. 62 − ř. 63) — kladná = odvod, záporná
     * = nadměrný odpočet. Stejná definice jako `FilingRounding` pro ř. 66.
     *
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $rows
     */
    public static function liability(array $rows): float
    {
        return round(($rows[62]['taxFull'] ?? 0.0) - ($rows[63]['taxFull'] ?? 0.0), 2);
    }

    /** @return array<int, array{base: float, taxFull: float, taxReduced: float}> */
    private function rows(int $filingId, string $baseCol, string $fullCol, string $reducedCol): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [row], %n AS [b], %n AS [f], %n AS [r]'
            . ' FROM [economy_vat_filing_return_rows] WHERE [filing] = %i',
            $baseCol, $fullCol, $reducedCol, $filingId,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['row']] = [
                'base'       => (float) $row['b'],
                'taxFull'    => (float) $row['f'],
                'taxReduced' => (float) $row['r'],
            ];
        }
        return $out;
    }
}
