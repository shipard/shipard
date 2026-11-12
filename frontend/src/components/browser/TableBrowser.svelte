<script>
  import { get } from '../../api/client.js';
  import Button from '../ui/Button.svelte';
  import Notice from '../ui/Notice.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { formatDate, formatDateTime } from '../../utils/formatDate.js';

  let { tab } = $props();

  // --- Form dialog state ---
  let formOpen = $state(false);
  let editRecordId = $state(null);

  // --- State ---
  let columns = $state([]);
  let rows = $state([]);
  let total = $state(0);
  let limit = $state(20);
  let offset = $state(0);
  let sortColumn = $state(null);
  let sortDirection = $state('asc');
  let loading = $state(false);
  let error = $state(null);

  // --- Derived ---
  let startRecord = $derived(total === 0 ? 0 : offset + 1);
  let endRecord = $derived(Math.min(offset + limit, total));
  let hasPrev = $derived(offset > 0);
  let hasNext = $derived(offset + limit < total);

  // --- Helpers ---

  function isNumericType(type) {
    return ['int', 'smallint', 'bigint', 'numeric'].includes(type);
  }

  function formatCell(value, col) {
    if (value === null || value === undefined) return '—';
    switch (col.type) {
      case 'int':
      case 'smallint':
      case 'bigint':
        return String(value);
      case 'numeric':
        return typeof value === 'number'
          ? value.toFixed(col.scale ?? 2)
          : String(value);
      case 'boolean':
        return value ? t('common.yes') : t('common.no');
      case 'date':
        return typeof value === 'string' ? formatDate(value) : String(value);
      case 'datetime':
        return typeof value === 'string' ? formatDateTime(value) : String(value);
      default:
        return String(value);
    }
  }

  function buildDataPath() {
    let path = `/${tab.table}?limit=${limit}&offset=${offset}`;
    if (sortColumn) path += `&sort=${sortColumn}:${sortDirection}`;
    if (tab.filter) {
      for (const [key, value] of Object.entries(tab.filter)) {
        path += `&filter[${key}]=${value}`;
      }
    }
    return path;
  }

  // --- Data fetching ---

  async function fetchData() {
    loading = true;
    error = null;

    const result = await get(buildDataPath());
    loading = false;

    if (!result?.success) {
      error = result?.error ? translateError(result.error) : t('browser.fetchFailed');
      return;
    }

    rows = result.data ?? [];
    total = result.meta?.total ?? 0;
  }

  async function init() {
    loading = true;
    error = null;
    columns = [];
    rows = [];
    total = 0;

    const metaResult = await get(`/_meta/tables/${tab.table}`);
    if (!metaResult?.success) {
      error = metaResult?.error?.message ?? t('browser.metaFetchFailed');
      loading = false;
      return;
    }

    // Filter out primary key, password_hash, and json columns
    columns = (metaResult.data?.columns ?? []).filter(col => {
      if (col.primaryKey) return false;
      if (col.id === 'password_hash') return false;
      if (col.type === 'json') return false;
      return true;
    });

    await fetchData();
  }

  // --- Form dialog handlers ---

  function openCreate() {
    editRecordId = null;
    formOpen = true;
  }

  function openEdit(row) {
    editRecordId = row.id ?? null;
    formOpen = true;
  }

  function handleFormClose() {
    formOpen = false;
    editRecordId = null;
  }

  function handleFormSaved() {
    // Refresh the current page after a successful save
    fetchData();
  }

  // --- Interaction handlers ---

  function handleSort(colId) {
    if (sortColumn === colId) {
      sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
      sortColumn = colId;
      sortDirection = 'asc';
    }
    offset = 0;
    fetchData();
  }

  function handlePageSize(e) {
    limit = Number(e.target.value);
    offset = 0;
    fetchData();
  }

  function handlePrev() {
    offset = Math.max(0, offset - limit);
    fetchData();
  }

  function handleNext() {
    offset = offset + limit;
    fetchData();
  }

  // Re-initialize whenever the tab changes (different table or filter)
  $effect(() => {
    // Read tab fields to register reactive dependencies
    const _table = tab.table;
    const _filter = tab.filter;

    offset = 0;
    sortColumn = null;
    sortDirection = 'asc';
    init();
  });
</script>

<FormDialog
  table={tab.table}
  recordId={editRecordId}
  open={formOpen}
  onClose={handleFormClose}
  onSaved={handleFormSaved}
/>

<div class="shpd-browser">
  <div class="shpd-browser__toolbar">
    <Button label={t('browser.addRecord')} variant="primary" onclick={openCreate} />
  </div>

  {#if loading && columns.length === 0}
    <!-- Initial load spinner -->
    <div class="shpd-browser__loading">
      <span class="shpd-browser__spinner" aria-label={t('common.loading')}></span>
      <span class="shpd-browser__loading-text">{t('common.loading')}</span>
    </div>

  {:else if error}
    <!-- Chyba načtení místo obsahu (#133 D60): Notice nahoře v oblasti. -->
    <div class="shpd-browser__notice"><Notice tone="danger">{error}</Notice></div>

  {:else}
    <!-- Obal datové mřížky (D57) — roluje v obou osách -->
    <div class="shpd-browser__table-wrap">
      <table class="shpd-browser__table">
        <thead>
          <tr>
            {#each columns as col (col.id)}
              <th
                class="shpd-browser__th"
                class:shpd-browser__th--numeric={isNumericType(col.type)}
                class:shpd-browser__th--sorted={sortColumn === col.id}
                onclick={() => handleSort(col.id)}
                aria-sort={sortColumn === col.id ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'}
              >
                <span class="shpd-browser__th-label">{col.name}</span>
                {#if sortColumn === col.id}
                  <span class="shpd-browser__sort-icon" aria-hidden="true">
                    {sortDirection === 'asc' ? '▲' : '▼'}
                  </span>
                {/if}
              </th>
            {/each}
          </tr>
        </thead>

        <tbody>
          {#if loading}
            <!-- Reload spinner row shown during sort/pagination refetch -->
            <tr>
              <td colspan={columns.length} class="shpd-browser__loading-row">
                <span class="shpd-browser__spinner" aria-label={t('common.loading')}></span>
              </td>
            </tr>
          {:else if rows.length === 0}
            <tr>
              <td colspan={columns.length} class="shpd-browser__empty-row">
                {t('common.empty')}
              </td>
            </tr>
          {:else}
            {#each rows as row}
              <tr class="shpd-browser__row" ondblclick={() => openEdit(row)}>
                {#each columns as col (col.id)}
                  <td
                    class="shpd-browser__td"
                    class:shpd-browser__td--numeric={isNumericType(col.type)}
                  >
                    {formatCell(row[col.id], col)}
                  </td>
                {/each}
              </tr>
            {/each}
          {/if}
        </tbody>
      </table>
    </div>

    <!-- Pagination bar -->
    <div class="shpd-browser__pagination">
      <span class="shpd-browser__pagination-info">
        {t('browser.pagination.info', { start: startRecord, end: endRecord, total })}
      </span>

      <div class="shpd-browser__pagination-controls">
        <button
          class="shpd-browser__pagination-btn"
          disabled={!hasPrev || loading}
          onclick={handlePrev}
        >{t('browser.pagination.prev')}</button>

        <button
          class="shpd-browser__pagination-btn"
          disabled={!hasNext || loading}
          onclick={handleNext}
        >{t('browser.pagination.next')}</button>
      </div>

      <label class="shpd-browser__page-size-label">
        {t('browser.pagination.pageSize')}
        <select
          class="shpd-browser__page-size"
          value={limit}
          onchange={handlePageSize}
        >
          <option value="20">20</option>
          <option value="50">50</option>
          <option value="100">100</option>
        </select>
      </label>
    </div>
  {/if}
</div>

<style>
  .shpd-browser {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }

  /* Toolbar */
  .shpd-browser__toolbar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  /* Loading — initial full-area spinner */
  .shpd-browser__loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    flex: 1;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-browser__loading-text {
    color: var(--shpd-color-text-secondary);
  }

  /* CSS-only spinner */
  .shpd-browser__spinner {
    display: inline-block;
    width: 1.125rem;
    height: 1.125rem;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-spin 0.7s linear infinite;
    flex-shrink: 0;
  }

  @keyframes shpd-spin {
    to { transform: rotate(360deg); }
  }

  /* Chyba načtení — obal dává odsazení od krajů, vzhled má Notice. */
  .shpd-browser__notice {
    padding: var(--shpd-space-md);
  }

  /* Datová mřížka s držící hlavičkou (#133 D57): vlastní obal, který
     vyplní panel nad stránkováním a roluje v obou osách — sticky
     hlavička na něm závisí. Proto ne `TableScroll`. */
  .shpd-browser__table-wrap {
    flex: 1;
    overflow: auto;
  }

  .shpd-browser__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
    white-space: nowrap;
  }

  /* Header */
  .shpd-browser__th {
    position: sticky;
    top: 0;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background-color: var(--shpd-color-bg);
    border-bottom: 2px solid var(--shpd-color-border);
    text-align: left;
    font-weight: 600;
    color: var(--shpd-color-text);
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
  }

  .shpd-browser__th:hover {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-browser__th--sorted {
    color: var(--shpd-color-primary);
  }

  .shpd-browser__th--numeric {
    text-align: right;
  }

  .shpd-browser__th-label {
    margin-right: var(--shpd-space-xs);
  }

  .shpd-browser__sort-icon {
    font-size: 0.65rem;
    vertical-align: middle;
    color: var(--shpd-color-primary);
  }

  /* Data rows */
  .shpd-browser__row {
    cursor: pointer;
  }

  .shpd-browser__row:hover {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-browser__td {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    color: var(--shpd-color-text);
    vertical-align: middle;
  }

  .shpd-browser__td--numeric {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  /* Empty / loading row inside tbody */
  .shpd-browser__empty-row,
  .shpd-browser__loading-row {
    padding: var(--shpd-space-xl) var(--shpd-space-md);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-browser__loading-row {
    height: 5rem;
    vertical-align: middle;
  }

  /* Pagination bar */
  .shpd-browser__pagination {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
    flex-wrap: wrap;
  }

  .shpd-browser__pagination-info {
    flex: 1;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-browser__pagination-controls {
    display: flex;
    gap: var(--shpd-space-xs);
  }

  .shpd-browser__pagination-btn {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    transition: background-color 0.15s, border-color 0.15s;
  }

  .shpd-browser__pagination-btn:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-hover);
    border-color: var(--shpd-color-primary);
  }

  .shpd-browser__pagination-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  .shpd-browser__page-size-label {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-browser__page-size {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text);
  }
</style>
