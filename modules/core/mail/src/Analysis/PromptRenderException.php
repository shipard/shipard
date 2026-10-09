<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * `prompt_template` profilu nejde vykreslit: syntaktická chyba, neznámá
 * proměnná (`strict_variables`) nebo zakázaná konstrukce sandboxu. Jde
 * o chybu konfigurace profilu, ne zprávy — runner ji ukládá jako
 * `config_error` bez opakování.
 */
final class PromptRenderException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
