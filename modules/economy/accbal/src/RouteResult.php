<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Výsledek pokusu o přeúčtování jedné clearingové úhrady
 * ({@see ClearingRouter}). Nese dost pro čitelný report (CLI) i agregaci
 * do {@see RouteSummary}.
 */
final class RouteResult
{
    /** Úhrada přeúčtována z clearingu na účet otevřeného předpisu. */
    public const STATUS_ROUTED = 'routed';
    /** Dry-run: úhrada by se přeúčtovala, reálně nic nezměněno. */
    public const STATUS_PLANNED = 'planned';
    /** Úhrada zůstává na clearingu — viz reason (no_partner | no_open_item | engine_error). */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param ?string $targetAccount účet předpisu (OpenItem::accountNumber)
     * @param ?string $targetCategory kategorie úhrady (#79 D3a): je-li
     *        vyplněna, engine úhradu položí na účet této kategorie, ne na
     *        `targetAccount` — výpis ukazuje kategorii, ať neslibuje 756
     */
    private function __construct(
        public readonly int $txId,
        public readonly string $status,
        public readonly ?string $reason,
        public readonly ?string $targetAccount,
        public readonly ?int $partner,
        public readonly ?string $currency,
        public readonly float $amount,
        public readonly float $amountHc,
        public readonly ?string $targetCategory = null,
    ) {}

    public static function routed(int $txId, string $targetAccount, int $partner, ?string $currency, float $amount, float $amountHc, ?string $targetCategory = null): self
    {
        return new self($txId, self::STATUS_ROUTED, null, $targetAccount, $partner, $currency, $amount, $amountHc, $targetCategory);
    }

    public static function planned(int $txId, string $targetAccount, int $partner, ?string $currency, float $amount, float $amountHc, ?string $targetCategory = null): self
    {
        return new self($txId, self::STATUS_PLANNED, null, $targetAccount, $partner, $currency, $amount, $amountHc, $targetCategory);
    }

    public static function skipped(int $txId, string $reason, float $amount = 0.0, float $amountHc = 0.0): self
    {
        return new self($txId, self::STATUS_SKIPPED, $reason, null, null, null, $amount, $amountHc);
    }

    /** Cíl pro výpis: kategorie úhrady (přesměrovaný případ), jinak účet předpisu. */
    public function targetLabel(): ?string
    {
        if ($this->targetCategory !== null) {
            return "kategorie {$this->targetCategory} (předpis {$this->targetAccount})";
        }
        return $this->targetAccount;
    }
}
