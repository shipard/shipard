<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;

/**
 * Periferie zařízení (core_devices_peripherals, #177 U19, U23).
 *
 * Řádky zakládá a aktualizuje jen `PeripheralSync` (přímý zápis podle
 * hlášení zařízení). Přes dokumentový lifecycle jde změnit jediná věc —
 * `role`: musí být v číselníku, sedět na druh periferie a role označená
 * `single` smí být na jednom zařízení nejvýš jednou. Periferie
 * zneplatněného zařízení je jen ke čtení.
 */
class PeripheralDocument extends Document
{
    public const string TABLE = 'core_devices_peripherals';

    /** Sloupce, které smí uložení změnit. */
    private const array MUTABLE_COLUMNS = ['id', 'role'];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $id = (int) ($data['id'] ?? 0);
        $original = $id > 0 ? $this->loadPeripheralRow($id) : null;
        if ($original === null) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Periferie hlásí zařízení samo — založit ji ručně nelze',
                'system_managed',
            );
            return $result;
        }

        foreach ($data as $column => $value) {
            if (in_array($column, self::MUTABLE_COLUMNS, true) || !array_key_exists($column, $original)) {
                continue;
            }
            if (self::normalize($value) !== self::normalize($original[$column])) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    'U periferie lze změnit jen roli — ostatní údaje hlásí zařízení',
                    'immutable',
                );
                return $result;
            }
        }

        $deviceId = (int) ($original['device'] ?? 0);
        if ($this->deviceState($deviceId) !== DeviceDocument::STATE_ACTIVE) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Zařízení je zneplatněné — role jeho periferií už nelze měnit',
                'revoked',
            );
            return $result;
        }

        if (!array_key_exists('role', $data)) {
            return $result;
        }
        $role = trim((string) ($data['role'] ?? ''));
        if ($role === '') {
            return $result;
        }

        $roles = $this->roles();
        if ($roles->isUnknown($role)) {
            $result->addError('role', 'Neznámá role periferie', 'invalid');
            return $result;
        }
        if (!$roles->allowsKind($role, (string) ($original['kind'] ?? ''))) {
            $result->addError(
                'role',
                sprintf('Roli „%s“ nejde přiřadit periferii tohoto druhu', $roles->label($role)),
                'role_kind_mismatch',
            );
            return $result;
        }
        if ($roles->isSingle($role) && $this->countWithRole($deviceId, $role, $id) > 0) {
            $result->addError(
                'role',
                sprintf('Roli „%s“ už má jiná periferie tohoto zařízení — nejdřív ji tam odeberte', $roles->label($role)),
                'duplicate_role',
            );
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        if (array_key_exists('role', $data)) {
            $role = trim((string) ($data['role'] ?? ''));
            $data['role'] = $role !== '' ? $role : null;
        }
    }

    public function beforeDelete(array $data): void
    {
        throw new \LogicException('Peripherals are reported by the device — they are never deleted by hand');
    }

    protected function roles(): PeripheralRoles
    {
        return new PeripheralRoles($this->config);
    }

    /** Uložený řádek periferie, null = neexistuje (přepsatelné v testech). */
    protected function loadPeripheralRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', self::TABLE, $id);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Stav zařízení periferie, null = zařízení neexistuje (přepsatelné v testech). */
    protected function deviceState(int $deviceId): ?int
    {
        if ($this->db === null) {
            return DeviceDocument::STATE_ACTIVE;
        }
        $state = $this->db->fetchSingle(
            'SELECT [docState] FROM %n WHERE [id] = %i',
            DeviceDocument::TABLE,
            $deviceId,
        );
        return $state === null || $state === false ? null : (int) $state;
    }

    /** Počet jiných periferií zařízení s touto rolí (přepsatelné v testech). */
    protected function countWithRole(int $deviceId, string $role, int $exceptId): int
    {
        if ($this->db === null) {
            return 0;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n WHERE [device] = %i AND [role] = %s AND [id] <> %i',
            self::TABLE,
            $deviceId,
            $role,
            $exceptId,
        );
    }

    private static function normalize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_array($value)) {
            return (string) json_encode($value);
        }
        return $value === null ? '' : (string) $value;
    }
}
