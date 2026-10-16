<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Overview;

use Shipard\Core\Overview\OverviewBlock;
use Shipard\Core\Overview\OverviewBlockResult;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Overview\OverviewItem;
use Shipard\Core\Utils\IsoDate;

/**
 * Přehled firmy → Peníze: banka (#120 D5, D14). Per platný bankovní účet
 * koncový zůstatek **posledního výpisu** (podle `period_end`) s datem
 * výpisu — číslo z výpisu, ne z deníku. Výpis starší než 35 dní = `stale`,
 * účet bez výpisu = `missing` (datum se posílá vždy, D14). Měny per účet,
 * domácí první (D4); žádný součet přes měny.
 */
final class BankBalancesBlock implements OverviewBlock
{
    public const TEXTS_ITEM = 'economy.bank.overviewTexts';

    /** Práh stáří posledního výpisu (D14): starší = `stale`. */
    public const STALE_DAYS = 35;

    private const DOC_STATE_DELETED = 90;
    private const VIEWER_STATEMENTS = 'economy.bank.statements';

    public function isAvailable(OverviewContext $ctx): bool
    {
        return $ctx->hasTable('economy_bank_statements') && $ctx->hasTable('economy_codebooks_bank_accounts');
    }

    public function collect(OverviewContext $ctx): OverviewBlockResult
    {
        $texts       = $ctx->texts(self::TEXTS_ITEM);
        $today       = $ctx->todayIso();
        $staleBefore = $ctx->today->modify('-' . self::STALE_DAYS . ' days')->format('Y-m-d');

        $accounts = $ctx->db->fetchAll(
            'SELECT [id], [code], [name], [currency] FROM [economy_codebooks_bank_accounts]'
            . ' WHERE [docState] != %i AND ([valid_to] IS NULL OR [valid_to] >= %s)'
            . ' ORDER BY [is_default] DESC, [sort_order], [id]',
            self::DOC_STATE_DELETED, $today,
        );

        $items = [];
        foreach ($accounts as $account) {
            $accountId = (int) $account['id'];
            $label     = trim((string) ($account['name'] ?? ''));
            if ($label === '') {
                $label = (string) ($account['code'] ?? $accountId);
            }

            $statement = $ctx->db->fetchRow(
                'SELECT [id], [statement_number], [period_end], [closing_balance], [currency]'
                . ' FROM [economy_bank_statements]'
                . ' WHERE [bank_account] = %i AND [docState] != %i'
                . ' ORDER BY [period_end] DESC, [id] DESC LIMIT 1',
                $accountId, self::DOC_STATE_DELETED,
            );

            if ($statement === null || $statement['closing_balance'] === null) {
                $items[] = new OverviewItem(
                    'bank:' . $accountId,
                    $label,
                    'bank',
                    null,
                    $statement !== null ? IsoDate::fromDb($statement['period_end']) : null,
                    OverviewItem::STATE_MISSING,
                    $texts->t('bank.noStatement', 'No statement yet'),
                    null,
                    ['kind' => 'open_viewer', 'target' => ['viewerId' => self::VIEWER_STATEMENTS]],
                );
                continue;
            }

            $periodEnd = IsoDate::fromDb($statement['period_end']);
            $number    = trim((string) ($statement['statement_number'] ?? ''));
            $currency  = (string) ($statement['currency'] ?: $account['currency'] ?: $ctx->homeCurrency);

            $items[] = new OverviewItem(
                'bank:' . $accountId,
                $label,
                'bank',
                OverviewItem::amount((float) $statement['closing_balance'], $currency),
                $periodEnd,
                ($periodEnd !== null && $periodEnd < $staleBefore) ? OverviewItem::STATE_STALE : OverviewItem::STATE_OK,
                $number !== ''
                    ? $texts->t('bank.statement', 'According to statement no. {number}', ['number' => $number])
                    : $texts->t('bank.lastStatement', 'According to the last statement'),
                null,
                ['kind' => 'open_detail', 'target' => ['viewerId' => self::VIEWER_STATEMENTS, 'recordId' => (int) $statement['id']]],
            );
        }

        return new OverviewBlockResult(self::homeCurrencyFirst($items, $ctx->homeCurrency));
    }

    /**
     * Domácí měna první (D4); uvnitř skupiny zůstává pořadí účtů. Položky
     * bez částky (`missing`) drží pořadí účtů za domácí měnou.
     *
     * @param list<OverviewItem> $items
     * @return list<OverviewItem>
     */
    public static function homeCurrencyFirst(array $items, string $homeCurrency): array
    {
        $home = strtoupper($homeCurrency);
        usort($items, static fn (OverviewItem $a, OverviewItem $b): int
            => (($a->amount['currency'] ?? '') === $home ? 0 : 1) <=> (($b->amount['currency'] ?? '') === $home ? 0 : 1));
        return $items;
    }
}
