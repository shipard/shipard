<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Seed;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Core\Exchange\Dataset\SetupReferences;
use Shipard\Module\Core\Exchange\Export\SetupExporter;

/**
 * `setup/<tabulka>.jsonc` → číselníky přes `TableGateway` (Document hooky,
 * `docStateMain` odvozený z cfgItemu). Upsert podle přirozeného klíče
 * tabulky (`SetupExporter::TABLES`) — default mailbox po resetu existuje
 * a jen se aktualizuje. FK přichází přirozeným klíčem (`SetupReferences`):
 * účet z rozvrhu číslem → id jako dosud (chybějící = null), ostatní
 * přenosné reference (druh názvem, účet kódem, řada dokladů
 * `{docType, code}` — D37) přísně: nenalezená nebo nejednoznačná = chyba
 * řádku.
 */
final class SetupSeeder implements SectionSeeder
{
    public function section(): string
    {
        return 'setup';
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        $references = new SetupReferences($ctx->db, $ctx->tables);
        foreach ($ctx->reader->listFiles('setup') as $rel) {
            try {
                $file = $ctx->reader->readJsonc($rel);
            } catch (DatasetException $e) {
                $report->failed('setup', $e->getMessage());
                continue;
            }
            $key = (string) ($file['table'] ?? '');
            $spec = SetupExporter::TABLES[$key] ?? null;
            if ($spec === null) {
                $report->failed('setup', "{$rel}: neznámá tabulka '{$key}'");
                continue;
            }
            if ($key === SetupExporter::SETTINGS_TABLE) {
                // Už aplikováno před resetem (DatasetSeedCommand); tady idempotentně znovu,
                // aby --no-reset i přímé volání seederu dopadly stejně a počty seděly.
                try {
                    self::applySettingsRows((array) ($file['rows'] ?? []), $ctx->dsConnection);
                    $report->ok('setup');
                } catch (\Throwable $e) {
                    $report->failed('setup', "{$rel}: {$e->getMessage()}");
                }
                continue;
            }
            $def = $ctx->tables[$spec['table']] ?? null;
            if ($def === null) {
                $report->skipped('setup', "{$rel}: tabulka {$spec['table']} na tomto DS není (modul neaktivní)");
                continue;
            }

            $columns = [];
            foreach ($def->columns as $col) {
                $columns[$col->id] = $col;
            }
            $gateway = new TableGateway(
                $spec['table'], $ctx->db, $ctx->registry, $def->childTables, $ctx->config, $ctx->dsConfig,
                docStates: $def->docStates, tableDef: $def,
            );

            // Jednotka počítání = soubor (tabulka), stejně jako v dumpu;
            // chyba libovolného řádku = chyba souboru.
            $rowErrors = 0;
            foreach ((array) ($file['rows'] ?? []) as $i => $row) {
                if (!is_array($row)) {
                    $report->warning("setup {$rel}: rows[{$i}] není objekt");
                    $rowErrors++;
                    continue;
                }
                try {
                    $this->upsertRow($ctx, $gateway, $references, $spec, $columns, $row);
                } catch (\Throwable $e) {
                    $label = implode('/', array_map(static fn(string $k) => (string) ($row[$k] ?? '?'), $spec['key']));
                    $report->warning("setup {$rel} [{$label}]: {$e->getMessage()}");
                    $rowErrors++;
                }
            }
            if ($rowErrors > 0) {
                $report->failed('setup', "{$rel}: {$rowErrors} řádků se nepodařilo uložit (viz varování)");
            } else {
                $report->ok('setup');
            }
        }
    }

    /**
     * `setup/settings.jsonc` → `core_system_settings`. Volá se před `ds-reset`
     * (tabulka je keepOnReset, provisionery v ds-upgrade hodnoty čtou).
     *
     * @return int počet zapsaných klíčů (0 = soubor v sadě není)
     */
    public static function applySettings(DatasetReader $reader, DataSourceConnection $dsConnection): int
    {
        $rel = 'setup/' . SetupExporter::SETTINGS_TABLE . '.jsonc';
        if (!$reader->fileExists($rel)) {
            return 0;
        }
        $file = $reader->readJsonc($rel);
        return self::applySettingsRows((array) ($file['rows'] ?? []), $dsConnection);
    }

    /**
     * @param array<int, mixed> $rows
     */
    private static function applySettingsRows(array $rows, DataSourceConnection $dsConnection): int
    {
        $store = new SettingsStore($dsConnection);
        $n = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['key'] ?? null) || $row['key'] === '') {
                continue;
            }
            if (!SetupExporter::isPortableSetting($row['key'])) {
                throw new DatasetException("nastavení '{$row['key']}' sada nesmí nést");
            }
            $store->set($row['key'], $row['value'] ?? null);
            $n++;
        }
        return $n;
    }

    /**
     * @param array{table: string, key: list<string>} $spec
     * @param array<string, \Shipard\Core\Database\ColumnDefinition> $columns
     * @param array<string, mixed> $row
     */
    private function upsertRow(
        SeedContext $ctx,
        TableGateway $gateway,
        SetupReferences $references,
        array $spec,
        array $columns,
        array $row,
    ): void {
        $data = [];
        foreach ($row as $colId => $value) {
            $col = $columns[$colId] ?? null;
            if ($col === null || $colId === 'id') {
                continue; // sloupec na tomto DS není (jiná sada modulů) — vynechat
            }
            if ($col->reference === SetupReferences::ACCOUNTS_TABLE && is_string($value)) {
                // Účet osnovy jako dosud: chybějící analytika = null, ohlásí ji
                // až engine (bank_account_not_found), ne seed.
                $acc = $ctx->db->fetch(
                    'SELECT [id] FROM [economy_accounting_accounts] WHERE [number] = %s AND [docState] <> 90 ORDER BY [docState] = 70, [id] LIMIT 1',
                    $value,
                );
                $value = $acc !== null ? (int) $acc['id'] : null;
            } elseif ($col->reference !== null && $value !== null && SetupReferences::isPortable($col->reference)) {
                $value = $references->resolve($col->reference, $value);
            } elseif ($col->type === 'json' && is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($col->type === 'boolean' && is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            $data[$colId] = $value;
        }

        // Upsert podle přirozeného klíče.
        $where = [];
        $args = [];
        foreach ($spec['key'] as $k) {
            if (!array_key_exists($k, $data)) {
                throw new DatasetException("chybí klíčový sloupec '{$k}'");
            }
            $where[] = "[{$k}] = %s";
            $args[] = (string) $data[$k];
        }
        $existing = $ctx->db->fetch(
            'SELECT [id] FROM %n WHERE ' . implode(' AND ', $where) . ' ORDER BY [id] LIMIT 1',
            $spec['table'], ...$args,
        );
        if ($existing !== null) {
            $data['id'] = (int) $existing['id'];
        }

        $result = $gateway->saveDocument($data);
        if (!$result->isSuccess()) {
            $msg = $result->getErrorMessage();
            if ($msg === null && $result->getValidation() !== null) {
                $msg = implode('; ', array_map(
                    static fn($e) => ($e->column !== '' ? $e->column . ': ' : '') . $e->message,
                    $result->getValidation()->getErrors(),
                ));
            }
            throw new DatasetException($msg ?? 'save failed');
        }
    }
}
