<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\StructuredFields\StructuredFieldRenderer;
use Shipard\Core\StructuredFields\StructuredFieldResolver;

abstract class TableViewer
{
    /** Default number of rows per page. Subclasses can override. */
    protected int $pageSize = 50;

    /**
     * Compiled config runtime — injected via setConfig() after construction.
     * Available if the data source has a compiled configuration.
     */
    protected ?ConfigRuntime $config = null;

    /**
     * Request language (e.g. 'cs', 'en') — injected via setLanguage().
     * Subclasses use it for inline label maps in renderDetail() when no
     * cfgItem-based localization is available yet.
     */
    protected ?string $language = null;

    /**
     * Set by subclass to enable automatic viewGroup tab support.
     * Must match the cfgItem ID of a docStates config (e.g. 'core.system.docStatesArchive').
     */
    protected ?string $docStatesCfgItem = null;

    /**
     * Active grid sort injected by ViewerController, or null (viewer's
     * default ordering). Shape: {column: string, dir: 'asc'|'desc'}.
     * The controller validates the column against sortable grid columns
     * before injecting — selectRows() signature stays unchanged (D9).
     * See docs/viewer-grid.md §7.1.
     */
    protected ?array $sort = null;

    public function __construct(
        protected DataSourceConnection $db,
        protected string $table,
    ) {}

    /** Inject the active grid sort — called by ViewerController before selectRows(). */
    public function setSort(?array $sort): void
    {
        $this->sort = $sort;
    }

    /** Inject compiled config — called by ViewerRegistry after construction. */
    public function setConfig(ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    /** Inject request language — called by ViewerRegistry after construction. */
    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }

    /**
     * Returns available viewGroup identifiers for this viewer's tab bar.
     * Derived automatically from $docStatesCfgItem if set.
     * Returns empty array when docStates are not supported.
     *
     * An item is either a plain string id ('active', 'archive', …) that the
     * frontend maps to an i18n label, or an object {id, label} with the
     * label already localized by the backend — used by data-driven groups
     * (e.g. LedgerViewer derives groups from economy_accbal_balances).
     *
     * @return list<string|array{id: string, label: string}>
     */
    public function getViewGroups(): array
    {
        if ($this->docStatesCfgItem === null || $this->config === null) {
            return [];
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        $groups = [];
        foreach (['active', 'archive', 'trash'] as $vg) {
            if (!empty($cfg->getViewGroupStates($vg))) {
                $groups[] = $vg;
            }
        }
        return $groups;
    }

    /**
     * ViewGroup the frontend pre-selects when the viewer opens. Exposed in
     * meta as `defaultViewGroup`. Data-driven viewers override this
     * (e.g. LedgerViewer returns the first balance's code).
     */
    public function getDefaultViewGroup(): string
    {
        return 'active';
    }

    /**
     * Build a WHERE fragment that restricts rows to the given viewGroup.
     * Use inside selectRows() when handling a 'viewGroup' filter.
     *
     * @return array{0: string, 1: array}  [sql_fragment, params]
     */
    protected function buildViewGroupFilter(string $cfgItemId, string $viewGroup): array
    {
        if ($this->config === null) {
            return ['', []];
        }
        $cfg    = DocStateConfig::fromCfgItem($this->config->cfgItem($cfgItemId));
        $states = $cfg->getViewGroupStates($viewGroup);
        if (empty($states)) {
            return ['1=0', []];
        }
        $placeholders = implode(', ', array_fill(0, count($states), '%i'));
        return ['`docState` IN (' . $placeholders . ')', $states];
    }

    /**
     * Get the page size for this viewer.
     */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * Select rows for the viewer list.
     *
     * IMPORTANT: The implementation should fetch pageSize + 1 rows. The caller
     * (ViewerController) uses the extra row to determine if more data exists:
     * - If count > pageSize: trim to pageSize, set hasMore = true
     * - If count <= pageSize: return as is, set hasMore = false
     *
     * The ORDER BY clause must always end with a unique column (typically `id`)
     * to guarantee deterministic ordering with LIMIT/OFFSET. For example:
     *   ORDER BY last_name ASC, first_name ASC, id ASC
     *
     * @param string|null $search     Fulltext search query
     * @param array       $filters    Active filters [{id: string, value: mixed}]
     * @param int         $pageNumber Zero-based page number (0 = first page)
     * @return array Array of raw DB rows (up to pageSize + 1)
     */
    abstract public function selectRows(
        ?string $search,
        array $filters,
        int $pageNumber,
    ): array;

    /**
     * Render a single row for the list display.
     * Returns a structured object for the frontend.
     *
     * Row format:
     * - id: int (record ID)
     * - icon: string|null
     *     Icon identifier matching a key in frontend `iconMap`
     *     (e.g. 'user', 'company', 'invoice'). When omitted, the controller
     *     fills in the viewer's default icon from module.jsonc (`viewers[].icon`).
     *     Override per-row when the icon depends on the record's data
     *     (e.g. PersonsViewer switches between 'user' and 'company' based
     *     on person_type).
     * - t1: string|array|null (first line, left -- main text, displayed bold)
     * - i1: string|array|null (first line, right -- e.g. amount, code)
     * - t2: string|array|null (second line, left -- e.g. doc number, date)
     * - i2: string|array|null (second line, right -- e.g. total with VAT)
     * - t3: string|array|null (optional third line -- e.g. description)
     *
     * Each field (t1, i1, t2, i2, t3) can be:
     * - null: not displayed
     * - "string": plain text
     * - {"text": "...", "class": "...", "icon": "..."}: styled span
     * - [{"text": "...", "class": "..."}, ...]: array of styled spans
     *
     * Available classes: amount, muted, bold, primary, success, warning, danger
     *
     * @param array $rowData Raw DB row
     * @return array Structured row
     */
    abstract public function renderRow(array $rowData): array;

    /**
     * Column declaration for the grid layout. Null = viewer does not
     * support grid (list-only). See docs/viewer-grid.md §3.1–§3.2.
     *
     * @return list<array{id: string, label: string, width?: int,
     *                    align?: 'left'|'right', grow?: bool}>|null
     */
    public function getGridColumns(): ?array
    {
        return null;
    }

    /**
     * Optional grid options. Supported keys: showIndex (default true).
     * See docs/viewer-grid.md §3.5.
     */
    public function getGridOptions(): array
    {
        return [];
    }

    /**
     * Render a single row for the grid layout. Only called when
     * getGridColumns() !== null. Cell values use the same span format as
     * renderRow() fields (string, {text, class?, badge?}, or array of
     * spans); missing/null cell key = empty cell. No icon in grid rows.
     * See docs/viewer-grid.md §3.3.
     *
     * @return array{id: int, cells: array<string, mixed>, stateStyle?: ?string,
     *               rowClass?: ?string, group?: ?array{key: string, label: string}}
     */
    public function renderGridRow(array $rowData): array
    {
        return [];
    }

    /**
     * Default presentation layout: 'list' | 'grid'. Grid only applies
     * when the viewer supports it (getGridColumns() !== null) — the
     * controller validates and falls back to 'list' otherwise.
     */
    public function getDefaultLayout(): string
    {
        return 'list';
    }

    /**
     * Optional totals footer — aggregation over the WHOLE filtered set
     * (separate SELECT SUM(...) with the same WHERE as selectRows()).
     * Returns a map columnId => cell value (span format), or null.
     * The controller calls this only for layout=grid and page 0.
     * See docs/viewer-grid.md §3.5 (D7).
     */
    public function renderGridFooter(?string $search, array $filters): ?array
    {
        return null;
    }

    /**
     * Render the detail panel for a selected row.
     * Returns tabs with content.
     *
     * Content types:
     * - "properties": {type: "properties", groups: [{title, items: [{label, value}]}]}
     * - "table": {type: "table", columns: [{id, label}], rows: [{col: val}]}
     * - "html": {type: "html", html: "<div>..."}
     *
     * @param int $recordId
     * @return array {tabs: [{id: string, label: string, content: array}]}
     */
    public function renderDetail(int $recordId): array
    {
        return ['tabs' => []];
    }

    /**
     * Bloky strukturovaného pole (#74, I6) pro obsah typu `properties`
     * v `renderDetail()`:
     *
     *     'content' => ['type' => 'properties',
     *                   'groups' => $this->structuredFieldProperties($record, 'filing_profile',
     *                                                                'economy.vat.filingProfileCz')]
     *
     * Schéma se vybírá podle `_schema` v uložené hodnotě (záznam se zobrazí
     * podle verze, kterou nese), `$fallbackSchemaKey` platí jen když ho
     * hodnota nemá. Prázdná hodnota / nedostupné schéma → `[]`, takže
     * volající může blok podle prázdnosti vynechat.
     *
     * @param array<string, mixed> $row
     * @return list<array{title: ?string, items: list<array{label: string, value: string}>}>
     */
    protected function structuredFieldProperties(
        array $row,
        string $column,
        ?string $fallbackSchemaKey = null,
    ): array {
        $value = $row[$column] ?? null;
        $schema = (new StructuredFieldResolver($this->config))->forValue($value, $fallbackSchemaKey);
        if ($schema === null) {
            return [];
        }
        return (new StructuredFieldRenderer($this->config))->properties($schema, $value);
    }

    /**
     * Toolbar actions. Changes based on whether a row is selected.
     *
     * Labels come from `core.system.viewerDefaults.toolbarActions.<id>.name`,
     * already localized by ConfigLocalizer at compile time. English fallbacks
     * apply when the cfgItem is missing (e.g. config not yet compiled).
     *
     * @param array|null $selectedRow null if no row selected
     * @return array [{id: string, label: string, icon?: string, variant?: string}]
     */
    public function getToolbarActions(?array $selectedRow): array
    {
        $defs = ($this->config?->cfgItem('core.system.viewerDefaults') ?? [])['toolbarActions'] ?? [];

        $createDef = $defs['create'] ?? ['name' => 'Add', 'variant' => 'primary'];
        $actions = [[
            'id'      => 'create',
            'label'   => $createDef['name'] ?? 'Add',
            'variant' => $createDef['variant'] ?? 'primary',
        ]];

        if ($selectedRow !== null) {
            $editDef = $defs['edit'] ?? ['name' => 'Open', 'variant' => 'secondary'];
            $actions[] = [
                'id'      => 'edit',
                'label'   => $editDef['name'] ?? 'Open',
                'variant' => $editDef['variant'] ?? 'secondary',
            ];
        }

        return $actions;
    }

    /**
     * Filter definitions for the right panel.
     *
     * @return array [{id: string, label: string, type: string, options?: array}]
     */
    public function getFilters(): array
    {
        return [];
    }

    /**
     * Prefill values for a new record initiated from this viewer's
     * "Add" toolbar action. The viewer meta endpoint exposes the result as
     * `newRecordDefaults` and the frontend passes it to the form dialog as
     * `defaultData`. Used by per-type viewers (e.g. issued invoices set
     * `doc_type` so the form can pre-select a matching number series).
     *
     * @return array<string, mixed>
     */
    public function getNewRecordDefaults(): array
    {
        return [];
    }

    /**
     * Returns the list of number series shown as bottom tabs in the viewer's
     * row list. Empty array = no series tabs.
     *
     * Subclasses scoped to a single doc_type (e.g. ReceivedInvoicesViewer)
     * override this to expose the active series for their type. Generic
     * viewers leave the default empty. The meta endpoint exposes the result
     * as `numberSeries`.
     *
     * @return list<array{id: int, name: string}>
     */
    public function getNumberSeries(): array
    {
        return [];
    }

    /**
     * Look up a localized detail-tab label from a cfgItem of shape
     * `{tabs: {<key>: {name: "..."}}}`. The compiled config already holds
     * the language-resolved `name` thanks to ConfigLocalizer.
     *
     * Subclasses use this in renderDetail() instead of hardcoding labels —
     * fall back to the supplied English string when the cfgItem is missing
     * (e.g. config not yet compiled).
     */
    protected function detailTabLabel(string $cfgItemId, string $key, string $englishFallback): string
    {
        $defs = ($this->config?->cfgItem($cfgItemId) ?? [])['tabs'] ?? [];
        return $defs[$key]['name'] ?? $englishFallback;
    }

    /**
     * Shortcut for the most common case — the "Overview" tab shared by
     * almost every viewer's detail panel. Lives in core.system so all
     * modules pick up the same translation.
     */
    protected function defaultOverviewLabel(): string
    {
        return $this->detailTabLabel('core.system.viewerDetailLabels', 'overview', 'Overview');
    }

    /**
     * Build an accent-insensitive search condition for multiple columns.
     * Helper for subclasses to use in selectRows(). Column names are bare
     * (wrapped in backticks here, not qualified); viewers with aliased
     * columns call SearchCondition::anyContains() directly.
     *
     * @param string[] $columns Column names to search
     * @param string $search Raw search term (no wildcards — Dibi adds them)
     * @return array{0: string, 1: array} [sql_fragment, params]
     */
    protected function buildSearchCondition(array $columns, string $search): array
    {
        $columnSqls = array_map(static fn (string $col) => "`{$col}`", $columns);
        return SearchCondition::anyContains($columnSqls, $search);
    }

    /**
     * Build the ORDER BY clause honouring the active grid sort (D9).
     *
     * $columnMap maps grid column ids to SQL expressions (grid id is not
     * necessarily a column of this table — e.g. partner_name → p.`full_name`).
     * With an active sort whose column is in the map, returns
     * 'ORDER BY {expr} {DIR}, {uniqueTail} {DIR}'; otherwise returns
     * $default unchanged. $uniqueTail (typically the qualified `id`) is
     * ALWAYS appended — the deterministic-pagination rule; it follows the
     * sort direction for stable keyset behaviour.
     * See docs/viewer-grid.md §7.1.
     *
     * @param array<string, string> $columnMap  grid column id => SQL expression
     * @param string $default    full default clause incl. 'ORDER BY'
     * @param string $uniqueTail qualified unique column, e.g. 'j.`id`'
     */
    protected function buildSortedOrderBy(array $columnMap, string $default, string $uniqueTail): string
    {
        $column = $this->sort['column'] ?? null;
        if ($column === null || !isset($columnMap[$column])) {
            return $default;
        }
        $dir = strtolower((string) ($this->sort['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        return 'ORDER BY ' . $columnMap[$column] . ' ' . $dir . ', ' . $uniqueTail . ' ' . $dir;
    }

    /**
     * Compute LIMIT clause values for offset-based pagination.
     * Fetches pageSize + 1 to detect whether more rows exist.
     *
     * @param int $pageNumber Zero-based page number
     * @return array{0: int, 1: int} [offset, limit] -- use as "LIMIT offset, limit"
     */
    protected function buildPaginationLimit(int $pageNumber): array
    {
        $offset = $pageNumber * $this->pageSize;
        $limit = $this->pageSize + 1;
        return [$offset, $limit];
    }
}
