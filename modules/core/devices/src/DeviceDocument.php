<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;

/**
 * Zařízení (core_devices_devices, #177 U18).
 *
 * Vznik i zneplatnění záznamu patří `DeviceRegistrationService`, která
 * zapisuje přímo, mimo gateway (registrace vkládá řádek, zneplatnění je
 * atomický podmíněný UPDATE) — přes dokumentový lifecycle jde jen upravit
 * nastavení aktivního zařízení. Zneplatnění je konečné: zneplatněné
 * zařízení je jen ke čtení a stav se tudy nemění nikdy.
 *
 * Systémové sloupce (identita, klíč, časy) se z formuláře ani z CRUD
 * nezapíšou; tady je pojistka pro každého dalšího volajícího gateway.
 */
class DeviceDocument extends Document
{
    public const string TABLE = 'core_devices_devices';

    public const int STATE_ACTIVE = 40;
    public const int STATE_REVOKED = 70;

    /** `mainState` stavů z `core.devices.docStates` — služba registrace zapisuje mimo gateway. */
    public const int STATE_MAIN_ACTIVE = 1;
    public const int STATE_MAIN_REVOKED = 2;

    public const int NAME_MAX_LENGTH = 100;
    public const int AUTO_LOCK_MAX_MINUTES = 1440;

    /** Sloupce, které nesmí změnit nikdo kromě registrace a podepsaných volání. */
    private const array IMMUTABLE_COLUMNS = [
        'device_id', 'platform', 'public_key', 'key_algorithm', 'key_storage',
        'registered_by', 'registered_at',
    ];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Zařízení vzniká jen registrací z nativní aplikace — založit ho ručně nelze',
                'system_managed',
            );
            return $result;
        }

        $original = $this->loadDeviceRow($id);
        if ($original === null) {
            return $result;
        }

        $oldState = (int) ($original['docState'] ?? self::STATE_ACTIVE);
        $newState = array_key_exists('docState', $data) ? (int) $data['docState'] : $oldState;

        if ($oldState === self::STATE_REVOKED) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Zneplatněné zařízení nelze měnit — zaregistrujte zařízení znovu',
                'revoked',
            );
            return $result;
        }
        if ($newState !== $oldState) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Stav zařízení nelze změnit ručně — použijte akci Zneplatnit',
                'state_locked',
            );
            return $result;
        }

        foreach (self::IMMUTABLE_COLUMNS as $column) {
            if (array_key_exists($column, $data)
                && self::normalize($data[$column]) !== self::normalize($original[$column] ?? null)) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    'Údaje z registrace zařízení nelze měnit',
                    'immutable',
                );
                return $result;
            }
        }

        if (array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                $result->addError('name', 'Název zařízení je povinný', 'required');
            } elseif (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                $result->addError(
                    'name',
                    sprintf('Název zařízení smí mít nejvýš %d znaků', self::NAME_MAX_LENGTH),
                    'too_long',
                );
            }
        }

        if (array_key_exists('mode', $data) && !DeviceModes::fromConfig($this->config)->isValid((string) $data['mode'])) {
            $result->addError('mode', 'Neznámý režim zařízení', 'invalid');
        }

        if (array_key_exists('auto_lock_minutes', $data)
            && $data['auto_lock_minutes'] !== null && $data['auto_lock_minutes'] !== '') {
            $minutes = filter_var($data['auto_lock_minutes'], FILTER_VALIDATE_INT);
            if ($minutes === false || $minutes < 1 || $minutes > self::AUTO_LOCK_MAX_MINUTES) {
                $result->addError(
                    'auto_lock_minutes',
                    sprintf(
                        'Automatické zamčení je celé číslo od 1 do %d minut; prázdné pole zamčení vypne',
                        self::AUTO_LOCK_MAX_MINUTES,
                    ),
                    'out_of_range',
                );
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $this->trackStateChange($data, $originalData);

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $data['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('auto_lock_minutes', $data)) {
            $minutes = $data['auto_lock_minutes'];
            $data['auto_lock_minutes'] = ($minutes === null || $minutes === '') ? null : (int) $minutes;
        }
    }

    public function beforeDelete(array $data): void
    {
        throw new \LogicException('Devices are never deleted — revoke the device instead');
    }

    /** Uložený řádek zařízení, null = neexistuje (přepsatelné v testech). */
    protected function loadDeviceRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', self::TABLE, $id);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    private static function normalize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return $value === null ? '' : (string) $value;
    }
}
