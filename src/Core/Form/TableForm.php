<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\StructuredFields\StructuredFieldFormBuilder;
use Shipard\Core\StructuredFields\StructuredFieldResolver;
use Shipard\Core\StructuredFields\StructuredSchema;

abstract class TableForm
{
    /** Max. počet sloupců default rendereru sub-tabulky (renderSubtable). */
    public const SUBTABLE_DEFAULT_MAX_COLUMNS = 6;

    /** Technické sloupce, které default renderer sub-tabulky nikdy nezobrazí. */
    private const SUBTABLE_SKIPPED_COLUMNS = ['created', 'modified', 'order_pos'];

    private const SUBTABLE_NUMERIC_TYPES = ['tinyint', 'smallint', 'int', 'bigint', 'numeric', 'float'];
    private const SUBTABLE_TEXT_TYPES = ['varchar', 'text', 'mediumtext', 'longtext'];

    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConnection $db = null;
    protected ?TableDefinition $tableDef = null;
    /** Konfigurace zdroje dat (stát…) — injektuje FormRegistry; v testech může chybět. */
    protected ?DataSourceConfig $dsConfig = null;

    /** @var array<string, TableDefinition> Všechny tabulky DS — default renderer sub-tabulky z nich bere definici dětské tabulky. */
    protected array $tables = [];

    public function __construct(
        protected string $table,
    ) {}

    public function setConfig(ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    public function setDb(DataSourceConnection $db): void
    {
        $this->db = $db;
    }

    public function setDsConfig(DataSourceConfig $dsConfig): void
    {
        $this->dsConfig = $dsConfig;
    }

    public function setTableDef(TableDefinition $tableDef): void
    {
        $this->tableDef = $tableDef;
    }

    /** @param array<string, TableDefinition> $tables Indexované názvem tabulky (výstup TableLoader::load). */
    public function setTables(array $tables): void
    {
        $this->tables = $tables;
    }

    abstract public function buildFormDefinition(array $data, bool $isNew): FormDefinition;

    /**
     * Server-side defaulty nového záznamu odvozené z client prefillu
     * (`?defaults[...]`) nebo kontextu (např. default pohyb řádku podle
     * doc_type hlavičky z `defaults[doc_head]`). Volá FormController
     * (GET /meta bez id) před buildFormDefinition; mutace `$data` se
     * propíší do response `data` — na rozdíl od mutací uvnitř
     * buildFormDefinition, které ovlivní jen podmíněné renderování.
     */
    public function applyNewRecordDefaults(array &$data): void
    {
    }

    /**
     * Schéma strukturovaného pole (#74) pro **editaci** tímto formulářem —
     * zrcadlo `Document::structuredSchemaFor()`. `null` = statický atribut
     * `schema` z definice sloupce, což platí pro všechny dnešní konzumenty.
     *
     * Kdo override potřebuje (schéma podle typu záznamu — hlavička podání
     * DPH ve Fázi 3 #55), musí ho držet **v souladu s dokumentovým hookem**:
     * formulář podle něj vykreslí pole a `TableGateway` podle dokumentového
     * hooku validuje a ukládá. Nejjednodušší je delegovat obojí na jednu
     * statickou metodu domény.
     *
     * @param array<string, mixed> $data data záznamu (s plochými virtuálními sloupci)
     */
    public function structuredSchemaFor(string $column, array $data): ?string
    {
        return null;
    }

    /**
     * Volitelná strukturovaná hlavička formuláře pro existující záznam.
     *
     * Default: žádná hlavička (modal zobrazí jen `title` z FormDefinition).
     * Subclassy mohou přepsat a vrátit `FormHeaderInfo` s identifikačními údaji
     * (např. název firmy, IČO, kód).
     *
     * Tato metoda se volá v `FormController` pro `GET /meta/{id}` a po úspěšném
     * `save` — NE pro `GET /meta` (nový záznam) ani pro `recalculate`. Hodnoty
     * v `$data` jsou tedy data uložená v DB, ne živá data z formuláře.
     */
    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        return null;
    }

    /**
     * Elementy pro strukturované pole (#74, I5) — `separator` per skupinu
     * schématu a `input`/`select` per pole, s virtuálními sloupci
     * `<sloupec>.<pole>`. Konzument je vloží do sekce/tabu:
     *
     *     $tab = $this->tab('filing', 'Podací údaje')->section()->col();
     *     $tab->addElements($this->structuredFieldElements('filing_profile'));
     *
     * Prázdné pole (sloupec bez `schema`, nedostupná konfigurace) vrací `[]`,
     * takže tab bez schématu prostě nic nevykreslí.
     *
     * @param array<string, mixed> $data data záznamu — jen pro hook
     *        `structuredSchemaFor()`; hodnoty polí posílá server v `data`
     * @return list<FormElement>
     */
    protected function structuredFieldElements(
        string $column,
        array $data = [],
        bool $groupSeparators = true,
    ): array {
        $schema = $this->structuredSchema($column, $data);
        if ($schema === null) {
            return [];
        }
        return (new StructuredFieldFormBuilder($this->config))
            ->elements($schema, $column, $groupSeparators);
    }

    /**
     * Schéma strukturovaného sloupce podle definice tabulky a hooku
     * `structuredSchemaFor()`; `null` = sloupec schéma nemá nebo není
     * v definici (např. modul, který ho přináší extension, není aktivní —
     * pak se tab jen nevykreslí).
     *
     * @param array<string, mixed> $data
     */
    protected function structuredSchema(string $column, array $data = []): ?StructuredSchema
    {
        $staticKey = null;
        foreach ($this->tableDef?->columns ?? [] as $col) {
            if ($col->id === $column) {
                $staticKey = $col->schema;
                break;
            }
        }
        return (new StructuredFieldResolver($this->config))
            ->forWrite($this->structuredSchemaFor($column, $data), $staticKey);
    }

    /** Má tabulka tento strukturovaný sloupec (a je tedy proč kreslit tab)? */
    protected function hasStructuredColumn(string $column): bool
    {
        return array_key_exists($column, $this->tableDef?->getStructuredColumns() ?? []);
    }

    /**
     * Opt-in whitelist sensitive sloupců editovatelných tímto formem.
     *
     * TableAccessGuard normálně odmítá zápis sensitive sloupců přes
     * form save (400 SENSITIVE_COLUMN) — sloupce vyjmenované zde projdou.
     * Konvence pro takové pole: input startuje prázdný (data ho nikdy
     * neobsahují — stripSensitive), placeholder `●●●●●● (zadat pro
     * změnu)`, prázdný submit hodnotu nemění (Document beforeSave
     * prázdnou hodnotu unsetne — vzor HostingDataSourceDocument).
     * Šifrování/uložení zůstává odpovědností Document třídy.
     *
     * @return list<string>
     */
    public function getEditableSensitiveColumns(): array
    {
        return [];
    }

    /**
     * Opt-in whitelist sloupců editovatelných i v read-only stavu dokumentu
     * (`readOnly: 1` v docStates cfgItem — Podáno, V pořádku…).
     *
     * FormController jinak uložení read-only záznamu odmítne (422
     * DOCUMENT_READONLY); payload složený jen z těchto sloupců projde.
     * Klient je dostane v `doc_states.editable_columns`, nechá jejich
     * inputy aktivní a při uložení pošle **jen je** — Document třída tedy
     * musí zvládnout částečné uložení (merge s uloženým řádkem, vzor
     * FilingDocument). Typicky poznámka k podanému podání nebo správce
     * daně na potvrzené registraci — údaje, které k zmrazenému záznamu
     * smí přibýt bez „Opravit". Viz docs/edit-forms.md kap. 26.
     *
     * @return list<string>
     */
    public function getReadOnlyEditableColumns(): array
    {
        return [];
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        $isNew = !isset($data['id']) || $data['id'] === null;
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    protected function tab(string $id, string $label, ?string $icon = null): TabBuilder
    {
        $colLabels = [];
        $colDefs   = [];
        if ($this->tableDef !== null) {
            foreach ($this->tableDef->columns as $col) {
                $colLabels[$col->id] = $col->formLabel ?? $col->name;
                $colDefs[$col->id]   = $col;
            }
        }
        return new TabBuilder($id, $label, $colLabels, $icon, colDefs: $colDefs);
    }

    /**
     * Build a tab that hosts a child table. The child table is rendered as a
     * grid with its own toolbar; the parent record is identified by parentId
     * at runtime.
     *
     * `$orderColumn` = pořadový sloupec dětské tabulky (např. `order_pos`):
     * sub-tabulka ukáže šipky přesunu (`POST …/subtable/{tab}/{id}/move`),
     * řádky se řadí `orderColumn ASC, id ASC` a `$sort` se nesmí zadat.
     * Bez něj (Kontakty, Adresy…) se pořadí neřeší.
     *
     * `$independentRows` = řádky se spravují vlastními stavy, ne rodičem:
     * sub-tabulka nikdy nenabízí Přidat / Smazat / přesun, ale dialog řádku
     * je plný (stavové přechody, zámek dětské tabulky) i u read-only
     * rodiče — události majetku nad kartou ve stavu V pořádku.
     */
    protected function subtableTab(
        string $id,
        string $label,
        string $table,
        string $foreignKey,
        ?string $formId = null,
        ?string $sort = null,
        ?string $icon = null,
        ?string $orderColumn = null,
        bool $independentRows = false,
    ): FormTab {
        return new FormTab(
            id: $id,
            label: $label,
            type: 'subtable',
            subtable: [
                'table'           => $table,
                'foreignKey'      => $foreignKey,
                'formId'          => $formId,
                'sort'            => $sort,
                'orderColumn'     => $orderColumn,
                'independentRows' => $independentRows,
            ],
            icon: $icon,
        );
    }

    // ── Sub-tabulky: sloupce a vyrenderované řádky ─────────────────────────

    /**
     * Sloupce a vyrenderované řádky sub-tabulky pro tab typu `subtable`
     * (endpoint `GET /_ui/form/{table}/subtable/{tabId}/{parentId}`,
     * docs/edit-forms.md kap. 15). Tvar sloupců je shodný
     * s `TableViewer::getGridColumns()` (`id`, `label`, `align`, `grow`,
     * `width`), buňky jsou hotové texty — klient nezná FK, enumy ani formát
     * částek. Řádek: `{id, cells: {colId: string|{text, class?}}, stateStyle?}`;
     * chybějící klíč v `cells` = prázdná buňka.
     *
     * Default: prvních SUBTABLE_DEFAULT_MAX_COLUMNS sloupců dětské tabulky
     * (bez PK, FK na rodiče, `system`, `sensitive`, `json` a technických
     * `created`/`modified`/`order_pos`), label `formLabel ?? name`
     * (TableLoader je už lokalizoval), číselné typy vpravo, první textový
     * sloupec `grow`. Buňky: cfgItem → `name` položky, boolean → Ano/Ne
     * z cfgItem `core.system.formDefaults`, numeric dle `scale`, datum
     * `d.m.Y`; `reference` zůstává surové id (default má být levný —
     * pojmenované FK řeší override). U dětské tabulky s docStates nese
     * řádek `stateStyle` stavu (archiv tlumený, koš škrtnutý — globální
     * `.docState_*` třídy).
     *
     * Overridy (`DocsHeadsFormBase`, `PersonsForm`) rozhodují podle
     * `$tab->id` a pro ostatní taby volají `parent::renderSubtable()`.
     * Sloupce smějí záviset na `$parentData` (doklad bez DPH nemá DPH
     * sloupce). `order_column` v odpovědi endpointu
     * plní controller z deklarace tabu (`subtableTab(..., orderColumn:)`),
     * hodnota z rendereru se nepoužívá.
     *
     * @param list<array<string, mixed>> $rows Řádky dětské tabulky bez sensitive
     *     sloupců, seřazené serverem podle `sort` tabu.
     * @param array<string, mixed> $parentData Data rodičovského záznamu.
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, order_column: ?string}
     */
    public function renderSubtable(FormTab $tab, array $rows, array $parentData): array
    {
        $childDef = $this->tables[(string) ($tab->subtable['table'] ?? '')] ?? null;
        if ($childDef === null) {
            return ['columns' => [], 'rows' => [], 'order_column' => null];
        }

        $colDefs = $this->defaultSubtableColumnDefs($childDef, (string) ($tab->subtable['foreignKey'] ?? ''));
        $columns = array_map(fn(ColumnDefinition $c) => $this->subtableColumnSpec($c), $colDefs);
        $growAssigned = false;
        foreach ($columns as $i => $spec) {
            if (!$growAssigned && in_array($colDefs[$i]->type, self::SUBTABLE_TEXT_TYPES, true) && $colDefs[$i]->cfgItem === null) {
                $columns[$i]['grow'] = true;
                $growAssigned = true;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($colDefs as $col) {
                $text = $this->defaultSubtableCell($col, $row[$col->id] ?? null);
                if ($text !== null) {
                    $cells[$col->id] = $text;
                }
            }
            $entry = ['id' => (int) ($row['id'] ?? 0), 'cells' => $cells];
            $style = $this->subtableRowStateStyle($childDef, $row);
            if ($style !== null) {
                $entry['stateStyle'] = $style;
            }
            $out[] = $entry;
        }

        return ['columns' => array_values($columns), 'rows' => $out, 'order_column' => null];
    }

    /**
     * Sloupce dětské tabulky pro default renderer — viz renderSubtable().
     *
     * @return list<ColumnDefinition>
     */
    protected function defaultSubtableColumnDefs(TableDefinition $childDef, string $foreignKey): array
    {
        $ds = $childDef->docStates;
        $skip = self::SUBTABLE_SKIPPED_COLUMNS;
        if ($ds !== null) {
            $skip[] = $ds->stateColumn;
            $skip[] = $ds->mainColumn;
        }

        $out = [];
        foreach ($childDef->columns as $col) {
            if ($col->primaryKey || $col->autoIncrement || $col->system || $col->sensitive) {
                continue;
            }
            if ($col->id === $foreignKey || in_array($col->id, $skip, true) || $col->type === 'json') {
                continue;
            }
            $out[] = $col;
            if (count($out) >= self::SUBTABLE_DEFAULT_MAX_COLUMNS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Specifikace sloupce sub-tabulky z definice sloupce: `id`, `label`,
     * `align: 'right'` pro čísla (ne enumy / FK). Sdílené overridy pro
     * sloupce, které berou 1:1 z tabulky.
     *
     * @return array<string, mixed>
     */
    protected function subtableColumnSpec(ColumnDefinition $col): array
    {
        $spec = ['id' => $col->id, 'label' => $col->formLabel ?? $col->name];
        if (in_array($col->type, self::SUBTABLE_NUMERIC_TYPES, true) && $col->cfgItem === null && $col->reference === null) {
            $spec['align'] = 'right';
        }
        return $spec;
    }

    /**
     * Text buňky podle typu sloupce — viz renderSubtable(). `null` = prázdná buňka.
     */
    protected function defaultSubtableCell(ColumnDefinition $col, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($col->cfgItem !== null) {
            return $this->cfgItemLabel($col->cfgItem, $value);
        }
        return match ($col->type) {
            'boolean'  => SubtableCellFormatter::boolean($value, ...$this->booleanLabels()),
            'numeric'  => SubtableCellFormatter::number($value, (int) ($col->scale ?? 2)),
            'float'    => SubtableCellFormatter::trimmedNumber($value, 4),
            'date'     => SubtableCellFormatter::date($value),
            'datetime' => SubtableCellFormatter::dateTime($value),
            'json'     => null,
            default    => is_scalar($value) ? (string) $value : null,
        };
    }

    /**
     * Lokalizovaný label sloupce dětské tabulky (`formLabel ?? name`
     * z TableLoader) pro hlavičku sub-tabulky v overridech; bez definice
     * tabulky (testy, chybějící registr) `$fallback`.
     */
    protected function subtableLabel(string $table, string $column, string $fallback): string
    {
        $def = $this->tables[$table] ?? null;
        if ($def !== null) {
            foreach ($def->columns as $col) {
                if ($col->id === $column) {
                    return $col->formLabel ?? $col->name;
                }
            }
        }
        return $fallback;
    }

    /**
     * Lokalizovaný `name` položky cfgItem pro hodnotu enumu; neznámá
     * položka / chybějící config → surová hodnota.
     */
    protected function cfgItemLabel(string $cfgItemId, mixed $value): string
    {
        $cfg = $this->config?->cfgItem($cfgItemId);
        $entry = is_array($cfg) && is_scalar($value) ? ($cfg[(string) $value] ?? null) : null;
        if (is_array($entry) && isset($entry['name'])) {
            return (string) $entry['name'];
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Labely boolean buněk („Ano" / „Ne") z cfgItem `core.system.formDefaults`
     * — stejný zdroj lokalizace jako label tabu General.
     *
     * @return array{string, string} [yes, no]
     */
    protected function booleanLabels(): array
    {
        $defaults = $this->config?->cfgItem('core.system.formDefaults');
        return [
            (string) ($defaults['booleanYes']['name'] ?? 'Yes'),
            (string) ($defaults['booleanNo']['name'] ?? 'No'),
        ];
    }

    /**
     * `stateStyle` stavu řádku (`archive`, `trash`, `done`…) pro tabulky
     * s docStates; `null` bez docStates / configu / neznámý stav.
     *
     * @param array<string, mixed> $row
     */
    protected function subtableRowStateStyle(TableDefinition $childDef, array $row): ?string
    {
        $ds = $childDef->docStates;
        if ($ds === null || $this->config === null) {
            return null;
        }
        $state = $row[$ds->stateColumn] ?? null;
        if ($state === null || $state === '' || !is_numeric($state)) {
            return null;
        }
        $cfgData = $this->config->cfgItem($ds->cfgItem);
        $style = DocStateConfig::fromCfgItem(is_array($cfgData) ? $cfgData : null)
            ->getState((int) $state)['stateStyle'] ?? null;
        return is_string($style) && $style !== '' ? $style : null;
    }

    /**
     * Localized label for the generic "General" tab — same source as
     * AutoFormBuilder, so every form (PHP / JSONC / auto) shows the same label.
     */
    protected function defaultGeneralTabLabel(): string
    {
        $defaults = $this->config?->cfgItem('core.system.formDefaults');
        return $defaults['generalTabLabel']['name'] ?? 'General';
    }

    /**
     * Create an attachments tab for the current table. The tableId is taken
     * from the loaded table definition.
     *
     * `$sendFlag` zapne u příloh přepínač „Odeslat s dokladem“ — jen pro
     * záznamy, které se odesílají (vydané doklady).
     */
    protected function attachmentsTab(
        string $id = 'attachments',
        string $label = 'Přílohy',
        ?string $changeEndpoint = null,
        bool $sendFlag = false,
    ): FormTab {
        $tableId = $this->tableDef?->tableId ?? 0;
        return new FormTab(
            id: $id,
            label: $label,
            type: 'attachments',
            tableId: $tableId,
            changeEndpoint: $changeEndpoint,
            sendFlag: $sendFlag,
        );
    }
}
