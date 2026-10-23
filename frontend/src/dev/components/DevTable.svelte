<script>
  // Řaditelná tabulka dashboardu. Sloupec: {id, label, align?, sortable?
  // (výchozí true), sortValue?(row), defaultDir?}. Buňku vykreslí snippet
  // `cell(row, column)`, bez něj `row[id]`. Null hodnoty řadí nakonec.
  import { t, currentLanguage } from '../i18n/index.js';

  let {
    columns = [],
    rows = [],
    rowKey = (row, i) => i,
    initialSort = null,
    rowClass = () => '',
    onRowClick = null,
    empty = t('common.nothingToShow'),
    cell,
  } = $props();

  // výchozí řazení je záměrně jen počáteční hodnota — dál ho řídí uživatel
  // svelte-ignore state_referenced_locally
  let sort = $state(initialSort);

  function compare(a, b) {
    const na = a === null || a === undefined;
    const nb = b === null || b === undefined;
    if (na || nb) return na === nb ? 0 : na ? 1 : -1;
    if (typeof a === 'number' && typeof b === 'number') return a - b;
    return String(a).localeCompare(String(b), currentLanguage());
  }

  const sorted = $derived.by(() => {
    if (!sort) return rows;
    const col = columns.find((c) => c.id === sort.id);
    if (!col) return rows;
    const value = col.sortValue ?? ((row) => row[col.id]);
    const dir = sort.dir === 'desc' ? -1 : 1;
    return [...rows].sort((x, y) => {
      const a = value(x);
      const b = value(y);
      const na = a === null || a === undefined;
      const nb = b === null || b === undefined;
      if (na || nb) return na === nb ? 0 : na ? 1 : -1;
      return compare(a, b) * dir;
    });
  });

  function toggle(col) {
    if (col.sortable === false) return;
    if (sort?.id === col.id) sort = { id: col.id, dir: sort.dir === 'asc' ? 'desc' : 'asc' };
    else sort = { id: col.id, dir: col.defaultDir ?? 'asc' };
  }
</script>

<div class="dev-table-wrap">
  <table class="dev-table">
    <thead>
      <tr>
        {#each columns as col (col.id)}
          <th class:dev-num={col.align === 'right'} class:is-sorted={sort?.id === col.id}>
            {#if col.sortable === false}
              {col.label}
            {:else}
              <button type="button" class="th-btn" onclick={() => toggle(col)}>
                {col.label}{#if sort?.id === col.id}<span class="arrow">{sort.dir === 'asc' ? '▲' : '▼'}</span>{/if}
              </button>
            {/if}
          </th>
        {/each}
      </tr>
    </thead>
    <tbody>
      {#if sorted.length === 0}
        <tr><td colspan={columns.length} class="dev-empty">{empty}</td></tr>
      {:else}
        {#each sorted as row, i (rowKey(row, i))}
          <tr class={rowClass(row)} class:is-clickable={!!onRowClick} onclick={onRowClick ? () => onRowClick(row) : undefined}>
            {#each columns as col (col.id)}
              <td class:dev-num={col.align === 'right'}>
                {#if cell}{@render cell(row, col)}{:else}{row[col.id] ?? '—'}{/if}
              </td>
            {/each}
          </tr>
        {/each}
      {/if}
    </tbody>
  </table>
</div>

<style>
  .th-btn {
    background: none;
    border: 0;
    padding: 0;
    font: inherit;
    color: inherit;
    cursor: pointer;
    white-space: nowrap;
  }
  .th-btn:hover { color: var(--shpd-color-text); }
  .arrow { margin-left: var(--shpd-space-xs); font-size: 0.7em; }
</style>
