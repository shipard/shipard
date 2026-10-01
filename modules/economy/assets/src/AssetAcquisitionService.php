<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

/**
 * Pořízení karty majetku z dokladů (docs/assets.md D14, D61, D63): řádky
 * `purchase.asset` potvrzených dokladů (stav V pořádku), které nesou kartu.
 *
 * Pořízení je věc řádku — počítá se jen karta zapsaná na řádku, karta
 * z hlavičky dokladu se neřeší. Částka = základ řádku v domácí měně
 * (`vat_base_dom`); neodpočitatelnou DPH doplní uživatel při zařazení.
 * Účet řádku určuje význam: 04x = dlouhodobý majetek k zařazení, ostatní
 * (5xx) = drobný majetek do nákladů.
 *
 * DB přístup je v protected metodě (přepsatelné v testech).
 */
class AssetAcquisitionService
{
    public const OPERATION = 'purchase.asset';

    /** Stav hlavičky dokladu „V pořádku“. */
    public const DOC_STATE_CONFIRMED = 40;

    /** Syntetická skupina účtů pořízení dlouhodobého majetku. */
    public const ACQUISITION_ACCOUNT_PREFIX = '04';

    public function __construct(protected readonly ?\Dibi\Connection $db)
    {
    }

    /**
     * Pořízení karty: řádky od nejstaršího, součet a podklad pro zařazení
     * (součet řádků na 04x a jejich poslední účetní datum).
     *
     * @return array{
     *     rows: list<array{rowId: int, docId: int, docNumber: string, date: ?string, text: string,
     *         accountNumber: string, amount: float, toActivate: bool}>,
     *     total: float,
     *     activation: array{amount: float, date: ?string},
     * }
     */
    public function acquisition(int $assetId): array
    {
        $rows = [];
        $total = 0.0;
        $toActivate = 0.0;
        $lastDate = null;

        foreach ($this->loadRows($assetId) as $row) {
            $amount = round((float) ($row['vat_base_dom'] ?? 0), 2);
            $accountNumber = trim((string) ($row['account_number'] ?? ''));
            $date = self::isoDate($row['accounting_date'] ?? null);
            $isAcquisition = str_starts_with($accountNumber, self::ACQUISITION_ACCOUNT_PREFIX);

            $rows[] = [
                'rowId'         => (int) $row['id'],
                'docId'         => (int) $row['doc_head'],
                'docNumber'     => (string) ($row['doc_number'] ?? ''),
                'date'          => $date,
                'text'          => (string) ($row['description'] ?? ''),
                'accountNumber' => $accountNumber,
                'amount'        => $amount,
                'toActivate'    => $isAcquisition,
            ];
            $total += $amount;
            if ($isAcquisition) {
                $toActivate += $amount;
                if ($date !== null && ($lastDate === null || $date > $lastDate)) {
                    $lastDate = $date;
                }
            }
        }

        return [
            'rows'       => $rows,
            'total'      => round($total, 2),
            'activation' => ['amount' => round($toActivate, 2), 'date' => $lastDate],
        ];
    }

    /**
     * Řádky pořízení karty na potvrzených dokladech, od nejstaršího.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadRows(int $assetId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [r].[id], [r].[doc_head], [r].[description], [r].[vat_base_dom],'
            . ' [h].[doc_number], [h].[accounting_date], [a].[number] AS [account_number]'
            . ' FROM [docs_core_rows] [r]'
            . ' JOIN [docs_core_heads] [h] ON [h].[id] = [r].[doc_head]'
            . ' LEFT JOIN [economy_accounting_accounts] [a] ON [a].[id] = [r].[account]'
            . ' WHERE [r].[asset] = %i AND [r].[operation] = %s AND [h].[docState] = %i'
            . ' ORDER BY [h].[accounting_date], [h].[id], [r].[order_pos], [r].[id]',
            $assetId,
            self::OPERATION,
            self::DOC_STATE_CONFIRMED,
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
