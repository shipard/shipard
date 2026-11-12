<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Reports;

use Shipard\Module\Economy\Accounting\AccountingRules;
use Shipard\Core\Reports\ReportBuilder;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Module\Economy\Vat\DeductionCoefficientResolver;
use Shipard\Module\Economy\Vat\VatJournalCrossCheck;
use Shipard\Module\Economy\Vat\VatReturnCalculator;

/**
 * Živé přiznání k DPH (DPHDP3) — počítá se on-demand z potvrzených dokladů
 * (D1, žádná persistence). Řádky formuláře s daty + dopočty 46/62–65
 * (kind computed); hlavička výsledku nese operativní stav DPH (ř. 64/65),
 * použitý koeficient odpočtu (ř. 52, jen je-li co krátit) a výsledek
 * křížové kontroly proti 343 analytikám deníku jako messages.
 */
final class VatReturnLiveBuilder implements ReportBuilder
{
    public function build(ReportRequest $request): ReportResult
    {
        $cs      = $request->language === 'cs';
        $support = new VatReportSupport();
        $columns = $this->columns($cs);

        $mapping = $support->mapping($request);
        if ($mapping === null) {
            return $support->missingConfigResult($request, $columns, $cs);
        }

        $docs        = $support->docs($request);
        $coefficient = $support->deductionCoefficient($request);
        $calc        = (new VatReturnCalculator($mapping))->calculate($docs, $coefficient['value']);

        $rows       = [];
        $messages   = [];
        $lastFiling = $support->lastFilingMessage($request, $cs);
        if ($lastFiling !== null) {
            $messages[] = $lastFiling;
        }
        if ($docs !== []) {
            $all = $calc['rows'];
            foreach ($calc['computed'] as $number => $values) {
                $all[$number] = $values;
            }
            ksort($all);
            $computedNumbers = array_keys($calc['computed']);

            foreach ($all as $number => $values) {
                $label = $mapping->dp3RowLabel((int) $number)
                    ?? ($cs ? "Řádek {$number}" : "Row {$number}");
                $rows[] = new ReportRow(
                    in_array($number, $computedNumbers, true) ? ReportRowKind::Computed : ReportRowKind::Detail,
                    0,
                    (string) $number,
                    $label,
                    [
                        'base'       => $support->money($values['base']),
                        'tax'        => $support->money($values['taxFull']),
                        'taxReduced' => $support->money($values['taxReduced']),
                    ],
                );
            }

            $messages[] = $this->currentPositionMessage($calc['computed'], $cs);
            array_push($messages, ...$this->coefficientMessages($calc['computed'], $coefficient, $cs));
            array_push($messages, ...$this->crossCheckMessages($request, $support, $docs, $cs));
        }

        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: $messages,
            columns: $columns,
            rows: $rows,
        );
    }

    /** Operativní stav DPH (ř. 64/65) — informační řádek hlavičky (D1). */
    private function currentPositionMessage(array $computed, bool $cs): ReportMessage
    {
        $own    = $computed[64]['taxFull'];
        $excess = $computed[65]['taxFull'];
        if ($own > 0) {
            $text = ($cs ? 'Vlastní daň (ř. 64): ' : 'Tax liability (row 64): ')
                . number_format($own, 2, ',', ' ');
        } elseif ($excess > 0) {
            $text = ($cs ? 'Nadměrný odpočet (ř. 65): ' : 'Excess deduction (row 65): ')
                . number_format($excess, 2, ',', ' ');
        } else {
            $text = $cs ? 'Daňová povinnost je nulová.' : 'The tax position is zero.';
        }
        return new ReportMessage(ReportMessageSeverity::Info, 'vatReturn.currentPosition', $text);
    }

    /**
     * Koeficient odpočtu (ř. 52, #59 D13d): zpráva jen když je v dokladech
     * nenulový krácený nárok — jinak by šuměla u firem bez osvobozených
     * plnění. Default (bez záznamu pro rok) navíc doporučí koeficient nastavit.
     *
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $computed
     * @param array{value: float, source: string, year: int} $coefficient
     * @return list<ReportMessage>
     */
    private function coefficientMessages(array $computed, array $coefficient, bool $cs): array
    {
        $reduced = $computed[46]['taxReduced'];
        if (abs($reduced) < 0.005) {
            return [];
        }
        $isDefault = $coefficient['source'] === DeductionCoefficientResolver::SOURCE_DEFAULT;
        $sourceLabel = match ($coefficient['source']) {
            DeductionCoefficientResolver::SOURCE_PROVISIONAL      => $cs ? 'zálohový' : 'provisional',
            DeductionCoefficientResolver::SOURCE_PREVIOUS_SETTLED => $cs ? 'vypořádací minulého roku' : 'previous year settled',
            default                                               => $cs ? 'default' : 'default',
        };
        $text = sprintf(
            $cs
                ? 'Krácený nárok %s × koeficient %s (%s) → ř. 52 = %s.'
                : 'Reduced deduction %s × coefficient %s (%s) → row 52 = %s.',
            number_format($reduced, 2, ',', ' '),
            number_format($coefficient['value'], 2, ',', ' '),
            $sourceLabel,
            number_format($computed[52]['taxFull'], 2, ',', ' '),
        );
        $messages = [new ReportMessage(ReportMessageSeverity::Info, 'vatReturn.deductionCoefficient', $text)];
        if ($isDefault) {
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Info,
                'vatReturn.deductionCoefficientDefault',
                $cs
                    ? "Pro rok {$coefficient['year']} není zadaný koeficient odpočtu — platí plný nárok (1,00). Nastavte ho v Nastavení → Účetnictví → Koeficienty odpočtu DPH."
                    : "No deduction coefficient is set for {$coefficient['year']} — full deduction (1.00) applies. Set it under Settings → Accounting → VAT deduction coefficients.",
            );
        }
        return $messages;
    }

    /**
     * Křížová kontrola proti deníku — dvě nezávislé cesty ke stejným
     * číslům (D1); rozdíly jsou warning, shoda info.
     *
     * @param list<array<string, mixed>> $docs
     * @return list<ReportMessage>
     */
    private function crossCheckMessages(ReportRequest $request, VatReportSupport $support, array $docs, bool $cs): array
    {
        // Analytiky podle týchž pravidel předpisu jako účtování dokladů (#89 D9).
        $rules        = AccountingRules::resolve($request->config, $request->db->getDibiConnection());
        $accountRules = is_array($rules) && is_array($rules['accounts'] ?? null) ? $rules['accounts'] : [];
        $check    = (new VatJournalCrossCheck($request->db, $support->vatCodes($request), $accountRules))->check($docs);
        $messages = [];
        foreach ($check['differences'] as $diff) {
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Warning,
                'vatReturn.journalMismatch',
                sprintf(
                    $cs
                        ? 'Křížová kontrola: účet %s (%s) — daň dle dokladů %s, deník %s, rozdíl %s.'
                        : 'Cross-check: account %s (%s) — tax per documents %s, journal %s, delta %s.',
                    $diff['account'],
                    $diff['vatCode'] ?? '—',
                    number_format($diff['recapTax'], 2, ',', ' '),
                    number_format($diff['journalTax'], 2, ',', ' '),
                    number_format($diff['delta'], 2, ',', ' '),
                ),
            );
        }
        if ($check['journalErrorRows'] > 0) {
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Warning,
                'vatReturn.journalErrorRows',
                $cs
                    ? "Deník obsahuje {$check['journalErrorRows']} chybových 343 řádků za výběr — křížová kontrola je nespolehlivá."
                    : "The journal contains {$check['journalErrorRows']} error rows on 343 accounts for the selection — the cross-check is unreliable.",
            );
        }
        if ($check['differences'] === [] && $check['journalErrorRows'] === 0) {
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Info,
                'vatReturn.journalMatch',
                $cs ? 'Součty daně souhlasí s účetním deníkem.' : 'Tax totals match the accounting journal.',
            );
        }
        return $messages;
    }

    /** @return list<ReportColumn> */
    private function columns(bool $cs): array
    {
        return [
            new ReportColumn('base', ReportColumn::TYPE_MONEY, $cs ? 'Základ daně / Hodnota' : 'Tax base / Value'),
            new ReportColumn('tax', ReportColumn::TYPE_MONEY, $cs ? 'Daň' : 'Tax'),
            new ReportColumn('taxReduced', ReportColumn::TYPE_MONEY, $cs ? 'Krácený odpočet' : 'Reduced deduction'),
        ];
    }
}
