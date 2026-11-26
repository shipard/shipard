<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\I18n\DisplayDate;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleStore;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Společný základ upozornění na tržby ve frontě (#171 D13, D25): **jeden
 * nález na registraci** (`findingKey` = id registrace) s počtem tržeb a časem
 * nejstarší — výpadek služby tak nedá stovky upozornění, jedno na tržbu.
 *
 * Stáří se měří od času tržby (`sold_at`): od něj běží i lhůta 48 hodin.
 * Tržby `confirmed`, `verified` (ověřovací režim) a `dropped` (pojistka
 * serveru) upozornění nedávají (U5).
 */
abstract class SaleCheck extends AlertCheck
{
    public const int REGISTRATIONS_TABLE_ID = 468;
    public const string VIEWER_ID = 'economy.salesRegistration.sales';

    /** Po jaké době nepotvrzená tržba začíná být vidět. */
    public const int UNCONFIRMED_SEC = 3600;

    /** Lhůta, do které má být tržba zaevidovaná. */
    public const int OVERDUE_SEC = 172800;

    /** @return list<string> stavy tržeb, kterých se upozornění týká */
    abstract protected function states(): array;

    /** Tržba je starší než tolik sekund (null = bez dolní meze). */
    abstract protected function olderThanSec(): ?int;

    /** Tržba je nejvýš tolik sekund stará (null = bez horní meze). */
    abstract protected function youngerThanSec(): ?int;

    /**
     * @param array{registration: int, name: string, count: int, oldest: mixed} $group
     */
    abstract protected function finding(array $group, Texts $t): AlertFinding;

    public function run(): array
    {
        $now = $this->now();
        $older = $this->olderThanSec();
        $younger = $this->youngerThanSec();
        $t = Texts::of($this->config);

        $findings = [];
        $groups = $this->groups(
            $this->states(),
            $older !== null ? $now->modify('-' . $older . ' seconds')->format('Y-m-d H:i:s') : null,
            $younger !== null ? $now->modify('-' . $younger . ' seconds')->format('Y-m-d H:i:s') : null,
        );
        foreach ($groups as $group) {
            if ((int) $group['count'] > 0) {
                $findings[] = $this->finding($group, $t);
            }
        }
        return $findings;
    }

    /**
     * @param array{registration: int, name: string, count: int, oldest: mixed} $group
     * @param array<string, scalar> $params
     */
    protected function build(array $group, string $severity, string $title, string $message, Texts $t, array $params = []): AlertFinding
    {
        $registration = (int) $group['registration'];
        $params += [
            'name'   => (string) $group['name'],
            'count'  => (int) $group['count'],
            'oldest' => (string) DisplayDate::dateTime($group['oldest'], $this->language),
        ];
        $replace = static function (string $text) use ($params): string {
            foreach ($params as $name => $value) {
                $text = str_replace('{' . $name . '}', (string) $value, $text);
            }
            return $text;
        };

        return new AlertFinding(
            findingKey: (string) $registration,
            title: $replace($title),
            message: $replace($message),
            severity: $severity,
            subjectTableId: self::REGISTRATIONS_TABLE_ID,
            subjectRowId: $registration,
            actions: [[
                'id'      => 'open_sales',
                'label'   => $t->t('alert.openSales', 'Open sales registration'),
                'kind'    => 'open_viewer',
                'primary' => true,
                'target'  => ['viewerId' => self::VIEWER_ID],
            ]],
            context: ['registration' => $registration, 'count' => (int) $group['count']],
        );
    }

    /**
     * Počty tržeb v daných stavech a stáří po registracích (přepsatelné v testech).
     *
     * @param list<string> $states
     * @param string|null  $soldBefore tržba starší než tento čas
     * @param string|null  $soldFrom   tržba ne starší než tento čas
     * @return list<array{registration: int, name: string, count: int, oldest: mixed}>
     */
    protected function groups(array $states, ?string $soldBefore, ?string $soldFrom): array
    {
        $sql = 'SELECT s.`registration`, COALESCE(r.`name`, \'\') AS `name`, COUNT(*) AS `count`, MIN(s.`sold_at`) AS `oldest`'
            . ' FROM `' . SaleStore::SALES . '` s'
            . ' LEFT JOIN `' . RegistrationDocument::TABLE . '` r ON r.`id` = s.`registration`'
            . ' WHERE s.`state` IN %in';
        $params = [$states];
        if ($soldBefore !== null) {
            $sql .= ' AND s.`sold_at` < %s';
            $params[] = $soldBefore;
        }
        if ($soldFrom !== null) {
            $sql .= ' AND s.`sold_at` >= %s';
            $params[] = $soldFrom;
        }
        $sql .= ' GROUP BY s.`registration`, r.`name` ORDER BY s.`registration` ASC';

        return array_map(
            static fn(array $row): array => [
                'registration' => (int) $row['registration'],
                'name'         => (string) $row['name'],
                'count'        => (int) $row['count'],
                'oldest'       => $row['oldest'],
            ],
            $this->db->fetchAll($sql, ...$params),
        );
    }

    /** Teď v místním čase serveru — v něm je uložený čas tržby (přepsatelné v testech). */
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
