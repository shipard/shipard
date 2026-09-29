<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/** Skupina typů majetku (economy_assets_type_groups, D25). */
class AssetTypeGroupDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (trim((string) ($data['name'] ?? '')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }
    }
}
