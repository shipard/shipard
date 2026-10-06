<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

/**
 * Odeslanou zprávu nejde předat transportu — důvod nese `errorCode`,
 * REST z něj dělá kód chyby (`NOT_FOUND` 404, ostatní 409).
 */
final class SentMessageException extends \DomainException
{
    public const NOT_FOUND      = 'NOT_FOUND';
    /** Odeslat znovu jde jen zprávu ve stavu Odeslaná. */
    public const INVALID_STATE  = 'INVALID_STATE';
    /** Zpráva už ve frontě čeká. */
    public const ALREADY_QUEUED = 'ALREADY_QUEUED';
    /** Zpráva převzatá ze starého systému — znovu ji odeslat nejde (#104 D5). */
    public const IMPORTED       = 'IMPORTED';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
