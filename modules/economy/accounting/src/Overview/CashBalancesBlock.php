<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Overview;

use Shipard\Core\Overview\OverviewBlock;
use Shipard\Core\Overview\OverviewBlockResult;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Overview\OverviewItem;
use Shipard\Core\Overview\OverviewMessage;
use Shipard\Core\Reports\DbFiscalPeriodProvider;

/**
 * Přehled firmy → Peníze: pokladny (#120 D5, D15, D19). Per platná pokladna
 * s účtem zůstatek účtu z deníku za běžné účetní období „podle
 * zaúčtovaných dokladů" s datem posledního zápisu; cizoměnová pokladna
 * v měně pokladny z `money_*_cur`. Chybí-li počáteční stavy
 * ({@see OpeningSequenceStatus}), místo položek jedna zpráva —
 * bankovní položky v sekci zůstávají.
 */
final class CashBalancesBlock implements OverviewBlock
{
    public const TEXTS_ITEM = 'economy.accounting.overviewTexts';

    private const DOC_STATE_DELETED = 90;
    private const VIEWER_JOURNAL    = 'economy.accounting.journal';

    public function isAvailable(OverviewContext $ctx): bool
    {
        return $ctx->hasTable('economy_codebooks_cash_desks') && $ctx->hasTable('economy_accounting_journal');
    }

    public function collect(OverviewContext $ctx): OverviewBlockResult
    {
        $texts = $ctx->texts(self::TEXTS_ITEM);

        $desks = $ctx->db->fetchAll(
            'SELECT [d].[id], [d].[code], [d].[name], [d].[currency], [a].[number] AS [account_number]'
            . ' FROM [economy_codebooks_cash_desks] [d]'
            . ' JOIN [economy_accounting_accounts] [a] ON [a].[id] = [d].[accounting_account]'
            . ' WHERE [d].[docState] != %i AND [a].[docState] != %i'
            . ' AND ([d].[valid_to] IS NULL OR [d].[valid_to] >= %s)'
            . ' ORDER BY [d].[is_default] DESC, [d].[sort_order], [d].[id]',
            self::DOC_STATE_DELETED, self::DOC_STATE_DELETED, $ctx->todayIso(),
        );
        if ($desks === []) {
            return OverviewBlockResult::empty();
        }

        $year = (new DbFiscalPeriodProvider($ctx->db))->yearForDate($ctx->todayIso());
        if ($year === null) {
            return new OverviewBlockResult([], [new OverviewMessage(
                OverviewMessage::SEVERITY_INFO,
                'noFiscalYear',
                $texts->t('fiscalYear.none', 'No fiscal year covers today.'),
            )]);
        }

        if ((new OpeningSequenceStatus($ctx->db))->check($year['id'])['missing']) {
            return OverviewBlockResult::unavailable(new OverviewMessage(
                OverviewMessage::SEVERITY_WARNING,
                'openingMissing',
                $texts->t('opening.missing', 'Opening balances of the current fiscal year have not been carried over yet, so the balance cannot be shown.'),
            ));
        }

        $balances = new JournalAccountBalance($ctx->db);
        $home     = strtolower($ctx->homeCurrency);
        $items    = [];
        foreach ($desks as $desk) {
            $number   = (string) $desk['account_number'];
            $currency = strtolower((string) ($desk['currency'] ?: $home));
            $balance  = $balances->forAccount($number, $year['id']);
            $label    = trim((string) ($desk['name'] ?? ''));
            if ($label === '') {
                $label = (string) ($desk['code'] ?? $desk['id']);
            }

            $items[] = new OverviewItem(
                'cash:' . (int) $desk['id'],
                $label,
                'cash',
                OverviewItem::amount($currency === $home ? $balance['balance'] : $balance['balanceCur'], $currency),
                $balance['lastDate'],
                OverviewItem::STATE_OK,
                $texts->t('cash.posted', 'According to posted documents'),
                null,
                ['kind' => 'open_viewer', 'target' => [
                    'viewerId' => self::VIEWER_JOURNAL,
                    'filters'  => ['fiscal_year' => $year['id'], 'account' => $number],
                ]],
            );
        }

        usort($items, static fn (OverviewItem $a, OverviewItem $b): int
            => (strtolower($a->amount['currency']) === $home ? 0 : 1) <=> (strtolower($b->amount['currency']) === $home ? 0 : 1));

        return new OverviewBlockResult($items);
    }
}
