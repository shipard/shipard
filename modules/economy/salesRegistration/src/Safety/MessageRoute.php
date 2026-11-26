<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Safety;

/**
 * Kam zpráva evidence tržeb skutečně jde (#171 D9, D10) — výsledek
 * {@see MessageRouteResolver}: prostředí, identita, pod kterou se podepisuje,
 * a ověřovací mód; nebo nic (`dropped`) s důvodem.
 */
final readonly class MessageRoute
{
    /** Podepisuje se certifikátem registrace. */
    public const string IDENTITY_REGISTRATION = 'registration';
    /** Podepisuje se sdílenou testovací identitou — ve zprávě jde její identifikátor. */
    public const string IDENTITY_TEST = 'test';

    /** Pojistka `drop` ze sekce `server.json`. */
    public const string DROP_CONFIGURED = 'configured';
    /** Sekce chybí a server není produkční. */
    public const string DROP_DEFAULT = 'default';
    /** Sekce je chybná nebo nečitelná. */
    public const string DROP_INVALID = 'invalid';
    /** Registrace má režim, který doplněk nezná. */
    public const string DROP_UNKNOWN_MODE = 'unknownMode';

    private function __construct(
        public bool $dropped,
        public ?string $environment,
        public ?string $identity,
        public bool $verification,
        public ?string $dropReason,
        public ?string $problem,
    ) {
    }

    public static function to(string $environment, string $identity, bool $verification): self
    {
        return new self(false, $environment, $identity, $verification, null, null);
    }

    public static function drop(string $reason, ?string $problem = null): self
    {
        return new self(true, null, null, false, $reason, $problem);
    }

    public function usesTestIdentity(): bool
    {
        return $this->identity === self::IDENTITY_TEST;
    }
}
