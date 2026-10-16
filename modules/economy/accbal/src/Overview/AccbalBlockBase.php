<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal\Overview;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedTexts;
use Shipard\Core\Overview\OverviewBlock;
use Shipard\Core\Overview\OverviewBlockResult;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Overview\OverviewItem;
use Shipard\Core\Overview\OverviewMessage;
use Shipard\Core\Reports\DbFiscalPeriodProvider;
use Shipard\Module\Economy\Accbal\CaseTotals;
use Shipard\Module\Economy\Accounting\Overview\OpeningSequenceStatus;

/**
 * Společný průběh bloků saldokonta v Přehledu firmy (#120 D6, D18, D19):
 * skupina podle kódu (`receivables` / `payables`), jen běžné účetní období
 * (klíč případu nese `fiscal_year` — součet přes období by dluh po převodu
 * počátečních stavů zdvojil), pojistka počátečních stavů
 * ({@see OpeningSequenceStatus}), položky per měna dokladu z
 * {@see CaseTotals} (domácí první, D4) a poznámka o nespárovaných
 * platbách (D18).
 *
 * Blok předává `CaseTotals::totals()` **přesně tu mapu filtrů, kterou
 * vloží do prokliku** `open_viewer` — číslo po prokliku sedí s patičkou
 * vieweru případů z konstrukce.
 */
abstract class AccbalBlockBase implements OverviewBlock
{
    public const TEXTS_ITEM = 'economy.accbal.overviewTexts';

    protected const VIEWER_CASES    = 'economy.accbal.cases';
    protected const GROUP_UNMATCHED = 'unmatched_payments';
    private const DOC_STATE_DELETED = 90;

    /** Kód saldokonta (`economy_accbal_balances.code`), které blok ukazuje. */
    abstract protected function groupCode(): string;

    /**
     * Položky bloku: `id` (přípona id položky), `label` (už přeložený
     * přes `$texts`), `filters` — případové filtry vieweru nad skupinou
     * a běžným obdobím (`kind`, `overdue`, `dueWithin`).
     *
     * @return list<array{id: string, label: string, filters: array<string, string>}>
     */
    abstract protected function itemSpecs(FeedTexts $texts): array;

    public function isAvailable(OverviewContext $ctx): bool
    {
        return $ctx->hasTable('economy_accbal_ledger') && $ctx->hasTable('economy_accbal_balances');
    }

    public function collect(OverviewContext $ctx): OverviewBlockResult
    {
        $texts = $ctx->texts(self::TEXTS_ITEM);
        if (!$this->groupExists($ctx->db, $this->groupCode())) {
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
                $texts->t('opening.missing', 'Opening balances of the current fiscal year have not been carried over yet, so open items cannot be shown.'),
            ));
        }

        $totals = new CaseTotals($ctx->db);
        $home   = strtolower($ctx->homeCurrency);
        $group  = $this->groupCode();

        $items = [];
        foreach ($this->itemSpecs($texts) as $spec) {
            $base = ['fiscal_year' => (string) $year['id']] + $spec['filters'];
            $rows = $totals->totals(CaseTotals::filtersFromMap(['viewGroup' => $group] + $base), $ctx->today);
            if ($rows === []) {
                $rows = [['currency' => $home, 'count' => 0, 'residual' => 0.0]];
            }
            foreach (self::homeFirst($rows, $home) as $row) {
                $currency = $row['currency'] !== '' ? $row['currency'] : $home;
                $items[]  = new OverviewItem(
                    "{$group}:{$spec['id']}:{$currency}",
                    $spec['label'],
                    $group,
                    OverviewItem::amount($row['residual'], $currency),
                    $ctx->todayIso(),
                    OverviewItem::STATE_OK,
                    $row['count'] > 0
                        ? $texts->t('cases.count', '{n, plural, one {# case} other {# cases}}', ['n' => $row['count']])
                        : null,
                    null,
                    ['kind' => 'open_viewer', 'target' => [
                        'viewerId'  => self::VIEWER_CASES,
                        'viewGroup' => $group,
                        'filters'   => $base + ['currency' => $currency],
                    ]],
                );
            }
        }

        return new OverviewBlockResult($items, $this->unmatchedMessages($ctx, $texts, $totals, (string) $year['id'], $home));
    }

    /**
     * Nespárované platby (D18): počet a součet otevřených případů skupiny
     * per měna, s proklikem. Skupina nemusí na DS existovat — pak nic.
     *
     * @return list<OverviewMessage>
     */
    private function unmatchedMessages(OverviewContext $ctx, FeedTexts $texts, CaseTotals $totals, string $yearId, string $home): array
    {
        if (!$this->groupExists($ctx->db, self::GROUP_UNMATCHED)) {
            return [];
        }
        $base = ['fiscal_year' => $yearId];
        $rows = $totals->totals(CaseTotals::filtersFromMap(['viewGroup' => self::GROUP_UNMATCHED] + $base), $ctx->today);

        $messages = [];
        foreach (self::homeFirst($rows, $home) as $row) {
            if ($row['count'] <= 0) {
                continue;
            }
            $currency   = $row['currency'] !== '' ? $row['currency'] : $home;
            $messages[] = new OverviewMessage(
                OverviewMessage::SEVERITY_INFO,
                'unmatchedPayments',
                $texts->t(
                    'unmatched.summary',
                    '{n, plural, one {# unmatched payment} other {# unmatched payments}} totalling {amount, number,#,##0.00} {currency}',
                    ['n' => $row['count'], 'amount' => abs($row['residual']), 'currency' => strtoupper($currency)],
                ),
                ['kind' => 'open_viewer', 'target' => [
                    'viewerId'  => self::VIEWER_CASES,
                    'viewGroup' => self::GROUP_UNMATCHED,
                    'filters'   => $base + ['currency' => $currency],
                ]],
            );
        }
        return $messages;
    }

    private function groupExists(DataSourceConnection $db, string $code): bool
    {
        return $db->fetchSingle(
            'SELECT [id] FROM [economy_accbal_balances] WHERE [code] = %s AND [docState] != %i LIMIT 1',
            $code, self::DOC_STATE_DELETED,
        ) !== null;
    }

    /**
     * @param list<array<string, mixed>> $rows řádky s klíčem `currency`
     * @return list<array<string, mixed>>
     */
    private static function homeFirst(array $rows, string $home): array
    {
        usort($rows, static fn (array $a, array $b): int
            => ($a['currency'] === $home ? 0 : 1) <=> ($b['currency'] === $home ? 0 : 1));
        return $rows;
    }
}
