<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Resolve\ReferenceException;
use Shipard\Module\Core\Exchange\Resolve\ReferenceResolver;
use Shipard\Module\Docs\Core\Defaults\DocHeadDefaults;
use Shipard\Module\Docs\Core\Defaults\DocRowDefaults;
use Shipard\Module\Docs\Core\DocHeadVatContext;
use Shipard\Module\Docs\Core\DocTypes;
use Shipard\Module\Docs\Core\VatRateDates;

/**
 * Výchozí hodnoty pokynu (#166 D40–D44, D48–D50): co payload neuvádí, doplní
 * se **týmž kódem jako ve formuláři** — `Defaults\DocHeadDefaults`
 * a `Defaults\DocRowDefaults` z `docs.core`. Tahle třída je jen adaptér mezi
 * formátem a sloupci dokladu; vlastní pravidlo nemá žádné.
 *
 *  1. Z payloadu složí sloupce — **jen pro klíče, které payload nese**.
 *     Klíč s hodnotou `null` je přítomný („záměrně prázdné“, D50), chybějící
 *     klíč a prázdný text se doplní.
 *  2. Společná třída doplní chybějící sloupce.
 *  3. Doplněné sloupce se zapíšou zpět do **pracovní kopie payloadu**
 *     (cesta přes {@see DocumentIssuePaths}) — zbytek applieru (kontext DPH,
 *     data sazby, kurz, účetní datum, validace cílového stavu) je tak vidí,
 *     jako by je payload nesl. Odpověď je vyjmenuje v `_resolve.defaults`
 *     jako `{path, value}`.
 *
 * Reference, které pravidla potřebují (pokladna, řada, registrace DPH,
 * položky řádků), se dohledávají potichu: nenalezená reference výchozí
 * hodnotu jen nedostane, nález za ni hlásí applier na svém místě.
 *
 * Importní mód (D48, D49): datum vystavení se nedoplňuje a registrace DPH
 * se bere bez ohledu na platnost; z řádku se doplňuje jen pohyb — obsah
 * (text, cena, jednotka, množství, kód DPH) nese historie sama.
 */
final class InstructionDefaults
{
    /** @var array<string, array<string, ?int>> tabulka → reference → id */
    private array $found = [];

    public function __construct(
        private readonly DocHeadDefaults $headDefaults,
        private readonly DocRowDefaults $rowDefaults,
        private readonly ?ReferenceResolver $references,
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
    ) {
    }

    /**
     * Výchozí hodnoty hlavičky.
     *
     * @param array<string, mixed> $canonical payload po normalizaci tvarů
     * @param string $docType `doc_type` hlavičky
     * @return array{canonical: array<string, mixed>, defaults: list<array{path: string, value: mixed}>}
     */
    public function head(array $canonical, string $docType, bool $import): array
    {
        $dates = self::object($canonical, 'dates');
        $vat = self::object($canonical, 'vat');
        $payment = self::object($canonical, 'payment');

        // Automatické zaokrouhlení řeší applier nad `totals` sám (příznak
        // nepatří do výčtu doplněných polí) — tady je klíč jen přítomný.
        $head = ['doc_type' => $docType, 'total_rounding_auto' => 0];
        if (isset($canonical['cashDirection'])) {
            $head['cash_dir'] = (int) $canonical['cashDirection'];
        }
        foreach (['cash_desk' => ['economy_codebooks_cash_desks', 'cashDesk'], 'number_series' => ['docs_core_number_series', 'numberSeries']] as $column => [$table, $key]) {
            $id = $this->find($table, $canonical[$key] ?? null);
            if ($id !== null) {
                $head[$column] = $id;
            }
        }
        if (self::present($canonical, 'currency')) {
            $head['doc_currency'] = is_string($canonical['currency']) ? strtolower($canonical['currency']) : null;
        }
        if (self::present($payment, 'method')) {
            $head['payment_method'] = DocumentApplier::PAYMENT_METHOD_MAP[(string) $payment['method']] ?? null;
        }
        if (self::present($vat, 'mode')) {
            $head['vat_mode'] = DocumentApplier::VAT_MODE_MAP[(string) $vat['mode']] ?? null;
        }
        if (self::present($dates, 'issueDate')) {
            $head['issue_date'] = $dates['issueDate'];
        }
        // DUZP jen jako datum, ke kterému má registrace platit.
        if (DocTypes::isTaxDocument($this->config, $docType) && !empty($dates['taxPointDate'])) {
            $head['vat_duzp'] = $dates['taxPointDate'];
        }
        if (self::present($vat, 'registration')) {
            $head['vat_registration'] = $this->find('economy_codebooks_vat_registrations', $vat['registration']);
        }
        if (self::present($payment, 'ownBankAccount')) {
            $head['bank_account'] = $this->find('economy_codebooks_bank_accounts', $payment['ownBankAccount']);
        }

        $filled = $this->headDefaults->apply($head, issueDate: !$import, registrationValidity: !$import);

        $defaults = [];
        foreach ($filled as $column) {
            $value = match ($column) {
                'doc_currency'   => strtoupper((string) $head[$column]),
                'payment_method' => self::name(DocumentApplier::PAYMENT_METHOD_MAP, $head[$column]),
                'vat_mode'       => self::name(DocumentApplier::VAT_MODE_MAP, $head[$column]),
                default          => $head[$column],
            };
            $path = DocumentIssuePaths::path($column, $canonical, []);
            self::set($canonical, $path, $value);
            $defaults[] = ['path' => $path, 'value' => $value];
        }

        return ['canonical' => $canonical, 'defaults' => $defaults];
    }

    /**
     * Výchozí hodnoty řádků — po hlavičce, potřebují její kontext DPH.
     *
     * Jednotka z karty položky se do payloadu zapisuje systémovým kódem;
     * jednotku bez kódu formát vyjádřit neumí, její id jde stranou
     * (`unitIds`) a applier ho použije při dohledání jednotek.
     *
     * @param array<string, mixed> $canonical payload s doplněnou hlavičkou
     * @param array<string, mixed> $head sloupce hlavičky pro {@see DocHeadVatContext::fromHead()}
     * @param string|null $country země registrace DPH dokladu, zná-li ji volající
     * @return array{canonical: array<string, mixed>, defaults: list<array{path: string, value: mixed}>, unitIds: array<int, int>}
     */
    public function rows(array $canonical, array $head, bool $import, ?string $country = null): array
    {
        $defaults = [];
        $unitIds = [];
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        if ($rows === []) {
            return ['canonical' => $canonical, 'defaults' => [], 'unitIds' => []];
        }
        $context = DocHeadVatContext::fromHead($head, $this->db, $this->config, $country);

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $columns = $this->rowColumns($row);
            foreach ($this->rowDefaults->apply($columns, $context, content: !$import) as $column) {
                $value = $columns[$column];
                if ($column === 'unit') {
                    $code = $this->unitCode((int) $value);
                    if ($code === null) {
                        $unitIds[(int) $i] = (int) $value;
                        $defaults[] = ['path' => "rows.{$i}.unit", 'value' => (int) $value];
                        continue;
                    }
                    $value = $code;
                }
                $path = DocumentIssuePaths::path("rows.{$i}.{$column}", $canonical, []);
                self::set($canonical, $path, $value);
                $defaults[] = ['path' => $path, 'value' => $value];
            }
        }

        return ['canonical' => $canonical, 'defaults' => $defaults, 'unitIds' => $unitIds];
    }

    /**
     * Sloupce řádku pro {@see DocRowDefaults}: jen to, co řádek payloadu nese.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowColumns(array $row): array
    {
        $columns = ['row_kind' => DocumentApplier::ROW_KIND_MAP[(string) ($row['rowKind'] ?? 'item')] ?? 1];
        if (isset($row['accSide'])) {
            $columns['acc_side'] = $row['accSide'];
        }
        $item = is_array($row['item'] ?? null) ? $row['item'] : [];
        $itemId = $this->find('economy_items', $item['ref'] ?? null);
        if ($itemId !== null) {
            $columns['item'] = $itemId;
        }
        if (self::present($row, 'operation')) {
            $columns['operation'] = $row['operation'];
        }
        // Text řádku skládá CanonicalRowText (popis, jinak název a popis
        // položky z payloadu) — doplňuje se, jen když by řádek zůstal bez textu.
        if (self::present($row, 'description') || CanonicalRowText::compose($row) !== null) {
            $columns['description'] = $row['description'] ?? null;
        }
        // Řádek zadaný celkovou cenou cenu za jednotku z karty nedostane.
        if (self::present($row, 'unitPrice') || isset($row['totalPrice'])) {
            $columns['unit_price'] = $row['unitPrice'] ?? null;
        }
        foreach (['unit' => 'unit', 'quantity' => 'quantity'] as $column => $key) {
            if (self::present($row, $key)) {
                $columns[$column] = $row[$key];
            }
        }
        // Objekt `vat` bez klíče `code` je „chybí“; `{"code": null}` je
        // záměrně bez kódu (U6).
        $vat = is_array($row['vat'] ?? null) ? $row['vat'] : [];
        if (self::present($vat, 'code')) {
            $columns['vat_code'] = $vat['code'];
        }
        $columns[VatRateDates::COLUMN] = VatRateDates::dateString($vat['rateDate'] ?? null);

        return $columns;
    }

    /** Systémový kód jednotky; null u jednotky bez kódu. */
    private function unitCode(int $unitId): ?string
    {
        $row = $this->db->fetchRow('SELECT `system_code` FROM `core_units` WHERE `id` = %i', $unitId);
        $code = trim((string) ($row['system_code'] ?? ''));
        return $code !== '' ? $code : null;
    }

    /** Id záznamu z reference; nevyplněná, nenalezená i nejednoznačná reference = null. */
    private function find(string $table, mixed $reference): ?int
    {
        if (!CanonicalReferences::isSet($reference) || $this->references === null) {
            return null;
        }
        $key = (string) json_encode($reference);
        if (!array_key_exists($key, $this->found[$table] ?? [])) {
            try {
                $this->found[$table][$key] = $this->references->resolve($table, $reference);
            } catch (ReferenceException) {
                $this->found[$table][$key] = null;
            }
        }
        return $this->found[$table][$key];
    }

    /**
     * Nese objekt klíč? `null` ano („záměrně prázdné“, D50); chybějící klíč
     * a prázdný text ne — ty se doplní.
     *
     * @param array<string, mixed> $object
     */
    private static function present(array $object, string $key): bool
    {
        return array_key_exists($key, $object) && $object[$key] !== '';
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private static function object(array $canonical, string $key): array
    {
        return is_array($canonical[$key] ?? null) ? $canonical[$key] : [];
    }

    /** @param array<string, int> $map název formátu → hodnota sloupce */
    private static function name(array $map, mixed $value): ?string
    {
        $name = array_search((int) $value, $map, true);
        return is_string($name) ? $name : null;
    }

    /**
     * Zapíše hodnotu na tečkovou cestu; chybějící (i `null`) mezilehlý objekt
     * založí.
     *
     * @param array<string, mixed> $canonical
     */
    private static function set(array &$canonical, string $path, mixed $value): void
    {
        $node = &$canonical;
        $keys = explode('.', $path);
        $last = array_pop($keys);
        foreach ($keys as $key) {
            $key = ctype_digit($key) ? (int) $key : $key;
            if (!is_array($node[$key] ?? null)) {
                $node[$key] = [];
            }
            $node = &$node[$key];
        }
        $node[$last] = $value;
    }
}
