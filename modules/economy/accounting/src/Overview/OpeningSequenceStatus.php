<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Overview;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\IsoDate;

/**
 * Pojistka počátečních stavů (#120 D6, D15), sdílená bloky pokladny
 * i saldokonta: běžné účetní období **nemá zápisy deníku v otevíracím
 * měsíci** (`fiscal_months.period_type` 0) a **předchozí období zápisy má**
 * → počáteční stavy nejsou převedené a zůstatky by lhaly (saldokonto: klíč
 * případu nese `fiscal_year`, pokladna: účet začíná od nuly).
 *
 * První rok firmy (bez předchozího období) ani předchozí období bez zápisů
 * varování nedávají — není co převádět.
 */
final class OpeningSequenceStatus
{
    private const DOC_STATE_DELETED    = 90;
    private const PERIOD_TYPE_OPENING  = 0;

    public function __construct(private readonly DataSourceConnection $db) {}

    /** @return array{missing: bool, firstYear: bool, previousYearId: ?int} */
    public function check(int $fiscalYearId): array
    {
        $dateBegin = IsoDate::fromDb($this->db->fetchSingle(
            'SELECT [date_begin] FROM [economy_codebooks_fiscal_years] WHERE [id] = %i AND [docState] != %i',
            $fiscalYearId, self::DOC_STATE_DELETED,
        ));
        if ($dateBegin === null) {
            return ['missing' => false, 'firstYear' => true, 'previousYearId' => null];
        }

        $previousYearId = $this->db->fetchSingle(
            'SELECT [id] FROM [economy_codebooks_fiscal_years]'
            . ' WHERE [docState] != %i AND [date_end] < %s ORDER BY [date_end] DESC, [id] DESC LIMIT 1',
            self::DOC_STATE_DELETED, $dateBegin,
        );
        if ($previousYearId === null) {
            return ['missing' => false, 'firstYear' => true, 'previousYearId' => null];
        }
        $previousYearId = (int) $previousYearId;

        $previousHasEntries = $this->db->fetchSingle(
            'SELECT 1 FROM [economy_accounting_journal] WHERE [fiscal_year] = %i LIMIT 1',
            $previousYearId,
        ) !== null;
        if (!$previousHasEntries) {
            return ['missing' => false, 'firstYear' => false, 'previousYearId' => $previousYearId];
        }

        $hasOpeningEntries = $this->db->fetchSingle(
            'SELECT 1 FROM [economy_accounting_journal] [j]'
            . ' JOIN [economy_codebooks_fiscal_months] [m] ON [m].[id] = [j].[fiscal_month]'
            . ' WHERE [j].[fiscal_year] = %i AND [m].[period_type] = %i LIMIT 1',
            $fiscalYearId, self::PERIOD_TYPE_OPENING,
        ) !== null;

        return ['missing' => !$hasOpeningEntries, 'firstYear' => false, 'previousYearId' => $previousYearId];
    }
}
