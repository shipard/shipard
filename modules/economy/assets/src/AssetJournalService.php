<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

/**
 * Náklady a výnosy karty majetku z deníku (docs/assets.md D15, D64): řádky
 * deníku s dimenzí `asset` = karta, kromě operací `asset.*` — ty jsou
 * vlastní zaúčtování majetku (zařazení, odpisy, vyřazení) a ukazuje je plán
 * odpisů. Souhrn po účetních letech dělí obrat na náklady (třída 5),
 * výnosy (třída 6) a ostatní účty (pořízení na 042, DPH a závazek
 * u dokladu s kartou na hlavičce).
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class AssetJournalService
{
    /** Nejvýš tolik řádků deníku ukáže karta; zbytek je v deníku s filtrem. */
    public const ROW_LIMIT = 200;

    /** Operace vlastního zaúčtování majetku — do nákladů a výnosů nepatří. */
    public const SYSTEM_OPERATIONS = 'asset.%';

    public function __construct(protected readonly ?\Dibi\Connection $db)
    {
    }

    /**
     * @return array{
     *     years: list<array{year: string, expenses: float, revenues: float, otherDr: float, otherCr: float}>,
     *     rows: list<array{docId: int, docNumber: string, date: ?string, accountNumber: string, text: string,
     *         moneyDr: float, moneyCr: float}>,
     *     more: bool,
     * }
     */
    public function overview(int $assetId): array
    {
        $rows = [];
        foreach ($this->loadRows($assetId, self::ROW_LIMIT + 1) as $row) {
            $rows[] = [
                'docId'         => (int) ($row['doc_head'] ?? 0),
                'docNumber'     => (string) ($row['doc_number'] ?? ''),
                'date'          => self::isoDate($row['accounting_date'] ?? null),
                'accountNumber' => (string) ($row['account_number'] ?? ''),
                'text'          => (string) ($row['text'] ?? ''),
                'moneyDr'       => round((float) ($row['money_dr'] ?? 0), 2),
                'moneyCr'       => round((float) ($row['money_cr'] ?? 0), 2),
            ];
        }
        if ($rows === []) {
            return ['years' => [], 'rows' => [], 'more' => false];
        }
        $more = count($rows) > self::ROW_LIMIT;

        $years = [];
        foreach ($this->loadYearTotals($assetId) as $year) {
            $years[] = [
                'year'     => (string) ($year['year_name'] ?? ''),
                'expenses' => round((float) ($year['expenses'] ?? 0), 2),
                'revenues' => round((float) ($year['revenues'] ?? 0), 2),
                'otherDr'  => round((float) ($year['other_dr'] ?? 0), 2),
                'otherCr'  => round((float) ($year['other_cr'] ?? 0), 2),
            ];
        }

        return ['years' => $years, 'rows' => array_slice($rows, 0, self::ROW_LIMIT), 'more' => $more];
    }

    /**
     * Řádky deníku karty mimo zaúčtování majetku, od nejnovějšího.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadRows(int $assetId, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [j].[doc_head], [j].[doc_number], [j].[accounting_date], [j].[account_number],'
            . ' [j].[text], [j].[money_dr], [j].[money_cr]'
            . ' FROM [economy_accounting_journal] [j]'
            . ' WHERE [j].[asset] = %i AND ([j].[operation] IS NULL OR [j].[operation] NOT LIKE %s)'
            . ' ORDER BY [j].[accounting_date] DESC, [j].[id] DESC'
            . ' LIMIT %i',
            $assetId,
            self::SYSTEM_OPERATIONS,
            $limit,
        );
        return array_map(static fn(iterable $row): array => AssetPlanService::plain($row), $rows);
    }

    /**
     * Obraty karty po účetních letech, od nejnovějšího.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadYearTotals(int $assetId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [y].[name] AS [year_name],'
            . ' SUM(CASE WHEN [j].[account_number] LIKE %s THEN [j].[money_dr] - [j].[money_cr] ELSE 0 END) AS [expenses],'
            . ' SUM(CASE WHEN [j].[account_number] LIKE %s THEN [j].[money_cr] - [j].[money_dr] ELSE 0 END) AS [revenues],'
            . ' SUM(CASE WHEN [j].[account_number] LIKE %s OR [j].[account_number] LIKE %s THEN 0 ELSE [j].[money_dr] END) AS [other_dr],'
            . ' SUM(CASE WHEN [j].[account_number] LIKE %s OR [j].[account_number] LIKE %s THEN 0 ELSE [j].[money_cr] END) AS [other_cr]'
            . ' FROM [economy_accounting_journal] [j]'
            . ' LEFT JOIN [economy_codebooks_fiscal_years] [y] ON [y].[id] = [j].[fiscal_year]'
            . ' WHERE [j].[asset] = %i AND ([j].[operation] IS NULL OR [j].[operation] NOT LIKE %s)'
            . ' GROUP BY [j].[fiscal_year], [y].[name], [y].[date_begin]'
            . ' ORDER BY [y].[date_begin] DESC',
            '5%', '6%', '5%', '6%', '5%', '6%',
            $assetId,
            self::SYSTEM_OPERATIONS,
        );
        return array_map(static fn(iterable $row): array => AssetPlanService::plain($row), $rows);
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }
}
