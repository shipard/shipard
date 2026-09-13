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

    private function __construct(
        public readonly int $txId,
        public readonly string $status,
        public readonly ?string $reason,
        public readonly ?string $targetAccount,
        public readonly ?int $partner,
        public readonly ?string $currency,
        public readonly float $amount,
        public readonly float $amountHc,
    ) {}

    public static function routed(int $txId, string $targetAccount, int $partner, ?string $currency, float $amount, float $amountHc): self
    {
        return new self($txId, self::STATUS_ROUTED, null, $targetAccount, $partner, $currency, $amount, $amountHc);
    }

    public static function planned(int $txId, string $targetAccount, int $partner, ?string $currency, float $amount, float $amountHc): self
    {
        return new self($txId, self::STATUS_PLANNED, null, $targetAccount, $partner, $currency, $amount, $amountHc);
    }

    public static function skipped(int $txId, string $reason, float $amount = 0.0, float $amountHc = 0.0): self
    {
        return new self($txId, self::STATUS_SKIPPED, $reason, null, null, null, $amount, $amountHc);
    }
}
