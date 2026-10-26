<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Base\Persons\Values\PersonContext;
use Shipard\Module\Base\Persons\Values\PersonValues;
use Shipard\Module\World\Validations\Country;

class PersonDocument extends Document
{
    /**
     * Virtuální klíč payloadu s předem známou zemí osoby (#118 D18) — posílá
     * ho `PersonApplier` (`country` canonicalu) a side-create z dokladu
     * (země strany). Do SQL nejde, `beforeSave` ho odstraní.
     */
    public const COUNTRY_HINT = '_country';

    /** Země osoby vyřešená pro jeden zápis (validate → beforeSave). */
    private ?string $country = null;

    /**
     * Adresy k založení s novou osobou (#103 D99): klíč `addresses` (seznam
     * řádků `base_persons_addresses` bez `person`) přichází z formuláře
     * (`PersonsForm::getNestedRowKeys`, osoba z kontroly návrhu) i z applieru
     * (side-create dodavatele). Adresy nejsou child tabulka osoby — `beforeSave`
     * je z dat vyjme (gateway by je poslala do INSERTu), `afterPersist` zapíše
     * uvnitř transakce gateway, takže osoba bez adresy nevznikne.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $pendingAddresses = null;

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

        if (array_key_exists('addresses', $data) && $data['addresses'] !== null) {
            if (!is_array($data['addresses']) || !array_is_list($data['addresses'])) {
                $result->addError('addresses', 'Adresy musí být seznam řádků', 'invalid');
            } else {
                foreach ($data['addresses'] as $n => $row) {
                    if (!is_array($row)) {
                        $result->addError("addresses.{$n}", 'Adresa musí být objekt', 'invalid');
                    }
                }
            }
        }

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

        // Platnost IČO, DIČ a PSČ adres zapisovaných s osobou (#118 D19):
        // partner dostane varování, vlastní firma chybu — její údaje jdou
        // na doklady, do QR platby a ISDOC.
        $country = $this->resolveCountry($data);
        $own = !empty($data['is_own']);
        self::report($result, PersonValues::checkPerson($data, $country), $own);
        if (is_array($data['addresses'] ?? null)) {
            foreach ($data['addresses'] as $n => $row) {
                if (is_array($row)) {
                    self::report($result, PersonValues::checkAddress($row), $own, "addresses.{$n}.");
                }
            }
        }

        return $result;
    }

    /**
     * Nálezy hodnot do výsledku validace: chyba u vlastní firmy, varování
     * u partnera (D19).
     *
     * @param list<array{column: string, code: string, message: string}> $findings
     */
    public static function report(ValidationResult $result, array $findings, bool $own, string $columnPrefix = ''): void
    {
        foreach ($findings as $finding) {
            $column = $columnPrefix . $finding['column'];
            if ($own) {
                $result->addError($column, $finding['message'], $finding['code']);
            } else {
                $result->addWarning($column, $finding['message'], $finding['code']);
            }
        }
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

        // Kanonický tvar údajů (#118 D18): IČO, DIČ, telefon, e-mail, web podle
        // země osoby; adresy zapisované s osobou podle vlastní země.
        $country = $this->resolveCountry($data);
        $this->country = null;
        unset($data[self::COUNTRY_HINT]);
        $data = PersonValues::normalizePerson($data, $country);

        // Adresy (#103 D99) nejsou sloupec osoby — vyjmout, zapíše je
        // afterPersist. Jen při vzniku záznamu; úprava osoby řádky adres
        // neřeší (spravují je jejich dialogy).
        $this->pendingAddresses = null;
        if (array_key_exists('addresses', $data)) {
            if (empty($data['id']) && is_array($data['addresses'])) {
                $this->pendingAddresses = array_map(
                    static fn (array $address): array => PersonValues::normalizeAddress($address),
                    array_values(array_filter($data['addresses'], 'is_array')),
                );
            }
            unset($data['addresses']);
        }

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
     * Řádky adres vyjmuté v `beforeSave` zapíše k nové osobě — uvnitř
     * transakce gateway (i applieru přes TransactionlessTableGateway).
     * Řádek adresy přebírá stav rodiče (osoba i adresa sdílejí
     * `core.system.docStatesArchive`), `order_pos` podle pořadí; `person`
     * a `id` z řádku se nepoužijí.
     */
    public function afterPersist(array $data): void
    {
        $addresses = $this->pendingAddresses;
        $this->pendingAddresses = null;
        if ($addresses === null || $addresses === [] || empty($data['id'])) {
            return;
        }
        $fixed = [
            'person'       => (int) $data['id'],
            'docState'     => (int) ($data['docState'] ?? 40),
            'docStateMain' => (int) ($data['docStateMain'] ?? 3),
        ];
        $position = 0;
        foreach ($addresses as $address) {
            unset($address['id'], $address['person'], $address['docState'], $address['docStateMain']);
            $address = array_filter($address, static fn (mixed $value): bool => $value !== null);
            if ($address === []) {
                continue;
            }
            $this->insertAddress($address + ['order_pos' => ++$position] + $fixed);
        }
    }

    /**
     * Zápis jednoho řádku adresy — šev pro testy (Dibi\Connection je final).
     *
     * @param array<string, mixed> $row
     */
    protected function insertAddress(array $row): void
    {
        if ($this->db === null) {
            throw new \LogicException('PersonDocument: adresy nejde zapsat bez databáze');
        }
        $this->db->insert('base_persons_addresses', $row)->execute();
    }

    /**
     * Země osoby pro pravidla hodnot (#118 D18): klíč `_country` z payloadu
     * → adresy v témže zápisu (sídlo, jinak první se zemí) → uložené adresy
     * → země zdroje dat. Jeden výpočet na zápis (validate i beforeSave).
     *
     * @param array<string, mixed> $data
     */
    private function resolveCountry(array $data): string
    {
        if ($this->country !== null) {
            return $this->country;
        }
        $hint = PersonValues::text($data[self::COUNTRY_HINT] ?? null);
        $country = $hint !== null ? Country::normalize($hint) : null;
        if ($country === null && empty($data['id']) && is_array($data['addresses'] ?? null)) {
            $country = PersonContext::countryFromAddresses(array_values(array_filter($data['addresses'], 'is_array')));
        }
        $context = $this->personContext();
        if ($country === null && !empty($data['id'])) {
            $country = $context->country((int) $data['id']);
        }
        return $this->country = $country ?? $context->dsCountry();
    }

    /** Šev pro testy — kontext s databází a zemí zdroje dat. */
    protected function personContext(): PersonContext
    {
        return new PersonContext($this->db, $this->dsConfig?->getCountry() ?? 'cz');
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
