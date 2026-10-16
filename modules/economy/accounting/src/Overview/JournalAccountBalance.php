<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Overview;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\IsoDate;

/**
 * Zůstatek účtu z deníku za účetní období — totéž číslo jako sloupec
 * `closing` hlavní knihy (otevírací + běžné měsíce, uzavírací měsíc
 * vyloučen stejně jako v `GeneralLedgerBuilder`), navíc v měně účtu
 * ze sloupců `money_dr_cur` / `money_cr_cur` (cizoměnová pokladna, D19)
 * a datum posledního zápisu (D5). Shodu s hlavní knihou hlídá integrační
 * test.
 */
final class JournalAccountBalance
{
    private const PERIOD_TYPES_IN_BALANCE = [0, 1];

    public function __construct(private readonly DataSourceConnection $db) {}

    /** @return array{balance: float, balanceCur: float, lastDate: ?string, entries: int} */
    public function forAccount(string $accountNumber, int $fiscalYearId): array
    {
        $row = $this->db->fetchRow(
            'SELECT SUM([j].[money_dr] - [j].[money_cr]) AS [balance],'
            . ' SUM([j].[money_dr_cur] - [j].[money_cr_cur]) AS [balance_cur],'
            . ' MAX([j].[accounting_date]) AS [last_date], COUNT(*) AS [entries]'
            . ' FROM [economy_accounting_journal] [j]'
            . ' JOIN [economy_codebooks_fiscal_months] [m] ON [m].[id] = [j].[fiscal_month]'
            . ' WHERE [j].[account_number] = %s AND [j].[fiscal_year] = %i AND [m].[period_type] IN %in',
            $accountNumber, $fiscalYearId, self::PERIOD_TYPES_IN_BALANCE,
        );

        return [
            'balance'    => round((float) ($row['balance'] ?? 0), 2),
            'balanceCur' => round((float) ($row['balance_cur'] ?? 0), 2),
            'lastDate'   => IsoDate::fromDb($row['last_date'] ?? null),
            'entries'    => (int) ($row['entries'] ?? 0),
        ];
    }
}
