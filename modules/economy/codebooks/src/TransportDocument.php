<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Způsob dopravy (economy_codebooks_transports, #72 D5). `partner` je
 * Osoba pro saldokonto — dopravce, za kterým vzniká pohledávka 311
 * z dokladu na dobírku; vlastní doprava protistranu nemá.
 */
class TransportDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['code'])) {
            $result->addError('code', 'Kód je povinný', 'required');
        }
        if (empty($data['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }

        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && (string) $data['valid_from'] > (string) $data['valid_to']
        ) {
            $result->addError('valid_to', 'Platnost do nesmí být dříve než platnost od.', 'invalid_range');
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['code', 'name', 'notice'] as $col) {
            if (isset($data[$col]) && $data[$col] !== null) {
                $data[$col] = trim((string) $data[$col]);
            }
        }
    }
}
