<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Navigation\NavigationItemsProvider;

/**
 * Sidebar položky pro saldokonta se `show_in_navigation = 1` (checkbox
 * v Nastavení saldokont). Klik otevře Saldokonto po případech
 * (economy.accbal.cases, #69 D1) napevno filtrované přes `fixedViewGroup`
 * = code — viewer pak chip lištu saldokont nerenderuje; pohyby jsou
 * detail (akce „Pohyby případu").
 *
 * `_order` 32+ řadí položky hned za Saldokonto (navOrder 31, za Bankovními
 * transakcemi 30); Saldo pohyby mají 39 a Výpisy 40, takže bez kolize se
 * vejde 7 saldokont — víc jich seed nemá a případná remíza jen stabilně
 * zařadí pohyby před přetékající saldokonta.
 */
class BalancesNavigationProvider implements NavigationItemsProvider
{
    public function items(DataSourceConnection $db, string $language): array
    {
        // DS před ds-upgrade sloupec show_in_navigation nemá — dotaz by
        // shodil celou navigaci. Očekávatelný stav → prázdný seznam.
        //
        // Agregace předpisových účtů (bal_side = 0) určuje ikonu položky:
        // čistě MD = pohledávkový typ, čistě DAL = závazkový typ. Dobropisové
        // řádky (modify_sign = 1) mají stranu obrácenou — bez vyloučení by
        // Závazky vyšly „smíšené" a spadly na fallback.
        try {
            $balances = $db->fetchAll(
                'SELECT b.`code`, b.`name`, b.`short_name`,'
                . ' MIN(a.`acc_side`) AS side_min, MAX(a.`acc_side`) AS side_max,'
                . ' COUNT(a.`id`) AS side_cnt'
                . ' FROM `economy_accbal_balances` b'
                . ' LEFT JOIN `economy_accbal_balance_accounts` a'
                . ' ON a.`balance` = b.`id` AND a.`bal_side` = 0'
                . ' AND a.`modify_sign` = 0 AND a.`docState` != 90'
                . ' WHERE b.`show_in_navigation` = 1 AND b.`docState` != 90'
                . ' GROUP BY b.`id`'
                . ' ORDER BY b.`sort_order` ASC, b.`name` ASC',
            );
        } catch (\Throwable) {
            return [];
        }

        $items = [];
        foreach ($balances as $i => $b) {
            $shortName = trim((string) ($b['short_name'] ?? ''));
            $items[] = [
                'id'             => 'accbal-balance:' . $b['code'],
                'label'          => $shortName !== '' ? $shortName : (string) $b['name'],
                'type'           => 'viewer',
                'viewerId'       => 'economy.accbal.cases',
                'icon'           => self::balanceIcon($b),
                'fixedViewGroup' => (string) $b['code'],
                '_section'       => 'accounting',
                '_order'         => 32 + $i,
            ];
        }
        return $items;
    }

    /** @param array<string, mixed> $b řádek s agregacemi side_min/side_max/side_cnt */
    private static function balanceIcon(array $b): string
    {
        $cnt = (int) ($b['side_cnt'] ?? 0);
        if ($cnt > 0 && (int) $b['side_min'] === (int) $b['side_max']) {
            return (int) $b['side_min'] === 0 ? 'receivable' : 'payable';
        }
        return 'balance';
    }
}
