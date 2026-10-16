<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Overview;

use Shipard\Core\Overview\OverviewBlock;
use Shipard\Core\Overview\OverviewBlockResult;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Overview\OverviewItem;
use Shipard\Core\Overview\OverviewMessage;
use Shipard\Core\Reports\DbFiscalPeriodProvider;
use Shipard\Core\Reports\FiscalPeriodProvider;
use Shipard\Core\Reports\ReportMessageSeverity;

/**
 * Přehled firmy → Výsledek hospodaření (#120 D8): od začátku běžného
 * účetního období z výsledovky (`economy.accounting.profitLoss`, sloupec
 * `ytd` za běžný měsíc) — jeden výpočet, žádné sčítání mimo report.
 * Vedle výsledku i výnosy a náklady (součty tříd 6 / 5), jsou-li v reportu.
 * Chybové zprávy reportu (chybové masky účtů) se propisují do sekce.
 */
final class ProfitLossBlock implements OverviewBlock
{
    public const TEXTS_ITEM = CashBalancesBlock::TEXTS_ITEM;
    public const REPORT_ID  = 'economy.accounting.profitLoss';

    private const PERIOD_TYPE_REGULAR = 1;

    public function isAvailable(OverviewContext $ctx): bool
    {
        return $ctx->hasTable('economy_accounting_journal') && $ctx->hasTable('economy_codebooks_fiscal_months');
    }

    public function collect(OverviewContext $ctx): OverviewBlockResult
    {
        $texts   = $ctx->texts(self::TEXTS_ITEM);
        $periods = new DbFiscalPeriodProvider($ctx->db);

        $year = $periods->yearForDate($ctx->todayIso());
        if ($year === null) {
            return new OverviewBlockResult([], [new OverviewMessage(
                OverviewMessage::SEVERITY_INFO,
                'noFiscalYear',
                $texts->t('fiscalYear.none', 'No fiscal year covers today.'),
            )]);
        }
        $position = $this->currentMonthPosition($ctx, $periods, $year['id']);
        if ($position === null) {
            return new OverviewBlockResult([], [new OverviewMessage(
                OverviewMessage::SEVERITY_INFO,
                'noCurrentMonth',
                $texts->t('month.none', 'Today is not in a regular month of the fiscal year.'),
            )]);
        }

        $params = ['fiscalYear' => $year['name'], 'monthFrom' => $position, 'monthTo' => $position];
        $report = $ctx->reports->run(self::REPORT_ID, $params + ['detail' => 'synthetic']);
        $values = ProfitLossReader::read($report);

        $messages = [];
        foreach ($report->messages as $message) {
            if ($message->severity === ReportMessageSeverity::Error) {
                $messages[] = new OverviewMessage(OverviewMessage::SEVERITY_ERROR, 'report.' . $message->code, $message->text);
            }
        }

        $link  = ['kind' => 'open_report', 'target' => ['reportId' => self::REPORT_ID, 'params' => $params]];
        $note  = $texts->t('result.sinceStart', 'Since the beginning of fiscal year {year}', ['year' => $year['name']]);
        $items = [];
        foreach ([
            ['id' => 'result', 'label' => $texts->t('result.ytd', 'Profit or loss'), 'value' => $values['result']],
            ['id' => 'revenues', 'label' => $texts->t('result.revenues', 'Revenues'), 'value' => $values['revenues']],
            ['id' => 'costs', 'label' => $texts->t('result.costs', 'Costs'), 'value' => $values['costs']],
        ] as $spec) {
            if ($spec['value'] === null) {
                continue;
            }
            $items[] = new OverviewItem(
                'result:' . $spec['id'],
                $spec['label'],
                'result',
                OverviewItem::amount($spec['value'], $ctx->homeCurrency),
                $ctx->todayIso(),
                OverviewItem::STATE_OK,
                $note,
                null,
                $link,
            );
        }

        return new OverviewBlockResult($items, $messages);
    }

    /**
     * Pozice běžného měsíce obsahujícího dnešek mezi běžnými měsíci roku
     * (1-based, jak ji berou parametry `monthFrom` / `monthTo`); null, když
     * dnešek v žádném běžném měsíci roku neleží.
     */
    private function currentMonthPosition(OverviewContext $ctx, FiscalPeriodProvider $periods, int $yearId): ?int
    {
        $current = $ctx->db->fetchSingle(
            'SELECT [id] FROM [economy_codebooks_fiscal_months]'
            . ' WHERE [fiscal_year] = %i AND [period_type] = %i AND [date_begin] <= %s AND [date_end] >= %s'
            . ' ORDER BY [date_begin], [id] LIMIT 1',
            $yearId, self::PERIOD_TYPE_REGULAR, $ctx->todayIso(), $ctx->todayIso(),
        );
        if ($current === null) {
            return null;
        }
        $position = 0;
        foreach ($periods->monthsOfYear($yearId) as $month) {
            if ($month['periodType'] !== self::PERIOD_TYPE_REGULAR) {
                continue;
            }
            $position++;
            if ($month['id'] === (int) $current) {
                return $position;
            }
        }
        return null;
    }
}
