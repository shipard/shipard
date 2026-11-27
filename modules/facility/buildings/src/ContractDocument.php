<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\I18n\DisplayDate;
use Shipard\Core\Numbering\NumberContext;
use Shipard\Core\Numbering\NumberPattern;
use Shipard\Core\Numbering\SequenceCounter;
use Shipard\Core\Numbering\SequenceStorage;
use Shipard\Module\Base\Places\PlaceDocument;
use Shipard\Module\Base\Places\PlaceKindDocument;
use Shipard\Module\Base\Places\PlaceTypes;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingSettings;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingSettingsResolver;

/**
 * Smlouva (facility_buildings_contracts, #174 D6, D7, D20–D22).
 *
 * Pravidla:
 *   - název a druh jsou povinné; druh jde měnit jen v Konceptu — typ, číslo
 *     a rok čítače jsou od něj odvozené. Typ se z druhu denormalizuje při
 *     každém uložení konceptu a přepíše, co poslal klient;
 *   - ukončení nesmí být před zahájením;
 *   - místa (child tabulka, klíč `places`) se řídí vlastnostmi typu
 *     ({@see ContractTypes}): typ bez míst žádná mít nesmí, jinak jen místa
 *     povolených typů, žádné dvakrát a žádné v koši. Chyba jde na
 *     `places.N.place` se jménem místa;
 *   - potvrzení (přechod do V pořádku) navíc vyžaduje nájemce, zahájení
 *     a u typu s povinnými místy aspoň jedno místo;
 *   - překryv (D7): místo, které je v jiné smlouvě V pořádku nebo V opravě
 *     s překrývající se dobou, je **varování**, ne chyba — sdílená místa
 *     a přechod nájemců jsou běžné. Prázdné ukončení = doba neurčitá na obou
 *     stranách porovnání; jeden nález na místo;
 *   - číslo (D15) přidělí přechod z Konceptu do V pořádku přes
 *     SequenceCounter nad čítači smluv — čítač per druh a kalendářní rok
 *     zahájení (u průběžného čítače bez roku). Převzaté (importované) číslo
 *     zůstává a potvrzená smlouva se do Konceptu nevrací;
 *   - přechod do Ukončená / Zrušená doplní prázdné datum ukončení dneškem;
 *   - fyzicky smazat jde jen koncept, i z koše (do koše vede sada stavů jen
 *     z Konceptu); s ním odejdou i vazby míst a řádky předpisu.
 *
 * Fakturace (fáze 2, #174 D29, D32, D36):
 *   - fakturační předpis jako u zakázky — periodicita, fakturovat od, text
 *     dokladu a přepisy výchozích hodnot druhu (`inv_*`, prázdné = z druhu;
 *     efektivní hodnoty skládá jen InvoicingSettingsResolver). Periodicita
 *     je nepovinná — smlouva bez ní se nefakturuje;
 *   - prázdné „fakturovat od“ se doplní zahájením a dřív než zahájení být
 *     nesmí; měna prázdná = domácí měna zdroje dat;
 *   - potvrzení smlouvy **s periodicitou** vyžaduje „fakturovat od“,
 *     efektivní typ dokladu a řadu dokladů (smlouva → druh), aspoň jeden
 *     řádek předpisu a platnost každého řádku uvnitř platnosti smlouvy;
 *   - šablona řádků druhu (D32) se zkopíruje do konceptu bez řádků **jen
 *     při vložení nebo změně druhu** — uživatel, který řádky smaže a smlouvu
 *     uloží, je znovu nedostane. Kopie běží v `afterPersist`, tedy
 *     v transakci uložení; že jde o vložení / změnu druhu, si dokument
 *     poznamená v `beforeSave` (jen tam má originál).
 *
 * Číslo se čerpá v beforeSave — před transakcí gateway, čítač má vlastní
 * (uvnitř vnější transakce volajícího se vlastní neotevírá).
 */
class ContractDocument extends Document
{
    public const TABLE = 'facility_buildings_contracts';
    public const COUNTERS_TABLE = 'facility_buildings_number_counters';
    public const PLACES_TABLE = 'facility_buildings_contract_places';
    /** Řádky předpisu smlouvy — sub-tabulka, ne child tabulka (D30). */
    public const ROWS_TABLE = 'facility_buildings_contract_rows';
    /** Šablona řádků na druhu (D32). */
    public const KIND_ROWS_TABLE = 'facility_buildings_contract_kind_rows';
    /** Klíč child setu míst v datech dokumentu (`childTables[].dataKey`). */
    public const PLACES_KEY = 'places';
    /** cfgItem stavů smlouvy (config/docStates.jsonc) — jediný zdroj id. */
    public const DOC_STATES_CFG_ITEM = 'facility.buildings.docStates';

    public const STATE_DRAFT = 10;
    public const STATE_CANCELLED = 30;
    public const STATE_CONFIRMED = 40;
    public const STATE_FINISHED = 70;
    public const STATE_EDIT = 80;
    public const STATE_DELETED = 90;

    /** Koncové stavy — přechod do nich doplní datum ukončení (D21). */
    public const END_STATES = [self::STATE_FINISHED, self::STATE_CANCELLED];
    /** Platné smlouvy — jen ty se počítají do překryvu míst (D22). */
    public const VALID_STATES = [self::STATE_CONFIRMED, self::STATE_EDIT];

    public const HOME_CURRENCY_SETTING = 'economy.homeCurrency';
    public const DEFAULT_HOME_CURRENCY = 'czk';

    /** Jazyk hlášek validace — česká věta bez jazyka požadavku. */
    private const MESSAGE_LANGUAGE = 'cs';

    /**
     * Druh, jehož šablonu řádků má `afterPersist` zkopírovat (D32): nastaví
     * `beforeSave` při vložení nebo změně druhu konceptu, jinak null.
     */
    private ?int $templateKind = null;

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $original = $id !== null ? $this->loadRow($id) : null;
        $newState = (int) ($data['docState'] ?? $original['docState'] ?? self::STATE_DRAFT);
        $wasDraft = $original === null || (int) ($original['docState'] ?? self::STATE_DRAFT) === self::STATE_DRAFT;
        $confirming = $newState === self::STATE_CONFIRMED;

        if (trim((string) ($data['title'] ?? '')) === '') {
            $result->addError('title', 'Název smlouvy je povinný', 'required');
        }

        $kindId = (int) ($data['kind'] ?? 0);
        $kind = null;
        if ($kindId <= 0) {
            $result->addError('kind', 'Druh smlouvy je povinný', 'required');
        } elseif (!$wasDraft && $kindId !== (int) ($original['kind'] ?? 0)) {
            $result->addError('kind', 'Druh potvrzené smlouvy nejde změnit — typ a číslo by přestaly sedět.', 'kindLocked');
        } elseif ($wasDraft) {
            $kind = $this->loadKind($kindId);
            if ($kind === null) {
                if ($this->db !== null) {
                    $result->addError('kind', 'Druh smlouvy neexistuje', 'not_found');
                }
            } elseif ((int) ($kind['docState'] ?? 0) === self::STATE_DELETED) {
                $result->addError('kind', 'Druh smlouvy je smazaný', 'invalid_state');
            } else {
                // Denormalizace: typ jde vždy z druhu.
                $data['type'] = (string) ($kind['type'] ?? '');
            }
        } else {
            // Potvrzená smlouva: druh se nemění, typ zůstává uložený.
            $data['type'] = (string) ($original['type'] ?? $data['type'] ?? '');
            $kind = $this->loadKind($kindId);
        }
        $type = (string) ($data['type'] ?? '');

        if ($confirming && empty($data['customer'])) {
            $result->addError('customer', 'Smlouva musí mít nájemce — doplň ho před potvrzením.', 'required');
        }

        $start = self::isoDate($data['date_start'] ?? null);
        $end = self::isoDate($data['date_end'] ?? null);
        if ($confirming && $start === null) {
            $result->addError('date_start', 'Datum zahájení je povinné při potvrzení smlouvy.', 'required');
        }
        if ($start !== null && $end !== null && $end < $start) {
            $result->addError('date_end', 'Datum ukončení nesmí být dříve než zahájení.', 'invalid_range');
        }

        $this->validateInvoicing($data, $result, $id, $kind, $start, $end, $confirming);

        // Místa: z dat, jinak uložená (uložení bez klíče na vazby nesahá,
        // pravidla ale platí pro smlouvu jako celek).
        $placeRows = array_key_exists(self::PLACES_KEY, $data) && is_array($data[self::PLACES_KEY])
            ? array_values($data[self::PLACES_KEY])
            : ($id !== null ? $this->loadPlaceRows($id) : []);
        $placeIds = $this->validatePlaces($placeRows, $type, $confirming, $result);

        if ($placeIds !== [] && $start !== null && in_array($newState, [self::STATE_DRAFT, ...self::VALID_STATES], true)) {
            $this->warnAboutOverlaps($placeIds, $start, $end, $id, $result);
        }

        $number = trim((string) ($data['number'] ?? ''));
        if ($number !== '' && $this->findNumberOwner($number, $id) !== null) {
            $result->addError('number', "Číslo {$number} už má jiná smlouva.", 'duplicate');
        }

        return $result;
    }

    /**
     * Fakturační předpis (D29, D36): tvar hodnot vždy; prázdné „fakturovat
     * od“ se doplní zahájením a dřív než zahájení být nesmí. Potvrzení
     * smlouvy s periodicitou navíc vyžaduje „fakturovat od“, efektivní typ
     * dokladu a řadu (smlouva → druh), aspoň jeden řádek předpisu a platnost
     * řádků uvnitř platnosti smlouvy.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $kind řádek druhu se sloupci `inv_*`
     */
    private function validateInvoicing(
        array &$data,
        ValidationResult $result,
        ?int $id,
        ?array $kind,
        ?string $start,
        ?string $end,
        bool $confirming,
    ): void {
        $periodicity = $data['inv_periodicity'] ?? null;
        $hasPeriodicity = $periodicity !== null && $periodicity !== '';
        if ($hasPeriodicity && !InvoicingSettings::isPeriodicity($periodicity)) {
            $result->addError('inv_periodicity', 'Neznámá periodicita.', 'invalid');
        }
        $docType = $data['inv_doc_type'] ?? null;
        if ($docType !== null && $docType !== '' && !InvoicingSettings::isDocType($docType)) {
            $result->addError('inv_doc_type', 'Periodická fakturace vystavuje jen faktury a zálohové faktury vydané.', 'invalid');
        }
        $timing = $data['inv_timing'] ?? null;
        if ($timing !== null && $timing !== '' && !InvoicingSettings::isTiming($timing)) {
            $result->addError('inv_timing', 'Neznámý okamžik fakturace.', 'invalid');
        }
        if (isset($data['inv_due_days']) && $data['inv_due_days'] !== '' && (int) $data['inv_due_days'] < 0) {
            $result->addError('inv_due_days', 'Splatnost nesmí být záporná.', 'invalid');
        }

        // D36: fakturovat od = zahájení, když je prázdné; ne dřív. Doplnění
        // je tady, ne v beforeSave — potvrzení by jinak spadlo na povinné
        // pole dřív, než se doplní.
        $invFrom = self::isoDate($data['inv_from'] ?? null);
        if ($invFrom === null && $start !== null) {
            $data['inv_from'] = $start;
            $invFrom = $start;
        } elseif ($invFrom !== null && $start !== null && $invFrom < $start) {
            $result->addError('inv_from', 'Fakturovat od nesmí být dříve než zahájení smlouvy.', 'before_start');
        }

        $settings = InvoicingSettingsResolver::resolve($data, $kind);
        $seriesId = (int) ($data['inv_number_series'] ?? 0);
        if ($seriesId > 0) {
            $series = $this->loadDocSeries($seriesId);
            if ($series === null) {
                if ($this->db !== null) {
                    $result->addError('inv_number_series', 'Řada dokladů neexistuje', 'not_found');
                }
            } elseif ($settings->docType !== null && (string) ($series['doc_type'] ?? '') !== $settings->docType) {
                $result->addError('inv_number_series', 'Řada dokladů musí být stejného typu jako typ dokladu.', 'series_type_mismatch');
            }
        }

        if (!$confirming || !$hasPeriodicity) {
            return;
        }
        if ($invFrom === null) {
            $result->addError('inv_from', 'Fakturovat od je povinné při potvrzení smlouvy s periodicitou.', 'required');
        }
        if ($settings->docType === null) {
            $result->addError('inv_doc_type', 'Smlouva ani její druh nemají typ dokladu — doplň ho před potvrzením.', 'required');
        }
        if ($settings->numberSeries === null) {
            $result->addError('inv_number_series', 'Smlouva ani její druh nemají řadu dokladů — doplň ji před potvrzením.', 'required');
        }

        $rows = $id !== null ? $this->loadPrescriptionRows($id) : [];
        if ($rows === []) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Smlouva s periodicitou potřebuje aspoň jeden řádek předpisu — co se má fakturovat.',
                'rows_required',
            );
            return;
        }
        foreach ($rows as $row) {
            foreach (ContractRowDocument::validityProblems($row, $start, $end) as $message) {
                $label = trim((string) ($row['description'] ?? '')) ?: ('#' . (int) ($row['id'] ?? 0));
                $result->addError(ValidationError::FIELD_FORM, "Řádek „{$label}“: {$message}", 'row_outside_contract');
            }
        }
    }

    /**
     * Místa smlouvy podle vlastností typu. Vrací index řádku → id místa pro
     * místa, která pravidly prošla (kontrola překryvu).
     *
     * @param list<mixed> $rows řádky vazby `[{id?, place, order_pos}]`
     * @return array<int, int>
     */
    private function validatePlaces(array $rows, string $type, bool $confirming, ValidationResult $result): array
    {
        $types = $this->types();
        $mode = $type !== '' ? $types->places($type) : ContractTypes::PLACES_OPTIONAL;

        $idsByIndex = [];
        foreach ($rows as $index => $row) {
            $placeId = is_array($row) ? (int) ($row['place'] ?? 0) : 0;
            if ($placeId <= 0) {
                $result->addError(self::placeColumn($index), 'Místo není vybrané.', 'required');
                continue;
            }
            $idsByIndex[$index] = $placeId;
        }

        if ($idsByIndex === []) {
            if ($confirming && $mode === ContractTypes::PLACES_REQUIRED) {
                $result->addError(self::PLACES_KEY, 'Smlouva tohoto typu musí mít aspoň jedno místo — doplň ho před potvrzením.', 'required');
            }
            return [];
        }

        $places = $this->loadPlaces(array_values(array_unique($idsByIndex)));
        $nameOf = static fn (int $placeId): string => trim((string) ($places[$placeId]['name'] ?? '')) ?: ('#' . $placeId);

        $valid = [];
        $seen = [];
        foreach ($idsByIndex as $index => $placeId) {
            $column = self::placeColumn($index);
            $name = $nameOf($placeId);

            if ($mode === ContractTypes::PLACES_NONE) {
                $result->addError($column, "Smlouva typu {$types->label($type)} místa nemá — odeber místo {$name}.", 'not_allowed');
                continue;
            }
            if (isset($seen[$placeId])) {
                $result->addError($column, "Místo {$name} je ve smlouvě dvakrát.", 'duplicate');
                continue;
            }
            $seen[$placeId] = true;

            $place = $places[$placeId] ?? null;
            if ($place === null) {
                if ($this->db !== null) {
                    $result->addError($column, "Místo {$name} neexistuje.", 'not_found');
                }
                continue;
            }
            if ((int) ($place['docState'] ?? 0) === PlaceDocument::STATE_DELETED) {
                $result->addError($column, "Místo {$name} je v koši.", 'invalid_state');
                continue;
            }
            $placeType = (string) ($place['kind_type'] ?? '');
            if ($type !== '' && $placeType !== '' && !$types->allowsPlaceType($type, $placeType)) {
                $result->addError(
                    $column,
                    "Místo {$name} ({$this->placeTypeLabel($placeType)}) nejde dát do smlouvy typu {$types->label($type)}"
                    . " — povolené typy míst: {$this->placeTypeLabels($types->placeTypes($type) ?? [])}.",
                    'place_type',
                );
                continue;
            }
            $valid[$index] = $placeId;
        }
        return $valid;
    }

    /**
     * Varování o překryvu (D7, D22): pro každé místo nejvýš jeden nález —
     * první platná smlouva (podle zahájení), ve které místo už je.
     *
     * @param array<int, int> $placeIds index řádku → id místa
     */
    private function warnAboutOverlaps(array $placeIds, string $start, ?string $end, ?int $selfId, ValidationResult $result): void
    {
        $overlaps = $this->findOverlaps(array_values(array_unique($placeIds)), $start, $end, $selfId);
        if ($overlaps === []) {
            return;
        }

        $byPlace = [];
        foreach ($overlaps as $overlap) {
            $placeId = (int) ($overlap['place'] ?? 0);
            $byPlace[$placeId] ??= $overlap;
        }

        foreach ($placeIds as $index => $placeId) {
            $overlap = $byPlace[$placeId] ?? null;
            if ($overlap === null) {
                continue;
            }
            $name = trim((string) ($overlap['place_name'] ?? '')) ?: ('#' . $placeId);
            $number = trim((string) ($overlap['number'] ?? '')) ?: trim((string) ($overlap['title'] ?? ''));
            $result->addWarning(
                self::placeColumn($index),
                "{$name} je i ve smlouvě {$number} ({$this->validityText($overlap['date_start'] ?? null, $overlap['date_end'] ?? null)})",
                'overlap',
            );
        }
    }

    /** „od 01.01.2027“ / „01.01.2027 – 31.12.2028“ / „bez omezení“. */
    private function validityText(mixed $start, mixed $end): string
    {
        $from = DisplayDate::date(self::isoDate($start), self::MESSAGE_LANGUAGE);
        $to = DisplayDate::date(self::isoDate($end), self::MESSAGE_LANGUAGE);
        return match (true) {
            $from !== null && $to !== null => "{$from} – {$to}",
            $from !== null => "od {$from}",
            $to !== null => "do {$to}",
            default => 'bez omezení',
        };
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $this->trackStateChange($data, $originalData);

        foreach (['title', 'number', 'internal_note', 'payment_reference', 'inv_doc_text'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null) {
                $trimmed = trim((string) $data[$col]);
                $data[$col] = $trimmed === '' ? null : $trimmed;
            }
        }

        // Měna: prázdná = domácí měna zdroje dat (jako u zakázek). Úprava
        // bez klíče měny na uloženou hodnotu nesahá.
        if (($originalData === null || array_key_exists('currency', $data)) && !self::hasValue($data['currency'] ?? null)) {
            $data['currency'] = $this->homeCurrency();
        }

        // D32: šablona řádků jen při vložení nebo změně druhu konceptu —
        // podmínka „smlouva nemá řádky“ sama nestačí (viz afterPersist).
        $this->templateKind = null;
        $kindId = (int) ($data['kind'] ?? $originalData['kind'] ?? 0);
        $state = (int) ($data['docState'] ?? $originalData['docState'] ?? self::STATE_DRAFT);
        $kindChanged = $originalData === null || $kindId !== (int) ($originalData['kind'] ?? 0);
        if ($kindId > 0 && $kindChanged && $state === self::STATE_DRAFT) {
            $this->templateKind = $kindId;
        }

        $t = $this->stateTransition;
        if ($t === null) {
            return;
        }
        if (in_array($t['old'], [0, self::STATE_DRAFT], true)
            && $t['new'] === self::STATE_CONFIRMED
            && !self::hasValue($data['number'] ?? null)
        ) {
            $this->assignNumber($data);
        }
        if (in_array($t['new'], self::END_STATES, true) && self::isoDate($data['date_end'] ?? null) === null) {
            $data['date_end'] = $this->today();
        }
    }

    /**
     * Šablona řádků druhu (D32): po vložení nebo změně druhu konceptu, který
     * žádné řádky nemá, se do něj zkopírují řádky šablony — v transakci
     * uložení. Druh bez šablony nic nevloží.
     */
    public function afterPersist(array $data): void
    {
        $kindId = $this->templateKind;
        $this->templateKind = null;
        $id = (int) ($data['id'] ?? 0);
        if ($kindId === null || $id <= 0 || $this->countPrescriptionRows($id) > 0) {
            return;
        }
        $this->copyTemplateRows($id, $kindId);
    }

    public function beforeDelete(array $data): void
    {
        $state = (int) ($data['docState'] ?? self::STATE_DRAFT);
        if (!in_array($state, [self::STATE_DRAFT, self::STATE_DELETED], true)) {
            throw new \DomainException('Smazat jde jen koncept smlouvy — potvrzenou smlouvu ukonči nebo zruš.');
        }
    }

    public function afterDelete(array $data): void
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $this->deletePlaceRows($id);
            $this->deletePrescriptionRows($id);
        }
    }

    // ── Číslo (D15, D18) ────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    protected function assignNumber(array &$data): void
    {
        $kindId = (int) ($data['kind'] ?? 0);
        $kind = $kindId > 0 ? $this->loadKind($kindId) : null;
        if ($kind === null) {
            throw new \LogicException("Druh smlouvy id={$kindId} nenalezen");
        }
        $start = self::isoDate($data['date_start'] ?? null);
        if ($start === null) {
            throw new \DomainException('Bez data zahájení nejde přidělit číslo smlouvy.');
        }

        $year = (int) substr($start, 0, 4);
        $yearly = (string) ($kind['reset_scope'] ?? ContractKindDocument::RESET_YEARLY) === ContractKindDocument::RESET_YEARLY;
        $scope = $yearly ? $year : null;

        $sequence = $this->nextSequence($kindId, $scope);
        $number = NumberPattern::resolve((string) ($kind['number_pattern'] ?? ''), new NumberContext(
            sequence:   $sequence,
            seriesCode: (string) ($kind['number_code'] ?? ''),
            yearLabel:  (string) $year,
        ));
        if ($this->findNumberOwner($number, !empty($data['id']) ? (int) $data['id'] : null) !== null) {
            throw new \DomainException(
                "Číslo {$number} už má jiná smlouva — zkontroluj vzorec čísla a kód druhu v Nastavení → Budovy.",
            );
        }

        $data['sequence_number'] = $sequence;
        $data['number_year'] = $scope;
        $data['number'] = $number;
    }

    /** Další pořadí druhu z čítače (přepsatelné v testech). */
    protected function nextSequence(int $kindId, ?int $year): int
    {
        return $this->sequenceCounter()->next($kindId, $year, !$this->externalTransaction);
    }

    /** Čítač čísel smluv nad vlastní tabulkou čítačů a smlouvami. */
    protected function sequenceCounter(): SequenceCounter
    {
        if ($this->db === null) {
            throw new \LogicException('No DB connection available');
        }
        return new SequenceCounter(
            $this->db,
            new SequenceStorage(
                countersTable: self::COUNTERS_TABLE,
                counterSeriesColumn: 'kind',
                counterScopeColumn: 'year',
                counterValueColumn: 'last_assigned',
                recordsTable: self::TABLE,
                recordSeriesColumn: 'kind',
                recordScopeColumn: 'number_year',
                recordSequenceColumn: 'sequence_number',
            ),
        );
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    /** Uložený řádek smlouvy (id, kind, type, docState), null = neexistuje. */
    protected function loadRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [kind], [type], [docState] FROM [' . self::TABLE . '] WHERE [id] = %i',
            $id,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /**
     * Druh (id, type, number_code, number_pattern, reset_scope, docState
     * a výchozí hodnoty fakturace `inv_*`), null = neexistuje.
     */
    protected function loadKind(int $kindId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [type], [number_code], [number_pattern], [reset_scope], [docState], '
            . implode(', ', array_map(static fn (string $c): string => "[{$c}]", InvoicingSettings::COLUMNS))
            . ' FROM [' . ContractKindDocument::TABLE . '] WHERE [id] = %i',
            $kindId,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Řada dokladů (id, doc_type, docState), null = neexistuje. */
    protected function loadDocSeries(int $seriesId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT [id], [doc_type], [docState] FROM [docs_core_number_series] WHERE [id] = %i', $seriesId);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /**
     * Řádky předpisu smlouvy pro kontrolu při potvrzení (id, description,
     * valid_from, valid_to) v pořadí.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadPrescriptionRows(int $contractId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [id], [description], [valid_from], [valid_to] FROM [' . self::ROWS_TABLE . ']'
            . ' WHERE [contract] = %i ORDER BY [order_pos], [id]',
            $contractId,
        );
        return array_map(static fn ($row): array => iterator_to_array($row), $rows);
    }

    /** Počet řádků předpisu smlouvy. */
    protected function countPrescriptionRows(int $contractId): int
    {
        if ($this->db === null) {
            return 0;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM [' . self::ROWS_TABLE . '] WHERE [contract] = %i',
            $contractId,
        );
    }

    /**
     * Zkopíruje řádky šablony druhu do smlouvy — pořadí šablony, platnost
     * prázdná (šablona ji nemá). Jeden příkaz v transakci uložení; druh bez
     * šablony nevloží nic (query je final — seam pro testy).
     */
    protected function copyTemplateRows(int $contractId, int $kindId): void
    {
        $columns = '[order_pos], [item], [description], [quantity], [unit], [unit_price], [vat_code], [operation], [contributor]';
        $this->db?->query(
            'INSERT INTO [' . self::ROWS_TABLE . '] ([contract], ' . $columns . ')'
            . ' SELECT %i, ' . $columns . ' FROM [' . self::KIND_ROWS_TABLE . ']'
            . ' WHERE [kind] = %i ORDER BY [order_pos], [id]',
            $contractId,
            $kindId,
        );
    }

    /** Smaže řádky předpisu smazaného konceptu (query je final — seam pro testy). */
    protected function deletePrescriptionRows(int $contractId): void
    {
        $this->db?->query('DELETE FROM [' . self::ROWS_TABLE . '] WHERE [contract] = %i', $contractId);
    }

    /** Domácí měna zdroje dat ze settings `economy.homeCurrency`. */
    protected function homeCurrency(): string
    {
        $value = $this->settings?->get(self::HOME_CURRENCY_SETTING);
        return is_string($value) && $value !== '' ? $value : self::DEFAULT_HOME_CURRENCY;
    }

    /**
     * Uložené vazby míst smlouvy v pořadí.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadPlaceRows(int $contractId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [id], [place], [order_pos] FROM [' . self::PLACES_TABLE . ']'
            . ' WHERE [contract] = %i ORDER BY [order_pos], [id]',
            $contractId,
        );
        return array_map(static fn ($row): array => iterator_to_array($row), $rows);
    }

    /**
     * Místa podle id (i v koši): id → {name, docState, kind_type}.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    protected function loadPlaces(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [p].[id], [p].[name], [p].[docState], [k].[type] AS [kind_type]'
            . ' FROM [' . PlaceDocument::TABLE . '] [p]'
            . ' LEFT JOIN [' . PlaceKindDocument::TABLE . '] [k] ON [k].[id] = [p].[kind]'
            . ' WHERE [p].[id] IN %in',
            $ids,
        );
        $places = [];
        foreach ($rows as $row) {
            $places[(int) $row['id']] = iterator_to_array($row);
        }
        return $places;
    }

    /**
     * Platné smlouvy (V pořádku, V opravě) mimo tuto, které mají některé
     * z míst a jejich doba se s `$start`–`$end` překrývá. Prázdné ukončení
     * je doba neurčitá na obou stranách, prázdné zahájení cizí smlouvy také.
     * Řazeno podle zahájení — první nález na místo je ten nejstarší.
     *
     * @param list<int> $placeIds
     * @return list<array<string, mixed>> {place, place_name, number, title, date_start, date_end}
     */
    protected function findOverlaps(array $placeIds, string $start, ?string $end, ?int $excludeId): array
    {
        if ($this->db === null || $placeIds === []) {
            return [];
        }
        $sql = 'SELECT [cp].[place], [p].[name] AS [place_name], [c].[number], [c].[title], [c].[date_start], [c].[date_end]'
            . ' FROM [' . self::PLACES_TABLE . '] [cp]'
            . ' JOIN [' . self::TABLE . '] [c] ON [c].[id] = [cp].[contract]'
            . ' LEFT JOIN [' . PlaceDocument::TABLE . '] [p] ON [p].[id] = [cp].[place]'
            . ' WHERE [cp].[place] IN %in AND [c].[docState] IN %in AND [c].[id] <> %i'
            . ' AND ([c].[date_end] IS NULL OR [c].[date_end] >= %d)';
        $args = [$placeIds, self::VALID_STATES, $excludeId ?? 0, $start];
        if ($end !== null) {
            $sql .= ' AND ([c].[date_start] IS NULL OR [c].[date_start] <= %d)';
            $args[] = $end;
        }
        $sql .= ' ORDER BY [c].[date_start], [c].[id]';

        return array_map(static fn ($row): array => iterator_to_array($row), $this->db->fetchAll($sql, ...$args));
    }

    /** Id jiné smlouvy s tímto číslem (libovolný stav), null = volné. */
    protected function findNumberOwner(string $number, ?int $excludeId): ?int
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [' . self::TABLE . '] WHERE [number] = %s AND [id] <> %i LIMIT 1',
            $number,
            $excludeId ?? 0,
        );
        return $row === null || $row === false ? null : (int) $row['id'];
    }

    /** Smaže vazby míst smazané smlouvy (query je final — seam pro testy). */
    protected function deletePlaceRows(int $contractId): void
    {
        $this->db?->query('DELETE FROM [' . self::PLACES_TABLE . '] WHERE [contract] = %i', $contractId);
    }

    protected function today(): string
    {
        return date('Y-m-d');
    }

    protected function types(): ContractTypes
    {
        return new ContractTypes($this->config);
    }

    private function placeTypeLabel(string $placeType): string
    {
        return new PlaceTypes($this->config)->label($placeType);
    }

    /** @param list<string> $placeTypes */
    private function placeTypeLabels(array $placeTypes): string
    {
        $places = new PlaceTypes($this->config);
        return implode(', ', array_map(static fn (string $type): string => $places->label($type), $placeTypes));
    }

    /** Cesta chyby u místa: `places.<index>.place` (prvek `multilookup` ji ukáže u pole). */
    private static function placeColumn(int $index): string
    {
        return self::PLACES_KEY . '.' . $index . '.place';
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }

    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== false;
    }
}
