<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * Odpověď služby kurzovních lístků (#121 task B §3): `lists` = dekódované
 * lístky ve formátu shpd.exchangeRates.list.v1, `none` = služba žádný
 * lístek v rozsahu nemá (`valid: 0` bez `error`), `error` = síť, HTTP ≠ 200,
 * nevalidní JSON, `valid: 0` s `error` nebo odpověď bez `data`.
 */
final readonly class ExchangeRateServiceResponse
{
    public const string LISTS = 'lists';
    public const string NONE = 'none';
    public const string ERROR = 'error';

    /** @param list<array<string, mixed>> $lists */
    private function __construct(
        public string $kind,
        public array $lists,
        public ?string $error,
    ) {
    }

    /** @param list<array<string, mixed>> $lists */
    public static function lists(array $lists): self
    {
        return new self(self::LISTS, $lists, null);
    }

    public static function none(): self
    {
        return new self(self::NONE, [], null);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, [], $message);
    }

    public function isError(): bool
    {
        return $this->kind === self::ERROR;
    }

    public function isNone(): bool
    {
        return $this->kind === self::NONE;
    }
}
