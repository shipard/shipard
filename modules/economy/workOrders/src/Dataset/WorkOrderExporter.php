<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Dataset;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Dataset\SetupReferences;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Export\CanonicalParty;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportApplier;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodRepository;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Sekce `workOrders/` při dumpu (#131 fáze 2b, D30): jeden soubor per
 * zakázka (`shpd.dataset.workOrder.v1`) — hlavička s přirozenými klíči
 * místo id (řada kódem, zákazník identifikátory, středisko kódem, nadřazená
 * číslem, řada dokladů `{docType, code}` D37, účet kódem), řádky předpisu
 * (položka `ourCode`, jednotka `system_code`) a evidence období jen
 * s vystaveným dokladem (`docNumber`). Pořadí: nadřazené před podřízenými,
 * jinak podle čísla; slug = číslo zakázky.
 *
 * Nepřenáší se: zakázka řady bez kódu (varování), období bez dokladu
 * (naplánované, čekající, zastavené — varování s počtem), `content_hash`
 * (spočítá seeder), výsledky běhů.
 */
class WorkOrderExporter implements RecordExporter
{
    public const SECTION = WorkOrderSeeder::SECTION;
    public const FORMAT = 'shpd.dataset.workOrder.v1';

    /** Štíhlá identifikace zákazníka — osoba sama je v `persons/`. */
    public const PARTY_KEYS = ['name', 'country', 'companyId', 'taxId', 'vatId'];

    private const DELETED = 90;

    /** @var list<string> */
    private array $warnings = [];

    private readonly SetupReferences $references;
    private readonly WorkOrderTypes $types;

    /** @var array<int, ?string> */
    private array $seriesCodes = [];
    /** @var array<int, ?string> */
    private array $numbers = [];
    /** @var array<int, ?string> */
    private array $costCenterCodes = [];

    /**
     * @param array<string, TableDefinition> $tables aktivní tabulky DS (SetupReferences)
     */
    public function __construct(
        private readonly Connection $db,
        ?ConfigRuntime $config = null,
        array $tables = [],
    ) {
        $this->references = new SetupReferences($db, $tables);
        $this->types = new WorkOrderTypes($config);
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function exportAll(): array
    {
        return $this->export(null);
    }

    /** @param list<int> $ids */
    public function exportByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return $ids === [] ? [] : $this->export($ids);
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param list<int>|null $ids
     * @return list<ExportedRecord>
     */
    private function export(?array $ids): array
    {
        $sql = 'SELECT * FROM [' . WorkOrderDocument::TABLE . '] WHERE [docState] <> %i';
        $args = [self::DELETED];
        if ($ids !== null) {
            $sql .= ' AND [id] IN %in';
            $args[] = $ids;
        }
        $heads = [];
        foreach ($this->db->fetchAll($sql, ...$args) as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $heads[(int) $row['id']] = $row;
        }

        $out = [];
        foreach (self::ordered($heads) as $head) {
            $record = $this->record($head);
            if ($record !== null) {
                $out[] = $record;
            }
        }
        return $out;
    }

    /**
     * Pořadí souborů: nadřazené před podřízenými (hloubka v řetězci
     * nadřazených uvnitř výběru), pak zakázky bez čísla, pak číslo, pak id.
     *
     * @param array<int, array<string, mixed>> $heads id → hlavička
     * @return list<array<string, mixed>>
     */
    public static function ordered(array $heads): array
    {
        $depth = static function (int $id) use ($heads): int {
            $d = 0;
            $seen = [$id => true];
            $current = $heads[$id] ?? null;
            while ($current !== null) {
                $parent = (int) ($current['parent'] ?? 0);
                if ($parent <= 0 || !isset($heads[$parent]) || isset($seen[$parent])) {
                    break;
                }
                $seen[$parent] = true;
                $d++;
                $current = $heads[$parent];
            }
            return $d;
        };
        $key = static fn(array $h): array => [
            $depth((int) $h['id']),
            V::str($h['number'] ?? null) === null ? 0 : 1,
            (string) ($h['number'] ?? ''),
            (int) $h['id'],
        ];
        $list = array_values($heads);
        usort($list, static fn(array $a, array $b): int => $key($a) <=> $key($b));
        return $list;
    }

    /** @param array<string, mixed> $head */
    private function record(array $head): ?ExportedRecord
    {
        $id = (int) $head['id'];
        $number = V::str($head['number'] ?? null);
        $label = $number ?? "#{$id}";

        $seriesCode = $this->seriesCode((int) ($head['number_series'] ?? 0));
        if ($seriesCode === null) {
            $this->warnings[] = "workOrders: zakázka {$label}: řada zakázek bez kódu — nelze přenést";
            return null;
        }
        $state = self::stateName((int) ($head['docState'] ?? 0));
        if ($state === null) {
            $this->warnings[] = "workOrders: zakázka {$label}: stav {$head['docState']} formát nezná — nepřenáší se";
            return null;
        }
        $periodic = $this->isPeriodic($head);

        $workOrder = [
            'number'           => $number,
            'sequenceNumber'   => V::int($head['sequence_number'] ?? null),
            'numberSeries'     => $seriesCode,
            'title'            => V::str($head['title'] ?? null),
            'state'            => $state,
            'dateStart'        => V::date($head['date_start'] ?? null),
            'dateEnd'          => V::date($head['date_end'] ?? null),
            'costCenter'       => $this->costCenterCode((int) ($head['cost_center'] ?? 0), $label),
            'internalNote'     => V::str($head['internal_note'] ?? null),
            'customer'         => $this->party((int) ($head['customer'] ?? 0), $label),
            'currency'         => V::str($head['currency'] ?? null),
            'paymentReference' => V::str($head['payment_reference'] ?? null),
            'parent'           => $this->parentNumber((int) ($head['parent'] ?? 0), $label),
            'invoicing'        => $periodic ? $this->invoicing($head, $label) : null,
        ];

        $data = V::prune([
            'format'    => self::FORMAT,
            'workOrder' => $workOrder,
            'rows'      => $periodic ? $this->rows($id) : [],
            'periods'   => $periodic ? $this->periods($id, $label) : [],
        ]);
        return new ExportedRecord($id, $number ?? 'koncept', $data);
    }

    /** Stav payloadu importu; V opravě se přenáší jako V pořádku (data zakázky platí). */
    public static function stateName(int $docState): ?string
    {
        if ($docState === WorkOrderDocument::STATE_EDIT) {
            $docState = WorkOrderDocument::STATE_CONFIRMED;
        }
        $name = array_search($docState, WorkOrderImportApplier::STATES, true);
        return $name === false ? null : $name;
    }

    /** @param array<string, mixed> $head */
    private function isPeriodic(array $head): bool
    {
        $type = (string) ($head['type'] ?? '');
        if ($type !== '' && isset($this->types->all()[$type])) {
            return $this->types->invoicing($type) === WorkOrderTypes::INVOICING_PERIODIC;
        }
        // Bez konfigurace typů: periodická zakázka má předpis.
        return V::str($head['inv_periodicity'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $head
     * @return array<string, mixed>
     */
    private function invoicing(array $head, string $label): array
    {
        $vatMode = V::int($head['inv_vat_mode'] ?? null);
        $paymentMethod = V::int($head['inv_payment_method'] ?? null);

        $docSeriesId = V::int($head['inv_number_series'] ?? null);
        $docSeries = $this->references->export(SetupReferences::DOC_SERIES_TABLE, $docSeriesId);
        if ($docSeriesId !== null && $docSeriesId > 0 && $docSeries === null) {
            $this->warnings[] = "workOrders: zakázka {$label}: řada dokladů #{$docSeriesId} neexistuje — přepis řady se nepřenáší";
        }
        $bankAccountId = V::int($head['inv_bank_account'] ?? null);
        $bankAccount = $this->references->export('economy_codebooks_bank_accounts', $bankAccountId);
        if ($bankAccountId !== null && $bankAccountId > 0 && $bankAccount === null) {
            $this->warnings[] = "workOrders: zakázka {$label}: bankovní účet #{$bankAccountId} bez kódu — přepis účtu se nepřenáší";
        }

        return [
            'periodicity'   => V::str($head['inv_periodicity'] ?? null),
            'invoiceFrom'   => V::date($head['inv_from'] ?? null),
            'docText'       => V::str($head['inv_doc_text'] ?? null),
            'docType'       => V::str($head['inv_doc_type'] ?? null),
            'numberSeries'  => $docSeries,
            'dueDays'       => V::int($head['inv_due_days'] ?? null),
            'timing'        => V::str($head['inv_timing'] ?? null),
            'vatMode'       => $vatMode !== null ? DocumentApplier::canonicalVatMode($vatMode) : null,
            'paymentMethod' => $paymentMethod !== null ? DocumentApplier::canonicalPaymentMethod($paymentMethod) : null,
            'bankAccount'   => $bankAccount,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $workOrderId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [r].*, [i].[code] AS [item_code], [u].[system_code] AS [unit_code], [u].[shortcut] AS [unit_shortcut]'
            . ' FROM [' . WorkOrderDocument::ROWS_TABLE . '] AS [r]'
            . ' LEFT JOIN [economy_items] AS [i] ON [i].[id] = [r].[item]'
            . ' LEFT JOIN [core_units] AS [u] ON [u].[id] = [r].[unit]'
            . ' WHERE [r].[work_order] = %i ORDER BY [r].[order_pos], [r].[id]',
            $workOrderId,
        );
        $out = [];
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            $out[] = V::prune([
                'item'        => V::str($r['item_code'] ?? null),
                'description' => V::str($r['description'] ?? null),
                'quantity'    => V::float($r['quantity'] ?? null),
                'unit'        => V::str($r['unit_code'] ?? null) ?? V::str($r['unit_shortcut'] ?? null),
                'unitPrice'   => V::float($r['unit_price'] ?? null),
                'vatCode'     => V::str($r['vat_code'] ?? null),
                'operation'   => V::str($r['operation'] ?? null),
                'validFrom'   => V::date($r['valid_from'] ?? null),
                'validTo'     => V::date($r['valid_to'] ?? null),
            ]);
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function periods(int $workOrderId, string $label): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [p].[period_from], [p].[period_to], [p].[state], [d].[doc_number], [d].[docState] AS [doc_state]'
            . ' FROM [' . PeriodRepository::TABLE . '] AS [p]'
            . ' LEFT JOIN [docs_core_heads] AS [d] ON [d].[id] = [p].[doc]'
            . ' WHERE [p].[work_order] = %i ORDER BY [p].[period_from], [p].[id]',
            $workOrderId,
        );
        $out = [];
        $skipped = 0;
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            $docNumber = V::str($r['doc_number'] ?? null);
            $issued = (string) ($r['state'] ?? '') === PeriodRepository::STATE_ISSUED
                && $docNumber !== null
                && (int) ($r['doc_state'] ?? 0) !== self::DELETED;
            if (!$issued) {
                $skipped++;
                continue;
            }
            $out[] = [
                'from'      => V::date($r['period_from'] ?? null),
                'to'        => V::date($r['period_to'] ?? null),
                'state'     => PeriodRepository::STATE_ISSUED,
                'docNumber' => $docNumber,
            ];
        }
        if ($skipped > 0) {
            $this->warnings[] = "workOrders: zakázka {$label}: {$skipped} období bez vystaveného dokladu"
                . ' (naplánované, čekající nebo zastavené) se nepřenáší';
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function party(int $personId, string $label): ?array
    {
        if ($personId <= 0) {
            return null;
        }
        $party = CanonicalParty::fromPerson($this->db, $personId);
        if ($party === null) {
            $this->warnings[] = "workOrders: zakázka {$label}: zákazník #{$personId} neexistuje — nepřenáší se";
            return null;
        }
        $out = [];
        foreach (self::PARTY_KEYS as $key) {
            $out[$key] = $party[$key] ?? null;
        }
        return V::prune($out);
    }

    private function seriesCode(int $seriesId): ?string
    {
        if ($seriesId <= 0) {
            return null;
        }
        if (!array_key_exists($seriesId, $this->seriesCodes)) {
            $row = $this->db->fetch('SELECT [number_code] FROM [' . WorkOrderDocument::SERIES_TABLE . '] WHERE [id] = %i', $seriesId);
            $row = $row === null ? null : (is_array($row) ? $row : $row->toArray());
            $this->seriesCodes[$seriesId] = $row === null ? null : V::str($row['number_code'] ?? null);
        }
        return $this->seriesCodes[$seriesId];
    }

    private function parentNumber(int $parentId, string $label): ?string
    {
        if ($parentId <= 0) {
            return null;
        }
        if (!array_key_exists($parentId, $this->numbers)) {
            $row = $this->db->fetch('SELECT [number] FROM [' . WorkOrderDocument::TABLE . '] WHERE [id] = %i', $parentId);
            $row = $row === null ? null : (is_array($row) ? $row : $row->toArray());
            $this->numbers[$parentId] = $row === null ? null : V::str($row['number'] ?? null);
        }
        if ($this->numbers[$parentId] === null) {
            $this->warnings[] = "workOrders: zakázka {$label}: nadřazená zakázka #{$parentId} bez čísla — vazba se nepřenáší";
        }
        return $this->numbers[$parentId];
    }

    private function costCenterCode(int $costCenterId, string $label): ?string
    {
        if ($costCenterId <= 0) {
            return null;
        }
        if (!array_key_exists($costCenterId, $this->costCenterCodes)) {
            $this->costCenterCodes[$costCenterId] = V::str($this->references->export('economy_codebooks_cost_centers', $costCenterId));
        }
        if ($this->costCenterCodes[$costCenterId] === null) {
            $this->warnings[] = "workOrders: zakázka {$label}: středisko #{$costCenterId} bez kódu — nepřenáší se";
        }
        return $this->costCenterCodes[$costCenterId];
    }
}
