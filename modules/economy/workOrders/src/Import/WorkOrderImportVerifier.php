<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Import;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\DocRowOperationRules;
use Shipard\Module\Economy\Codebooks\FiscalYearLookup;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingSettings;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingSettingsResolver;
use Shipard\Module\Economy\WorkOrders\KindDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Kontrola payloadu `shpd.workOrders.workOrder.v1` před zápisem
 * (tasks/work-orders-import.md §1, docs/work-orders.md §6). Tvar už ohlídalo
 * JSON Schema; tady se ověřuje, co schéma neumí a co by `WorkOrderDocument`
 * buď neohlásil, nebo tiše opravil (neexterní typ zákazníka vynuluje místo
 * chyby):
 *
 *  - existence referencí podle id (řada zakázek, středisko, zákazník,
 *    položka, řada dokladů, bankovní účet) — nová id drží runner (I1);
 *  - kódy: měna (`world.base.currencies`), jednotka (`UnitResolver`:
 *    system_code / zkratka / název), kód DPH (kódy země výchozí registrace
 *    pro směr cílového dokladu, včetně skrytých), pohyb (`docs.core.rowOperations`
 *    pro efektivní typ dokladu, ne systémový);
 *  - pravidla typu z `WorkOrderTypes` podle řady: strana jen u externích,
 *    nadřazená jen u jednorázových, `invoicing` a `rows` jen u periodického
 *    typu; periodická mimo Koncept potřebuje periodicitu a aspoň jeden řádek;
 *  - řada dokladů v přepisu musí být typu efektivního dokladu
 *    (`InvoicingSettingsResolver`: zakázka → druh);
 *  - číslo a čítač (I4): `sequenceNumber` u řady s ročním restartem potřebuje
 *    fiskální rok data zahájení; `number` bez `sequenceNumber` = varování
 *    `counter_not_synced`; `sequenceNumber` bez `number` je chyba.
 *
 * Ostatní pravidla (zákazník povinný při potvrzení, nadřazená ne periodická,
 * cyklus, duplicitní číslo, splatnost…) hlídá `WorkOrderDocument` při
 * zápisu a applier je překládá na cesty payloadu. Kontrola nic nezapisuje.
 *
 * DB přístup je v protected metodách (přepsatelné v testech). Bez
 * zkompilované konfigurace (typy, měny, pohyby) se příslušná pravidla
 * přeskočí — degradace, ne crash.
 */
class WorkOrderImportVerifier
{
    public const CODE_NOT_FOUND = 'not_found';
    public const CODE_INVALID_STATE = 'invalid_state';
    public const CODE_NOT_ALLOWED = 'not_allowed';
    public const CODE_REQUIRED = 'required';
    public const CODE_TYPE_UNKNOWN = 'type_unknown';
    public const CODE_CURRENCY_UNKNOWN = 'currency_unknown';
    public const CODE_UNIT_NOT_FOUND = 'unit_not_found';
    public const CODE_VAT_CODE_UNKNOWN = 'vat_code_unknown';
    public const CODE_OPERATION_INVALID = 'operation_invalid';
    public const CODE_SERIES_TYPE_MISMATCH = 'series_type_mismatch';
    public const CODE_PARENT_NOT_FOUND = 'parent_not_found';
    public const CODE_FISCAL_YEAR_MISSING = 'fiscal_year_missing';
    public const CODE_COUNTER_NOT_SYNCED = 'counter_not_synced';
    public const CODE_NUMBER_REQUIRED = 'number_required';

    public const CURRENCIES_CFG_ITEM = 'world.base.currencies';
    public const ROW_OPERATIONS_CFG_ITEM = 'docs.core.rowOperations';

    /** Odkazy hlavičky podle id: klíč payloadu → [tabulka, kód chyby]. */
    private const HEAD_REFERENCES = [
        'costCenter' => ['economy_codebooks_cost_centers', 'cost_center_not_found'],
        'customer'   => ['base_persons_persons', 'customer_not_found'],
    ];

    /** Pole strany — jen u externího typu. */
    private const PARTY_KEYS = ['customer', 'currency', 'paymentReference'];

    public function __construct(
        protected readonly ?\Dibi\Connection $db,
        protected readonly ?ConfigRuntime $config,
    ) {
    }

    /**
     * @param array<string, mixed> $payload payload, který prošel schématem
     */
    public function verify(array $payload): WorkOrderImportCheck
    {
        $wo = $payload['workOrder'];
        $invoicing = is_array($wo['invoicing'] ?? null) ? $wo['invoicing'] : null;
        $rows = is_array($payload['rows'] ?? null) ? array_values($payload['rows']) : [];
        $state = (string) $wo['state'];
        $draft = $state === 'draft';
        $issues = [];

        // ── Řada → druh → typ ───────────────────────────────────────────────
        $seriesId = (int) $wo['numberSeries'];
        $series = $this->loadSeries($seriesId);
        if ($series === null) {
            if ($this->db !== null) {
                $issues[] = $this->issue('workOrder.numberSeries', self::CODE_NOT_FOUND, "Číselná řada zakázek #{$seriesId} v tomto zdroji dat neexistuje.");
            }
        } elseif ((int) ($series['docState'] ?? 0) === WorkOrderDocument::STATE_DELETED) {
            $issues[] = $this->issue('workOrder.numberSeries', self::CODE_INVALID_STATE, "Číselná řada zakázek #{$seriesId} je smazaná.");
        }
        $kind = $series !== null && (int) ($series['kind'] ?? 0) > 0 ? $this->loadKind((int) $series['kind']) : null;

        $types = new WorkOrderTypes($this->config);
        $type = (string) ($series['type'] ?? '');
        $rulesApply = $type !== '' && isset($types->all()[$type]);
        if ($type !== '' && $types->isUnknown($type)) {
            $issues[] = $this->issue('workOrder.numberSeries', self::CODE_TYPE_UNKNOWN, "Druh řady má neznámý typ zakázky „{$type}“.");
        }
        $external = $rulesApply && $types->isExternal($type);
        $oneOff = $rulesApply && $types->isOneOff($type);
        $periodic = $rulesApply && $types->invoicing($type) === WorkOrderTypes::INVOICING_PERIODIC;
        $typeLabel = $rulesApply ? $types->label($type) : $type;

        // ── Pravidla typu ───────────────────────────────────────────────────
        if ($rulesApply && !$external) {
            foreach (self::PARTY_KEYS as $key) {
                if (($wo[$key] ?? null) !== null) {
                    $issues[] = $this->issue("workOrder.{$key}", self::CODE_NOT_ALLOWED, "Zákazník, měna a VS patří jen externí zakázce — typ „{$typeLabel}“ stranu nemá.");
                }
            }
        }
        if ($rulesApply && !$oneOff && ($wo['parent'] ?? null) !== null) {
            $issues[] = $this->issue('workOrder.parent', self::CODE_NOT_ALLOWED, "Nadřazenou zakázku může mít jen jednorázová zakázka — typ „{$typeLabel}“ ne.");
        }
        if ($rulesApply && !$periodic) {
            if ($invoicing !== null) {
                $issues[] = $this->issue('workOrder.invoicing', self::CODE_NOT_ALLOWED, "Fakturační předpis má jen periodická zakázka — typ „{$typeLabel}“ nefakturuje.");
            }
            if ($rows !== []) {
                $issues[] = $this->issue('rows', self::CODE_NOT_ALLOWED, "Řádky předpisu má jen periodická zakázka — typ „{$typeLabel}“ nefakturuje.");
            }
        }
        if ($periodic && !$draft) {
            if (($invoicing['periodicity'] ?? null) === null) {
                $issues[] = $this->issue('workOrder.invoicing.periodicity', self::CODE_REQUIRED, 'Periodicita je povinná u periodické zakázky mimo Koncept.');
            }
            if ($rows === []) {
                $issues[] = $this->issue('rows', self::CODE_REQUIRED, 'Periodická zakázka mimo Koncept potřebuje aspoň jeden řádek předpisu.');
            }
        }
        if (!$draft && ($wo['dateStart'] ?? null) === null) {
            $issues[] = $this->issue('workOrder.dateStart', self::CODE_REQUIRED, 'Datum zahájení je povinné u zakázky mimo Koncept.');
        }

        // ── Reference hlavičky a měna ───────────────────────────────────────
        foreach (self::HEAD_REFERENCES as $key => [$table, $code]) {
            $id = $wo[$key] ?? null;
            if ($id !== null && !$this->referenceExists($table, (int) $id)) {
                $issues[] = $this->issue("workOrder.{$key}", $code, "Záznam {$table} #{$id} v tomto zdroji dat neexistuje.");
            }
        }
        $currency = $wo['currency'] ?? null;
        $currencies = $this->config?->cfgItem(self::CURRENCIES_CFG_ITEM);
        if ($currency !== null && is_array($currencies) && $currencies !== [] && !isset($currencies[strtolower((string) $currency)])) {
            $issues[] = $this->issue('workOrder.currency', self::CODE_CURRENCY_UNKNOWN, "Neznámá měna „{$currency}“.");
        }

        // ── Fakturační předpis: řada dokladů vs. efektivní typ dokladu ──────
        $settings = InvoicingSettingsResolver::resolve([
            'inv_doc_type'      => $invoicing['docType'] ?? null,
            'inv_number_series' => $invoicing['numberSeries'] ?? null,
        ], $kind);
        $docType = $settings->docType;
        $docSeriesId = $invoicing['numberSeries'] ?? null;
        if ($docSeriesId !== null) {
            $docSeries = $this->loadDocSeries((int) $docSeriesId);
            if ($docSeries === null) {
                if ($this->db !== null) {
                    $issues[] = $this->issue('workOrder.invoicing.numberSeries', self::CODE_NOT_FOUND, "Řada dokladů #{$docSeriesId} v tomto zdroji dat neexistuje.");
                }
            } elseif ((int) ($docSeries['docState'] ?? 0) === WorkOrderDocument::STATE_DELETED) {
                $issues[] = $this->issue('workOrder.invoicing.numberSeries', self::CODE_INVALID_STATE, "Řada dokladů #{$docSeriesId} je smazaná.");
            } elseif ($docType !== null && (string) ($docSeries['doc_type'] ?? '') !== $docType) {
                $issues[] = $this->issue('workOrder.invoicing.numberSeries', self::CODE_SERIES_TYPE_MISMATCH, "Řada dokladů #{$docSeriesId} je typu „{$docSeries['doc_type']}“, předpis vystavuje „{$docType}“.");
            }
        }
        $bankAccount = $invoicing['bankAccount'] ?? null;
        if ($bankAccount !== null && !$this->referenceExists('economy_codebooks_bank_accounts', (int) $bankAccount)) {
            $issues[] = $this->issue('workOrder.invoicing.bankAccount', 'bank_account_not_found', "Bankovní účet #{$bankAccount} v tomto zdroji dat neexistuje.");
        }

        // ── Řádky ───────────────────────────────────────────────────────────
        $unitIds = [];
        $vatCodes = null;
        $operations = $this->config?->cfgItem(self::ROW_OPERATIONS_CFG_ITEM);
        foreach ($rows as $index => $row) {
            $item = $row['item'] ?? null;
            if ($item !== null && !$this->referenceExists('economy_items', (int) $item)) {
                $issues[] = $this->issue("rows.{$index}.item", 'item_not_found', "Položka #{$item} v tomto zdroji dat neexistuje.");
            }
            $unit = trim((string) ($row['unit'] ?? ''));
            if ($unit !== '') {
                $unitId = $this->resolveUnit($unit);
                if ($unitId === null) {
                    $issues[] = $this->issue("rows.{$index}.unit", self::CODE_UNIT_NOT_FOUND, "Jednotka „{$unit}“ v tomto zdroji dat neexistuje (system_code, zkratka ani název).");
                } else {
                    $unitIds[$index] = $unitId;
                }
            }
            $vatCode = trim((string) ($row['vatCode'] ?? ''));
            if ($vatCode !== '') {
                $vatCodes ??= $this->vatCodes($docType);
                if ($vatCodes !== [] && !in_array($vatCode, $vatCodes, true)) {
                    $issues[] = $this->issue("rows.{$index}.vatCode", self::CODE_VAT_CODE_UNKNOWN, "Kód DPH „{$vatCode}“ není mezi kódy pro „{$docType}“.");
                }
            }
            $operation = trim((string) ($row['operation'] ?? ''));
            if ($operation !== '' && $docType !== null && is_array($operations) && $operations !== []
                && !self::operationAllowed($operation, $docType, $operations)
            ) {
                $issues[] = $this->issue("rows.{$index}.operation", self::CODE_OPERATION_INVALID, "Pohyb „{$operation}“ není pohybem dokladu „{$docType}“ (nebo je systémový).");
            }
        }

        // ── Nadřazená podle čísla ───────────────────────────────────────────
        $parentId = null;
        $parentNumber = trim((string) ($wo['parent'] ?? ''));
        if ($parentNumber !== '') {
            $parent = $this->findWorkOrder($parentNumber);
            if ($parent === null) {
                $issues[] = $this->issue('workOrder.parent', self::CODE_PARENT_NOT_FOUND, "Nadřazená zakázka {$parentNumber} v tomto zdroji dat neexistuje — importuj ji dřív.");
            } else {
                $parentId = (int) $parent['id'];
            }
        }

        // ── Číslo a čítač (I4) ──────────────────────────────────────────────
        $number = trim((string) ($wo['number'] ?? ''));
        $sequence = $wo['sequenceNumber'] ?? null;
        $fiscalYearId = null;
        if ($number !== '' && $sequence === null) {
            $issues[] = $this->issue('workOrder.sequenceNumber', self::CODE_COUNTER_NOT_SYNCED, "Číslo {$number} bez pořadí v řadě — čítač řady se nesrovná.", 'warning');
        } elseif ($number === '' && $sequence !== null) {
            $issues[] = $this->issue('workOrder.sequenceNumber', self::CODE_NUMBER_REQUIRED, 'Pořadí v řadě bez čísla nemá smysl — pošli i `number`, nebo pořadí vynech.');
        }
        if ($series !== null && $number !== '' && $sequence !== null) {
            $yearly = (string) ($series['reset_scope'] ?? WorkOrderDocument::RESET_SCOPE_FISCAL_YEAR) === WorkOrderDocument::RESET_SCOPE_FISCAL_YEAR;
            if ($yearly) {
                $start = (string) ($wo['dateStart'] ?? '');
                $fiscalYearId = $start !== '' ? $this->fiscalYearIdForDate($start) : null;
                if ($fiscalYearId === null && $this->db !== null) {
                    $issues[] = $this->issue('workOrder.dateStart', self::CODE_FISCAL_YEAR_MISSING, 'Řada čísluje po fiskálních letech a pro datum zahájení není založený fiskální rok — čítač nejde srovnat.');
                }
            }
        }

        return new WorkOrderImportCheck($issues, $series, $kind, $parentId, $unitIds, $fiscalYearId);
    }

    /**
     * Je pohyb pohybem cílového dokladu a ne systémový (vzor
     * WorkOrderRowsForm::operationOptions)?
     *
     * @param array<string, mixed> $operations cfgItem docs.core.rowOperations
     */
    public static function operationAllowed(string $operation, string $docType, array $operations): bool
    {
        $entry = $operations[$operation] ?? null;
        if (!is_array($entry) || !is_array($entry['docTypes'][$docType] ?? null)) {
            return false;
        }
        return !DocRowOperationRules::isSystem($operation, $operations);
    }

    /** @return array{severity: string, path: string, code: string, message: string} */
    private function issue(string $path, string $code, string $message, string $severity = 'error'): array
    {
        return ['severity' => $severity, 'path' => $path, 'code' => $code, 'message' => $message];
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    /** Řada zakázek s typem druhu (id, kind, reset_scope, docState, type), null = neexistuje. */
    protected function loadSeries(int $seriesId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [s].[id], [s].[kind], [s].[reset_scope], [s].[docState], [k].[type]'
            . ' FROM [' . WorkOrderDocument::SERIES_TABLE . '] [s]'
            . ' LEFT JOIN [' . KindDocument::TABLE . '] [k] ON [k].[id] = [s].[kind]'
            . ' WHERE [s].[id] = %i',
            $seriesId,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Druh (id, type, sloupce inv_*), null = neexistuje. */
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

    /** Existuje nesmazaný záznam? Bez DB se bere jako existující. */
    protected function referenceExists(string $table, int $id): bool
    {
        if ($this->db === null) {
            return true;
        }
        $row = $this->db->fetch('SELECT [id] FROM [' . $table . '] WHERE [id] = %i AND [docState] <> 90', $id);
        return $row !== null && $row !== false;
    }

    /** Id jednotky podle kódu (system_code, zkratka, název — UnitResolver), null = nenalezena. */
    protected function resolveUnit(string $unit): ?int
    {
        if ($this->db === null) {
            return null;
        }
        return (new UnitResolver($this->db))->resolve($unit)->matchedId;
    }

    /**
     * Kódy DPH země výchozí registrace pro směr cílového dokladu (včetně
     * skrytých — import nenabízí, ověřuje). Prázdné = kontext neznámý
     * (bez registrace, bez typu dokladu), kontrola se přeskočí.
     *
     * @return list<string>
     */
    protected function vatCodes(?string $docType): array
    {
        if ($this->db === null || $this->config === null || $docType === null) {
            return [];
        }
        $direction = match (DocDocument::resolveTradeDir(['doc_type' => $docType], $this->config)) {
            1 => 'output',
            2 => 'input',
            default => null,
        };
        $country = $this->db->fetchSingle(
            'SELECT [country] FROM [economy_codebooks_vat_registrations]'
            . ' WHERE [docState] IN (10, 40, 80) ORDER BY [country] ASC, [id] ASC LIMIT 1',
        );
        if ($direction === null || !is_string($country) || $country === '') {
            return [];
        }
        try {
            $codes = (new VatRateResolver($this->config))->getVatCodes($country, $direction, 'domestic', includeHidden: true);
        } catch (\LogicException) {
            return [];
        }
        return array_map('strval', array_keys($codes));
    }

    /** Zakázka podle čísla v libovolném stavu (id, docState), null = neexistuje. */
    protected function findWorkOrder(string $number): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [docState] FROM [' . WorkOrderDocument::TABLE . '] WHERE [number] = %s LIMIT 1',
            $number,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    protected function fiscalYearIdForDate(string $date): ?int
    {
        return $this->db !== null ? FiscalYearLookup::yearIdForDate($this->db, $date) : null;
    }
}
