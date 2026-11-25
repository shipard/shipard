<script>
  // Stromový layout vieweru — docs/viewer-tree.md (#169 D8, D20–D22).
  // Řádky přicházejí ze serveru jako plochý seznam v pořadí průchodu do
  // hloubky (`depth`, `childCount`, `parent`, `match`); komponenta z nich
  // podle sady rozbalených uzlů vybere viditelné a nakreslí je jako lehkou
  // tabulku: první sloupec stromový (odsazení, chevron, ikona), ostatní
  // buňky jako v gridu. Na telefonu dvouřádkové řádky bez hlavičky.
  //
  // Stav rozbalení komponenta nedrží — dostává ho (`expanded`, `expandAll`)
  // a jen hlásí klik na chevron. Paměť a dočasné rozbalení při hledání řeší
  // Viewer.svelte.
  import { tick } from 'svelte';
  import Icon from '../ui/Icon.svelte';
  import SpanBadge from './SpanBadge.svelte';
  import { normalizeSpans } from './viewerSpans.js';
  import { visibleTreeRows, isExpanded } from '../../utils/viewerTree.js';
  import { resolveIcon, iconChevronRight, iconChevronDown, iconAdd } from '../../icons.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { t } from '../../i18n/index.js';

  let {
    /** Všechny řádky stromu (DFS pořadí ze serveru). */
    rows,
    /** Sloupce ve tvaru sloupců gridu; první je stromový. */
    columns,
    selectedRowId = null,
    /** Sada id rozbalených uzlů (Set), null = nic. */
    expanded = null,
    /** true = všechno rozbalené, `expanded` se nečte. */
    expandAll = false,
    loadingRows = false,
    onSelect,
    onRowDblClick,
    onToggle,
    onRowAction,
  } = $props();

  let scrollEl = $state(null);

  let visibleRows = $derived(visibleTreeRows(rows, expanded, expandAll));
  let treeColumn = $derived(columns[0] ?? null);
  let restColumns = $derived(columns.slice(1));
  // Sloupec akce se kreslí, jen když ho nějaký řádek potřebuje.
  let hasActions = $derived(rows.some(r => r.rowAction != null));

  // Šířky sloupců: tabulka má pevné rozvržení (`table-layout: fixed`), aby
  // sloupce s deklarovanou šířkou ji opravdu dostaly a nesmrskly se na
  // obsah vedle roztaženého názvu. Sloupec bez šířky (typicky stromový
  // s `grow`) si rozdělí zbytek. Šířka přichází ze serveru v px, do CSS
  // jde px / 16 jako u gridu (#133 D46).
  const ACTION_COL_REM = 2.5;
  const FLEX_COL_MIN_REM = 14;

  function colStyle(col) {
    return col.width ? `width: ${col.width / 16}rem;` : '';
  }

  // Nejmenší šířka tabulky — pod ní se strom roluje do strany, místo aby
  // se sloupec bez šířky smrskl na nulu.
  let tableMinWidth = $derived(
    columns.reduce((sum, col) => sum + (col.width ? col.width / 16 : FLEX_COL_MIN_REM), 0)
      + (hasActions ? ACTION_COL_REM : 0)
  );

  /** Neprázdné buňky mimo stromový sloupec — druhý řádek na telefonu. */
  function restCells(row) {
    return restColumns
      .map(col => ({ id: col.id, spans: (normalizeSpans(row.cells?.[col.id]) ?? []).filter(s => s?.text != null && s.text !== '') }))
      .filter(cell => cell.spans.length > 0);
  }

  function handleToggle(event, row) {
    event.stopPropagation();
    onToggle?.(row);
  }

  // Akce řádku nesmí řádek vybrat.
  function handleRowAction(event, row) {
    event.stopPropagation();
    onRowAction?.(row);
  }

  // Vybraný řádek do zorného pole — výběr odjinud (otevření záznamu
  // z jiného místa) rozbalí předky a řádek může být mimo obrazovku. Efekt
  // čte jen selectedRowId; na rozbalování a sbalování nereaguje.
  $effect(() => {
    const id = selectedRowId;
    if (id == null) return;
    tick().then(() => {
      scrollEl?.querySelector(`[data-row-id="${id}"]`)?.scrollIntoView({ block: 'nearest' });
    });
  });
</script>

<!-- Spany jedné buňky — stejný formát jako list a grid (badge = pilulka). -->
{#snippet cellSpans(spans)}
  {#each spans ?? [] as span}
    {#if span.badge}
      <SpanBadge style={span.badge} text={span.text} />
    {:else}
      <span class={span.class ? `shpd-tree__span--${span.class}` : ''}>{span.text}</span>
    {/if}
  {/each}
{/snippet}

<!-- Chevron uzlu s dětmi, jinak mezera stejné šířky. -->
{#snippet chevron(row)}
  {#if row.childCount > 0}
    {@const open = isExpanded(row, expanded, expandAll)}
    <button
      class="shpd-tree__chevron"
      type="button"
      data-testid="viewer-tree-toggle"
      aria-expanded={open}
      aria-label={t(open ? 'viewer.tree.collapse' : 'viewer.tree.expand')}
      onclick={(e) => handleToggle(e, row)}
    >
      <Icon icon={open ? iconChevronDown : iconChevronRight} size="xs" />
    </button>
  {:else}
    <span class="shpd-tree__chevron shpd-tree__chevron--none"></span>
  {/if}
{/snippet}

<!-- Počet podřízených u sbaleného uzlu. -->
{#snippet childCount(row)}
  {#if row.childCount > 0 && !isExpanded(row, expanded, expandAll)}
    <span class="shpd-tree__count">{t('viewer.tree.childCount', { count: row.childCount })}</span>
  {/if}
{/snippet}

{#snippet rowActionButton(row)}
  {#if row.rowAction}
    <button
      class="shpd-tree__action"
      type="button"
      data-testid="viewer-tree-row-action"
      title={row.rowAction.label}
      aria-label={row.rowAction.label}
      onclick={(e) => handleRowAction(e, row)}
    >
      <Icon icon={resolveIcon(row.rowAction.icon, iconAdd)} size="sm" />
    </button>
  {/if}
{/snippet}

<div
  class="shpd-tree"
  class:shpd-tree--mobile={layoutStore.isMobile}
  data-testid="viewer-tree"
  bind:this={scrollEl}
>
  {#if loadingRows && rows.length === 0}
    <div class="shpd-tree__status">
      <span class="shpd-tree__spinner"></span>
      <span>{t('common.loading')}</span>
    </div>
  {:else if rows.length === 0}
    <div class="shpd-tree__status">
      {t('common.empty')}
    </div>
  {:else if layoutStore.isMobile}
    <!-- Telefon: bez hlavičky, první buňka a pod ní ostatní spojené „ · “ (D20). -->
    {#each visibleRows as row (row.id)}
      {@const rest = restCells(row)}
      <div
        class="shpd-tree__item {row.stateStyle ? `docState_${row.stateStyle}` : ''}"
        class:shpd-tree__item--selected={selectedRowId === row.id}
        class:shpd-tree__item--context={row.match === false}
        data-testid="viewer-tree-row"
        data-row-id={row.id}
        style:padding-left="calc({row.depth} * var(--shpd-tree-indent))"
      >
        {@render chevron(row)}
        <button class="shpd-tree__item-main" type="button" onclick={() => onSelect?.(row)}>
          <span class="shpd-tree__icon">
            <Icon icon={resolveIcon(row.icon)} />
          </span>
          <span class="shpd-tree__item-text">
            <span class="shpd-tree__item-line">
              <span class="shpd-tree__label">{@render cellSpans(normalizeSpans(row.cells?.[treeColumn?.id]))}</span>
              {@render childCount(row)}
            </span>
            {#if rest.length > 0}
              <span class="shpd-tree__item-line shpd-tree__item-line--secondary">
                {#each rest as cell, i (cell.id)}
                  <!-- Oddělovač výrazem (mezery kolem tečky v šabloně Svelte ořízne);
                       řádek je flex, takže mezery mezi prvky šablony nic nepřidají. -->
                  {#if i > 0}<span class="shpd-tree__sep">{' · '}</span>{/if}
                  <span class="shpd-tree__rest-cell">{@render cellSpans(cell.spans)}</span>
                {/each}
              </span>
            {/if}
          </span>
        </button>
        {@render rowActionButton(row)}
      </div>
    {/each}
  {:else}
    <table class="shpd-tree__table" style:min-width="{tableMinWidth}rem">
      <colgroup>
        {#each columns as col (col.id)}
          <col style={colStyle(col)} />
        {/each}
        {#if hasActions}
          <col style="width: {ACTION_COL_REM}rem;" />
        {/if}
      </colgroup>
      <thead>
        <tr>
          {#each columns as col (col.id)}
            <th class="shpd-tree__th" class:shpd-tree__th--num={col.align === 'right'}>{col.label}</th>
          {/each}
          {#if hasActions}
            <th class="shpd-tree__th"></th>
          {/if}
        </tr>
      </thead>
      <tbody>
        {#each visibleRows as row (row.id)}
          <tr
            class="shpd-tree__tr {row.stateStyle ? `docState_${row.stateStyle}` : ''}"
            class:shpd-tree__tr--selected={selectedRowId === row.id}
            class:shpd-tree__tr--context={row.match === false}
            data-testid="viewer-tree-row"
            data-row-id={row.id}
            onclick={() => onSelect?.(row)}
            ondblclick={() => onRowDblClick?.(row)}
          >
            <td class="shpd-tree__td shpd-tree__td--tree">
              <div class="shpd-tree__node" style:padding-left="calc({row.depth} * var(--shpd-tree-indent))">
                {@render chevron(row)}
                <span class="shpd-tree__icon">
                  <Icon icon={resolveIcon(row.icon)} />
                </span>
                <span class="shpd-tree__label">{@render cellSpans(normalizeSpans(row.cells?.[treeColumn?.id]))}</span>
                {@render childCount(row)}
              </div>
            </td>
            {#each restColumns as col (col.id)}
              <td class="shpd-tree__td" class:shpd-tree__td--num={col.align === 'right'}>
                {@render cellSpans(normalizeSpans(row.cells?.[col.id]))}
              </td>
            {/each}
            {#if hasActions}
              <td class="shpd-tree__td shpd-tree__td--action">
                {@render rowActionButton(row)}
              </td>
            {/if}
          </tr>
        {/each}
      </tbody>
    </table>
  {/if}
</div>

<style>
  /* Vlastní rolovací obal jako ViewerGrid (#133 D57) — držící hlavička na
     něm závisí. Odsazení úrovně je jedna proměnná pro desktop i telefon. */
  .shpd-tree {
    --shpd-tree-indent: 1.375rem;
    flex: 1;
    overflow: auto;
    background-color: var(--shpd-color-bg);
  }

  .shpd-tree__table {
    width: 100%;
    table-layout: fixed;
    border-collapse: separate;
    border-spacing: 0;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-tree__th {
    position: sticky;
    top: 0;
    z-index: 2;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    text-align: left;
    font-weight: 600;
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    border-bottom: 2px solid var(--shpd-color-border);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .shpd-tree__tr {
    cursor: pointer;
    transition: background-color 0.1s;
    /* Držící hlavička nesmí překrýt řádek dorolovaný do zorného pole. */
    scroll-margin-top: 2.25rem;
  }

  .shpd-tree__tr:hover > .shpd-tree__td {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-tree__td {
    padding: 0.375rem var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    height: 2.125rem;
    box-sizing: border-box;
  }

  /* Levý stavový proužek — stejně jako ViewerGrid: ::before na první
     buňce, barva z globálních docState_* tříd (--shpd-row-bar). */
  .shpd-tree__td:first-child {
    position: relative;
  }

  .shpd-tree__td:first-child::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 6px; /* px-ok: stavový proužek */
    background-color: var(--shpd-row-bar, transparent);
  }

  .shpd-tree__node {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    min-width: 0;
  }

  /* Chevron: tlačítko kvůli klávesnici a čtečce; uzel bez dětí má mezeru
     stejné šířky, ať ikony a názvy sourozenců lícují. */
  .shpd-tree__chevron {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 1.25rem;
    height: 1.25rem;
    padding: 0;
    border: none;
    border-radius: var(--shpd-radius-sm);
    background: none;
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
  }

  .shpd-tree__chevron:hover {
    background-color: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }

  .shpd-tree__chevron:focus-visible,
  .shpd-tree__action:focus-visible,
  .shpd-tree__item-main:focus-visible {
    outline: 2px solid var(--shpd-color-primary);
    outline-offset: -2px;
  }

  .shpd-tree__chevron--none {
    cursor: default;
    pointer-events: none;
  }

  .shpd-tree__icon {
    display: inline-flex;
    flex-shrink: 0;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-tree__label {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Počet podřízených u sbaleného uzlu — šedě, za názvem. */
  .shpd-tree__count {
    flex-shrink: 0;
    margin-left: var(--shpd-space-xs);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-xs);
  }

  /* Předek jako kontext (match: false, D21) — tlumeně, barvou sekundárního
     textu; klikatelný jako každý jiný řádek. */
  .shpd-tree__tr--context > .shpd-tree__td,
  .shpd-tree__tr.docState_archive > .shpd-tree__td,
  .shpd-tree__tr.docState_trash > .shpd-tree__td {
    color: var(--shpd-color-text-secondary);
  }

  /* Výběr: stejné tokeny jako ViewerRow / ViewerGrid; tlumené a tónové
     texty mají na vybraném řádku barvu běžného textu (#153 D91). */
  .shpd-tree__tr--selected > .shpd-tree__td {
    background-color: var(--shpd-color-bg-selected);
    color: var(--shpd-color-text);
  }

  .shpd-tree__tr--selected:hover > .shpd-tree__td {
    background-color: var(--shpd-color-bg-selected-hover);
  }

  .shpd-tree__tr--selected .shpd-tree__icon,
  .shpd-tree__tr--selected .shpd-tree__count,
  .shpd-tree__tr--selected .shpd-tree__chevron,
  .shpd-tree__tr--selected .shpd-tree__action,
  .shpd-tree__tr--selected .shpd-tree__span--muted,
  .shpd-tree__tr--selected .shpd-tree__span--primary,
  .shpd-tree__tr--selected .shpd-tree__span--warning,
  .shpd-tree__tr--selected .shpd-tree__span--danger,
  .shpd-tree__item--selected .shpd-tree__icon,
  .shpd-tree__item--selected .shpd-tree__count,
  .shpd-tree__item--selected .shpd-tree__chevron,
  .shpd-tree__item--selected .shpd-tree__action,
  .shpd-tree__item--selected .shpd-tree__item-line--secondary,
  .shpd-tree__item--selected .shpd-tree__span--muted,
  .shpd-tree__item--selected .shpd-tree__span--primary,
  .shpd-tree__item--selected .shpd-tree__span--warning,
  .shpd-tree__item--selected .shpd-tree__span--danger {
    color: var(--shpd-color-text);
  }

  .shpd-tree__tr--selected .shpd-tree__span--success::before,
  .shpd-tree__tr--selected :global(.shpd-span-badge--success::before),
  .shpd-tree__item--selected .shpd-tree__span--success::before,
  .shpd-tree__item--selected :global(.shpd-span-badge--success::before) {
    background: var(--shpd-color-success);
    box-shadow: 0 0 0 1px var(--shpd-color-text-on-fill);
  }

  .shpd-tree__th--num,
  .shpd-tree__td--num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  /* Buňka akce je přesně na tlačítko: bez odsazení a bez výpustky, jinak
     by se u okraje ukázalo oříznuté „…“. */
  .shpd-tree__td--action {
    padding: 0;
    text-align: center;
    text-overflow: clip;
  }

  /* Akce řádku: vidět při najetí, na vybraném řádku a při fokusu z
     klávesnice; na dotykovém zařízení vždy (najetí tam není). Schovává se
     průhledností, ne display — tlačítko zůstává v pořadí tabulátoru. */
  .shpd-tree__action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 1.75rem;
    height: 1.75rem;
    padding: 0;
    border: none;
    border-radius: var(--shpd-radius-sm);
    background: none;
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    opacity: 0;
  }

  .shpd-tree__action:hover {
    background-color: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }

  .shpd-tree__tr:hover .shpd-tree__action,
  .shpd-tree__tr--selected .shpd-tree__action,
  .shpd-tree__action:focus-visible {
    opacity: 1;
  }

  @media (hover: none) {
    .shpd-tree__action {
      opacity: 1;
    }
  }

  /* Víc spanů v jedné buňce (název + štítek stavu) — mezera mezi nimi. */
  .shpd-tree__td:not(.shpd-tree__td--tree) > :global(* + *),
  .shpd-tree__label > :global(* + *),
  .shpd-tree__rest-cell > :global(* + *) {
    margin-left: var(--shpd-space-xs);
  }

  /* Styled span variants — stejný slovník jako ViewerRow a ViewerGrid. */
  .shpd-tree__span--amount   { font-variant-numeric: tabular-nums; font-weight: 600; }
  .shpd-tree__span--muted    { color: var(--shpd-color-text-secondary); }
  .shpd-tree__span--bold     { font-weight: 600; }
  .shpd-tree__span--primary  { color: var(--shpd-color-primary-text); }
  .shpd-tree__span--success::before {
    content: '';
    display: inline-block;
    width: 0.5rem;
    height: 0.5rem;
    margin-right: var(--shpd-space-xs);
    border-radius: 50%;
    vertical-align: middle;
    background: var(--shpd-color-success-mark);
  }
  .shpd-tree__span--warning  { color: var(--shpd-color-warning-text); }
  .shpd-tree__span--danger   { color: var(--shpd-color-danger-text); }

  /* ── Telefon: strom přes celou šířku, dvouřádkové řádky ── */
  .shpd-tree__item {
    position: relative;
    display: flex;
    align-items: center;
    min-height: 2.75rem;
    border-bottom: 1px solid var(--shpd-color-border);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    scroll-margin-top: var(--shpd-space-sm);
  }

  .shpd-tree__item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 6px; /* px-ok: stavový proužek */
    background-color: var(--shpd-row-bar, transparent);
  }

  /* Chevron a akce jako dotykové cíle 44 px (2.75rem). */
  .shpd-tree--mobile .shpd-tree__chevron,
  .shpd-tree--mobile .shpd-tree__action {
    width: 2.75rem;
    height: 2.75rem;
  }

  .shpd-tree__item-main {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    flex: 1;
    min-width: 0;
    min-height: 2.75rem;
    padding: var(--shpd-space-xs) var(--shpd-space-sm) var(--shpd-space-xs) 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .shpd-tree__item-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .shpd-tree__item-line {
    display: flex;
    align-items: baseline;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
  }

  .shpd-tree__item-line--secondary {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-xs);
  }

  .shpd-tree__rest-cell {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .shpd-tree__sep {
    flex-shrink: 0;
    white-space: pre;
  }

  .shpd-tree__item--context,
  .shpd-tree__item.docState_archive,
  .shpd-tree__item.docState_trash {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-tree__item--selected {
    background-color: var(--shpd-color-bg-selected);
    color: var(--shpd-color-text);
  }

  /* Status / spinner — stejné texty a vzor jako list a grid. */
  .shpd-tree__status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-tree__spinner {
    display: inline-block;
    width: 1.125rem;
    height: 1.125rem;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-tree-spin 0.7s linear infinite;
    flex-shrink: 0;
  }

  @keyframes shpd-tree-spin {
    to { transform: rotate(360deg); }
  }
</style>
