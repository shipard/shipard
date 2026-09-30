<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;
use Shipard\Module\Economy\Codebooks\FiscalMonthLookup;

/**
 * Zápis systémových odpisů (původ `system`, rovnou potvrzené) z plánovaných
 * řádků enginu — jediná cesta pro „Odpisy za období“ (D33) i poslední
 * odpisy při vyřazení (D35).
 *
 * Píše přímo do tabulky, ne přes `AssetEventDocument`: vyřazení zakládá
 * odpisy z `afterPersist()` uvnitř transakce uložení, kde dokument nemá
 * gateway a druhý `begin()` by vnější transakci commitnul. Částky, období
 * i zaokrouhlení pocházejí z enginu, takže pravidla události splňují
 * z konstrukce; zámek účetního měsíce, který by jinak hlídal lock
 * provider, kontroluje zapisovač sám.
 *
 * Volající vlastní transakci. Výjimka = nic se nemá commitnout.
 */
class SystemDepreciationWriter
{
    public function __construct(protected readonly ?\Dibi\Connection $db)
    {
    }

    /**
     * @param list<PlanRow> $rows plánované odpisy jednoho okruhu
     * @param string $scope `tax` / `acc`
     * @return float součet založených odpisů
     * @throws \DomainException datum odpisu padá do zamčeného účetního měsíce
     */
    public function write(int $assetId, string $scope, array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            if (!$row->isPlanned() || !$row->isDepreciation() || $row->period === null) {
                throw new \LogicException('Systémový odpis lze založit jen z plánovaného řádku odpisu');
            }

            $locked = $this->lockedMonthLabel($row->date);
            if ($locked !== null) {
                throw new \DomainException(
                    'Odpis k ' . (new \DateTimeImmutable($row->date))->format('j. n. Y')
                    . " nelze založit — účetní měsíc {$locked} je zamčený.",
                );
            }

            $this->insert([
                'asset'        => $assetId,
                'event_kind'   => AssetEvent::KIND_DEPRECIATION,
                'scope'        => $scope,
                'event_date'   => $row->date,
                'period_begin' => $row->period->begin,
                'period_end'   => $row->period->end,
                'amount'       => $row->amount,
                'half_year'    => $row->halfYear ? 1 : 0,
                'origin'       => AssetEvent::ORIGIN_SYSTEM,
                'docState'     => AssetEventDocument::STATE_CONFIRMED,
                'docStateMain' => AssetEventDocument::MAIN_CONFIRMED,
            ]);
            $total += $row->amount;
        }
        return $total;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** Popisek zamčeného měsíce (`2024/03`), null = datum není v zamčeném měsíci. */
    protected function lockedMonthLabel(string $date): ?string
    {
        if ($this->db === null) {
            return null;
        }
        $month = FiscalMonthLookup::lockedMonthForDate($this->db, $date);

        return $month === null ? null : sprintf('%04d/%02d', $month['calendar_year'], $month['calendar_month']);
    }

    /** @param array<string, mixed> $row */
    protected function insert(array $row): void
    {
        $this->db?->query('INSERT INTO [' . AssetPlanService::EVENTS_TABLE . '] %v', $row);
    }
}
