<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Base\Persons\Values\PersonContext;
use Shipard\Module\Base\Persons\Values\PersonValues;

/**
 * Adresa osoby (`base_persons_addresses`, #118 D18): při uložení z
 * formuláře se země ukládá malými a PSČ v kanonickém tvaru podle země
 * adresy — při částečném updatu bez země podle uloženého řádku. Řádky
 * zapisované s novou osobou normalizuje `PersonDocument`, podřízené
 * záznamy applieru `PersonApplier` — všechny touž funkcí
 * ({@see PersonValues::normalizeAddress}). PSČ cz / sk / de kontroluje
 * (D19): u adresy vlastní firmy chyba, u partnera varování.
 */
class AddressDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $own = $this->personContext()->isOwn((int) ($data['person'] ?? 0));
        PersonDocument::report($result, PersonValues::checkAddress($data), $own);
        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $data = PersonValues::normalizeAddress($data, PersonValues::text($originalData['country'] ?? null));
    }

    protected function personContext(): PersonContext
    {
        return new PersonContext($this->db, $this->dsConfig?->getCountry() ?? 'cz');
    }
}
