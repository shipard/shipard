<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Zdroj dat nemá kompilovanou konfiguraci v jazyce tisku — typicky čeká na
 * `ds-upgrade` po přidání jazyka dokumentů (#90 D29). Tisk bez popisků
 * konfigurace (sazby DPH, způsob úhrady) by vyšel poloprázdný, proto raději
 * nevznikne. Controller mapuje na HTTP 409.
 */
final class PrintLanguageNotCompiledException extends \RuntimeException
{
    public function __construct(public readonly string $language, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Print language '{$language}' has no compiled configuration in this data source — run ds-upgrade",
            0,
            $previous,
        );
    }
}
