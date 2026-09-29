<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/** Typ majetku (economy_assets_types, D25). */
class AssetTypeDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (trim((string) ($data['name'] ?? '')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $category = (string) ($data['default_category'] ?? '');
        if ($category !== '' && new AssetCategories($this->config)->isUnknown($category)) {
            $result->addError('default_category', 'Neznámý druh majetku', 'invalid');
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['name', 'short_name'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null) {
                $trimmed = trim((string) $data[$col]);
                $data[$col] = $trimmed === '' ? null : $trimmed;
            }
        }
        if (array_key_exists('default_category', $data) && $data['default_category'] === '') {
            $data['default_category'] = null;
        }
    }
}
