<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints\Jobs;

use Shipard\Core\Prints\PrintErrors;

/**
 * Chyba tiskové úlohy s kódem a HTTP stavem (docs/prints.md kap. 14).
 * Controller ji převede na odpověď beze změny.
 *
 * Stavy: chyba nastavení zařízení a tiskárny 409 (uživatel ji napraví
 * v Nastavení → Zařízení, ne opakováním), neplatné tělo 422, cizí úloha 403.
 * `DEVICE_UNKNOWN` je tu 409, ne 401 jako u podepsaných volání zařízení
 * (kontrakt §7.2): `deviceId` je hodnota v těle přihlášeného požadavku
 * a 401 by webové aplikaci znamenalo neplatnou session.
 */
final class PrintJobException extends \RuntimeException
{
    public const string DEVICE_UNKNOWN = 'DEVICE_UNKNOWN';
    public const string DEVICE_REVOKED = 'DEVICE_REVOKED';
    public const string NO_PERIPHERAL_FOR_ROLE = 'NO_PERIPHERAL_FOR_ROLE';
    public const string PERIPHERAL_NOT_PRESENT = 'PERIPHERAL_NOT_PRESENT';
    public const string ROLE_AMBIGUOUS = 'ROLE_AMBIGUOUS';
    public const string PROFILE_NO_DRAWER = 'PROFILE_NO_DRAWER';
    public const string PRINT_NOT_FOUND = 'PRINT_NOT_FOUND';
    public const string PRINT_FORMAT_UNSUPPORTED = 'PRINT_FORMAT_UNSUPPORTED';
    public const string PAYLOAD_TOO_LARGE = 'PAYLOAD_TOO_LARGE';
    public const string JOB_NOT_FOUND = 'JOB_NOT_FOUND';
    public const string FORBIDDEN = 'FORBIDDEN';
    public const string VALIDATION_ERROR = 'VALIDATION_ERROR';

    /** @param list<array<string, string>> $details */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unknownDevice(): self
    {
        return new self(self::DEVICE_UNKNOWN, 'Device is not registered in this data source', 409);
    }

    public static function revokedDevice(): self
    {
        return new self(self::DEVICE_REVOKED, 'Device has been revoked', 403);
    }

    public static function noPeripheralForRole(string $role): self
    {
        return new self(self::NO_PERIPHERAL_FOR_ROLE, "Device has no printer with role '{$role}'", 409);
    }

    public static function peripheralNotPresent(string $role): self
    {
        return new self(
            self::PERIPHERAL_NOT_PRESENT,
            "Printer with role '{$role}' is no longer reported by the device",
            409,
        );
    }

    public static function roleAmbiguous(string $role): self
    {
        return new self(self::ROLE_AMBIGUOUS, "More printers of the device have role '{$role}'", 409);
    }

    public static function profileWithoutDrawer(string $profileId): self
    {
        return new self(self::PROFILE_NO_DRAWER, "Printer profile '{$profileId}' has no cash drawer", 409);
    }

    public static function printNotFound(string $printId): self
    {
        return new self(self::PRINT_NOT_FOUND, "Unknown print '{$printId}'", 404);
    }

    public static function formatUnsupported(string $printId): self
    {
        return new self(
            self::PRINT_FORMAT_UNSUPPORTED,
            "Print '{$printId}' is not a print for a receipt printer",
            409,
        );
    }

    public static function payloadTooLarge(int $size, int $limit): self
    {
        return new self(self::PAYLOAD_TOO_LARGE, "Print job data has {$size} bytes, the limit is {$limit}", 413);
    }

    public static function jobNotFound(): self
    {
        return new self(self::JOB_NOT_FOUND, 'Print job not found', 404);
    }

    public static function foreignJob(): self
    {
        return new self(self::FORBIDDEN, 'Print job belongs to another user', 403);
    }

    /** @param list<array{field: string, message: string}> $details */
    public static function validation(array $details): self
    {
        return new self(self::VALIDATION_ERROR, 'Request does not match the contract', 422, $details);
    }

    /**
     * Chyba tisku záznamu se stejným kódem a stavem jako `/_prints`.
     * Null = výjimka není chyba tisku.
     */
    public static function fromPrintError(\Throwable $e): ?self
    {
        $error = PrintErrors::of($e);
        return $error === null
            ? null
            : new self($error['code'], $error['message'], $error['status'], $error['details'], $e);
    }
}
