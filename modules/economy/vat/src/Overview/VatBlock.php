<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Overview;

use Shipard\Core\Feed\FeedTexts;
use Shipard\Core\Overview\OverviewBlock;
use Shipard\Core\Overview\OverviewBlockResult;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Overview\OverviewItem;
use Shipard\Core\Overview\OverviewMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Vat\Deadline\FilingDeadlineRule;
use Shipard\Module\Economy\Vat\Deadline\FilingDeadlines;

/**
 * Přehled firmy → DPH (#120 D7, D16, D17): dva řádky z živého přiznání —
 * předchozí období, dokud k němu není podání (s termínem podání z pravidla
 * země), a běžné období průběžně. Neplátce (bez aktivní registrace, nebo
 * agenda DPH vypnutá) blok nedostává, sekce se neposílá. Hodnota = řádek
 * 64 / 65 reportu `economy.vat.returnLive`, chyby reportu do sekce.
 */
final class VatBlock implements OverviewBlock
{
    public const TEXTS_ITEM = 'economy.vat.overviewTexts';
    public const REPORT_ID  = 'economy.vat.returnLive';

    public function isAvailable(OverviewContext $ctx): bool
    {
        if (!$ctx->hasTable('economy_vat_report_periods') || !$ctx->hasTable('economy_codebooks_vat_registrations')) {
            return false;
        }
        // Preference agendy (nastavení); zdroj pravdy o plátcovství je registrace (collect).
        return (new SettingsStore($ctx->db))->get('economy.vatAgenda') !== false;
    }

    public function collect(OverviewContext $ctx): OverviewBlockResult
    {
        $texts    = $ctx->texts(self::TEXTS_ITEM);
        $selector = new VatPeriodSelector($ctx->db, $ctx->hasTable('economy_vat_filings'));

        $registration = $selector->activeRegistration($ctx->todayIso());
        if ($registration === null) {
            return OverviewBlockResult::empty(); // neplátce (D7) — bez položek, sekce se neposílá
        }
        ['current' => $current, 'previous' => $previous] = $selector->periods($registration['id'], $ctx->todayIso());
        if ($current === null && $previous === null) {
            return new OverviewBlockResult([], [new OverviewMessage(
                OverviewMessage::SEVERITY_INFO,
                'noVatPeriod',
                $texts->t('period.none', 'No VAT return period covers today.'),
            )]);
        }

        $rule     = FilingDeadlines::forCountry($ctx->country);
        $items    = [];
        $messages = [];
        if ($previous !== null) {
            $items[] = $this->item($ctx, $texts, $previous, 'previous', $rule, $messages);
        }
        if ($current !== null) {
            $items[] = $this->item($ctx, $texts, $current, 'current', $rule, $messages);
        }

        return new OverviewBlockResult($items, $messages);
    }

    /**
     * @param array{id: int, name: string, dateBegin: string, dateEnd: string} $period
     * @param list<OverviewMessage> $messages chybové zprávy reportu (doplňuje)
     */
    private function item(OverviewContext $ctx, FeedTexts $texts, array $period, string $slot, ?FilingDeadlineRule $rule, array &$messages): OverviewItem
    {
        $report   = $ctx->reports->run(self::REPORT_ID, ['period' => $period['id']]);
        $position = VatPositionReader::read($report);
        foreach ($report->messages as $message) {
            if ($message->severity === ReportMessageSeverity::Error) {
                $messages[] = new OverviewMessage(OverviewMessage::SEVERITY_ERROR, 'report.' . $message->code, $message->text);
            }
        }

        $note = match ($position['kind']) {
            VatPositionReader::KIND_TAX    => $texts->t('vat.tax', 'VAT payable'),
            VatPositionReader::KIND_REFUND => $texts->t('vat.refund', 'Excess deduction'),
            VatPositionReader::KIND_ZERO   => $texts->t('vat.zero', 'No VAT due'),
            default                        => $texts->t('vat.none', 'No documents in the period yet'),
        };

        return new OverviewItem(
            'vat:' . $slot,
            $slot === 'current'
                ? $texts->t('vat.periodLive', 'VAT return {period} (live)', ['period' => $period['name']])
                : $texts->t('vat.period', 'VAT return {period}', ['period' => $period['name']]),
            'vat',
            OverviewItem::amount($position['amount'], $ctx->homeCurrency),
            $ctx->todayIso(),
            OverviewItem::STATE_OK,
            $note,
            $rule?->returnDeadline($period['dateEnd']),
            ['kind' => 'open_report', 'target' => ['reportId' => self::REPORT_ID, 'params' => ['period' => $period['id']]]],
        );
    }
}
