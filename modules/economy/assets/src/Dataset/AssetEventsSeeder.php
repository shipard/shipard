<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Dataset;

use Dibi\Connection;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Economy\Assets\AssetDocument;
use Shipard\Module\Economy\Assets\AssetEventDocument;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;

/**
 * Hodnotové události karet (`events[]` v `assets/*.jsonc`, #131 fáze 2c,
 * D38, D40) — druhý krok seedu majetku, až za `docs/` (jako evidence
 * období zakázek): sekce bez vlastních souborů a `counts`, jednotka
 * počítání = soubor karty s událostmi.
 *
 * Zápis přes `AssetEventDocument` chronologicky (datum, pořadí druhu
 * v rámci dne, pořadí v souboru), rovnou ve stavu Potvrzeno, všechny
 * události jedné karty v jedné transakci. Původ určuje dokument: událost
 * s `origin: manual` se ukládá bez markeru a dostane původ `manual`
 * (tak vzniká i ručně); jen `origin: import` nese marker `_import`
 * (zdroj dat po migraci, uvolněné validace a původ `import`). Efekty na
 * kartu (datum pořízení, archiv po vyřazení) dělá dokument sám. Karta,
 * která už nějakou událost má (`--no-reset` nad existující kartou),
 * se přeskočí — nic se nezdvojí.
 */
class AssetEventsSeeder implements SectionSeeder
{
    public const SECTION = 'assetEvents';

    /** Klíč události obálky → sloupec události. */
    public const EVENT_COLUMNS = [
        'kind'            => 'event_kind',
        'scope'           => 'scope',
        'date'            => 'event_date',
        'periodBegin'     => 'period_begin',
        'periodEnd'       => 'period_end',
        'amount'          => 'amount',
        'accumulated'     => 'accumulated',
        'unitsDone'       => 'units_done',
        'priceIncreased'  => 'price_increased',
        'originalDate'    => 'original_date',
        'claimUnrecorded' => 'claim_unrecorded',
        'halfYear'        => 'half_year',
        'note'            => 'note',
    ];

    private const DELETED = 90;

    public function section(): string
    {
        return self::SECTION;
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        foreach ($ctx->reader->listFiles(AssetSeeder::SECTION) as $rel) {
            try {
                $file = $ctx->reader->readJsonc($rel);
            } catch (DatasetException $e) {
                $report->failed(self::SECTION, "{$rel}: {$e->getMessage()}");
                continue;
            }
            $events = is_array($file['events'] ?? null) ? array_values($file['events']) : [];
            if ($events === []) {
                continue;
            }
            $number = trim((string) ($file['asset']['assetNumber'] ?? ''));
            $assetId = $number !== '' ? $this->findCardId($ctx->db, $number) : null;
            if ($assetId === null) {
                $report->failed(self::SECTION, "{$rel}: karta '{$number}' na DS není — události se nezapsaly");
                continue;
            }
            if ($this->hasEvents($ctx->db, $assetId)) {
                $report->skipped(self::SECTION, "{$rel}: karta {$number} už má události — seed je nemění");
                continue;
            }

            $this->begin($ctx->db);
            try {
                $failure = $this->writeEvents($ctx, $assetId, $events);
            } catch (\Throwable $e) {
                $this->rollback($ctx->db);
                $report->failed(self::SECTION, "{$rel}: {$e->getMessage()}");
                continue;
            }
            if ($failure !== null) {
                $this->rollback($ctx->db);
                $report->failed(self::SECTION, "{$rel}: {$failure}");
                continue;
            }
            $this->commit($ctx->db);
            $report->ok(self::SECTION);
        }
    }

    /**
     * Události chronologicky přes dokument; první odmítnutá = text chyby
     * (volající dělá rollback).
     *
     * @param list<mixed> $events
     */
    private function writeEvents(SeedContext $ctx, int $assetId, array $events): ?string
    {
        foreach ($this->chronological($events) as $index) {
            $event = $events[$index];
            if (!is_array($event)) {
                return "events[{$index}]: událost není objekt";
            }
            $result = $this->saveEvent($ctx, $this->eventRow($event, $assetId));
            if (!$result->isSuccess()) {
                return AssetSeeder::describe($result, "events[{$index}]", self::EVENT_COLUMNS, ['origin' => 'origin']);
            }
        }
        return null;
    }

    /**
     * Řádek události: potvrzená, s markerem importního módu jen u původu
     * `import` — jinak původ nastaví dokument (`manual`).
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function eventRow(array $event, int $assetId): array
    {
        $row = ['asset' => $assetId];
        foreach (self::EVENT_COLUMNS as $key => $column) {
            if (array_key_exists($key, $event)) {
                $row[$column] = $event[$key];
            }
        }
        foreach (['claim_unrecorded', 'half_year', 'price_increased'] as $flag) {
            $row[$flag] = !empty($row[$flag]) ? 1 : 0;
        }
        $row['docState'] = AssetEventDocument::STATE_CONFIRMED;
        if ((string) ($event['origin'] ?? AssetEvent::ORIGIN_MANUAL) === AssetEvent::ORIGIN_IMPORT) {
            $row[AssetEventDocument::IMPORT_KEY] = true;
        }
        return $row;
    }

    /**
     * Indexy událostí chronologicky: datum, pořadí druhu v rámci dne,
     * pořadí v souboru.
     *
     * @param list<mixed> $events
     * @return list<int>
     */
    public static function chronological(array $events): array
    {
        $keys = [];
        foreach ($events as $index => $event) {
            $keys[$index] = [
                is_array($event) ? (string) ($event['date'] ?? '') : '',
                AssetEvent::kindOrder(is_array($event) ? (string) ($event['kind'] ?? '') : ''),
                $index,
            ];
        }
        uasort($keys, static fn(array $a, array $b): int => $a <=> $b);
        return array_keys($keys);
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** Id karty s inventárním číslem mimo koš. */
    protected function findCardId(Connection $db, string $number): ?int
    {
        $row = $db->fetch(
            'SELECT [id] FROM [' . AssetDocument::TABLE . '] WHERE [asset_number] = %s AND [docState] <> %i',
            $number, self::DELETED,
        );
        $row = $row === null ? null : (is_array($row) ? $row : $row->toArray());
        return $row === null ? null : (int) $row['id'];
    }

    /** Má karta nesmazanou událost? */
    protected function hasEvents(Connection $db, int $assetId): bool
    {
        $row = $db->fetch(
            'SELECT [id] FROM [' . AssetEventDocument::TABLE . '] WHERE [asset] = %i AND [docState] <> %i LIMIT 1',
            $assetId, self::DELETED,
        );
        return $row !== null;
    }

    /**
     * Událost přes gateway bez vlastní transakce — transakci drží seeder
     * per karta.
     *
     * @param array<string, mixed> $row
     */
    protected function saveEvent(SeedContext $ctx, array $row): DocumentResult
    {
        return $this->gateway($ctx)->saveDocument($row);
    }

    private function gateway(SeedContext $ctx): TableGateway
    {
        $def = $ctx->tables[AssetEventDocument::TABLE] ?? null;
        if ($def === null) {
            throw new DatasetException('tabulka ' . AssetEventDocument::TABLE . ' na tomto zdroji dat není (modul neaktivní)');
        }
        return new TransactionlessTableGateway(
            AssetEventDocument::TABLE, $ctx->db, $ctx->registry, $def->childTables, $ctx->config, $ctx->dsConfig,
            null, $def->docStates, $def,
        );
    }

    protected function begin(Connection $db): void
    {
        $db->begin();
    }

    protected function commit(Connection $db): void
    {
        $db->commit();
    }

    protected function rollback(Connection $db): void
    {
        $db->rollback();
    }
}
