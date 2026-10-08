<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing\Contributor;

/**
 * Výsledek přispěvatele (D10): Ready s kanonickými řádky, které nahradí
 * řádky přispěvatele v dokladu (libovolný počet, i prázdný), Waiting
 * s důvodem čekání, Failed se zprávou.
 */
final readonly class ContributionResult
{
    public const READY = 'ready';
    public const WAITING = 'waiting';
    public const FAILED = 'failed';

    /**
     * @param list<array<string, mixed>> $rows kanonické řádky (`$defs/Row`)
     */
    private function __construct(
        public string $status,
        public array $rows = [],
        public ?string $message = null,
    ) {
    }

    /** @param list<array<string, mixed>> $rows */
    public static function ready(array $rows): self
    {
        return new self(self::READY, array_values($rows));
    }

    public static function waiting(string $reason): self
    {
        return new self(self::WAITING, [], $reason);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, [], $message);
    }

    public function isReady(): bool
    {
        return $this->status === self::READY;
    }

    public function isWaiting(): bool
    {
        return $this->status === self::WAITING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}
