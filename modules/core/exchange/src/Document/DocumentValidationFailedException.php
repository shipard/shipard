<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Core\Document\ValidationResult;

/**
 * Doklad neprošel validací `DocDocument` při uložení (#166 D21). Vyhazuje
 * se uvnitř transakce apply, aby se vrátily založené entity; {@see
 * DocumentApplier::apply()} z ní udělá 422 `validation_failed` s nálezy
 * dokladu v `_resolve.issues` — ne `internal_error`.
 */
final class DocumentValidationFailedException extends \RuntimeException
{
    public function __construct(public readonly ValidationResult $validation)
    {
        parent::__construct('Validace dokladu selhala.');
    }
}
