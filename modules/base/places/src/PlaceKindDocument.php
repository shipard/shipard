<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;

/**
 * Druh místa (base_places_kinds, #169 D2, D14, D15, D18).
 *
 * Typ druhu je po prvním potvrzení jen ke čtení: řídí úrovně a adresu míst,
 * která druh mají, takže by jeho změna rozbila existující strom.
 * „Potvrzený“ = uložený řádek už není v Konceptu (vzor druhů zakázek).
 *
 * Druh, který používá místo mimo koš, nejde do koše (D18). Do archivu jít
 * smí — místa si druh ponechají, jen ho formulář místa nově nenabízí.
 */
class PlaceKindDocument extends Document
{
    public const TABLE = 'base_places_kinds';
    public const ICONS_CFG_ITEM = 'base.places.icons';
    public const STATE_DRAFT = 10;
    public const STATE_DELETED = 90;

    /** Stavy, ve kterých se druh nabízí novým místům. */
    public const LIVE_STATES = [10, 40, 80];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $types = $this->types();

        if (trim((string) ($data['name'] ?? '')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $type = (string) ($data['type'] ?? '');
        if ($type === '') {
            $result->addError('type', 'Typ místa je povinný', 'required');
        } elseif ($types->isUnknown($type)) {
            $result->addError('type', 'Neznámý typ místa', 'invalid');
        }

        $icon = trim((string) ($data['icon'] ?? ''));
        if ($icon !== '' && !$this->isKnownIcon($icon)) {
            $result->addError('icon', 'Ikona není ze sady ikon míst', 'invalid');
        }

        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $original = $id !== null ? $this->loadKindRow($id) : null;
        if ($original !== null
            && $type !== ''
            && (int) ($original['docState'] ?? self::STATE_DRAFT) !== self::STATE_DRAFT
            && $type !== (string) ($original['type'] ?? '')
        ) {
            $result->addError(
                'type',
                'Typ potvrzeného druhu nejde změnit — místa druhu by přestala odpovídat úrovním.',
                'typeLocked',
            );
        }

        $newState = (int) ($data['docState'] ?? $original['docState'] ?? self::STATE_DRAFT);
        $oldState = (int) ($original['docState'] ?? 0);
        if ($id !== null
            && $newState === self::STATE_DELETED
            && $oldState !== self::STATE_DELETED
            && $this->countPlacesUsingKind($id) > 0
        ) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Druh používají místa — nejdřív jim nastavte jiný druh, nebo druh jen ukončete.',
                'in_use',
            );
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $data['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('icon', $data) && trim((string) ($data['icon'] ?? '')) === '') {
            $data['icon'] = null;
        }
    }

    /** Bez zkompilované konfigurace se ikona nehlídá (degradace, ne crash). */
    private function isKnownIcon(string $icon): bool
    {
        $icons = $this->config?->cfgItem(self::ICONS_CFG_ITEM);
        return !is_array($icons) || $icons === [] || isset($icons[$icon]);
    }

    /** Uložený řádek druhu, null = nový druh (přepsatelné v testech). */
    protected function loadKindRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT [id], [type], [docState] FROM [' . self::TABLE . '] WHERE [id] = %i', $id);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Počet míst mimo koš, která druh mají (přepsatelné v testech). */
    protected function countPlacesUsingKind(int $kindId): int
    {
        if ($this->db === null) {
            return 0;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM [' . PlaceDocument::TABLE . '] WHERE [kind] = %i AND [docState] <> %i',
            $kindId,
            PlaceDocument::STATE_DELETED,
        );
    }

    protected function types(): PlaceTypes
    {
        return new PlaceTypes($this->config);
    }
}
