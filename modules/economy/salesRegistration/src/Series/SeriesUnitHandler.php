<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Series;

use Shipard\Core\Document\AbstractDocumentEventHandler;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Module\Economy\SalesRegistration\Texts;
use Shipard\Module\Economy\SalesRegistration\UnitDocument;

/**
 * Číselná řada s evidenční jednotkou (#171 D4, D21): kód řady jde do zprávy
 * jako označení pokladního zařízení (`id_pokl`), takže musí projít maskou
 * typu evidence — kód řady má až 40 znaků a volnější sadu, maska EET 2.0
 * jen 20. Kontroluje se při uložení řady (přiřazení jednotky i změna kódu
 * řady s jednotkou), ne až při odeslání tržby.
 *
 * Handler `beforeSave` chybu k poli vrátit neumí; `\DomainException` dá
 * formuláři odpověď 422 s hláškou nad poli (stejně jako
 * `DocsHeadsVatPeriodHandler`). Kód nové řady v tuhle chvíli už existuje —
 * `NumberSeriesDocument::beforeSave()` ho doplňuje dřív.
 */
class SeriesUnitHandler extends AbstractDocumentEventHandler
{
    public const string TABLE = 'docs_core_number_series';

    public function onBeforeSave(string $tableId, array &$data, ?array $originalData): void
    {
        if ($tableId !== self::TABLE) {
            return;
        }
        $column = SeriesUnitFormTab::COLUMN;
        $effective = static fn(string $key): mixed
            => array_key_exists($key, $data) ? $data[$key] : ($originalData[$key] ?? null);

        $unit = (int) ($effective($column) ?? 0);
        if ($unit <= 0) {
            // Prázdný výběr = bez jednotky (formulář posílá prázdný řetězec).
            if (array_key_exists($column, $data)) {
                $data[$column] = null;
            }
            return;
        }

        $code = trim((string) ($effective('code') ?? ''));
        $unchanged = $originalData !== null
            && (int) ($originalData[$column] ?? 0) === $unit
            && trim((string) ($originalData['code'] ?? '')) === $code;
        if ($unchanged) {
            return;
        }

        $t = Texts::of($this->config);
        $typeId = $this->unitTypeId($unit);
        if ($typeId === null) {
            throw new \DomainException($t->t('series.unitUnknown', 'The selected registration unit does not exist.'));
        }
        if ($code === '') {
            throw new \DomainException($t->t(
                'series.codeRequired',
                'A series with a registration unit needs a code — it is sent as the cash register identifier.',
            ));
        }
        $type = $this->type($typeId);
        if ($type !== null && !$type->isValidCashRegisterId($code)) {
            $hint = trim((string) $type->cashRegisterIdHint);
            throw new \DomainException($t->t(
                'series.codeInvalid',
                'The series code "{code}" cannot be used as a cash register identifier of {type}{hint}. Change the code or remove the registration unit.',
                ['code' => $code, 'type' => $type->name, 'hint' => $hint !== '' ? ' (' . $hint . ')' : ''],
            ));
        }
    }

    /** Id typu evidence registrace, které jednotka patří; null = jednotka neexistuje (přepsatelné v testech). */
    protected function unitTypeId(int $unitId): ?string
    {
        if ($this->db === null) {
            return null;
        }
        $type = $this->db->fetchSingle(
            'SELECT r.[type] FROM %n u JOIN %n r ON r.[id] = u.[registration] WHERE u.[id] = %i',
            UnitDocument::TABLE,
            RegistrationDocument::TABLE,
            $unitId,
        );
        return is_string($type) ? $type : null;
    }

    protected function type(string $typeId): ?SalesRegistrationType
    {
        return SalesRegistrationTypes::fromConfig($this->config)->get($typeId);
    }
}
