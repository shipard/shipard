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
 * adresy — při částečném updatu bez země podle uloženého řádku, bez
 * jakékoli země podle země osoby (D24, {@see PersonContext::country};
 * do `country` se nic nezapisuje). Řádky zapisované s novou osobou
 * normalizuje `PersonDocument`, podřízené záznamy applieru `PersonApplier`
 * — všechny touž funkcí ({@see PersonValues::normalizeAddress}). PSČ
 * cz / sk / de kontroluje (D19): u adresy vlastní firmy chyba, u partnera
 * varování.
 */
class AddressDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $context = $this->personContext();
        $person = (int) ($data['person'] ?? 0);
        // Update bez klíče `country` v payloadu: vlastní země uloženého řádku
        // má přednost před zemí osoby (gateway validuje jen poslaná pole).
        $stored = !array_key_exists('country', $data) && !empty($data['id'])
            ? $context->addressCountry((int) $data['id'])
            : null;
        PersonDocument::report(
            $result,
            PersonValues::checkAddress($data, $stored, $context->country($person)),
            $context->isOwn($person),
        );
        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $person = (int) ($data['person'] ?? $originalData['person'] ?? 0);
        $data = PersonValues::normalizeAddress(
            $data,
            PersonValues::text($originalData['country'] ?? null),
            $this->personContext()->country($person),
        );
    }

    protected function personContext(): PersonContext
    {
        return new PersonContext($this->db, $this->dsConfig?->getCountry() ?? 'cz');
    }
}
