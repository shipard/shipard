<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Evidenční jednotka registrace (economy_sales_registration_units, #171 D3).
 *
 * Číslo jednotky odpovídá masce typu evidence nadřízené registrace
 * a u jedné registrace je jen jednou (unikátní index — tady jen srozumitelná
 * hláška místo chyby databáze). Smazání jednotky, která je výchozí
 * jednotkou registrace, registraci výchozí jednotku odebere.
 */
class UnitDocument extends Document
{
    public const string TABLE = 'economy_sales_registration_units';

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $original = $id !== null ? $this->loadUnitRow($id) : null;
        $effective = static fn(string $column): mixed
            => array_key_exists($column, $data) ? $data[$column] : ($original[$column] ?? null);

        $registrationId = (int) ($effective('registration') ?? 0);
        if ($registrationId <= 0) {
            $result->addError('registration', 'Jednotka musí patřit registraci', 'required');
            return $result;
        }
        if ($original !== null && $registrationId !== (int) ($original['registration'] ?? 0)) {
            $result->addError('registration', 'Jednotku nejde přesunout pod jinou registraci', 'invalid');
            return $result;
        }

        $unitId = trim((string) $effective('unit_id'));
        if ($unitId === '') {
            $result->addError('unit_id', 'Číslo jednotky je povinné', 'required');
            return $result;
        }

        $typeId = $this->registrationType($registrationId);
        if ($typeId === null) {
            $result->addError('registration', 'Registrace jednotky neexistuje', 'invalid');
            return $result;
        }
        $type = $this->types()->get($typeId);
        if ($type !== null && !$type->isValidUnitId($unitId)) {
            $result->addError(
                'unit_id',
                sprintf('Číslo jednotky „%s“ neodpovídá tvaru, který předepisuje typ evidence %s.', $unitId, $type->name),
                'invalid_format',
            );
        }

        if ($this->countUnits($registrationId, $unitId, $id) > 0) {
            $result->addError('unit_id', 'Jednotka s tímto číslem už u registrace je.', 'duplicate');
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        if (array_key_exists('unit_id', $data) && $data['unit_id'] !== null) {
            $data['unit_id'] = trim((string) $data['unit_id']);
        }
        if (array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            $data['name'] = $name !== '' ? $name : null;
        }
    }

    public function afterDelete(array $data): void
    {
        if (!empty($data['id']) && !empty($data['registration'])) {
            $this->clearDefaultUnit((int) $data['registration'], (int) $data['id']);
        }
    }

    protected function types(): SalesRegistrationTypes
    {
        return SalesRegistrationTypes::fromConfig($this->config);
    }

    /** Uložený řádek jednotky, null = nová (přepsatelné v testech). */
    protected function loadUnitRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', self::TABLE, $id);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Typ evidence registrace, null = registrace neexistuje (přepsatelné v testech). */
    protected function registrationType(int $registrationId): ?string
    {
        if ($this->db === null) {
            return '';
        }
        $type = $this->db->fetchSingle(
            'SELECT [type] FROM %n WHERE [id] = %i',
            RegistrationDocument::TABLE,
            $registrationId,
        );
        return $type === null || $type === false ? null : (string) $type;
    }

    /** Počet jiných jednotek registrace s tímto číslem (přepsatelné v testech). */
    protected function countUnits(int $registrationId, string $unitId, ?int $exceptId): int
    {
        if ($this->db === null) {
            return 0;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n WHERE [registration] = %i AND [unit_id] = %s AND [id] <> %i',
            self::TABLE,
            $registrationId,
            $unitId,
            $exceptId ?? 0,
        );
    }

    /** Registrace přijde o výchozí jednotku, kterou byla smazaná jednotka (přepsatelné v testech). */
    protected function clearDefaultUnit(int $registrationId, int $unitId): void
    {
        $this->db?->query(
            'UPDATE %n SET [default_unit] = NULL WHERE [id] = %i AND [default_unit] = %i',
            RegistrationDocument::TABLE,
            $registrationId,
            $unitId,
        );
    }
}
