<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Sales;

/**
 * Výsledek rozdílového výpočtu nad jedním dokladem ({@see SaleSync}).
 *
 *  - `none`          — nic k evidenci (nespadá, nebo součet tržeb sedí);
 *  - `created`       — vznikla tržba `saleId`;
 *  - `wouldCreate`   — běh nanečisto: tržba by vznikla;
 *  - `unregistrable` — doklad spadá, ale tržbu založit nejde (`reason`, U9);
 *  - `busy`          — doklad právě zpracovává jiný proces, příště.
 */
final readonly class SyncResult
{
    public const string NONE = 'none';
    public const string CREATED = 'created';
    public const string WOULD_CREATE = 'wouldCreate';
    public const string UNREGISTRABLE = 'unregistrable';
    public const string BUSY = 'busy';

    /** @param array<string, mixed> $sale data tržby (vzniklé, nebo která by vznikla) */
    private function __construct(
        public string $status,
        public int $docId,
        public ?int $saleId = null,
        public ?string $reason = null,
        public ?int $registration = null,
        public array $sale = [],
    ) {
    }

    public static function none(int $docId): self
    {
        return new self(self::NONE, $docId);
    }

    public static function busy(int $docId): self
    {
        return new self(self::BUSY, $docId);
    }

    public static function unregistrable(int $docId, string $reason, ?int $registration = null): self
    {
        return new self(self::UNREGISTRABLE, $docId, reason: $reason, registration: $registration);
    }

    /** @param array<string, mixed> $sale */
    public static function created(int $docId, int $saleId, array $sale): self
    {
        return new self(self::CREATED, $docId, $saleId, registration: (int) $sale['registration'], sale: $sale);
    }

    /** @param array<string, mixed> $sale */
    public static function wouldCreate(int $docId, array $sale): self
    {
        return new self(self::WOULD_CREATE, $docId, registration: (int) $sale['registration'], sale: $sale);
    }

    public function isCreated(): bool
    {
        return $this->status === self::CREATED;
    }
}
