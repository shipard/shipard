<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;

/**
 * Registrace k evidenci tržeb (economy_sales_registration_registrations,
 * #171 D3, D10, D16).
 *
 *  - typ je z registru {@see SalesRegistrationTypes} (data země × engine)
 *    a po nahrání certifikátu ho nejde změnit — certifikát patří typu;
 *  - identifikátor poplatníka (systémový, z certifikátu) odpovídá masce typu;
 *  - výchozí evidenční jednotka patří této registraci;
 *  - mezi živými registracemi (Koncept, V pořádku, V opravě) je dvojice
 *    (typ, poplatník) jen jednou. Registrace různých typů a různých
 *    poplatníků vedle sebe žít smí (D16), archiv dvojici nést smí taky.
 *
 * Uložení přichází i jako částečná data (jen změněné sloupce), proto se
 * pravidla počítají nad uloženým řádkem doplněným o příchozí hodnoty.
 */
class RegistrationDocument extends Document
{
    public const string TABLE = 'economy_sales_registration_registrations';
    public const string MODES_CFG = 'economy.salesRegistration.modes';

    public const string MODE_PRODUCTION = 'production';
    public const string MODE_VERIFICATION = 'verification';
    public const string MODE_PLAYGROUND = 'playground';
    public const array MODES = [self::MODE_PRODUCTION, self::MODE_VERIFICATION, self::MODE_PLAYGROUND];

    public const int STATE_DRAFT = 10;
    public const int STATE_CONFIRMED = 40;

    /** Stavy, ve kterých registrace žije (mimo archiv a koš). */
    public const array LIVE_STATES = [10, 40, 80];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $original = $id !== null ? $this->loadRegistrationRow($id) : null;
        $effective = static fn(string $column): mixed
            => array_key_exists($column, $data) ? $data[$column] : ($original[$column] ?? null);

        if (trim((string) $effective('name')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $typeId = trim((string) $effective('type'));
        $originalType = trim((string) ($original['type'] ?? ''));
        $taxpayerId = trim((string) $effective('taxpayer_id'));
        $type = $typeId !== '' ? $this->types()->get($typeId) : null;

        if ($typeId === '') {
            $result->addError('type', 'Typ evidence je povinný', 'required');
        } elseif ($original !== null && $typeId !== $originalType && trim((string) ($original['taxpayer_id'] ?? '')) !== '') {
            $result->addError(
                'type',
                'Typ evidence nejde změnit — registrace už má nahraný certifikát tohoto typu.',
                'typeLocked',
            );
        } elseif ($type === null && $typeId !== $originalType) {
            // Uložený typ, který ze zdroje dat zmizel (vypnutý modul země),
            // úpravu ostatních polí neblokuje; nově ho zvolit nejde.
            $result->addError('type', 'Neznámý typ evidence', 'invalid');
        }

        $mode = (string) ($effective('mode') ?? self::MODE_VERIFICATION);
        if (!in_array($mode, self::MODES, true)) {
            $result->addError('mode', 'Neznámý režim registrace', 'invalid');
        }

        if ($taxpayerId !== '' && $type !== null && !$type->isValidTaxpayerId($taxpayerId)) {
            $result->addError(
                ValidationError::FIELD_FORM,
                sprintf('%s „%s“ neodpovídá tvaru, který typ evidence předepisuje.', $type->taxpayerIdLabel, $taxpayerId),
                'invalid_format',
            );
        }

        $validFrom = self::dateOf($effective('valid_from'));
        $validTo = self::dateOf($effective('valid_to'));
        if ($validFrom !== '' && $validTo !== '' && $validFrom > $validTo) {
            $result->addError('valid_to', 'Platnost do nesmí být dříve než platnost od.', 'invalid_range');
        }

        $defaultUnit = (int) ($effective('default_unit') ?? 0);
        if ($defaultUnit > 0 && ($id === null || $this->unitRegistration($defaultUnit) !== $id)) {
            $result->addError(
                'default_unit',
                'Výchozí evidenční jednotka musí být jednotkou této registrace.',
                'invalid',
            );
        }

        $state = (int) ($effective('docState') ?? self::STATE_DRAFT);
        if ($typeId !== '' && $taxpayerId !== '' && in_array($state, self::LIVE_STATES, true)
            && $this->countLiveRegistrations($typeId, $taxpayerId, $id) > 0
        ) {
            $result->addError(
                ValidationError::FIELD_FORM,
                sprintf(
                    'Pro poplatníka %s už existuje jiná registrace tohoto typu evidence — nejdřív ji archivujte.',
                    $taxpayerId,
                ),
                'duplicate',
            );
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $this->trackStateChange($data, $originalData);

        foreach (['name', 'type'] as $column) {
            if (array_key_exists($column, $data) && $data[$column] !== null) {
                $data[$column] = trim((string) $data[$column]);
            }
        }
        // Prázdný výběr a prázdné datum = bez hodnoty.
        foreach (['valid_from', 'valid_to', 'default_unit', 'notice'] as $column) {
            if (array_key_exists($column, $data) && ($data[$column] === '' || $data[$column] === 0 || $data[$column] === '0')) {
                $data[$column] = null;
            }
        }
    }

    /** Datum sloupce jako `Y-m-d` (formulář posílá text, databáze objekt); bez hodnoty prázdný řetězec. */
    public static function dateOf(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return is_scalar($value) ? substr(trim((string) $value), 0, 10) : '';
    }

    protected function types(): SalesRegistrationTypes
    {
        return SalesRegistrationTypes::fromConfig($this->config);
    }

    /** Uložený řádek registrace, null = nová (přepsatelné v testech). */
    protected function loadRegistrationRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', self::TABLE, $id);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Id registrace, které jednotka patří, nebo null (přepsatelné v testech). */
    protected function unitRegistration(int $unitId): ?int
    {
        if ($this->db === null) {
            return null;
        }
        $registration = $this->db->fetchSingle(
            'SELECT [registration] FROM %n WHERE [id] = %i',
            UnitDocument::TABLE,
            $unitId,
        );
        return $registration === null || $registration === false ? null : (int) $registration;
    }

    /** Počet jiných živých registrací téhož typu a poplatníka (přepsatelné v testech). */
    protected function countLiveRegistrations(string $type, string $taxpayerId, ?int $exceptId): int
    {
        if ($this->db === null) {
            return 0;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n WHERE [type] = %s AND [taxpayer_id] = %s AND [id] <> %i AND [docState] IN %in',
            self::TABLE,
            $type,
            $taxpayerId,
            $exceptId ?? 0,
            self::LIVE_STATES,
        );
    }
}
