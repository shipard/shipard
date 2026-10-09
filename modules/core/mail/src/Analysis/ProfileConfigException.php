<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * AI profil nebo backend nejde pro běh použít (nevalidní `output_schema`,
 * nepodporovaný provider). Chyba konfigurace DS, ne zprávy — runner ji
 * ukládá jako `config_error` bez opakování.
 */
final class ProfileConfigException extends \RuntimeException
{
}
