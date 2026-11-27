<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Numbering\NumberPattern;

/**
 * Druh smlouvy (facility_buildings_contract_kinds, #174 D4, D15, D18).
 *
 * Pravidla:
 *   - název a typ jsou povinné; typ musí být z `facility.buildings.contractTypes`;
 *   - typ jde měnit jen v Konceptu a jen dokud druh nepoužívá žádná smlouva
 *     — smlouva si typ z druhu denormalizuje a řídí jím povolená místa;
 *   - číslování: vzorec přes {@see NumberPattern::validate()} (`%C` vyžaduje
 *     kód druhu), pořadí `%3`–`%6` je povinné, roční restart vyžaduje rok
 *     ve vzorci (jinak by se čísla po roce opakovala — číslo smlouvy je
 *     unikátní) a kód nesmí mít jiný druh mimo koš;
 *   - do koše jde jen druh, který nepoužívá žádná smlouva mimo koš;
 *     archivovat jde vždy.
 *
 * Přechody stavu jdou přes dokument (`stateTransitionsRunDocumentHooks`
 * na tabulce), takže pravidla platí i pro tlačítka stavů.
 */
class ContractKindDocument extends Document
{
    public const TABLE = 'facility_buildings_contract_kinds';
    public const RESET_SCOPES_CFG_ITEM = 'facility.buildings.resetScopes';

    public const STATE_DRAFT = 10;
    public const STATE_CONFIRMED = 40;
    public const STATE_DELETED = 90;

    /** Stavy, ve kterých jde druh vybrat na nové smlouvě. */
    public const LIVE_STATES = [10, 40, 80];

    public const RESET_CONTINUOUS = 'continuous';
    public const RESET_YEARLY = 'yearly';
    public const RESET_SCOPES = [self::RESET_CONTINUOUS, self::RESET_YEARLY];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $types = $this->types();
        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $original = $id !== null ? $this->loadKindRow($id) : null;

        if (trim((string) ($data['name'] ?? '')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $type = trim((string) ($data['type'] ?? ''));
        if ($type === '') {
            $result->addError('type', 'Typ smlouvy je povinný', 'required');
        } elseif ($types->isUnknown($type)) {
            $result->addError('type', 'Neznámý typ smlouvy', 'invalid');
        }

        if ($original !== null && $type !== '' && $type !== (string) ($original['type'] ?? '')) {
            if ((int) ($original['docState'] ?? self::STATE_DRAFT) !== self::STATE_DRAFT) {
                $result->addError('type', 'Typ potvrzeného druhu nejde změnit — smlouvy druhu by přestaly odpovídat povoleným místům.', 'typeLocked');
            } elseif ($this->countContractsUsingKind((int) $id) > 0) {
                $result->addError('type', 'Typ druhu, který už používají smlouvy, nejde změnit — smlouvy by přestaly odpovídat povoleným místům.', 'typeLocked');
            }
        }

        $this->validateNumbering($data, $id, $result);

        $newState = (int) ($data['docState'] ?? $original['docState'] ?? self::STATE_DRAFT);
        $oldState = (int) ($original['docState'] ?? 0);
        if ($id !== null
            && $newState === self::STATE_DELETED
            && $oldState !== self::STATE_DELETED
            && $this->countContractsUsingKind($id) > 0
        ) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Druh používají smlouvy — nejdřív jim nastavte jiný druh, nebo druh jen ukončete.',
                'in_use',
            );
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function validateNumbering(array $data, ?int $id, ValidationResult $result): void
    {
        $pattern = trim((string) ($data['number_pattern'] ?? ''));
        $code = trim((string) ($data['number_code'] ?? ''));

        $patternValid = true;
        foreach (NumberPattern::validate($pattern, $code !== '' ? $code : null) as $problem) {
            $patternValid = false;
            $column = $problem['target'] === NumberPattern::TARGET_CODE ? 'number_code' : 'number_pattern';
            $result->addError($column, self::patternMessage($problem['code'], $problem['message']), $problem['code']);
        }

        $scope = (string) ($data['reset_scope'] ?? self::RESET_YEARLY);
        if (!in_array($scope, self::RESET_SCOPES, true)) {
            $result->addError('reset_scope', 'Neznámý restart počítadla', 'invalid');
        }

        if ($patternValid) {
            if (preg_match('/%[3-6]/', $pattern) !== 1) {
                $result->addError('number_pattern', 'Vzorec musí obsahovat pořadí — %3 až %6.', 'sequence_required');
            } elseif ($scope === self::RESET_YEARLY && preg_match('/%[yY]/', $pattern) !== 1) {
                $result->addError(
                    'number_pattern',
                    'Při ročním restartu musí vzorec obsahovat rok (%y nebo %Y) — jinak by se čísla po roce opakovala.',
                    'year_required',
                );
            }
        }

        if ($code !== '' && $this->findCodeOwner($code, $id) !== null) {
            $result->addError('number_code', "Kód {$code} už má jiný druh smlouvy.", 'duplicate');
        }
    }

    /** Česká hláška k nálezu {@see NumberPattern::validate()}; neznámý kód nechá zprávu enginu. */
    private static function patternMessage(string $code, string $fallback): string
    {
        return match ($code) {
            'required' => 'Vzorec čísla je povinný',
            'required_for_pattern' => 'Vzorec obsahuje %C — doplň kód druhu.',
            'unknown_placeholder' => 'Vzorec obsahuje neznámý zástupný znak — povolené jsou %C, %y, %Y a %3 až %6.',
            default => $fallback,
        };
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['name', 'number_pattern'] as $col) {
            if (isset($data[$col])) {
                $data[$col] = trim((string) $data[$col]);
            }
        }
        foreach (['notice', 'number_code'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null) {
                $trimmed = trim((string) $data[$col]);
                $data[$col] = $trimmed === '' ? null : $trimmed;
            }
        }
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    /** Uložený řádek druhu (id, type, docState), null = neexistuje. */
    protected function loadKindRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT [id], [type], [docState] FROM [' . self::TABLE . '] WHERE [id] = %i', $id);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Počet smluv mimo koš, které druh mají. */
    protected function countContractsUsingKind(int $kindId): int
    {
        if ($this->db === null) {
            return 0;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM [' . ContractDocument::TABLE . '] WHERE [kind] = %i AND [docState] <> %i',
            $kindId,
            ContractDocument::STATE_DELETED,
        );
    }

    /** Id jiného druhu mimo koš se stejným kódem, null = volný. */
    protected function findCodeOwner(string $code, ?int $excludeId): ?int
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [' . self::TABLE . '] WHERE [number_code] = %s AND [id] <> %i AND [docState] <> %i LIMIT 1',
            $code,
            $excludeId ?? 0,
            self::STATE_DELETED,
        );
        return $row === null || $row === false ? null : (int) $row['id'];
    }

    protected function types(): ContractTypes
    {
        return new ContractTypes($this->config);
    }
}
