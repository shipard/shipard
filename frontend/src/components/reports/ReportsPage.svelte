<script module>
  // Parametry per report přežívají přepínání reportů v rámci session —
  // module-level mapa, ne localStorage (trvalé uložení je otevřený bod
  // Fáze 3). Katalog se načítá jednou za session.
  const sessionState = new Map(); // reportId → {params, thousands}
  let catalogPromise = null;
</script>

<script>
  // Generická stránka reportu (D10) — z item.panelParams.reportId a katalogu
  // vybere definici, drží stav parametrů (období, detail, v tisících),
  // volá GET /_reports/{id} a výsledek předává čistému rendereru ReportView.
  import { untrack } from 'svelte';
  import PeriodPicker from './PeriodPicker.svelte';
  import VatPeriodPicker from './VatPeriodPicker.svelte';
  import ReportView from './ReportView.svelte';
  import Select from '../ui/Select.svelte';
  import Button from '../ui/Button.svelte';
  import Popover from '../ui/Popover.svelte';
  import { iconDownload } from '../../icons.js';
  import { noticeStore } from '../../stores/notice.svelte.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import {
    fetchReportCatalog, runReport, downloadReport, defaultPeriod, defaultVatPeriod, hasVatPeriod,
  } from '../../api/reports.js';

  let { item } = $props();

  const reportId = $derived(item?.panelParams?.reportId ?? null);

  let catalog = $state(null); // {items, fiscalYears}
  let catalogError = $state(null);
  let params = $state(null);  // {fiscalYear, monthFrom, monthTo, detail}
  let thousands = $state(false);
  let result = $state(null);
  let runError = $state(null);
  let loading = $state(false);
  let requestSeq = 0;

  const reportDef = $derived(catalog?.items.find((i) => i.id === reportId) ?? null);
  const periodSource = $derived(reportDef?.periodSource ?? 'fiscal');
  const detailOptions = $derived(
    (reportDef?.params.find((p) => p.id === 'detail')?.options ?? [])
      .map((o) => ({ value: o, label: t(`reports.detail.${o}`) })),
  );
  const vatReportType = $derived(reportDef?.vatReportType ?? 'return');
  const noPeriods = $derived(catalog !== null && (periodSource === 'vatPeriod'
    ? !catalog.vatRegistrations.some((r) => (r.periods ?? []).some((p) => p.type === vatReportType))
    : catalog.fiscalYears.length === 0));

  async function loadCatalog() {
    catalogError = null;
    catalogPromise ??= fetchReportCatalog();
    const res = await catalogPromise;
    if (res === null) return; // 401 — globální auth flow
    if (!res.success) {
      catalogPromise = null; // retry smí fetch zopakovat
      catalogError = translateError(res.error);
      return;
    }
    catalog = {
      items: res.data.items ?? [],
      fiscalYears: res.data.periods?.fiscalYears ?? [],
      vatRegistrations: res.data.periods?.vatRegistrations ?? [],
    };
  }

  loadCatalog();

  // Overlay parametrů z deep-linku validovaný proti katalogu — nevalidní
  // pole padají na base; 400 z ručně upravené URL tak prakticky nenastane
  // (zbytek jistí banner z runReport).
  function overlayDeepLink(base, pending) {
    const merged = { ...base };
    if (periodSource === 'vatPeriod') {
      // Instance musí existovat a být typu reportu — jinak zůstává default
      // (typ vynucuje i server, 400 jistí banner z runReport).
      if (pending.period !== undefined && hasVatPeriod(catalog.vatRegistrations, pending.period, vatReportType)) {
        merged.period = pending.period;
      }
    } else {
      const year = pending.fiscalYear !== undefined
        ? catalog.fiscalYears.find((y) => String(y.name) === String(pending.fiscalYear))
        : null;
      if (year) merged.fiscalYear = String(year.name);
      const months = catalog.fiscalYears
        .find((y) => String(y.name) === String(merged.fiscalYear))?.months ?? 12;
      const from = pending.monthFrom ?? merged.monthFrom;
      const to = pending.monthTo ?? merged.monthTo;
      if (from >= 1 && from <= to && to <= months) {
        merged.monthFrom = from;
        merged.monthTo = to;
      }
    }
    const detailParam = reportDef?.params.find((p) => p.id === 'detail');
    if (pending.detail && detailParam?.options.includes(pending.detail)) {
      merged.detail = pending.detail;
    }
    return merged;
  }

  // Resolve parametrů při změně reportu / načtení katalogu: deep-link
  // (one-shot) overlay nad session/default; jinak session mapa, jinak
  // default (poslední celý měsíc, default detail deklarace).
  $effect(() => {
    if (!catalog || !reportId) return;
    // untrack: konzumace čte i nuluje tentýž $state — bez něj by se efekt
    // po vynulování naplánoval znovu.
    const pending = untrack(() => navigationStore.consumePendingReportParams());
    const saved = sessionState.get(reportId);
    if (saved && !pending) {
      params = saved.params;
      thousands = saved.thousands;
      return;
    }
    const period = periodSource === 'vatPeriod'
      ? defaultVatPeriod(catalog.vatRegistrations, vatReportType)
      : defaultPeriod(catalog.fiscalYears);
    // `detail` jen když ho report deklaruje — server by neznámý parametr odmítl.
    const detailParam = reportDef?.params.find((p) => p.id === 'detail');
    const base = saved?.params
      ?? (period ? (detailParam ? { ...period, detail: detailParam.default } : { ...period }) : null);
    params = base && pending ? overlayDeepLink(base, pending) : base;
    thousands = saved?.thousands ?? false;
  });

  // Deep-link URL (D10): ?report=&fy=&mf=&mt=&detail= přes replaceState —
  // bez reloadu, bez zásahu do zbytku shellu. Odchod ze stránky query
  // uklidí, aby reload neresuscitoval report přes jinou obrazovku.
  function syncUrl(id, p) {
    const entries = p.period != null
      ? { report: id, p: String(p.period) }
      : { report: id, fy: p.fiscalYear, mf: String(p.monthFrom), mt: String(p.monthTo) };
    if (p.detail !== undefined) entries.detail = p.detail;
    const query = new URLSearchParams(entries);
    history.replaceState(null, '', `${window.location.pathname}?${query}`);
  }

  $effect(() => () => {
    history.replaceState(null, '', window.location.pathname);
  });

  // Spuštění reportu při změně parametrů. `thousands` je čistě vizuální —
  // čte se přes untrack, aby přepínač nevyvolal nový API request.
  $effect(() => {
    const id = reportId;
    const p = params;
    if (!id || !p) {
      result = null;
      return;
    }
    sessionState.set(id, { params: p, thousands: untrack(() => thousands) });
    syncUrl(id, p);
    const seq = ++requestSeq;
    loading = true;
    runError = null;
    runReport(id, p).then((res) => {
      if (seq !== requestSeq) return; // stale odpověď po další změně parametrů
      loading = false;
      if (res === null) return;
      if (!res.success) {
        runError = translateError(res.error);
        result = null;
        return;
      }
      result = res.data;
    });
  });

  // Detail select — lokální zrcadlo kvůli bind:value (params je immutable).
  let detailValue = $state(null);
  $effect(() => {
    detailValue = params?.detail ?? null;
  });

  function commitDetail() {
    if (detailValue && params && detailValue !== params.detail) {
      params = { ...params, detail: detailValue };
    }
  }

  function changePeriod(period) {
    if (params) {
      params = { ...params, ...period };
    }
  }

  function setThousands(value) {
    thousands = value;
    const saved = reportId ? sessionState.get(reportId) : null;
    if (saved) saved.thousands = value;
  }

  // Export (XLSX / CSV) — stejné parametry jako zobrazený výsledek; proto
  // jen s načteným výsledkem a ne během načítání nového. „V tisících" se
  // do exportu nepromítá (čísla vždy přesně).
  const exportFormats = ['xlsx', 'csv'];
  let exportOpen = $state(false);
  let exportAnchor = $state(null);
  let exporting = $state(false);

  async function exportReport(format) {
    exportOpen = false;
    if (!reportId || !params || exporting) return;
    exporting = true;
    const res = await downloadReport(reportId, params, format);
    exporting = false;
    if (res !== null && !res.success) {
      noticeStore.show(`${t('reports.export.failed')}: ${translateError(res.error)}`);
    }
  }
</script>

<div class="shpd-reports">
  <div class="shpd-reports__toolbar">
    <h1 class="shpd-reports__title">{item?.label ?? ''}</h1>
    {#if catalog && reportDef && !noPeriods}
      {#if periodSource === 'vatPeriod'}
        <VatPeriodPicker
          registrations={catalog.vatRegistrations}
          reportType={vatReportType}
          value={params}
          onChange={changePeriod}
        />
      {:else}
        <PeriodPicker
          fiscalYears={catalog.fiscalYears}
          granularities={reportDef.periodGranularities}
          value={params}
          onChange={changePeriod}
        />
      {/if}
      {#if detailOptions.length > 0}
        <span class="shpd-reports__detail">
          <Select bind:value={detailValue} options={detailOptions} required onchange={commitDetail} />
        </span>
      {/if}
      <div class="shpd-reports__format" role="radiogroup">
        <button
          type="button"
          class="shpd-reports__format-segment"
          class:shpd-reports__format-segment--active={!thousands}
          role="radio"
          aria-checked={!thousands}
          onclick={() => setThousands(false)}
        >{t('reports.format.exact')}</button>
        <button
          type="button"
          class="shpd-reports__format-segment"
          class:shpd-reports__format-segment--active={thousands}
          role="radio"
          aria-checked={thousands}
          onclick={() => setThousands(true)}
        >{t('reports.format.thousands')}</button>
      </div>
      <span class="shpd-reports__export" bind:this={exportAnchor}>
        <Button
          label={t('reports.export.label')}
          icon={iconDownload}
          variant="secondary"
          size="sm"
          disabled={!result || loading}
          loading={exporting}
          onclick={() => { exportOpen = !exportOpen; }}
          testid="report-export"
        />
      </span>
    {/if}
  </div>

  {#if exportOpen}
    <Popover open={true} anchor={exportAnchor} placement="bottom" onClose={() => { exportOpen = false; }}>
      <div class="shpd-reports__export-menu">
        {#each exportFormats as format (format)}
          <button
            type="button"
            class="shpd-reports__export-item"
            data-testid="report-export-{format}"
            onclick={() => exportReport(format)}
          >{t(`reports.export.${format}`)}</button>
        {/each}
      </div>
    </Popover>
  {/if}

  <div class="shpd-reports__body">
    {#if catalogError}
      <div class="shpd-reports__error">
        <p>{t('reports.catalogFailed')}: {catalogError}</p>
        <button type="button" class="shpd-reports__retry" onclick={loadCatalog}>{t('reports.retry')}</button>
      </div>
    {:else if catalog && !reportDef}
      <p class="shpd-reports__note">{t('reports.unknownReport')}</p>
    {:else if noPeriods}
      <p class="shpd-reports__note">
        {t(periodSource === 'vatPeriod' ? 'reports.noVatRegistrations' : 'reports.noPeriods')}
      </p>
    {:else if runError}
      <div class="shpd-reports__error">
        <p>{t('reports.loadFailed')}: {runError}</p>
      </div>
    {:else if loading && !result}
      <p class="shpd-reports__note">{t('reports.loading')}</p>
    {:else if result}
      <div class="shpd-reports__result" class:shpd-reports__result--loading={loading}>
        <ReportView {result} {thousands} />
      </div>
    {/if}
  </div>
</div>

<style>
  .shpd-reports {
    display: flex;
    flex-direction: column;
    height: 100%;
  }

  .shpd-reports__toolbar {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    flex-wrap: wrap;
    padding: var(--shpd-space-md);
    background-color: var(--shpd-color-bg);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-reports__title {
    margin: 0;
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-reports__detail {
    width: auto;
    min-width: 10em;
  }

  .shpd-reports__format {
    display: inline-flex;
    gap: var(--shpd-space-xs);
  }

  .shpd-reports__format-segment {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
  }

  .shpd-reports__format-segment:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-reports__format-segment--active {
    border-color: var(--shpd-color-accent);
    background-color: var(--shpd-color-bg-secondary);
    font-weight: 500;
  }

  .shpd-reports__export-menu {
    display: flex;
    flex-direction: column;
    min-width: 160px;
    padding: 4px 0;
  }

  .shpd-reports__export-item {
    text-align: left;
    padding: 10px 14px;
    border: none;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    cursor: pointer;
  }

  .shpd-reports__export-item:hover {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-reports__body {
    flex: 1;
    overflow-y: auto;
    padding: var(--shpd-space-md);
  }

  .shpd-reports__result--loading {
    opacity: 0.6;
  }

  .shpd-reports__note {
    padding: var(--shpd-space-lg);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-reports__error {
    padding: var(--shpd-space-md);
    border: 1px solid var(--shpd-color-state-error-text);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-state-error-bg);
    color: var(--shpd-color-state-error-text);
  }

  .shpd-reports__retry {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    border: 1px solid currentColor;
    border-radius: var(--shpd-radius-md);
    background: none;
    color: inherit;
    cursor: pointer;
  }
</style>
