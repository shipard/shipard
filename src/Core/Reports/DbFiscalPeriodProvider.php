<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

use Shipard\Core\Database\DataSourceConnection;

final class DbFiscalPeriodProvider implements FiscalPeriodProvider
{
    private const DOC_STATE_DELETED = 90;
    private const PERIOD_TYPE_REGULAR = 1;

    public function __construct(private readonly DataSourceConnection $db) {}

    public function findYearByName(string $name): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT [id], [name] FROM [economy_codebooks_fiscal_years]'
            . ' WHERE [name] = %s AND [docState] != %i LIMIT 1',
            $name, self::DOC_STATE_DELETED,
        );
        return $row !== null ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    public function monthsOfYear(int $fiscalYearId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [period_type] FROM [economy_codebooks_fiscal_months]'
            . ' WHERE [fiscal_year] = %i ORDER BY [date_begin], [id]',
            $fiscalYearId,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'periodType' => (int) $row['period_type']];
        }
        return $out;
    }

    public function regularYears(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [y].[name] AS [name], COUNT([m].[id]) AS [months]'
            . ' FROM [economy_codebooks_fiscal_years] [y]'
            . ' JOIN [economy_codebooks_fiscal_months] [m] ON [m].[fiscal_year] = [y].[id]'
            . ' WHERE [y].[docState] != %i AND [m].[period_type] = %i'
            . ' GROUP BY [y].[id], [y].[name] ORDER BY [y].[name]',
            self::DOC_STATE_DELETED, self::PERIOD_TYPE_REGULAR,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['name' => (string) $row['name'], 'months' => (int) $row['months']];
        }
        return $out;
    }

    public function yearForDate(string $date): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT [id], [name] FROM [economy_codebooks_fiscal_years]'
            . ' WHERE [docState] != %i AND [date_begin] <= %s AND [date_end] >= %s'
            . ' ORDER BY [date_begin] DESC LIMIT 1',
            self::DOC_STATE_DELETED, $date, $date,
        );
        return $row !== null ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    public function years(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name] FROM [economy_codebooks_fiscal_years]'
            . ' WHERE [docState] != %i ORDER BY [date_begin] DESC, [id] DESC',
            self::DOC_STATE_DELETED,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        return $out;
    }
}
