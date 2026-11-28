<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\PeriodicInvoicing\Source\PeriodicInvoicingSource;

/**
 * Společný základ upozornění evidence období (tasks §6, #175 D6): jedno
 * upozornění pro všechny zdroje fakturace. Projde registr zdrojů, v evidenci
 * období každého zdroje vybere období podle stavu / výsledku běhu a seskupí
 * je per záznam. Nález má klíč `zdroj:id`, předmět = záznam zdroje
 * (`tableId()`), akci, která otevře viewer zdroje (záložka Fakturace),
 * a v `context.navSection` sekci vieweru zdroje — badge sekce bere feed
 * z nálezu, check sám sekci nemá (U4).
 */
abstract class PeriodCheckBase extends AlertCheck
{
    use UsesInvoicingSources;

    /**
     * Období evidence zdroje seřazená per záznam a od nejnovějšího období.
     * Seam pro testy.
     *
     * @param list<string> $states
     * @param list<string> $results
     * @return list<array<string, mixed>>
     */
    protected function periods(PeriodicInvoicingSource $source, array $states, array $results, ?string $waitingBefore = null): array
    {
        $column = $source->periodsRecordColumn();
        $sql = 'SELECT [p].[id], [p].[' . $column . '], [p].[period_from], [p].[period_to], [p].[state], [p].[result],'
            . ' [p].[message], [p].[waiting_since]'
            . ' FROM [' . $source->periodsTable() . '] [p]'
            . ' WHERE [p].[state] IN %in';
        $args = [$states];
        if ($results !== [] && $waitingBefore !== null) {
            $sql .= ' AND ([p].[result] IN %in OR [p].[waiting_since] < %s)';
            $args[] = $results;
            $args[] = $waitingBefore;
        } elseif ($results !== []) {
            $sql .= ' AND [p].[result] IN %in';
            $args[] = $results;
        }
        $sql .= ' ORDER BY [p].[' . $column . '], [p].[period_from] DESC';
        $rows = $this->db->fetchAll($sql, ...$args);
        return array_map(static fn($r): array => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /**
     * Záznamy všech zdrojů s vybranými obdobími. Období, jehož záznam
     * zdroj nezná (smazaný), se nehlásí.
     *
     * @param list<string> $states
     * @param list<string> $results
     * @return list<array{source: PeriodicInvoicingSource, recordId: int, label: string, periods: list<array<string, mixed>>}>
     */
    protected function recordsWithPeriods(array $states, array $results, ?string $waitingBefore = null): array
    {
        $out = [];
        foreach ($this->sources()->all() as $source) {
            $column = $source->periodsRecordColumn();
            $byRecord = [];
            foreach ($this->periods($source, $states, $results, $waitingBefore) as $period) {
                $byRecord[(int) $period[$column]][] = $period;
            }
            foreach ($byRecord as $recordId => $periods) {
                $record = $source->record($recordId);
                if ($record === null) {
                    continue;
                }
                $out[] = [
                    'source'   => $source,
                    'recordId' => $recordId,
                    'label'    => $source->recordLabel($record),
                    'periods'  => $periods,
                ];
            }
        }
        return $out;
    }

    /**
     * @param array{source: PeriodicInvoicingSource, recordId: int, label: string, periods: list<array<string, mixed>>} $group
     * @param string $titleRest zbytek titulku za „<Záznam> <popisek>: “
     */
    protected function finding(array $group, string $titleRest, string $message, string $severity): AlertFinding
    {
        $source = $group['source'];
        $context = [
            'periods' => array_map(static fn(array $p): string => self::isoDate($p['period_from']), $group['periods']),
        ];
        if ($source->navSection() !== null) {
            $context['navSection'] = $source->navSection();
        }

        return new AlertFinding(
            findingKey: $source->id() . ':' . $group['recordId'],
            title: $source->recordNoun() . ' ' . $group['label'] . ': ' . $titleRest,
            message: $message,
            severity: $severity,
            subjectTableId: $source->tableId(),
            subjectRowId: $group['recordId'],
            actions: [[
                'id'      => 'open_record',
                'label'   => $source->openActionLabel(),
                'kind'    => 'open_viewer',
                'primary' => true,
                'target'  => ['viewerId' => $source->viewerId(), 'recordId' => $group['recordId']],
            ]],
            context: $context,
        );
    }

    /** @param array<string, mixed> $period */
    protected static function periodLabel(array $period): string
    {
        return self::czDate($period['period_from']) . ' – ' . self::czDate($period['period_to']);
    }

    protected static function czDate(mixed $value): string
    {
        $string = self::isoDate($value);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $string);
        return $dt instanceof \DateTimeImmutable ? $dt->format('d.m.Y') : $string;
    }

    private static function isoDate(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    protected function today(): string
    {
        return date('Y-m-d');
    }
}
