<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

/**
 * Řádek plánu odpisů: potvrzená událost, nebo plánovaný odpis.
 *
 * U potvrzeného odpisu je `amount` převzatá částka a `computed` hodnota,
 * kterou by spočítal engine (rozdíl = hlášení `mismatch`). `entryPrice`,
 * `accumulated` a `residual` jsou stav okruhu po řádku.
 */
final readonly class PlanRow
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PLANNED = 'planned';

    /** @param list<PlanMessage> $messages */
    public function __construct(
        public string $kind,
        public string $status,
        public string $date,
        public ?Period $period,
        public float $amount,
        public ?float $computed,
        public ?string $formula,
        /** Vstupní cena — základ výpočtu. */
        public float $entryPrice,
        public float $accumulated,
        public float $residual,
        public array $messages = [],
        public bool $halfYear = false,
        public bool $claimUnrecorded = false,
        public ?int $eventId = null,
    ) {
    }

    public function isPlanned(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }

    public function isDepreciation(): bool
    {
        return $this->kind === AssetEvent::KIND_DEPRECIATION;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'status' => $this->status,
            'date' => $this->date,
            'period' => $this->period?->toArray(),
            'amount' => $this->amount,
            'computed' => $this->computed,
            'formula' => $this->formula,
            'entryPrice' => $this->entryPrice,
            'accumulated' => $this->accumulated,
            'residual' => $this->residual,
            'messages' => array_map(static fn(PlanMessage $m): array => $m->toArray(), $this->messages),
            'halfYear' => $this->halfYear,
            'claimUnrecorded' => $this->claimUnrecorded,
            'eventId' => $this->eventId,
        ];
    }
}
