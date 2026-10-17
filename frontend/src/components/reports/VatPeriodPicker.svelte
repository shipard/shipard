<script module>
  /**
   * Label zvolené instance tvrzení: název instance („01/2026"), u více
   * registrací s prefixem názvu registrace. Neznámé id (deep-link na
   * smazanou instanci) degraduje na „#id".
   *
   * @param {{period: number}|null} value
   * @param {Array<{id: number, name: string, periods: Array<{id: number, name: string}>}>} registrations
   * @returns {string}
   */
  export function formatVatPeriodLabel(value, registrations = []) {
    if (!value || value.period == null) return '';
    for (const registration of registrations) {
      const period = (registration.periods ?? []).find((p) => p.id === value.period);
      if (period) {
        return registrations.length > 1 ? `${registration.name} — ${period.name}` : period.name;
      }
    }
    return `#${value.period}`;
  }

  /**
   * Instance daného typu zvolené registrace seskupené po letech
   * (rok = rok dateBegin), nejnovější rok první, instance v roce dle dateBegin.
   *
   * @param {{periods: Array<object>}|null} registration
   * @param {string} reportType
   * @returns {Array<{year: string, periods: Array<object>}>}
   */
  export function periodsByYear(registration, reportType) {
    const byYear = new Map();
    for (const period of registration?.periods ?? []) {
      if (period.type !== reportType) continue;
      const year = period.dateBegin.slice(0, 4);
      if (!byYear.has(year)) byYear.set(year, []);
      byYear.get(year).push(period);
    }
    return [...byYear.entries()]
      .map(([year, periods]) => ({
        year,
        periods: [...periods].sort((a, b) => a.dateBegin.localeCompare(b.dateBegin)),
      }))
      .sort((a, b) => b.year.localeCompare(a.year));
  }
</script>

<script>
  // Picker instancí daňových tvrzení — protějšek PeriodPicker pro reporty
  // s periodSource 'vatPeriod' (D11). Controlled: dostane hodnotu {period}
  // a onChange, stav drží rodič. Nabízí instance typu reportu
  // (vatReportType) zvolené registrace, filtrované rokem (roletka; default
  // rok zvolené instance). Žádné slučování kvartálů — instance jsou to,
  // co v datech je; KH čtvrtletního plátce má měsíční instance typu cs.
  import Popover from '../ui/Popover.svelte';
  import Select from '../ui/Select.svelte';
  import { t } from '../../i18n/index.js';

  let {
    registrations = [],
    reportType = 'return',
    value = null,
    disabled = false,
    onChange,
  } = $props();

  let open = $state(false);
  let anchorEl = $state(null);

  const selected = $derived.by(() => {
    if (!value || value.period == null) return null;
    for (const registration of registrations) {
      const period = (registration.periods ?? []).find((p) => p.id === value.period);
      if (period) return { registration, period };
    }
    return null;
  });

  // Registrace a rok zvolené v panelu — sledují value, dokud uživatel nepřepne.
  let panelRegistrationId = $state(null);
  let panelYear = $state(null);
  $effect(() => {
    panelRegistrationId = selected?.registration.id ?? registrations[0]?.id ?? null;
    panelYear = selected?.period.dateBegin.slice(0, 4) ?? null;
  });

  const registration = $derived(
    registrations.find((r) => r.id === panelRegistrationId) ?? null,
  );

  const registrationOptions = $derived(registrations.map((r) => ({
    value: r.id,
    label: r.vatId ? `${r.name} (${r.vatId})` : r.name,
  })));

  const yearRows = $derived(periodsByYear(registration, reportType));
  const yearOptions = $derived(yearRows.map((row) => ({ value: row.year, label: row.year })));

  // Rok mimo nabídku (jiná registrace / typ) → nejnovější dostupný.
  const effectiveYear = $derived(
    yearRows.some((row) => row.year === panelYear) ? panelYear : (yearRows[0]?.year ?? null),
  );
  const visiblePeriods = $derived(
    yearRows.find((row) => row.year === effectiveYear)?.periods ?? [],
  );

  function select(period) {
    open = false;
    onChange?.({ period: period.id });
  }

  function isActive(period) {
    return value !== null && value.period === period.id;
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
  <span class="shpd-period__trigger-value">{formatVatPeriodLabel(value, registrations)}</span>
  <span class="shpd-period__trigger-arrow" aria-hidden="true">▾</span>
</button>

<Popover {open} anchor={anchorEl} placement="bottom" width="auto" onClose={() => { open = false; }}>
  <div class="shpd-period__panel">
    {#if registrations.length > 1}
      <div class="shpd-period__registration">
        <span class="shpd-period__registration-label">{t('reports.vatRegistration.label')}</span>
        <Select bind:value={panelRegistrationId} options={registrationOptions} required />
      </div>
    {/if}
    {#if yearOptions.length > 0}
      <div class="shpd-period__registration">
        <span class="shpd-period__registration-label">{t('reports.period.yearColumn')}</span>
        <Select value={effectiveYear} options={yearOptions} required onchange={(e) => { panelYear = e?.target?.value ?? effectiveYear; }} />
      </div>
      <div class="shpd-period__list">
        {#each visiblePeriods as period (period.id)}
          <button
            type="button"
            class="shpd-period__item"
            class:shpd-period__item--active={isActive(period)}
            class:shpd-period__item--draft={period.docState === 10}
            title={period.locked ? t('reports.period.locked') : (period.docState === 10 ? t('reports.period.draft') : undefined)}
            onclick={() => select(period)}
          >
            <span class="shpd-period__item-name">{period.name}</span>
            <span class="shpd-period__item-range">{period.dateBegin} – {period.dateEnd}</span>
            {#if period.locked}<span class="shpd-period__item-flag">🔒</span>{/if}
          </button>
        {/each}
      </div>
    {:else}
      <p class="shpd-period__empty">{t('reports.period.noInstances')}</p>
    {/if}
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

  .shpd-period__panel {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
    min-width: 18rem;
  }

  .shpd-period__registration {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-period__registration-label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }

  .shpd-period__list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-height: 60vh;
    overflow-y: auto;
  }

  .shpd-period__item {
    display: flex;
    align-items: baseline;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background: none;
    border: 1px solid transparent;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text);
    font-family: var(--shpd-font-family);
    font-size: var(--shpd-font-size-sm);
    text-align: left;
    cursor: pointer;
  }

  .shpd-period__item:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-period__item--active {
    border-color: var(--shpd-color-primary);
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-period__item--draft .shpd-period__item-name {
    font-style: italic;
  }

  .shpd-period__item-name {
    font-weight: 600;
    min-width: 5em;
  }

  .shpd-period__item-range {
    color: var(--shpd-color-text-secondary);
    font-variant-numeric: tabular-nums;
  }

  .shpd-period__item-flag {
    margin-left: auto;
    font-size: var(--shpd-font-size-xs);
  }

  .shpd-period__empty {
    margin: 0;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }
</style>
