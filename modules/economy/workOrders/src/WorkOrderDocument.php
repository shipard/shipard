<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Numbering\NumberContext;
use Shipard\Core\Numbering\NumberPattern;
use Shipard\Core\Numbering\SequenceCounter;
use Shipard\Core\Numbering\SequenceStorage;
use Shipard\Module\Economy\Codebooks\FiscalYearLookup;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingSettings;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingSettingsResolver;

/**
 * Zakázka (economy_work_orders_heads, docs/work-orders.md §5.3–5.4,
 * tasks/work-orders-phase1.md §3).
 *
 * Pravidla:
 *   - číselná řada je povinná a určuje druh a typ — oba se při uložení
 *     denormalizují z řady a přepíší, co poslal klient (jako `doc_type`
 *     u dokladů); řadu potvrzené zakázky změnit nejde;
 *   - podle příznaků typu (WorkOrderTypes): externí typ má zákazníka
 *     (povinný při potvrzení), měnu (výchozí domácí) a pevný VS, neexterní
 *     je nemá (vynulují se); nadřazenou zakázku smí mít jen jednorázový typ
 *     a nadřazená nesmí být periodická (P4), smazaná, zakázka sama, ani
 *     vytvořit cyklus v řetězci předků;
 *   - zahájení je povinné při potvrzení; u řady s ročním restartem musí
 *     pro něj existovat fiskální rok (chyba potvrzení s odkazem na účetní
 *     roky, ne výjimka z čítače);
 *   - číslo (D17) přidělí přechod z Konceptu do V pořádku přes SequenceCounter
 *     nad čítači zakázek; ručně zadané nebo importované číslo zůstává
 *     a potvrzená zakázka se do Konceptu nevrací (číslo se neuvolňuje);
 *   - přechod do Ukončeno / Zrušeno doplní prázdné datum ukončení dneškem;
 *   - smazat jde jen koncept (sada stavů vede do 90 jen z 10; beforeDelete
 *     to hlídá i pro přímé mazání); s konceptem odejdou i jeho řádky;
 *   - fakturační předpis (fáze 2, D3, D11, D12) jen u periodického typu:
 *     potvrzení vyžaduje periodicitu, efektivní typ dokladu a řadu (zakázka
 *     nebo druh) a aspoň jeden řádek předpisu; prázdné „fakturovat od“ se
 *     doplní zahájením; řada v přepisu musí být typu efektivního dokladu;
 *     u ostatních typů se fakturační pole vynulují;
 *   - import (tasks/work-orders-import.md I4): převzaté číslo zůstává
 *     a virtuální pole `_importSequence` (pořadí v řadě) k němu doplní
 *     pořadí a fiskální rok a srovná čítač řady přes GREATEST, aby další
 *     zakázka ve formuláři pokračovala za importovaným číslem. Marker se
 *     vyjme před SQL.
 *
 * Číslo se čerpá v beforeSave — před transakcí gateway, čítač má vlastní
 * (jako u dokladů; uvnitř vnější transakce applieru se vlastní neotevírá).
 */
class WorkOrderDocument extends Document
{
    public const TABLE = 'economy_work_orders_heads';
    public const SERIES_TABLE = 'economy_work_orders_number_series';
    public const COUNTERS_TABLE = 'economy_work_orders_number_counters';
    public const ROWS_TABLE = 'economy_work_orders_rows';
    /** cfgItem stavů zakázky (config/docStates.jsonc) — jediný zdroj id. */
    public const DOC_STATES_CFG_ITEM = 'economy.workOrders.docStates';

    public const STATE_DRAFT = 10;
    public const STATE_CANCELLED = 30;
    public const STATE_CONFIRMED = 40;
    public const STATE_FINISHED = 70;
    public const STATE_EDIT = 80;
    public const STATE_DELETED = 90;

    /** Koncové stavy — přechod do nich doplní datum ukončení (D18). */
    public const END_STATES = [self::STATE_FINISHED, self::STATE_CANCELLED];

    public const RESET_SCOPE_FISCAL_YEAR = 'fiscal_year';
    /** Virtuální pole importu: pořadí v řadě k převzatému číslu (applier importu, I4). */
    public const IMPORT_SEQUENCE_KEY = '_importSequence';
    public const HOME_CURRENCY_SETTING = 'economy.homeCurrency';
    public const DEFAULT_HOME_CURRENCY = 'czk';

    /** Strop průchodu řetězcem předků — pojistka proti poškozeným datům. */
    private const MAX_PARENT_DEPTH = 100;

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $types = $this->types();
        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $original = $id !== null ? $this->loadRow($id) : null;
        $newState = (int) ($data['docState'] ?? self::STATE_DRAFT);
        $wasDraft = $original === null || (int) ($original['docState'] ?? self::STATE_DRAFT) === self::STATE_DRAFT;

        if (trim((string) ($data['title'] ?? '')) === '') {
            $result->addError('title', 'Název zakázky je povinný', 'required');
        }

        $seriesId = (int) ($data['number_series'] ?? 0);
        $series = null;
        if ($seriesId <= 0) {
            $result->addError('number_series', 'Číselná řada je povinná', 'required');
        } elseif (!$wasDraft && $seriesId !== (int) ($original['number_series'] ?? 0)) {
            $result->addError('number_series', 'Řadu potvrzené zakázky nejde změnit — druh a číslo by přestaly sedět.', 'seriesLocked');
        } else {
            $series = $this->loadSeries($seriesId);
            if ($series === null) {
                if ($this->db !== null) {
                    $result->addError('number_series', 'Číselná řada neexistuje', 'not_found');
                }
            } elseif ((int) ($series['docState'] ?? 0) === self::STATE_DELETED) {
                $result->addError('number_series', 'Číselná řada je smazaná', 'invalid_state');
            } else {
                // Denormalizace: druh a typ jdou vždy z řady (jako doc_type).
                $data['kind'] = (int) $series['kind'];
                $data['type'] = (string) ($series['type'] ?? '');
            }
        }

        $type = (string) ($data['type'] ?? '');
        $external = $type !== '' && $types->isExternal($type);
        $oneOff = $type !== '' && $types->isOneOff($type);

        if ($external && $newState === self::STATE_CONFIRMED && empty($data['customer'])) {
            $result->addError('customer', 'Externí zakázka musí mít zákazníka — doplň ho před potvrzením.', 'required');
        }

        $parent = (int) ($data['parent'] ?? 0);
        if ($parent > 0 && $type !== '') {
            if (!$oneOff) {
                $result->addError('parent', 'Nadřazenou zakázku může mít jen jednorázová zakázka.', 'not_allowed');
            } elseif ($id !== null && $parent === $id) {
                $result->addError('parent', 'Zakázka nemůže být nadřazená sama sobě.', 'cycle');
            } else {
                $this->validateParent($parent, $id, $result);
            }
        }

        $start = self::isoDate($data['date_start'] ?? null);
        $end = self::isoDate($data['date_end'] ?? null);
        if ($newState === self::STATE_CONFIRMED && $start === null) {
            $result->addError('date_start', 'Datum zahájení je povinné při potvrzení zakázky.', 'required');
        }
        if ($start !== null && $end !== null && $end < $start) {
            $result->addError('date_end', 'Datum ukončení nesmí být dříve než zahájení.', 'invalid_range');
        }

        // Fiskální rok čísla: chybějící rok hlásit tady, ne výjimkou z čítače.
        if ($series !== null
            && $wasDraft
            && $newState === self::STATE_CONFIRMED
            && !self::hasValue($data['number'] ?? null)
            && (string) ($series['reset_scope'] ?? self::RESET_SCOPE_FISCAL_YEAR) === self::RESET_SCOPE_FISCAL_YEAR
            && $start !== null
            && $this->fiscalYearIdForDate($start) === null
        ) {
            $result->addError(
                'date_start',
                'Pro datum zahájení není založený fiskální rok — založ ho v Nastavení → Účetnictví → Fiskální období.',
                'fiscalYearMissing',
            );
        }

        $number = trim((string) ($data['number'] ?? ''));
        if ($number !== '' && $this->findNumberOwner($number, $id) !== null) {
            $result->addError('number', "Číslo {$number} už má jiná zakázka.", 'duplicate');
        }

        if ($type !== '' && $types->invoicing($type) === WorkOrderTypes::INVOICING_PERIODIC) {
            $this->validateInvoicing($data, $result, $id, $newState === self::STATE_CONFIRMED);
        }

        return $result;
    }

    /**
     * Fakturační předpis periodické zakázky (D3, D11, D12): tvar hodnot
     * vždy, při potvrzení periodicita, efektivní typ dokladu a řada
     * (zakázka → druh) a aspoň jeden řádek předpisu.
     *
     * @param array<string, mixed> $data
     */
    private function validateInvoicing(array $data, ValidationResult $result, ?int $id, bool $confirming): void
    {
        $periodicity = $data['inv_periodicity'] ?? null;
        if ($periodicity !== null && $periodicity !== '' && !InvoicingSettings::isPeriodicity($periodicity)) {
            $result->addError('inv_periodicity', 'Neznámá periodicita.', 'invalid');
        } elseif (($periodicity === null || $periodicity === '') && $confirming) {
            $result->addError('inv_periodicity', 'Periodicita je povinná při potvrzení periodické zakázky.', 'required');
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

        $kindId = (int) ($data['kind'] ?? 0);
        $settings = InvoicingSettingsResolver::resolve($data, $kindId > 0 ? $this->loadKind($kindId) : null);

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

        if (!$confirming) {
            return;
        }
        if ($settings->docType === null) {
            $result->addError('inv_doc_type', 'Zakázka ani její druh nemají typ dokladu — doplň ho před potvrzením.', 'required');
        }
        if ($settings->numberSeries === null) {
            $result->addError('inv_number_series', 'Zakázka ani její druh nemají řadu dokladů — doplň ji před potvrzením.', 'required');
        }
        if ($id === null || $this->countRows($id) === 0) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Periodická zakázka potřebuje aspoň jeden řádek předpisu — co se má fakturovat.',
                'rows_required',
            );
        }
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        // Marker importu ven z dat hned — nesmí dojít do SQL.
        $importSequence = $data[self::IMPORT_SEQUENCE_KEY] ?? null;
        unset($data[self::IMPORT_SEQUENCE_KEY]);

        $this->trackStateChange($data, $originalData);

        foreach (['title', 'number', 'payment_reference', 'internal_note'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null) {
                $trimmed = trim((string) $data[$col]);
                $data[$col] = $trimmed === '' ? null : $trimmed;
            }
        }

        $types = $this->types();
        $type = (string) ($data['type'] ?? $originalData['type'] ?? '');
        if ($type !== '' && !$types->isExternal($type)) {
            $data['customer'] = null;
            $data['currency'] = null;
            $data['payment_reference'] = null;
        } elseif ($type !== '' && !self::hasValue($data['currency'] ?? null)) {
            $data['currency'] = $this->homeCurrency();
        }
        if ($type !== '' && !$types->isOneOff($type)) {
            $data['parent'] = null;
        }
        if ($type !== '' && $types->invoicing($type) !== WorkOrderTypes::INVOICING_PERIODIC) {
            foreach ([...InvoicingSettings::COLUMNS, ...InvoicingSettings::WORK_ORDER_COLUMNS] as $col) {
                $data[$col] = null;
            }
        } elseif ($type !== '' && !self::hasValue($data['inv_from'] ?? null)) {
            $start = self::isoDate($data['date_start'] ?? $originalData['date_start'] ?? null);
            if ($start !== null) {
                $data['inv_from'] = $start;
            }
        }

        if ($importSequence !== null && (int) $importSequence > 0 && self::hasValue($data['number'] ?? null)) {
            $this->syncImportedNumber($data, (int) $importSequence);
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

    public function beforeDelete(array $data): void
    {
        if ((int) ($data['docState'] ?? self::STATE_DRAFT) !== self::STATE_DRAFT) {
            throw new \DomainException('Smazat jde jen koncept zakázky — potvrzenou zakázku ukonči nebo zruš.');
        }
    }

    public function afterDelete(array $data): void
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $this->deleteRows($id);
        }
    }

    // ── Číslo (D17) ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    protected function assignNumber(array &$data): void
    {
        $seriesId = (int) ($data['number_series'] ?? 0);
        $series = $seriesId > 0 ? $this->loadSeries($seriesId) : null;
        if ($series === null) {
            throw new \LogicException("Číselná řada zakázek id={$seriesId} nenalezena");
        }

        $start = self::isoDate($data['date_start'] ?? null);
        $yearly = (string) ($series['reset_scope'] ?? self::RESET_SCOPE_FISCAL_YEAR) === self::RESET_SCOPE_FISCAL_YEAR;
        $fyId = $yearly && $start !== null ? $this->fiscalYearIdForDate($start) : null;
        if ($yearly && $fyId === null) {
            throw new \DomainException('Pro datum zahájení není založený fiskální rok.');
        }

        $sequence = $this->nextSequence($seriesId, $fyId);
        $data['sequence_number'] = $sequence;
        $data['fiscal_year'] = $fyId;
        $data['number'] = NumberPattern::resolve((string) ($series['number_pattern'] ?? ''), new NumberContext(
            sequence:   $sequence,
            seriesCode: (string) ($series['number_code'] ?? ''),
            yearLabel:  fn(): string => $fyId !== null
                ? $this->yearLabel($fyId)
                : FiscalYearLookup::labelFromDate($start),
        ));
    }

    /**
     * Převzaté číslo s pořadím (import, I4): pořadí a fiskální rok na
     * zakázce + srovnání čítače řady (SequenceCounter::syncImported —
     * GREATEST, idempotentní). Rozsah čítače jako při přidělení: fiskální
     * rok data zahájení u řady s ročním restartem, jinak NULL. Chybějící
     * rok hlásí verifier importu předem; tady je to jen pojistka.
     *
     * @param array<string, mixed> $data
     */
    protected function syncImportedNumber(array &$data, int $sequence): void
    {
        $seriesId = (int) ($data['number_series'] ?? 0);
        $series = $seriesId > 0 ? $this->loadSeries($seriesId) : null;
        if ($series === null) {
            throw new \LogicException("Číselná řada zakázek id={$seriesId} nenalezena");
        }
        $start = self::isoDate($data['date_start'] ?? null);
        $yearly = (string) ($series['reset_scope'] ?? self::RESET_SCOPE_FISCAL_YEAR) === self::RESET_SCOPE_FISCAL_YEAR;
        $fyId = $yearly && $start !== null ? $this->fiscalYearIdForDate($start) : null;
        if ($yearly && $fyId === null) {
            throw new \DomainException('Pro datum zahájení není založený fiskální rok — čítač řady nejde srovnat.');
        }
        $data['sequence_number'] = $sequence;
        $data['fiscal_year'] = $fyId;
        $this->syncSequence($seriesId, $fyId, $sequence);
    }

    /** Další pořadí v řadě z čítače (přepsatelné v testech). */
    protected function nextSequence(int $seriesId, ?int $fiscalYearId): int
    {
        return $this->sequenceCounter()->next($seriesId, $fiscalYearId, !$this->externalTransaction);
    }

    /** Srovnání čítače na importované pořadí (přepsatelné v testech). */
    protected function syncSequence(int $seriesId, ?int $fiscalYearId, int $sequence): void
    {
        $this->sequenceCounter()->syncImported($seriesId, $fiscalYearId, $sequence);
    }

    /** Čítač čísel zakázek nad vlastní tabulkou čítačů a hlavičkami zakázek. */
    protected function sequenceCounter(): SequenceCounter
    {
        if ($this->db === null) {
            throw new \LogicException('No DB connection available');
        }
        return new SequenceCounter(
            $this->db,
            new SequenceStorage(
                countersTable: self::COUNTERS_TABLE,
                counterSeriesColumn: 'number_series',
                counterScopeColumn: 'fiscal_year',
                counterValueColumn: 'last_assigned',
                recordsTable: self::TABLE,
                recordSeriesColumn: 'number_series',
                recordScopeColumn: 'fiscal_year',
                recordSequenceColumn: 'sequence_number',
            ),
        );
    }

    protected function fiscalYearIdForDate(string $date): ?int
    {
        return $this->db !== null ? FiscalYearLookup::yearIdForDate($this->db, $date) : null;
    }

    protected function yearLabel(int $fiscalYearId): string
    {
        return $this->db !== null ? FiscalYearLookup::yearLabel($this->db, $fiscalYearId) : date('Y');
    }

    // ── Nadřazená zakázka (D15) ─────────────────────────────────────────────

    private function validateParent(int $parentId, ?int $selfId, ValidationResult $result): void
    {
        if ($this->db === null) {
            return;
        }
        $parentRow = $this->loadRow($parentId);
        if ($parentRow === null) {
            $result->addError('parent', 'Nadřazená zakázka neexistuje', 'not_found');
            return;
        }
        if ((int) ($parentRow['docState'] ?? 0) === self::STATE_DELETED) {
            $result->addError('parent', 'Nadřazená zakázka je smazaná.', 'invalid_state');
            return;
        }
        $parentType = (string) ($parentRow['type'] ?? '');
        if ($parentType !== '' && !$this->types()->canBeParent($parentType)) {
            $result->addError('parent', 'Periodická zakázka nemůže být nadřazenou — náklady podzakázek se pod ní nesčítají.', 'parent_type');
            return;
        }
        if ($selfId !== null && $this->chainContains($parentRow, $selfId)) {
            $result->addError('parent', 'Nadřazená zakázka by vytvořila cyklus — je sama podřízená této zakázce.', 'cycle');
        }
    }

    /**
     * Je `$id` v řetězci předků zakázky `$row` (včetně jí samé)?
     *
     * @param array<string, mixed> $row
     */
    private function chainContains(array $row, int $id): bool
    {
        $depth = 0;
        while ($row !== null && $depth++ < self::MAX_PARENT_DEPTH) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return true;
            }
            $next = (int) ($row['parent'] ?? 0);
            $row = $next > 0 ? $this->loadRow($next) : null;
        }
        return false;
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    /** Uložený řádek zakázky (id, number_series, type, parent, docState), null = neexistuje. */
    protected function loadRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [number_series], [kind], [type], [parent], [docState] FROM [' . self::TABLE . '] WHERE [id] = %i',
            $id,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Řada s typem druhu (kind, type, number_code, number_pattern, reset_scope, docState), null = neexistuje. */
    protected function loadSeries(int $seriesId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [s].[id], [s].[kind], [s].[number_code], [s].[number_pattern], [s].[reset_scope], [s].[docState], [k].[type]'
            . ' FROM [' . self::SERIES_TABLE . '] [s]'
            . ' LEFT JOIN [' . KindDocument::TABLE . '] [k] ON [k].[id] = [s].[kind]'
            . ' WHERE [s].[id] = %i',
            $seriesId,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Druh (sloupce fakturačního předpisu inv_*), null = neexistuje. */
    protected function loadKind(int $kindId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [type], ' . implode(', ', array_map(static fn(string $c): string => "[{$c}]", InvoicingSettings::COLUMNS))
            . ' FROM [' . KindDocument::TABLE . '] WHERE [id] = %i',
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

    /** Počet řádků předpisu zakázky. */
    protected function countRows(int $workOrderId): int
    {
        if ($this->db === null) {
            return 0;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM [' . self::ROWS_TABLE . '] WHERE [work_order] = %i',
            $workOrderId,
        );
    }

    /** Smaže řádky předpisu smazaného konceptu (query je final — seam pro testy). */
    protected function deleteRows(int $workOrderId): void
    {
        $this->db?->query('DELETE FROM [' . self::ROWS_TABLE . '] WHERE [work_order] = %i', $workOrderId);
    }

    /** Id jiné zakázky s tímto číslem (libovolný stav), null = volné. */
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

    protected function homeCurrency(): string
    {
        $value = $this->settings?->get(self::HOME_CURRENCY_SETTING);
        return is_string($value) && $value !== '' ? $value : self::DEFAULT_HOME_CURRENCY;
    }

    protected function today(): string
    {
        return date('Y-m-d');
    }

    protected function types(): WorkOrderTypes
    {
        return new WorkOrderTypes($this->config);
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
