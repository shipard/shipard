<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items\Dataset;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Economy\Items\AccountingItemMaterializer;
use Shipard\Module\Economy\Items\AccountingItemsOffer;

/**
 * Rozpoznání účetních položek z nabídky osnovy pro `dataset-dump` (#131
 * krok 0c). Sada naseedovaná s `provision.accountingItems` má položky
 * nabídky založené seedem ({@see AccountingItemsSeeder}), ne ze souborů
 * `items/`. Aby dump naseedovaného zdroje dat vrátil tytéž soubory (D10),
 * nezměněné položky z nabídky do `items/` nepíše a místo nich manifest
 * nese `provision.accountingItems`.
 *
 * Položka je **beze změny**, když má `source_kind = setup.accountingItems`,
 * není archivovaná, její kód je v nabídce aktivní varianty osnovy a název,
 * účet i obsahové štítky odpovídají tomu, co by z nabídky založil
 * {@see AccountingItemMaterializer}: název = lokalizovaný název nabídky
 * v jazyce zdroje dat, účet = aktivní analytický účet s číslem `account`
 * nabídky, štítky = `contentTags` nabídky v pořadí. Cokoli jiného
 * (přejmenování, jiný účet, jiné štítky, kód mimo nabídku) položku
 * nevyloučí — exportuje se jako každá jiná.
 *
 * `"all"` = každá položka nabídky, jejíž účet v osnově zdroje dat existuje
 * (ostatní seed přeskočí jako `account_not_found`), je ve zdroji dat beze
 * změny; jinak seznam nezměněných kódů v pořadí nabídky, prázdný = nic.
 * Bez rozhodnuté varianty, bez nabídky nebo bez sloupce `accounting_account`
 * (modul economy.accounting neaktivní — seed by položky nezaložil) je
 * výsledek prázdný, žádná výjimka.
 */
class ProvisionedItemsDetector
{
    private const ITEMS_TABLE = 'economy_items';

    /** Aktivní analytické účty — stejná kritéria jako `AccountingItemMaterializer::accountIdForNumber()`. */
    private const ACCOUNT_ACTIVE_STATES = [10, 40, 80];

    /**
     * @param array<string, TableDefinition> $tables definice tabulek zdroje
     *        dat — kvůli extension sloupci `accounting_account`, stejná brána
     *        jako `AccountingItemsGenerator::unavailableReason()`
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AccountingItemsOffer $offer,
        private readonly string $language,
        private readonly array $tables,
    ) {}

    public function detect(): ProvisionedItemsResult
    {
        $variant = $this->offer->variant();
        $seed = $variant !== null ? $this->offer->loadSeed($variant) : null;
        if ($seed === null || $seed['items'] === [] || !$this->hasAccountingColumn()) {
            return ProvisionedItemsResult::none();
        }

        $chart = $this->chartAccounts($seed['items']);

        // kód nabídky → id položky zdroje dat, která je proti nabídce beze změny
        $unchanged = [];
        foreach ($this->offerSourcedItems() as $row) {
            $code = (string) $row['code'];
            $entry = $seed['items'][$code] ?? null;
            if ($entry === null) {
                continue;
            }
            $account = (string) ($entry['account'] ?? '');
            if ($account === '' || !isset($chart[$account]) || (string) ($row['account_number'] ?? '') !== $account) {
                continue;
            }
            if ((string) $row['name'] !== AccountingItemsOffer::localizedField($entry, 'name', $this->language, $code)) {
                continue;
            }
            if (self::storedTags($row['content_tags'] ?? null) !== self::offerTags($entry)) {
                continue;
            }
            $unchanged[$code] = (int) $row['id'];
        }

        // Pořadí nabídky; číselné klíče seedu drží PHP jako int.
        $applicable = 0;
        $codes = [];
        $ids = [];
        foreach ($seed['items'] as $key => $entry) {
            $code = (string) $key;
            $account = (string) ($entry['account'] ?? '');
            if ($account === '' || !isset($chart[$account])) {
                continue;
            }
            $applicable++;
            if (isset($unchanged[$code])) {
                $codes[] = $code;
                $ids[] = $unchanged[$code];
            }
        }
        if ($codes === []) {
            return ProvisionedItemsResult::none();
        }
        return new ProvisionedItemsResult(
            $ids,
            count($codes) === $applicable ? DatasetManifest::PROVISION_ALL : $codes,
        );
    }

    private function hasAccountingColumn(): bool
    {
        foreach ($this->tables[self::ITEMS_TABLE]?->columns ?? [] as $column) {
            if ($column->id === 'accounting_account') {
                return true;
            }
        }
        return false;
    }

    /**
     * Nearchivované položky založené z nabídky, s číslem jejich účtu.
     *
     * @return list<array<string, mixed>>
     */
    private function offerSourcedItems(): array
    {
        return $this->db->fetchAll(
            'SELECT [i.id], [i.code], [i.name], [i.content_tags], [a.number] AS [account_number]'
            . ' FROM [' . self::ITEMS_TABLE . '] AS [i]'
            . ' LEFT JOIN [economy_accounting_accounts] AS [a] ON [a.id] = [i.accounting_account]'
            . ' WHERE [i.source_kind] = %s AND [i.docState] <> 90'
            . ' ORDER BY [i.id]',
            AccountingItemMaterializer::SOURCE_KIND,
        );
    }

    /**
     * Čísla účtů nabídky, která v osnově zdroje dat existují jako aktivní
     * analytické účty → set. Čísla jdou do dotazu jako řetězce — MySQL by
     * int porovnal s `number` číselně a '548100Z' by se rovnalo 548100.
     *
     * @param array<string, array<string, mixed>> $items
     * @return array<string, true>
     */
    private function chartAccounts(array $items): array
    {
        $numbers = [];
        foreach ($items as $entry) {
            $number = (string) ($entry['account'] ?? '');
            if ($number !== '' && !in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }
        if ($numbers === []) {
            return [];
        }
        $set = [];
        $rows = $this->db->fetchAll(
            'SELECT [number] FROM [economy_accounting_accounts]'
            . ' WHERE [number] IN %in AND [account_level] = 4 AND [docState] IN %in',
            $numbers,
            self::ACCOUNT_ACTIVE_STATES,
        );
        foreach ($rows as $row) {
            $set[(string) $row['number']] = true;
        }
        return $set;
    }

    /**
     * Štítky uložené u položky (`ItemDocument` je serializuje jako JSON
     * seznam v pořadí zápisu).
     *
     * @return list<string>
     */
    private static function storedTags(mixed $value): array
    {
        $tags = is_string($value) && $value !== '' ? json_decode($value, true) : null;
        return self::normalizeTags(is_array($tags) ? $tags : []);
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function offerTags(array $entry): array
    {
        return self::normalizeTags(is_array($entry['contentTags'] ?? null) ? $entry['contentTags'] : []);
    }

    /**
     * @param array<mixed> $tags
     * @return list<string>
     */
    private static function normalizeTags(array $tags): array
    {
        return array_values(array_filter($tags, static fn(mixed $t): bool => is_string($t) && $t !== ''));
    }
}
