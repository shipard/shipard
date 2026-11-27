<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

/**
 * Chyba volání zařízení s kódem a HTTP stavem z kontraktu
 * (docs/native-bridge.md §7.2, Chybové kódy). Controller ji převede na
 * odpověď beze změny.
 *
 * Zprávy jsou záměrně obecné: chyba ověření podpisem nesmí říct, která část
 * nesedí víc, než říká kód — nic, co by pomohlo hádat.
 */
final class DeviceException extends \RuntimeException
{
    public const string DEVICE_UNKNOWN = 'DEVICE_UNKNOWN';
    public const string DEVICE_REVOKED = 'DEVICE_REVOKED';
    public const string DEVICE_EXISTS = 'DEVICE_EXISTS';
    public const string CHALLENGE_INVALID = 'CHALLENGE_INVALID';
    public const string SIGNATURE_INVALID = 'SIGNATURE_INVALID';
    public const string RATE_LIMITED = 'RATE_LIMITED';
    public const string VALIDATION_ERROR = 'VALIDATION_ERROR';

    /** @param list<array{field: string, message: string}> $details */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function unknownDevice(): self
    {
        return new self(self::DEVICE_UNKNOWN, 'Unknown device', 401);
    }

    public static function revoked(): self
    {
        return new self(self::DEVICE_REVOKED, 'Device has been revoked', 403);
    }

    public static function exists(): self
    {
        return new self(self::DEVICE_EXISTS, 'Device is already registered', 409);
    }

    public static function challengeInvalid(): self
    {
        return new self(self::CHALLENGE_INVALID, 'Challenge is not valid', 401);
    }

    public static function signatureInvalid(): self
    {
        return new self(self::SIGNATURE_INVALID, 'Signature is not valid', 401);
    }

    public static function rateLimited(): self
    {
        return new self(self::RATE_LIMITED, 'Too many unused challenges', 429);
    }

    /** @param list<array{field: string, message: string}> $details */
    public static function validation(array $details): self
    {
        return new self(self::VALIDATION_ERROR, 'Request does not match the contract', 422, $details);
    }
}
