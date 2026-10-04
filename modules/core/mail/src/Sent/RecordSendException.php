<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

/**
 * Záznam nejde odeslat — důvod nese `errorCode` (REST kód chyby), text je
 * hláška pro uživatele.
 */
final class RecordSendException extends \DomainException
{
    /** Tisk nemá `sendPurpose` — není určený k odeslání. */
    public const PRINT_NOT_SENDABLE = 'PRINT_NOT_SENDABLE';
    public const NO_RECIPIENT       = 'NO_RECIPIENT';
    public const NO_SENDER          = 'NO_SENDER';
    public const SENDER_NOT_ALLOWED = 'SENDER_NOT_ALLOWED';
    public const INVALID_EMAIL      = 'INVALID_EMAIL';
    /** Příloha nepatří k odesílanému záznamu. */
    public const INVALID_ATTACHMENT = 'INVALID_ATTACHMENT';
    /** Prázdný předmět nebo tělo. */
    public const EMPTY_MESSAGE      = 'EMPTY_MESSAGE';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
