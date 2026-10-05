<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Export;

use Dibi\Connection;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;

/**
 * Sekce `setup/` sady — číselníky, které `ds-reset` + provisionery
 * neobnoví, ale sada na nich stojí (R1 v tasks/dataset-phase1.md):
 * vlastní bankovní účty, registrace DPH, šanony spisovny, mailboxy.
 *
 * Jeden soubor per tabulka (`shpd.dataset.setup.v1`), řádky s přirozeným
 * klíčem, bez interních id a auditních sloupců. Hodnoty se typují podle
 * `TableDefinition`, aby JSON nesl int/float/bool místo řetězců z MySQL.
 * Tabulky modulů, které na DS nejsou aktivní, se přeskočí.
 */
final class SetupExporter
{
    public const FORMAT = 'shpd.dataset.setup.v1';

    /**
     * Pseudo-tabulka `settings`: rozhodovací parametry DS z `core_system_settings`
     * (`economy.accountChart`, `economy.fiscalYearStartMonth`, …). Bez nich
     * provisionery po resetu neseedují účtový rozvrh ani fiskální roky a
     * doklady ze sady by se nezaúčtovaly. Aplikují se **před** `ds-reset`.
     */
    public const SETTINGS_TABLE = 'settings';

    /** Prefixy / klíče nastavení, které sada nese (branding soubory ne). */
    public const SETTINGS_PREFIXES = ['economy.'];
    // app.icon / app.companyLogo odkazují na soubory v branding/ — nepřenosné.
    public const SETTINGS_KEYS = ['app.name', 'app.shortName', 'app.theme', 'app.shell'];

    /** @var array<string, array{table: string, key: list<string>}> */
    public const TABLES = [
        'settings'          => ['table' => 'core_system_settings',                'key' => ['key']],
        'bank_accounts'     => ['table' => 'economy_codebooks_bank_accounts',     'key' => ['code']],
        'vat_registrations' => ['table' => 'economy_codebooks_vat_registrations', 'key' => ['country', 'name']],
        'binders'           => ['table' => 'base_registry_binders',               'key' => ['name']],
        'mailboxes'         => ['table' => 'core_mail_mailboxes',                 'key' => ['mailbox_id']],
    ];

    private const SKIP_COLUMNS = ['id', 'docStateMain', 'created', 'modified', 'created_by'];

    /**
     * FK sloupce, jejichž vynechání se nehlásí: nesou vazbu na záznam, který
     * sada z principu nepřenáší (osoba FÚ na registraci DPH, uživatel jako
     * autor automaticky vystavených dokladů řady), takže warning by jen
     * šuměl v každém dumpu.
     */
    private const SILENT_FK_COLUMNS = ['tax_office_person', 'auto_author'];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<int, ?string> */
    private array $accountNumberCache = [];

    /**
     * @param array<string, TableDefinition> $tables aktivní tabulky DS (TableLoader::load)
     */
    public function __construct(
        private readonly Connection $db,
        private readonly array $tables,
    ) {}

    /**
     * @return list<ExportedRecord> jeden záznam per tabulka (id = 0, slug = klíč sekce)
     */
    public function exportAll(): array
    {
        $out = [];
        foreach (self::TABLES as $key => $spec) {
            $def = $this->tables[$spec['table']] ?? null;
            if ($def === null) {
                continue;
            }
            $out[] = new ExportedRecord(0, $key, [
                'format' => self::FORMAT,
                'table'  => $key,
                'rows'   => $key === self::SETTINGS_TABLE
                    ? $this->exportSettings()
                    : $this->exportTable($spec['table'], $def, $spec['key']),
            ]);
        }
        return $out;
    }

    /** @return list<string> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param list<string> $keyColumns
     * @return list<array<string, mixed>>
     */
    private function exportTable(string $tableName, TableDefinition $def, array $keyColumns): array
    {
        /** @var array<string, ColumnDefinition> $columns */
        $columns = [];
        foreach ($def->columns as $col) {
            $columns[$col->id] = $col;
        }
        $hasDocState = isset($columns['docState']);

        // FK sloupce: interní id na jiném DS neplatí. Účet z rozvrhu se přenese
        // číslem (seed ho resolvuje zpět), ostatní reference se vynechají.
        foreach ($columns as $colId => $col) {
            if ($col->reference !== null && !in_array($colId, self::SKIP_COLUMNS, true)
                && $col->reference !== 'economy_accounting_accounts') {
                if (!in_array($colId, self::SILENT_FK_COLUMNS, true)) {
                    $this->warnings[] = "setup {$tableName}: sloupec '{$colId}' (FK na {$col->reference}) se nepřenáší";
                }
                unset($columns[$colId]);
            }
        }

        $order = implode(', ', array_map(static fn(string $c): string => "[{$c}]", [...$keyColumns, 'id']));
        $where = $hasDocState ? ' WHERE [docState] <> 90' : '';
        $rows = $this->db->fetchAll("SELECT * FROM [{$tableName}]{$where} ORDER BY {$order}");

        $out = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $record = [];
            foreach ($columns as $colId => $col) {
                if (in_array($colId, self::SKIP_COLUMNS, true) || !array_key_exists($colId, $row)) {
                    continue;
                }
                $record[$colId] = $col->reference === 'economy_accounting_accounts'
                    ? $this->accountNumber($row[$colId])
                    : self::typedValue($col, $row[$colId]);
            }
            $out[] = $record;
        }
        return $out;
    }

    /**
     * @return list<array{key: string, value: mixed}>
     */
    private function exportSettings(): array
    {
        $rows = $this->db->fetchAll('SELECT [key], [value] FROM [core_system_settings] ORDER BY [key]');
        $out = [];
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            $key = (string) ($r['key'] ?? '');
            if (!self::isPortableSetting($key)) {
                continue;
            }
            $value = is_string($r['value'] ?? null) ? json_decode($r['value'], true) : null;
            if ($value === null) {
                continue;
            }
            $out[] = ['key' => $key, 'value' => $value];
        }
        return $out;
    }

    public static function isPortableSetting(string $key): bool
    {
        if (in_array($key, self::SETTINGS_KEYS, true)) {
            return true;
        }
        foreach (self::SETTINGS_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function accountNumber(mixed $id): ?string
    {
        $id = V::int($id);
        if ($id === null || $id <= 0) {
            return null;
        }
        if (!array_key_exists($id, $this->accountNumberCache)) {
            $row = $this->db->fetch('SELECT [number] FROM [economy_accounting_accounts] WHERE [id] = %i', $id);
            $this->accountNumberCache[$id] = $row !== null ? V::str($row['number']) : null;
        }
        return $this->accountNumberCache[$id];
    }

    public static function typedValue(ColumnDefinition $col, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($col->type) {
            'tinyint', 'smallint', 'int', 'bigint', 'enumInt' => V::int($value),
            'numeric', 'float' => V::float($value),
            'boolean' => V::bool($value),
            'date' => V::date($value),
            'datetime' => V::dateTime($value),
            'json' => V::json($value),
            default => is_scalar($value) || $value instanceof \DateTimeInterface ? V::str($value) : null,
        };
    }
}
