<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Engine;

/**
 * Zpráva evidence tržeb v obecném tvaru (#171 D2) — co se eviduje, bez
 * ohledu na protokol země.
 *
 * `uuid` generuje volající (odesílač), ne engine: pokus o odeslání se dá
 * zapsat dřív, než zpráva odejde, a potvrzení se váže právě na toto UUID.
 * Částka je desetinný řetězec (`"1250.00"`), ne float. `extras` jsou data
 * specifická pro typ evidence; engine, který jim nerozumí, zprávu odmítne —
 * z daňové zprávy nesmí nic tiše zmizet.
 */
final readonly class SalesMessage
{
    /**
     * @param array<string, mixed> $extras doplňky typu evidence
     */
    public function __construct(
        public string $uuid,
        public string $unitId,
        public string $cashRegisterId,
        public string $serialNumber,
        public \DateTimeImmutable $soldAt,
        public string $amount,
        public string $currency,
        public bool $firstSending = true,
        public bool $verification = false,
        public string $taxpayerId = '',
        public array $extras = [],
    ) {
    }

    /** Nové UUID zprávy (verze 4). */
    public static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Zpráva, jak skutečně odejde: s identifikátorem poplatníka identity,
     * pod kterou se podepisuje, a s ověřovacím módem podle trasy.
     */
    public function routed(string $taxpayerId, bool $verification): self
    {
        return new self(
            $this->uuid,
            $this->unitId,
            $this->cashRegisterId,
            $this->serialNumber,
            $this->soldAt,
            $this->amount,
            $this->currency,
            $this->firstSending,
            $verification,
            $taxpayerId,
            $this->extras,
        );
    }
}
