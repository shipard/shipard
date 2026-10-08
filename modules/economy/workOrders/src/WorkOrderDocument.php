<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Numbering\NumberContext;
use Shipard\Core\Numbering\NumberPattern;
use Shipard\Core\Numbering\SequenceCounter;
use Shipard\Core\Numbering\SequenceStorage;
use Shipard\Module\Economy\Codebooks\FiscalYearLookup;

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
 *     to hlídá i pro přímé mazání).
 *
 * Číslo se čerpá v beforeSave — před transakcí gateway, čítač má vlastní
 * (jako u dokladů; uvnitř vnější transakce applieru se vlastní neotevírá).
 */
class WorkOrderDocument extends Document
{
    public const TABLE = 'economy_work_orders_heads';
    public const SERIES_TABLE = 'economy_work_orders_number_series';
    public const COUNTERS_TABLE = 'economy_work_orders_number_counters';

    public const STATE_DRAFT = 10;
    public const STATE_CANCELLED = 30;
    public const STATE_CONFIRMED = 40;
    public const STATE_FINISHED = 70;
    public const STATE_EDIT = 80;
    public const STATE_DELETED = 90;

    /** Koncové stavy — přechod do nich doplní datum ukončení (D18). */
    public const END_STATES = [self::STATE_FINISHED, self::STATE_CANCELLED];

    public const RESET_SCOPE_FISCAL_YEAR = 'fiscal_year';
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

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
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

    /** Další pořadí v řadě z čítače (přepsatelné v testech). */
    protected function nextSequence(int $seriesId, ?int $fiscalYearId): int
    {
        return $this->sequenceCounter()->next($seriesId, $fiscalYearId, !$this->externalTransaction);
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
