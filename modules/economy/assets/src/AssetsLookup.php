<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup karet majetku — hledá v inventárním čísle, názvu a zkráceném
 * názvu; display `inv. číslo — název`, sekundárně druh. Konzumenti: řádek
 * a hlavička dokladu (dimenze deníku), Fáze 7 příslušenství.
 *
 * Kartu jde z řádku dokladu rovnou založit (D62): `createDefaults()` ji
 * předvyplní z řádku a hlavičky — název z textu řádku, druh podle účtu
 * řádku (04x dlouhodobý hmotný, 5xx drobný), u drobného cena ze základu
 * řádku v domácí měně a datum pořízení z účetního data dokladu. U
 * dlouhodobého majetku cenu ani datum nenese karta, ale zařazení (D13,
 * D38) — to se předvyplní z pořízení na kartě.
 */
class AssetsLookup extends TableLookup
{
    /** Prefix čísla účtu řádku → druh nové karty. */
    private const CATEGORY_BY_ACCOUNT_PREFIX = [
        '04' => 'tangible',
        '5'  => 'small',
    ];

    public function getAllowedFilterKeys(): array
    {
        return [];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $q = trim($q);

        $sql = 'SELECT `id`, `asset_number`, `name`, `category` FROM `economy_assets_assets`'
            . ' WHERE `docState` IN (10, 40, 80)';
        $args = [];

        if ($q !== '') {
            [$searchSql, $searchArgs] = SearchCondition::anyContains(['`asset_number`', '`name`', '`short_name`'], $q);
            $sql .= ' AND ' . $searchSql;
            $args = array_merge($args, $searchArgs);
        }
        $sql .= ' ORDER BY `asset_number` ASC, `name` ASC LIMIT %i';
        $args[] = $limit;

        return array_map(fn($r) => $this->buildItem($r), $this->db->fetchAll($sql, ...$args));
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if ($intIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `asset_number`, `name`, `category` FROM `economy_assets_assets` WHERE `id` IN %in',
            $intIds,
        );
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    public function createDefaults(array $parentRow, array $parentHead): array
    {
        $defaults = [];

        $name = trim((string) ($parentRow['description'] ?? ''));
        if ($name !== '') {
            $defaults['name'] = mb_substr($name, 0, $this->nameLength());
        }

        $category = $this->categoryForAccount((int) ($parentRow['account'] ?? 0));
        if ($category !== null) {
            $defaults['category'] = $category;
        }
        if ($category !== null && (new AssetCategories($this->config))->isLongTerm($category)) {
            return $defaults;
        }

        $date = self::isoDate($parentHead['accounting_date'] ?? null);
        if ($date !== null) {
            $defaults['acquired_date'] = $date;
        }
        if ($category !== null) {
            $price = $this->baseInHomeCurrency($parentRow, $parentHead);
            if ($price !== null) {
                $defaults['price'] = $price;
            }
        }
        return $defaults;
    }

    /** Délka sloupce `name` karty — text řádku dokladu bývá delší. */
    private function nameLength(): int
    {
        foreach ($this->tableDef?->columns ?? [] as $column) {
            if ($column->id === 'name' && $column->length !== null) {
                return $column->length;
            }
        }
        return 150;
    }

    /** Druh karty podle účtu řádku; null bez účtu nebo mimo 04x / 5xx. */
    private function categoryForAccount(int $accountId): ?string
    {
        $number = $accountId > 0 ? $this->accountNumber($accountId) : null;
        if ($number === null) {
            return null;
        }
        foreach (self::CATEGORY_BY_ACCOUNT_PREFIX as $prefix => $category) {
            if (str_starts_with($number, (string) $prefix)) {
                return $category;
            }
        }
        return null;
    }

    /**
     * Základ řádku v domácí měně. Formulář řádku počítá živě jen základ
     * v měně dokladu (`vat_base`); domácí hodnotu (`vat_base_dom`) zapisuje
     * až uložení, proto se u cizí měny přepočítá kurzem hlavičky.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $head
     */
    private function baseInHomeCurrency(array $row, array $head): ?float
    {
        $base = $row['vat_base'] ?? null;
        if (!is_numeric($base)) {
            $dom = $row['vat_base_dom'] ?? null;
            return is_numeric($dom) && (float) $dom > 0 ? round((float) $dom, 2) : null;
        }
        $docCurrency = strtolower((string) ($head['doc_currency'] ?? ''));
        $homeCurrency = strtolower((string) ($head['home_currency'] ?? ''));
        $rate = (float) ($head['exchange_rate'] ?? 0);
        $foreign = $docCurrency !== '' && $homeCurrency !== '' && $docCurrency !== $homeCurrency;
        $value = (float) $base * ($foreign && $rate > 0 ? $rate : 1.0);
        return $value > 0 ? round($value, 2) : null;
    }

    private static function isoDate(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $string) === 1 ? substr($string, 0, 10) : null;
    }

    /** Číslo účtu podle id (přepsatelné v testu). */
    protected function accountNumber(int $accountId): ?string
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetchRow('SELECT `number` FROM `economy_accounting_accounts` WHERE `id` = %i', $accountId);
        return $row !== null ? (string) $row['number'] : null;
    }

    /** @param array<string, mixed> $row */
    private function buildItem(array $row): LookupItem
    {
        $number = trim((string) ($row['asset_number'] ?? ''));
        $name   = trim((string) ($row['name'] ?? ''));
        $primary = $number !== '' && $name !== '' ? "{$number} — {$name}" : ($name !== '' ? $name : ('#' . $row['id']));
        $category = (string) ($row['category'] ?? '');
        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: $category !== '' ? new AssetCategories($this->config)->label($category) : null,
        );
    }
}
