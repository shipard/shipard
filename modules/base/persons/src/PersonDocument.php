<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class PersonDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

        if ($personType === null || $personType === PersonType::Undefined) {
            $result->addError('person_type', 'Typ osoby je povinný', 'required');
        }

        if ($personType === PersonType::Company && empty($data['full_name'])) {
            $result->addError('full_name', 'Název firmy je povinný', 'required');
        }

        if ($personType === PersonType::Person) {
            if (empty($data['last_name'])) {
                $result->addError('last_name', 'Příjmení je povinné', 'required');
            }
            if (empty($data['first_name'])) {
                $result->addError('first_name', 'Jméno je povinné', 'required');
            }
        }

        // Jazyk dokumentů (#94 D1): prázdná volba = automaticky podle země.
        if (array_key_exists('language', $data)) {
            if ($data['language'] === '') {
                $data['language'] = null;
            }
            if ($data['language'] !== null && !$this->isDocumentLanguage($data['language'])) {
                $result->addError('language', 'Neznámý jazyk dokumentů', 'invalid_language');
            }
        }

        if (!empty($data['is_own'])) {
            if ($personType !== PersonType::Company) {
                $result->addError(
                    'is_own',
                    'Vlastní firma musí být typu Firma',
                    'is_own_not_company',
                );
            }

            if ($this->db !== null) {
                $sql = 'SELECT id FROM base_persons_persons
                        WHERE is_own = 1 AND docState != %i';
                $params = [90];
                if (!empty($data['id'])) {
                    $sql .= ' AND id != %i';
                    $params[] = (int) $data['id'];
                }
                $sql .= ' LIMIT 1';

                $existing = $this->db->fetch($sql, ...$params);
                if ($existing) {
                    $result->addError(
                        'is_own',
                        'Vlastní firma už je nastavena na jiném záznamu',
                        'is_own_duplicate',
                    );
                }
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

        // Auto-generate person_id if empty
        if (empty($data['person_id']) && $this->db !== null) {
            $data['person_id'] = $this->generatePersonId($personType);
        }

        if ($personType === PersonType::Company) {
            $data['first_name'] = '';
            $data['last_name'] = $data['full_name'] ?? '';
        }

        if ($personType === PersonType::Person) {
            $data['full_name'] = trim(
                ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')
            );
        }
    }

    /**
     * Je hodnota mezi jazyky dokumentů (cfgItem `world.base.documentLanguages`)?
     * Bez konfigurace se nekontroluje — není proti čemu.
     */
    private function isDocumentLanguage(mixed $language): bool
    {
        $languages = $this->config?->cfgItem('world.base.documentLanguages');
        if (!is_array($languages)) {
            return true;
        }
        return is_string($language) && array_key_exists($language, $languages);
    }

    private function generatePersonId(?PersonType $personType): string
    {
        $prefix = match ($personType) {
            PersonType::Company => 'F',
            PersonType::Person  => 'O',
            default             => 'X',
        };

        // Find the highest existing numeric suffix for this prefix
        $row = $this->db->fetch(
            'SELECT MAX(CAST(SUBSTRING(person_id, %i) AS UNSIGNED)) AS max_num
             FROM base_persons_persons
             WHERE person_id LIKE %s',
            strlen($prefix) + 1,
            $prefix . '%',
        );

        $next = ((int) ($row['max_num'] ?? 0)) + 1;
        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
