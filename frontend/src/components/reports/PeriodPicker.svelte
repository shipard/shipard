<script module>
  /**
   * Label zvoleného období dle vzoru starého Shipardu:
   * „2026 / 8", „2026 / 2Q", „2026 / 1|2", „2026" (celý rok);
   * nezarovnaný interval (deep-link) degraduje na „2026 / 2–4".
   *
   * @param {{fiscalYear: string, monthFrom: number, monthTo: number}|null} value
   * @param {Array<{name: string, months: number}>} fiscalYears
   * @returns {string}
   */
  export function formatPeriodLabel(value, fiscalYears = []) {
    if (!value) return '';
    const { fiscalYear, monthFrom: a, monthTo: b } = value;
    const months = fiscalYears.find((y) => String(y.name) === String(fiscalYear))?.months ?? 12;
    if (a === 1 && b === months) return String(fiscalYear);
    if (a === b) return `${fiscalYear} / ${a}`;
    if (b - a === 2 && a % 3 === 1) return `${fiscalYear} / ${(a + 2) / 3}Q`;
    if (b - a === 5 && (a === 1 || a === 7)) return `${fiscalYear} / ${a === 1 ? 1 : 2}|2`;
    return `${fiscalYear} / ${a}–${b}`;
  }
</script>

<script>
  // Picker období — dropdown s mřížkou roky × měsíc | čtvrtletí | pololetí |
  // rok (D8). Controlled: dostane hodnotu a onChange, stav drží rodič.
  // Nabízí jen granularity deklarované reportem; u kratšího fiskálního roku
  // jen existující měsíce a celá čtvrtletí/pololetí.
  import Popover from '../ui/Popover.svelte';
  import { t } from '../../i18n/index.js';

  let {
    fiscalYears = [],
    granularities = [],
    value = null,
    disabled = false,
    onChange,
  } = $props();

  let open = $state(false);
  let anchorEl = $state(null);

  // Roky sestupně — nejnovější nahoře, jako ve starém Shipardu.
  const years = $derived([...fiscalYears].reverse());

  const showMonths = $derived(granularities.includes('month'));
  const showQuarters = $derived(granularities.includes('quarter'));
  const showHalves = $derived(granularities.includes('halfYear'));
  const showYear = $derived(granularities.includes('year'));

  function select(year, monthFrom, monthTo) {
    open = false;
    onChange?.({ fiscalYear: String(year.name), monthFrom, monthTo });
  }

  function isActive(year, monthFrom, monthTo) {
    return value !== null
      && String(value.fiscalYear) === String(year.name)
      && value.monthFrom === monthFrom
      && value.monthTo === monthTo;
  }

  function range(count) {
    return Array.from({ length: count }, (_, i) => i + 1);
  }
</script>

<button
  type="button"
  class="shpd-period__trigger"
  bind:this={anchorEl}
  {disabled}
  aria-haspopup="dialog"
  aria-expanded={open}
  onclick={() => { if (!disabled) open = !open; }}
>
  <span class="shpd-period__trigger-label">{t('reports.period.label')}:</span>
  <span class="shpd-period__trigger-value">{formatPeriodLabel(value, fiscalYears)}</span>
  <span class="shpd-period__trigger-arrow" aria-hidden="true">▾</span>
</button>

<Popover {open} anchor={anchorEl} placement="bottom" width="auto" onClose={() => { open = false; }}>
  <div class="shpd-period__grid">
    {#each years as year (year.name)}
      <div class="shpd-period__row">
        <span class="shpd-period__year">{year.name}</span>
        {#if showMonths}
          <span class="shpd-period__cells">
            {#each range(Math.min(12, year.months)) as m}
              <button
                type="button"
                class="shpd-period__cell"
                class:shpd-period__cell--active={isActive(year, m, m)}
                onclick={() => select(year, m, m)}
              >{m}</button>
            {/each}
          </span>
        {/if}
        {#if showQuarters}
          <span class="shpd-period__cells shpd-period__cells--divided">
            {#each range(Math.floor(year.months / 3)) as q}
              <button
                type="button"
                class="shpd-period__cell"
                class:shpd-period__cell--active={isActive(year, 3 * q - 2, 3 * q)}
                onclick={() => select(year, 3 * q - 2, 3 * q)}
              >{q}Q</button>
            {/each}
          </span>
        {/if}
        {#if showHalves}
          <span class="shpd-period__cells shpd-period__cells--divided">
            {#each range(Math.floor(year.months / 6)) as h}
              <button
                type="button"
                class="shpd-period__cell"
                class:shpd-period__cell--active={isActive(year, 6 * h - 5, 6 * h)}
                onclick={() => select(year, 6 * h - 5, 6 * h)}
              >{h}|2</button>
            {/each}
          </span>
        {/if}
        {#if showYear}
          <span class="shpd-period__cells shpd-period__cells--divided">
            <button
              type="button"
              class="shpd-period__cell shpd-period__cell--year"
              class:shpd-period__cell--active={isActive(year, 1, year.months)}
              onclick={() => select(year, 1, year.months)}
            >{t('reports.period.yearColumn')}</button>
          </span>
        {/if}
      </div>
    {/each}
  </div>
</Popover>

<style>
  .shpd-period__trigger {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    font-family: var(--shpd-font-family);
    cursor: pointer;
  }

  .shpd-period__trigger:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .shpd-period__trigger:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-period__trigger-label {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-period__trigger-value {
    font-weight: 600;
  }

  .shpd-period__trigger-arrow {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-period__grid {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    max-height: 60vh;
    overflow-y: auto;
  }

  .shpd-period__row {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
  }

  .shpd-period__year {
    width: 3.2em;
    flex: none;
    font-weight: 600;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-period__cells {
    display: inline-flex;
    gap: 0.125rem;
  }

  .shpd-period__cells--divided {
    padding-left: var(--shpd-space-xs);
    border-left: 1px solid var(--shpd-color-border);
    margin-left: var(--shpd-space-xs);
  }

  .shpd-period__cell {
    min-width: 2em;
    padding: 0.125rem var(--shpd-space-xs);
    background: none;
    border: 1px solid transparent;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    font-variant-numeric: tabular-nums;
    cursor: pointer;
    text-align: center;
  }

  .shpd-period__cell:hover {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-period__cell--active {
    background-color: var(--shpd-color-accent);
    color: var(--shpd-color-text-on-accent, #fff);
    font-weight: 600;
  }

  .shpd-period__cell--year {
    min-width: 3em;
  }
</style>
