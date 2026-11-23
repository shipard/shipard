<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Defaults;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\DocHeadVatContext;
use Shipard\Module\Docs\Core\DocRowOperationRules;
use Shipard\Module\Docs\Core\VatRateDates;

/**
 * Výchozí hodnoty řádku nového dokladu (#166 D40, D42, D43) — jedno místo
 * pro formulář řádku (`DocRowsForm`) i pro pokyn výměnného formátu
 * (`DocumentApplier`). Pracuje nad sloupci `docs_core_rows` a kontextem
 * hlavičky z `DocHeadVatContext` (uložená hlavička i data hlavičky, která
 * ještě uložená není).
 *
 * **Kontrakt** je stejný jako u {@see DocHeadDefaults}: doplňuje se jen
 * sloupec, jehož klíč v datech **chybí**; přítomný klíč vyhrává i s hodnotou
 * `null` („záměrně prázdné“, D50).
 *
 * Týká se jen položkového řádku (`row_kind` 1); textový řádek výchozí
 * hodnoty nemá. Řádek kontace (strana `acc_side`, pohyb s `rowSide`)
 * dostane jen pohyb — částku a účet zadává uživatel přímo.
 *
 *  - **Pohyb** ({@see operationFor()}): u řádku s položkou mapa
 *    `docs.core.applyRowOperations` podle typu položky; u řádku bez položky
 *    a u typu dokladu bez záznamu v mapě první pohyb nabídky. Řádek
 *    s pohybem, který nese stranu (`rowSide`, účetní doklad), dál nic
 *    nedostane — částku zadává uživatel přímo.
 *  - **Obsah** (`$content`): text, cena a jednotka z položky, množství 1,
 *    kód DPH = první kód nabídky k datu sazby řádku (nikdy „Mimo DPH“), jen
 *    u hlavičky s DPH. Importní mód obsah nedoplňuje (D49) — historický řádek
 *    by jinak tiše dostal cenu z dnešní karty položky a základní sazbu.
 */
final class DocRowDefaults
{
    /** @var array<int, array{description: string, unit_price: ?float, unit: ?int, item_type: ?int}|null> */
    private array $itemCache = [];

    public function __construct(
        private readonly ?DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
    ) {
    }

    /**
     * Doplní chybějící sloupce řádku.
     *
     * @param array<string, mixed> $row sloupce řádku
     * @param array<string, mixed>|null $headContext {@see DocHeadVatContext}; null = hlavička není známá
     * @param bool $content doplnit obsah řádku (položka, množství, kód DPH); false = jen pohyb
     * @return list<string> doplněné sloupce
     */
    public function apply(array &$row, ?array $headContext, bool $content = true): array
    {
        if ((int) ($row['row_kind'] ?? 1) !== 1) {
            return [];
        }
        $filled = [];
        $item = $this->item($row['item'] ?? null);

        if (!array_key_exists('operation', $row)) {
            $operation = $this->operationFor($headContext, $item['item_type'] ?? null);
            if ($operation !== null) {
                $row['operation'] = $operation;
                $filled[] = 'operation';
            }
        }
        if (!$content || $this->isContation($row)) {
            return $filled;
        }

        foreach (['description', 'unit_price', 'unit'] as $column) {
            if (!array_key_exists($column, $row) && $item !== null && $item[$column] !== null) {
                $row[$column] = $item[$column];
                $filled[] = $column;
            }
        }
        if (!array_key_exists('quantity', $row)) {
            $row['quantity'] = 1;
            $filled[] = 'quantity';
        }
        if (!array_key_exists('vat_code', $row) && self::headHasVat($headContext)) {
            $code = $this->vatCode($row, $headContext);
            if ($code !== null) {
                $row['vat_code'] = $code;
                $filled[] = 'vat_code';
            }
        }

        return $filled;
    }

    /**
     * Sloupce, které by řádek dostal výchozí hodnotou, kdyby v něm chyběly —
     * stejné podmínky jako {@see apply()}: jen položkový řádek; obsah z karty
     * jen u řádku s položkou, kód DPH jen u hlavičky s DPH, řádek kontace
     * jen pohyb.
     *
     * Čte je export dokladu: prázdný sloupec z tohoto výčtu píše výslovným
     * `null` („záměrně prázdné“, D50), jinak by ho pokyn při načtení
     * doplnil a round-trip doklad změnil.
     *
     * @param array<string, mixed> $row sloupce řádku
     * @return list<string>
     */
    public function defaultableColumns(array $row, bool $headHasVat): array
    {
        if ((int) ($row['row_kind'] ?? 1) !== 1) {
            return [];
        }
        $columns = ['operation'];
        if ($this->isContation($row)) {
            return $columns;
        }
        if (!empty($row['item'])) {
            array_push($columns, 'description', 'unit_price', 'unit');
        }
        $columns[] = 'quantity';
        if ($headHasVat) {
            $columns[] = 'vat_code';
        }
        return $columns;
    }

    /**
     * Hodnoty, které řádek přebírá z karty položky: název jako text řádku,
     * prodejní cena bez DPH (je-li vyplněná) a jednotka; `item_type` pro
     * výchozí pohyb. Null = položka neexistuje.
     *
     * Formulář jimi při výběru položky řádek **přepisuje** vždy, pokyn jimi
     * jen doplňuje, co payload neuvádí (D42).
     *
     * @return array{description: string, unit_price: ?float, unit: ?int, item_type: ?int}|null
     */
    public function item(mixed $itemId): ?array
    {
        $id = (int) ($itemId ?? 0);
        if ($id <= 0 || $this->db === null) {
            return null;
        }
        if (array_key_exists($id, $this->itemCache)) {
            return $this->itemCache[$id];
        }
        $row = $this->db->fetchRow(
            'SELECT `name`, `sales_price_no_vat`, `unit`, `item_type` FROM `economy_items` WHERE `id` = %i',
            $id,
        );
        return $this->itemCache[$id] = $row === null ? null : [
            'description' => (string) ($row['name'] ?? ''),
            // Cena 0 na kartě = nevyplněná (řádek by jinak dostal cenu 0 místo prázdné).
            'unit_price'  => (float) ($row['sales_price_no_vat'] ?? 0) !== 0.0 ? (float) $row['sales_price_no_vat'] : null,
            'unit'        => !empty($row['unit']) ? (int) $row['unit'] : null,
            'item_type'   => isset($row['item_type']) && $row['item_type'] !== '' ? (int) $row['item_type'] : null,
        ];
    }

    /**
     * Výchozí pohyb řádku. **S položkou:** mapa `docs.core.applyRowOperations`
     * typu dokladu podle typu položky, pro typ mimo mapu její `default`.
     * **Bez položky**, u typu dokladu bez záznamu v mapě a když mapa ukazuje
     * na pohyb, který doklad nenabízí: první pohyb nabídky
     * ({@see operationOptions()}). Null bez nabídky.
     *
     * Výchozí pohyb mapy (`default`) se řádku bez položky nedává záměrně:
     * u přijaté faktury je to *Účetní položka*, která bez položky nejde
     * potvrdit (`item_required_for_acc_entry`) — řádek jen s textem a cenou
     * by tak nikdy nešel dát do V pořádku. Návrh z pošty si `default` drží
     * (`DocumentApplier::defaultRowOperationsForApply()`), tam ho doplňuje
     * obohacení o účetní položku.
     *
     * @param array<string, mixed>|null $headContext
     * @param int|null $itemType `economy.items.itemTypes`; null = řádek bez položky
     */
    public function operationFor(?array $headContext, ?int $itemType): ?string
    {
        $offered = array_column($this->operationOptions($headContext), 'value');
        if ($offered === []) {
            return null;
        }
        if ($itemType !== null) {
            $map = $this->operationMap((string) ($headContext['doc_type'] ?? ''));
            $code = $map['byItemType'][(string) $itemType] ?? ($map['default'] ?? null);
            if (is_string($code) && in_array($code, $offered, true)) {
                return $code;
            }
        }
        return $offered[0];
    }

    /**
     * Je pohyb „automatický“ — prázdný, nebo takový, jaký by řádek dostal
     * bez zásahu uživatele (hodnota mapy typu dokladu, první pohyb nabídky)?
     * Formulář podle toho pozná, zda smí pohyb při výběru položky přenastavit;
     * ručně zvolený zvláštní pohyb (odpočet zálohy, majetek) zůstává.
     *
     * @param array<string, mixed>|null $headContext
     */
    public function isAutomaticOperation(?array $headContext, string $operation): bool
    {
        if ($operation === '') {
            return true;
        }
        $map = $this->operationMap((string) ($headContext['doc_type'] ?? ''));
        $automatic = array_values(is_array($map['byItemType'] ?? null) ? $map['byItemType'] : []);
        $automatic[] = $map['default'] ?? null;
        $automatic[] = $this->operationOptions($headContext)[0]['value'] ?? null;
        return in_array($operation, $automatic, true);
    }

    /**
     * Nabídka pohybů podle `doc_type` hlavičky (a u pokladního dokladu podle
     * `cash_dir` — pohyb s `cashDir` se nabízí jen pro shodný směr), řazená
     * podle `docTypes[docType].order` vzestupně. `name` z cfgItem je už
     * lokalizované compiled configem.
     *
     * Systémové operace zakládá jen služba (assets D48) — v nabídce nejsou,
     * leda jako operace právě zobrazeného řádku (`$currentOperation`).
     *
     * @param array<string, mixed>|null $headContext
     * @return list<array{value: string, label: string}>
     */
    public function operationOptions(?array $headContext, string $currentOperation = ''): array
    {
        $docType = (string) ($headContext['doc_type'] ?? '');
        $cfg = $this->operations();
        if ($docType === '' || $cfg === []) {
            return [];
        }
        $cashDir = (int) ($headContext['cash_dir'] ?? 0);

        $entries = [];
        foreach ($cfg as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $docTypeAttrs = $entry['docTypes'][$docType] ?? null;
            if (!is_array($docTypeAttrs)) {
                continue;
            }
            if (isset($docTypeAttrs['cashDir']) && (int) $docTypeAttrs['cashDir'] !== $cashDir) {
                continue;
            }
            if (!empty($entry['system']) && (string) $key !== $currentOperation) {
                continue;
            }
            $entries[] = [
                'value' => (string) $key,
                'label' => (string) ($entry['name'] ?? $key),
                'order' => (int) ($docTypeAttrs['order'] ?? 0),
            ];
        }
        usort($entries, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(
            static fn (array $e): array => ['value' => $e['value'], 'label' => $e['label']],
            $entries,
        );
    }

    /**
     * Řádek kontace: nese stranu (`acc_side`), nebo pohyb se stranou
     * (`rowSide`). Obsah z položky, množství ani kód DPH nedostává.
     *
     * @param array<string, mixed> $row
     */
    private function isContation(array $row): bool
    {
        return ($row['acc_side'] ?? null) !== null || $this->hasRowSide((string) ($row['operation'] ?? ''));
    }

    /** Nese pohyb stranu účtování na řádku (`rowSide` — kontační řádek účetního dokladu)? */
    public function hasRowSide(string $operation): bool
    {
        $entry = $this->operations()[$operation] ?? null;
        return DocRowOperationRules::entryHasRowSide(is_array($entry) ? $entry : null);
    }

    /**
     * Má hlavička DPH (`vat_mode` ≠ 0)? Bez kontextu hlavičky ne.
     *
     * @param array<string, mixed>|null $headContext
     */
    public static function headHasVat(?array $headContext): bool
    {
        return $headContext !== null && (int) ($headContext['vat_mode'] ?? 0) !== 0;
    }

    /**
     * První kód nabídky hlavičky k datu sazby řádku — nikdy „Mimo DPH“
     * (#164 U4): je v nabídce poslední, a kdyby byl jediný, zůstane řádek
     * bez kódu.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $headContext
     */
    private function vatCode(array $row, ?array $headContext): ?string
    {
        $options = DocHeadVatContext::vatCodeOptions(
            $headContext,
            $this->config,
            $headContext !== null ? VatRateDates::lineDate($headContext, $row, $this->config) : null,
            includeOutOfScope: false,
        );
        return $options !== [] ? (string) $options[0]['value'] : null;
    }

    /** @return array<string, mixed> `docs.core.rowOperations` */
    private function operations(): array
    {
        $cfg = $this->config?->cfgItem('docs.core.rowOperations');
        return is_array($cfg) ? $cfg : [];
    }

    /** @return array<string, mixed> záznam `docs.core.applyRowOperations` typu dokladu; bez něj prázdný */
    private function operationMap(string $docType): array
    {
        $cfg = $this->config?->cfgItem('docs.core.applyRowOperations');
        return is_array($cfg) && is_array($cfg[$docType] ?? null) ? $cfg[$docType] : [];
    }
}
