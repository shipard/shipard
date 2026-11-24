<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Base\Persons\PersonDocument;
use Shipard\Module\Base\Persons\Values\PersonValues;

/**
 * Místo (base_places_places, #169 D3–D7, D13, D15; docs/places.md).
 *
 * Typ místo dědí z druhu. Pravidla, která drží strom pohromadě:
 *
 *  - **úrovně oběma směry** (D3, D15): nadřazené místo musí mít nižší
 *    úroveň typu než místo, a místo nižší než každé přímé podřízené — jinak
 *    by změna druhu budovy na místnost nechala pod místností podlaží.
 *    Pravidlo dává {@see PlaceTypes::canBeParent()}; typ bez úrovně
 *    (Ostatní) ho vypíná
 *  - **cyklus vždy** — i mezi místy typu Ostatní, kde úrovně nic nehlídají
 *  - **kód jedinečný mezi sourozenci** (D4) — stejné nadřazené místo, i
 *    nejvyšší úroveň; koš se nepočítá, velikost písmen nerozhoduje.
 *    Unikátní index to být nemůže: místnost 101 je v každé budově
 *  - **koš a archiv** (D7): místo s podřízeným místem mimo koš nejde do
 *    koše, s podřízeným mimo archiv a koš nejde do archivu
 *  - **adresa** (D13) jen u typu s `hasAddress`; u ostatních se při uložení
 *    maže. Normalizace a kontroly dělá {@see PersonValues} — místa jsou
 *    vlastní údaje, nález je proto chyba jako u adresy vlastní firmy
 *
 * Cesta se neukládá (D6) — počítá ji PlaceTree při čtení.
 */
class PlaceDocument extends Document
{
    public const TABLE = 'base_places_places';

    public const STATE_DRAFT = 10;
    public const STATE_ARCHIVED = 70;
    public const STATE_DELETED = 90;

    /** Stavy, ve kterých místo smí být nadřazené (lookup, validace). */
    public const LIVE_STATES = [10, 40, 80];

    /** Sloupce skupiny `address` — typ bez adresy je má vždy NULL. */
    public const ADDRESS_COLUMNS = [
        'street', 'house_number', 'orientation_number', 'city', 'zip', 'country', 'latitude', 'longitude',
    ];

    /** Strop průchodu řetězem nadřazených míst — pojistka proti poškozeným datům. */
    private const MAX_PARENT_DEPTH = 100;

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $types = $this->types();

        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $stored = $id !== null ? $this->loadPlaceRow($id) : null;

        if (trim((string) self::effective($data, $stored, 'name')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        // --- druh a z něj typ ---
        $type = '';
        $kindId = (int) self::effective($data, $stored, 'kind');
        if ($kindId <= 0) {
            $result->addError('kind', 'Druh místa je povinný', 'required');
        } else {
            $kind = $this->loadKindRow($kindId);
            if ($kind === null) {
                $result->addError('kind', 'Druh místa neexistuje', 'not_found');
            } elseif ((int) ($kind['docState'] ?? 0) === self::STATE_DELETED) {
                $result->addError('kind', 'Druh místa je smazaný', 'invalid_state');
            } else {
                $type = (string) ($kind['type'] ?? '');
            }
        }

        // --- nadřazené místo: existence, stav, cyklus, úroveň ---
        $parentId = (int) self::effective($data, $stored, 'parent');
        if ($parentId > 0) {
            if ($id !== null && $parentId === $id) {
                $result->addError('parent', 'Místo nemůže být nadřazené samo sobě.', 'cycle');
            } else {
                $this->validateParent($parentId, $id, $type, $result);
            }
        }

        // --- přímá podřízená místa: úroveň z druhé strany a koš / archiv ---
        $children = $id !== null ? $this->loadChildren($id) : [];
        if ($type !== '') {
            foreach ($children as $child) {
                $childType = (string) ($child['kind_type'] ?? '');
                if ($childType !== '' && !$types->canBeParent($type, $childType)) {
                    $result->addError(
                        'kind',
                        sprintf(
                            'Místo typu „%s“ nemůže být nadřazené místu typu „%s“ (%s) — podřízená místa nejdřív přesuňte.',
                            $types->label($type),
                            $types->label($childType),
                            (string) ($child['name'] ?? ''),
                        ),
                        'level',
                    );
                    break;
                }
            }
        }

        $newState = (int) (self::effective($data, $stored, 'docState') ?? self::STATE_DRAFT);
        $oldState = (int) ($stored['docState'] ?? 0);
        if ($newState !== $oldState && $this->blocksStateChange($newState, $children)) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Místo má podřízená místa — nejdřív je přesuňte nebo vyřaďte.',
                'has_children',
            );
        }

        // --- kód mezi sourozenci ---
        $code = trim((string) self::effective($data, $stored, 'code'));
        if ($code !== '' && $newState !== self::STATE_DELETED) {
            $needle = mb_strtolower($code);
            foreach ($this->loadSiblingCodes($parentId > 0 ? $parentId : null, $id) as $siblingCode) {
                if (mb_strtolower(trim($siblingCode)) === $needle) {
                    $result->addError(
                        'code',
                        'Kód už má jiné místo se stejným nadřazeným místem.',
                        'code_duplicate',
                    );
                    break;
                }
            }
        }

        // --- adresa (jen typ s adresou; ostatním ji beforeSave smaže) ---
        if ($type !== '' && $types->hasAddress($type)) {
            $storedCountry = !array_key_exists('country', $data)
                ? PersonValues::text($stored['country'] ?? null)
                : null;
            PersonDocument::report(
                $result,
                PersonValues::checkAddress($data, $storedCountry, $this->dataSourceCountry()),
                true,
            );
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['name', 'short_name', 'code', 'note'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null) {
                $trimmed = trim((string) $data[$col]);
                $data[$col] = $trimmed === '' && $col !== 'name' ? null : $trimmed;
            }
        }
        if (array_key_exists('parent', $data) && (int) ($data['parent'] ?? 0) <= 0) {
            $data['parent'] = null;
        }

        // Adresa podle typu (D13): typ bez adresy ji nemá nikdy — ani když
        // přišla v payloadu (změna druhu budovy na podlaží adresu maže).
        $types = $this->types();
        $kindId = (int) ($data['kind'] ?? $originalData['kind'] ?? 0);
        $type = $kindId > 0 ? (string) ($this->loadKindRow($kindId)['type'] ?? '') : '';
        if ($type !== '' && !$types->isUnknown($type) && $types->all() !== []) {
            if (!$types->hasAddress($type)) {
                foreach (self::ADDRESS_COLUMNS as $col) {
                    $data[$col] = null;
                }
            } else {
                $data = PersonValues::normalizeAddress(
                    $data,
                    PersonValues::text($originalData['country'] ?? null),
                    $this->dataSourceCountry(),
                );
            }
        }

        $this->applyOrder($data, $originalData);
    }

    /**
     * Pořadí mezi sourozenci: nové místo bez pořadí a místo přesunuté pod
     * jiné nadřazené (bez ruční změny pořadí) jdou na konec sourozenců.
     * Ručně zadané pořadí se nepřepisuje. Formulář posílá u nového záznamu
     * výchozí hodnotu sloupce 0 — bere se jako nezadané.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $originalData
     */
    private function applyOrder(array &$data, ?array $originalData): void
    {
        $parent = array_key_exists('parent', $data)
            ? ($data['parent'] !== null ? (int) $data['parent'] : null)
            : (($originalData['parent'] ?? null) !== null ? (int) $originalData['parent'] : null);

        if ($originalData === null) {
            if ((int) ($data['order_pos'] ?? 0) === 0) {
                $data['order_pos'] = $this->maxSiblingOrder($parent, null) + 1;
            }
            return;
        }

        $oldParent = ($originalData['parent'] ?? null) !== null ? (int) $originalData['parent'] : null;
        $orderChanged = array_key_exists('order_pos', $data)
            && (int) $data['order_pos'] !== (int) ($originalData['order_pos'] ?? 0);
        if ($parent !== $oldParent && !$orderChanged) {
            $data['order_pos'] = $this->maxSiblingOrder($parent, (int) ($originalData['id'] ?? $data['id'] ?? 0)) + 1;
        }
    }

    private function validateParent(int $parentId, ?int $selfId, string $type, ValidationResult $result): void
    {
        $parentRow = $this->loadPlaceRow($parentId);
        if ($parentRow === null) {
            $result->addError('parent', 'Nadřazené místo neexistuje', 'not_found');
            return;
        }
        if (!in_array((int) ($parentRow['docState'] ?? 0), self::LIVE_STATES, true)) {
            $result->addError('parent', 'Nadřazené místo je vyřazené nebo smazané.', 'invalid_state');
            return;
        }
        if ($selfId !== null && $this->chainContains($parentRow, $selfId)) {
            $result->addError(
                'parent',
                'Nadřazené místo je pod tímto místem — vznikl by cyklus.',
                'cycle',
            );
            return;
        }
        $parentType = (string) ($parentRow['kind_type'] ?? '');
        $types = $this->types();
        if ($type !== '' && $parentType !== '' && !$types->canBeParent($parentType, $type)) {
            $result->addError(
                'parent',
                sprintf(
                    'Místo typu „%s“ nemůže být pod místem typu „%s“.',
                    $types->label($type),
                    $types->label($parentType),
                ),
                'level',
            );
        }
    }

    /**
     * Je `$id` v řetězu nadřazených míst začínajícím řádkem `$row`
     * (včetně něj)?
     *
     * @param array<string, mixed>|null $row
     */
    private function chainContains(?array $row, int $id): bool
    {
        $depth = 0;
        while ($row !== null && $depth++ < self::MAX_PARENT_DEPTH) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return true;
            }
            $next = (int) ($row['parent'] ?? 0);
            $row = $next > 0 ? $this->loadPlaceRow($next) : null;
        }
        return false;
    }

    /**
     * D7: do koše nesmí místo s podřízeným mimo koš, do archivu místo
     * s podřízeným mimo archiv a koš.
     *
     * @param list<array<string, mixed>> $children přímá podřízená místa mimo koš
     */
    private function blocksStateChange(int $newState, array $children): bool
    {
        if ($newState === self::STATE_DELETED) {
            return $children !== [];
        }
        if ($newState === self::STATE_ARCHIVED) {
            foreach ($children as $child) {
                if ((int) ($child['docState'] ?? 0) !== self::STATE_ARCHIVED) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Hodnota sloupce po uložení: z payloadu, a když v něm klíč není,
     * z uloženého řádku (částečný payload, např. jen změna stavu).
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $stored
     */
    private static function effective(array $data, ?array $stored, string $column): mixed
    {
        return array_key_exists($column, $data) ? $data[$column] : ($stored[$column] ?? null);
    }

    // --- DB (protected: přepsatelné v testech) ---

    /**
     * Řádek místa s typem jeho druhu (`kind_type`), null = neexistuje.
     *
     * @return array<string, mixed>|null
     */
    protected function loadPlaceRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [p].[id], [p].[name], [p].[code], [p].[kind], [p].[parent], [p].[order_pos],'
            . ' [p].[country], [p].[docState], [k].[type] AS [kind_type]'
            . ' FROM [' . self::TABLE . '] [p]'
            . ' LEFT JOIN [' . PlaceKindDocument::TABLE . '] [k] ON [k].[id] = [p].[kind]'
            . ' WHERE [p].[id] = %i',
            $id,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /**
     * Druh místa (id, type, docState), null = neexistuje.
     *
     * @return array<string, mixed>|null
     */
    protected function loadKindRow(int $kindId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [type], [docState] FROM [' . PlaceKindDocument::TABLE . '] WHERE [id] = %i',
            $kindId,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /**
     * Přímá podřízená místa mimo koš s typem druhu.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadChildren(int $id): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [p].[id], [p].[name], [p].[docState], [k].[type] AS [kind_type]'
            . ' FROM [' . self::TABLE . '] [p]'
            . ' LEFT JOIN [' . PlaceKindDocument::TABLE . '] [k] ON [k].[id] = [p].[kind]'
            . ' WHERE [p].[parent] = %i AND [p].[docState] <> %i'
            . ' ORDER BY [p].[order_pos] ASC, [p].[id] ASC',
            $id,
            self::STATE_DELETED,
        );
        return array_map(static fn (iterable $row): array => iterator_to_array($row), $rows);
    }

    /**
     * Kódy sourozenců mimo koš — míst se stejným nadřazeným místem
     * (`null` = nejvyšší úroveň), bez místa `$excludeId`.
     *
     * @return list<string>
     */
    protected function loadSiblingCodes(?int $parentId, ?int $excludeId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [code] FROM [' . self::TABLE . ']'
            . ' WHERE [code] IS NOT NULL AND [docState] <> %i AND [id] <> %i AND '
            . ($parentId !== null ? '[parent] = %i' : '[parent] IS NULL'),
            ...array_merge([self::STATE_DELETED, $excludeId ?? 0], $parentId !== null ? [$parentId] : []),
        );
        return array_map(static fn (iterable $row): string => (string) iterator_to_array($row)['code'], $rows);
    }

    /** Nejvyšší pořadí mezi sourozenci mimo koš, 0 = žádný sourozenec. */
    protected function maxSiblingOrder(?int $parentId, ?int $excludeId): int
    {
        if ($this->db === null) {
            return 0;
        }
        return (int) $this->db->fetchSingle(
            'SELECT MAX([order_pos]) FROM [' . self::TABLE . ']'
            . ' WHERE [docState] <> %i AND [id] <> %i AND '
            . ($parentId !== null ? '[parent] = %i' : '[parent] IS NULL'),
            ...array_merge([self::STATE_DELETED, $excludeId ?? 0], $parentId !== null ? [$parentId] : []),
        );
    }

    /** Země zdroje dat — fallback pro pravidla PSČ (místo osobu nemá). */
    protected function dataSourceCountry(): string
    {
        return $this->dsConfig?->getCountry() ?? 'cz';
    }

    protected function types(): PlaceTypes
    {
        return new PlaceTypes($this->config);
    }
}
