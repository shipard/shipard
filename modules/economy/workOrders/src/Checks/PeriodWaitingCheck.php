<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Checks;

use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodRepository;

/**
 * Období čeká na podklady (D10): per zakázka — `waiting` období čekající
 * déle než lhůta (`economy.workOrders.contributorWaitDays`, výchozí 10)
 * nebo s výsledkem `edited` (podklady jsou, ale koncept byl ručně upraven
 * — běh ho nepřegeneruje, udělá to akce Přegenerovat).
 */
class PeriodWaitingCheck extends PeriodCheckBase
{
    public const SETTING_WAIT_DAYS = 'economy.workOrders.contributorWaitDays';
    public const DEFAULT_WAIT_DAYS = 10;

    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $limit = (new \DateTimeImmutable($this->today()))->modify('-' . $this->waitDays() . ' days')->format('Y-m-d 00:00:00');
        $findings = [];
        foreach ($this->byWorkOrder($this->periods([PeriodRepository::STATE_WAITING], [PeriodRepository::RESULT_EDITED], $limit)) as $workOrderId => $periods) {
            $label = self::workOrderLabel($periods[0]);
            $edited = array_values(array_filter($periods, static fn(array $p): bool => (string) ($p['result'] ?? '') === PeriodRepository::RESULT_EDITED));
            $overdue = array_values(array_filter($periods, static fn(array $p): bool => (string) ($p['result'] ?? '') !== PeriodRepository::RESULT_EDITED));
            $parts = [];
            if ($overdue !== []) {
                $list = implode(', ', array_map(static fn(array $p): string => self::periodLabel($p), $overdue));
                $parts[] = $isCs
                    ? "čeká na podklady déle než {$this->waitDays()} dní: {$list}"
                    : "waiting for input longer than {$this->waitDays()} days: {$list}";
            }
            if ($edited !== []) {
                $list = implode(', ', array_map(static fn(array $p): string => self::periodLabel($p), $edited));
                $parts[] = $isCs
                    ? "podklady jsou k dispozici, ale koncept byl ručně upraven — použij Přegenerovat: {$list}"
                    : "input is ready but the draft was edited manually — use Regenerate: {$list}";
            }
            $findings[] = $this->finding(
                $workOrderId,
                $periods,
                $isCs
                    ? "Zakázka {$label}: období čeká na podklady"
                    : "Work order {$label}: period waiting for input",
                self::capitalize(implode('; ', $parts)),
                'warning',
                $isCs,
            );
        }
        return $findings;
    }

    private static function capitalize(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /** Lhůta ze settings (string) → dny, prázdné / nesmyslné = výchozí. */
    protected function waitDays(): int
    {
        $value = (new SettingsStore($this->db))->get(self::SETTING_WAIT_DAYS);
        $days = is_numeric($value) ? (int) $value : 0;
        return $days > 0 ? $days : self::DEFAULT_WAIT_DAYS;
    }
}
