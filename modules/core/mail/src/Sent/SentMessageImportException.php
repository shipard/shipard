<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

/**
 * Payload importu odeslané zprávy porušuje kontrakt (#104 D3) — `field`
 * a `errorCode` nese REST jako `details` chyby 422 `VALIDATION_ERROR`.
 */
final class SentMessageImportException extends \DomainException
{
    public function __construct(
        public readonly string $field,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
