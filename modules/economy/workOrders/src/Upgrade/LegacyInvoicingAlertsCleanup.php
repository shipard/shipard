<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Upgrade;

use Dibi\Connection;
use Shipard\Core\Alerts\AlertReconciler;

/**
 * Uzavře osiřelé alerty periodické fakturace zakázek (#175): upozornění se
 * přesunula do modulu economy.periodicInvoicing a dostala id
 * `economy.periodicinvoicing.*`. Otevřené alerty se starým id
 * `economy.workorders.*` už žádný check nevrací — reconciler uzavírá jen
 * alerty checku, který právě běží, takže by ve feedu visely navždy
 * (`alerts-prune` maže jen uzavřené).
 *
 * Volá ho `ds-upgrade` (vždy, i pod `skipProvisioning`). Alerty převede do
 * stavu Vyřešeno s `resolved_at` jako reconciler; nic nemaže. Jen alerty
 * s tímto prefixem. Idempotentní — druhý běh nic nenajde. Nová upozornění
 * založí první běh `alerts-run`.
 */
final class LegacyInvoicingAlertsCleanup
{
    public const CHECK_ID_PREFIX = 'economy.workorders.';

    public function __construct(
        private readonly Connection $db,
        private readonly ?\Closure $executor = null,
    ) {}

    /** @return int počet uzavřených alertů */
    public function run(?string $now = null): int
    {
        return $this->execute(
            'UPDATE %n SET %a WHERE [check_id] LIKE %like~ AND [alert_state] IN %in',
            AlertReconciler::ALERTS_TABLE,
            [
                'alert_state' => AlertReconciler::STATE_RESOLVED,
                'resolved_at' => $now ?? date('Y-m-d H:i:s'),
            ],
            self::CHECK_ID_PREFIX,
            [AlertReconciler::STATE_ACTIVE, AlertReconciler::STATE_SNOOZED],
        );
    }

    /** Zápis (Dibi `query` je final — `$executor` je šev pro testy). */
    private function execute(string $sql, mixed ...$args): int
    {
        if ($this->executor !== null) {
            return (int) ($this->executor)($sql, ...$args);
        }
        $this->db->query($sql, ...$args);
        return $this->db->getAffectedRows();
    }
}
