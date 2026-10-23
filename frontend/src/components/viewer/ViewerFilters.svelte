<script>
  /**
   * Generický filtr bar vieweru — renderuje definice z meta.filters
   * (backend TableViewer::getFilters()). Podporované typy:
   *
   *   - select   {id, label, options: [{value, label, parent?}], parentFilter?}
   *              Závislý select: parentFilter odkazuje na id jiného filtru,
   *              options se omezí na ty s option.parent === hodnota rodiče;
   *              bez zvoleného rodiče je select disabled.
   *   - text     {id, label} — debounced input (prefix/contains řeší backend)
   *   - checkbox {id, label} — hodnota '1' / odstraněno
   *
   * Jiné typy (např. historický 'enum' v AlertsViewer) se přeskakují —
   * rodič (Viewer.svelte) bar renderuje jen když má aspoň jeden
   * podporovaný filtr. Hodnoty drží rodič (plain objekt id → string);
   * onChange(id, value) — prázdná hodnota filtr ruší.
   */
  import { t } from '../../i18n/index.js';

  let { filters = [], values = {}, onChange } = $props();

  const TEXT_DEBOUNCE_MS = 300;
  let textTimers = {};

  function handleTextInput(id, value) {
    clearTimeout(textTimers[id]);
    textTimers[id] = setTimeout(() => onChange?.(id, value.trim()), TEXT_DEBOUNCE_MS);
  }

  function optionsFor(filter) {
    const options = filter.options ?? [];
    if (!filter.parentFilter) return options;
    const parentValue = values[filter.parentFilter];
    if (parentValue == null || parentValue === '') return [];
    return options.filter(o => String(o.parent) === String(parentValue));
  }
</script>

<div class="shpd-viewer-filters">
  {#each filters as f (f.id)}
    {#if f.type === 'select'}
      <label class="shpd-viewer-filters__item">
        <span class="shpd-viewer-filters__label">{f.label}</span>
        <select
          class="shpd-viewer-filters__select"
          value={values[f.id] ?? ''}
          disabled={f.parentFilter != null && !values[f.parentFilter]}
          onchange={(e) => onChange?.(f.id, e.currentTarget.value)}
        >
          <option value="">{t('viewer.filters.all')}</option>
          {#each optionsFor(f) as o (o.value)}
            <option value={String(o.value)}>{o.label}</option>
          {/each}
        </select>
      </label>
    {:else if f.type === 'text'}
      <label class="shpd-viewer-filters__item">
        <span class="shpd-viewer-filters__label">{f.label}</span>
        <input
          class="shpd-viewer-filters__input"
          type="text"
          value={values[f.id] ?? ''}
          oninput={(e) => handleTextInput(f.id, e.currentTarget.value)}
        />
      </label>
    {:else if f.type === 'checkbox'}
      <label class="shpd-viewer-filters__item shpd-viewer-filters__item--checkbox">
        <input
          type="checkbox"
          checked={values[f.id] === '1'}
          onchange={(e) => onChange?.(f.id, e.currentTarget.checked ? '1' : '')}
        />
        <span class="shpd-viewer-filters__label">{f.label}</span>
      </label>
    {/if}
  {/each}
</div>

<style>
  .shpd-viewer-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--shpd-space-sm) var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
  }

  .shpd-viewer-filters__item {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    min-width: 0;
  }

  .shpd-viewer-filters__item--checkbox {
    flex-direction: row;
    align-items: center;
    gap: 0.375rem;
    /* Zarovnat checkbox na účaří inputů vedle (label nad polem je vynechán). */
    padding-bottom: 0.375rem;
  }

  .shpd-viewer-filters__label {
    font-size: 0.7rem;
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }

  .shpd-viewer-filters__item--checkbox .shpd-viewer-filters__label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-viewer-filters__select,
  .shpd-viewer-filters__input {
    padding: 0.1875rem 0.375rem;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    outline: none;
    max-width: 130px;
  }

  .shpd-viewer-filters__select:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .shpd-viewer-filters__input:focus,
  .shpd-viewer-filters__select:focus {
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }
</style>
