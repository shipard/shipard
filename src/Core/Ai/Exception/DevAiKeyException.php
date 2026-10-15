<?php

declare(strict_types=1);

namespace Shipard\Core\Ai\Exception;

/**
 * Serverový klíč pro vývoj (`secrets/dev-ai-<platforma>.key`) chybí, má
 * volná práva nebo nejde přečíst ({@see \Shipard\Core\Ai\DevAiKeyStore}).
 * Věta říká, co s tím — tiskne ji `ai-test` i detail AI funkce.
 */
final class DevAiKeyException extends \RuntimeException
{
    public const string MISSING = 'missing';
    public const string INSECURE = 'insecure';
    public const string UNREADABLE = 'unreadable';

    public function __construct(
        public readonly string $kind,
        string $message,
    ) {
        parent::__construct($message);
    }
}
