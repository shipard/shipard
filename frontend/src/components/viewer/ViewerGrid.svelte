<script>
  // Tabulkový (grid) layout vieweru — docs/viewer-grid.md §4.2.
  // Sticky hlavička + volitelný sticky součtový footer, infinite scroll
  // (vlastní scroll container, stejný threshold vzor jako list ve
  // Viewer.svelte), pořadová čísla, skupinové hlavičky (D6) a levý
  // stavový proužek přes globální docState_* třídy.
  import SpanBadge from './SpanBadge.svelte';
  import { normalizeSpans } from './viewerSpans.js';
  import { t } from '../../i18n/index.js';

  let {
    columns,
    showIndex = true,
    rows,
    footer = null,
    selectedRowId = null,
    hasMore = false,
    loadingRows = false,
    loadingMore = false,
    /** Aktivní řazení {column, dir} | null — cyklus počítá Viewer.svelte,
     *  grid jen hlásí klik na sortable hlavičku přes onSortChange(colId). */
    sort = null,
    onSortChange,
    onRowClick,
    onRowDblClick,
    onLoadMore,
  } = $props();

  let scrollEl = $state(null);

  function handleScroll() {
    if (!scrollEl || !hasMore || loadingMore || loadingRows) return;
    const { scrollTop, scrollHeight, clientHeight } = scrollEl;
    if (scrollHeight - scrollTop - clientHeight < 100) {
      onLoadMore?.();
    }
  }

  let colCount = $derived(columns.length + (showIndex ? 1 : 0));
  let growCount = $derived(columns.filter(c => c.grow).length);

  // Skupinové hlavičky vkládá frontend při změně group.key oproti
  // předchozímu řádku (bezstavové přes stránky infinite scrollu — D6).
  // Pořadová čísla běží přes skupiny průběžně.
  let displayRows = $derived.by(() => {
    const out = [];
    let prevGroupKey = null;
    let index = 0;
    for (const row of rows) {
      const group = row.group ?? null;
      if (group != null && group.key !== prevGroupKey) {
        out.push({ type: 'group', key: `g:${group.key}`, label: group.label });
      }
      prevGroupKey = group?.key ?? null;
      index += 1;
      out.push({ type: 'row', key: row.id, row, index });
    }
    return out;
  });

  /** Inline styl <col> — width px pro fixní, rovný podíl % pro grow. */
  function colStyle(col) {
    if (col.width) return `width: ${col.width}px;`;
    if (col.grow) return `width: ${Math.floor(100 / growCount)}%;`;
    return '';
  }
</script>

<!-- Render spanů jedné buňky. Span s `badge` → pilulka (přednost před `class`). -->
{#snippet cellSpans(value)}
  {#each normalizeSpans(value) ?? [] as span}
    {#if span.badge}
      <SpanBadge style={span.badge} text={span.text} />
    {:else}
      <span class={span.class ? `shpd-grid__span--${span.class}` : ''}>{span.text}</span>
    {/if}
  {/each}
{/snippet}

<div class="shpd-grid" bind:this={scrollEl} onscroll={handleScroll}>
  {#if loadingRows && rows.length === 0}
    <div class="shpd-grid__status">
      <span class="shpd-grid__spinner"></span>
      <span>{t('common.loading')}</span>
    </div>
  {:else if rows.length === 0}
    <div class="shpd-grid__status">
      {t('common.empty')}
    </div>
  {:else}
    <table class="shpd-grid__table">
      <colgroup>
        {#if showIndex}
          <col style="width: 44px;" />
        {/if}
        {#each columns as col (col.id)}
          <col style={colStyle(col)} />
        {/each}
      </colgroup>
      <thead>
        <tr>
          {#if showIndex}
            <th class="shpd-grid__th shpd-grid__th--index">#</th>
          {/if}
          {#each columns as col (col.id)}
            <th
              class="shpd-grid__th"
              class:shpd-grid__th--num={col.align === 'right'}
              aria-sort={sort?.column === col.id ? (sort.dir === 'desc' ? 'descending' : 'ascending') : undefined}
            >
              {#if col.sortable}
                <button class="shpd-grid__th-btn" type="button" onclick={() => onSortChange?.(col.id)}>
                  {col.label}{#if sort?.column === col.id}<span class="shpd-grid__sort-arrow">{sort.dir === 'desc' ? '↓' : '↑'}</span>{/if}
                </button>
              {:else}
                {col.label}
              {/if}
            </th>
          {/each}
        </tr>
      </thead>
      <tbody>
        {#each displayRows as entry (entry.key)}
          {#if entry.type === 'group'}
            <tr class="shpd-grid__group-row">
              <td class="shpd-grid__group-cell" colspan={colCount}>{entry.label}</td>
            </tr>
          {:else}
            <tr
              class="shpd-grid__tr {entry.row.stateStyle ? `docState_${entry.row.stateStyle}` : ''}"
              class:shpd-grid__tr--error={entry.row.rowClass === 'error'}
              class:shpd-grid__tr--selected={selectedRowId === entry.row.id}
              onclick={() => onRowClick?.(entry.row)}
              ondblclick={() => onRowDblClick?.(entry.row)}
            >
              {#if showIndex}
                <td class="shpd-grid__td shpd-grid__td--index">{entry.index}</td>
              {/if}
              {#each columns as col (col.id)}
                <td class="shpd-grid__td" class:shpd-grid__td--num={col.align === 'right'}>
                  {@render cellSpans(entry.row.cells?.[col.id])}
                </td>
              {/each}
            </tr>
          {/if}
        {/each}
      </tbody>
      {#if footer != null}
        <tfoot>
          <tr>
            {#if showIndex}
              <td class="shpd-grid__foot"></td>
            {/if}
            {#each columns as col (col.id)}
              <td class="shpd-grid__foot" class:shpd-grid__td--num={col.align === 'right'}>
                {@render cellSpans(footer[col.id])}
              </td>
            {/each}
          </tr>
        </tfoot>
      {/if}
    </table>

    {#if loadingMore}
      <div class="shpd-grid__status">
        <span class="shpd-grid__spinner"></span>
        <span>{t('common.loading')}</span>
      </div>
    {:else if !hasMore && rows.length > 0}
      <div class="shpd-grid__status shpd-grid__status--end">
        {t('viewer.endOfList')}
      </div>
    {/if}
  {/if}
</div>

<style>
  .shpd-grid {
    flex: 1;
    overflow: auto;
    background-color: var(--shpd-color-bg);
  }

  .shpd-grid__table {
    width: 100%;
    /* border-collapse: separate — s collapse by bordery sticky hlavičky
       odscrollovaly s obsahem. Bordery drží td/th samy. */
    border-collapse: separate;
    border-spacing: 0;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-grid__th {
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
  }

  .shpd-grid__th--index {
    color: var(--shpd-color-text-secondary);
    font-weight: 400;
  }

  /* Sortable hlavička — button kvůli a11y (klikatelné th by neneslo
     klávesnici), vizuálně splývá s ostatními th (inherit font/weight).
     U align:right sloupců drží zarovnání šířkou 100 %. */
  .shpd-grid__th-btn {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    font-weight: inherit;
    color: inherit;
    text-align: inherit;
    width: 100%;
    cursor: pointer;
    white-space: nowrap;
  }

  .shpd-grid__th-btn:hover {
    color: var(--shpd-color-primary);
  }

  .shpd-grid__sort-arrow {
    margin-left: 2px;
    color: var(--shpd-color-primary);
  }

  .shpd-grid__tr {
    cursor: pointer;
    transition: background-color 0.1s;
  }

  .shpd-grid__tr:hover > .shpd-grid__td {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-grid__td {
    padding: 6px var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    white-space: nowrap;
    height: 34px;
    box-sizing: border-box;
  }

  /* Levý stavový proužek (6 px, vizuálně shodný s ViewerRow) — ::before
     na první buňce; border-left na <tr> není spolehlivý. Barvu řídí
     --shpd-row-bar z globálních docState_* tříd (styles/base.css);
     bez proměnné je proužek průhledný. */
  .shpd-grid__td:first-child {
    position: relative;
  }

  .shpd-grid__td:first-child::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 6px;
    background-color: var(--shpd-row-bar, transparent);
  }

  /* Chybové řádky (rowClass: 'error') — stejné tokeny jako
     .shpd-detail__tr--error v detail table. */
  .shpd-grid__tr--error > .shpd-grid__td {
    background-color: var(--shpd-color-state-error-bg);
    color: var(--shpd-color-state-error-text);
  }

  /* Výběr přepisuje error podbarvení i hover. Stavový proužek
     (--shpd-row-bar) výběr nemění — zůstává i u vybraného řádku,
     stejně jako ve ViewerRow. */
  .shpd-grid__tr--selected > .shpd-grid__td {
    background-color: var(--shpd-color-bg-selected);
  }

  .shpd-grid__tr--selected:hover > .shpd-grid__td {
    background-color: var(--shpd-color-bg-selected-hover);
  }

  /* Číselné sloupce — zarovnání + tabular-nums, hlavička i buňky. */
  .shpd-grid__th--num,
  .shpd-grid__td--num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .shpd-grid__td--index {
    color: var(--shpd-color-text-secondary);
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
  }

  /* Skupinová hlavička (D6) — colspan přes všechny sloupce. */
  .shpd-grid__group-cell {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
    background-color: var(--shpd-color-bg-hover);
    border-bottom: 1px solid var(--shpd-color-border);
    white-space: nowrap;
  }

  /* Sticky součtový footer — tučně s horní linkou (vzhled jako
     _class: total řádek v detail table). */
  .shpd-grid__foot {
    position: sticky;
    bottom: 0;
    z-index: 2;
    padding: 6px var(--shpd-space-sm);
    font-weight: 600;
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    border-top: 2px solid var(--shpd-color-border);
    white-space: nowrap;
  }

  /* Styled span variants — stejný slovník jako ViewerRow. */
  .shpd-grid__span--amount   { font-variant-numeric: tabular-nums; font-weight: 600; }
  .shpd-grid__span--muted    { opacity: 0.6; }
  .shpd-grid__span--bold     { font-weight: 600; }
  .shpd-grid__span--primary  { color: var(--shpd-color-primary); }
  .shpd-grid__span--success  { color: var(--shpd-color-success); }
  .shpd-grid__span--warning  { color: var(--shpd-color-warning); }
  .shpd-grid__span--danger   { color: var(--shpd-color-danger); }

  /* Víc spanů v jedné buňce (cizoměnová částka) — mezera mezi nimi.
     :global kvůli SpanBadge (root element vlastní child komponenta,
     scoped selektor by ho minul). */
  .shpd-grid__td > :global(* + *),
  .shpd-grid__foot > :global(* + *) {
    margin-left: var(--shpd-space-xs);
  }

  /* Status / spinner — stejné texty a vzor jako list (Viewer.svelte). */
  .shpd-grid__status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-grid__status--end {
    opacity: 0.6;
    font-style: italic;
  }

  .shpd-grid__spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-grid-spin 0.7s linear infinite;
    flex-shrink: 0;
  }

  @keyframes shpd-grid-spin {
    to { transform: rotate(360deg); }
  }
</style>
