<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportStatus;

/**
 * `ReportResult` → `ReportTable` (docs/reports.md §15). Čistá funkce bez
 * DB — jediné místo s pravidly převodu, XLSX i CSV writer jen zapisují.
 *
 * Sloupce: [Účet, je-li u některého řádku `account`] + Název + sloupce
 * reportu; money `display: balance` = jedna číselná buňka (zůstatek),
 * `display: sides` = trojice „{label} — MD / D / Zůstatek". Čísla vždy
 * přesně (přepínač „v tisících" je věc zobrazení v UI) a nula jako číslo,
 * prázdná buňka jen tam, kde hodnota v řádku chybí.
 */
final class ReportTabularizer
{
    public function tabularize(ReportResult $result, ReportExportContext $context): ReportTable
    {
        $labels     = $context->labels;
        $hasAccount = false;
        foreach ($result->rows as $row) {
            if ($row->account !== null && $row->account !== '') {
                $hasAccount = true;
                break;
            }
        }

        $headers = $hasAccount ? [$labels->get('account'), $labels->get('label')] : [$labels->get('label')];
        $hasMoney = false;
        foreach ($result->columns as $column) {
            if ($column->type !== ReportColumn::TYPE_MONEY) {
                $headers[] = $column->label;
                continue;
            }
            $hasMoney = true;
            if ($column->display === ReportColumn::DISPLAY_SIDES) {
                foreach (['sideMd', 'sideD', 'sideBalance'] as $side) {
                    $headers[] = $column->label . ' — ' . $labels->get($side);
                }
            } else {
                $headers[] = $column->label;
            }
        }

        $rows = [];
        foreach ($result->rows as $row) {
            $rows[] = new ReportTableRow($row->kind, $row->level, $this->cells($row, $result->columns, $hasAccount));
        }

        return new ReportTable(
            title: $context->reportName,
            intro: $this->intro($result, $context, $hasMoney),
            headers: $headers,
            labelColumn: $hasAccount ? 1 : 0,
            rows: $rows,
            status: $result->status,
            messageHeaders: [
                $labels->get('severity'),
                $labels->get('code'),
                $labels->get('text'),
                $labels->get('row'),
            ],
            messages: array_map(
                fn (ReportMessage $message): array => $this->message($message, count($rows), $labels),
                $result->messages,
            ),
        );
    }

    /** @return list<array{0: string, 1: string}> */
    private function intro(ReportResult $result, ReportExportContext $context, bool $hasMoney): array
    {
        $labels = $context->labels;
        $intro  = [];

        $period = $result->params['period'] ?? null;
        if (is_array($period)) {
            $intro[] = [
                $labels->get('period'),
                ReportPeriodFormatter::label($period, $context->fiscalYearMonths, $context->language),
            ];
        }
        foreach ($result->params as $id => $value) {
            if ($id === 'period') {
                continue;
            }
            $intro[] = [$labels->paramName((string) $id), $labels->paramValue((string) $id, $value)];
        }
        $intro[] = [
            $labels->get('generatedAt'),
            $result->generatedAt->format($context->language === 'cs' ? 'j. n. Y H:i' : 'Y-m-d H:i'),
        ];
        $intro[] = [$labels->get('dataSource'), $context->dataSourceName];
        if ($hasMoney) {
            $intro[] = [$labels->get('note'), $labels->get('exactAmounts')];
        }
        if ($result->status !== ReportStatus::Ok) {
            $intro[] = [
                $labels->get('status'),
                $labels->get($result->status === ReportStatus::Errors ? 'statusErrors' : 'statusWarnings'),
            ];
        }

        return $intro;
    }

    /**
     * @param ReportColumn[] $columns
     * @return list<string|float|\DateTimeImmutable|null>
     */
    private function cells(ReportRow $row, array $columns, bool $hasAccount): array
    {
        $cells = [];
        if ($hasAccount) {
            $cells[] = $row->account !== null && $row->account !== '' ? $row->account : null;
        }
        $cells[] = $row->label;

        foreach ($columns as $column) {
            $value = $row->values[$column->id] ?? null;

            if ($column->type === ReportColumn::TYPE_MONEY) {
                $sides = $column->display === ReportColumn::DISPLAY_SIDES ? ['md', 'd', 'balance'] : ['balance'];
                foreach ($sides as $side) {
                    $amount  = is_array($value) ? ($value[$side] ?? null) : null;
                    $cells[] = is_int($amount) || is_float($amount) ? (float) $amount : null;
                }
                continue;
            }

            if (!is_string($value) || $value === '') {
                $cells[] = null;
                continue;
            }
            $cells[] = $column->type === ReportColumn::TYPE_DATE ? $this->date($value) : $value;
        }

        return $cells;
    }

    /** ISO `YYYY-MM-DD` → datum; cokoli jiného zůstává textem (nic se neztratí). */
    private function date(string $value): \DateTimeImmutable|string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        // createFromFormat přetečení tiše přepočítá (2026-02-31 → březen) — to není to datum.
        return $date !== false && $date->format('Y-m-d') === $value ? $date : $value;
    }

    /** @return array{severity: string, severityLabel: string, code: string, text: string, rowIndex: ?int} */
    private function message(ReportMessage $message, int $rowCount, ReportExportLabels $labels): array
    {
        $rowIndex = null;
        if ($message->rowRef !== null && preg_match('/^rows\.(\d+)$/', $message->rowRef, $match)) {
            $index = (int) $match[1];
            if ($index < $rowCount) {
                $rowIndex = $index;
            }
        }

        return [
            'severity'      => $message->severity->value,
            'severityLabel' => $labels->get(match ($message->severity) {
                ReportMessageSeverity::Error   => 'severityError',
                ReportMessageSeverity::Warning => 'severityWarning',
                default                        => 'severityInfo',
            }),
            'code'          => $message->code,
            'text'          => $message->text,
            'rowIndex'      => $rowIndex,
        ];
    }
}
