<script>
  // Porovnání dvou běhů (logika ai-eval-compare): metriky vedle sebe
  // s rozdílem a značkou „v rámci šumu“, pole zlepšená a zhoršená,
  // doklady s rozdílným počtem odchylek → detail obou běhů vedle sebe.
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { metricLabel, formatMetric, formatMetricDelta, isHeadlineMetric } from '../../testing/metrics.js';
  import { formatNumber } from '../../format.js';
  import Crumbs from '../../components/Crumbs.svelte';
  import DevTable from '../../components/DevTable.svelte';
  import RunSummaryLine from '../../components/RunSummaryLine.svelte';
  import { t } from '../../i18n/index.js';

  const route = $derived(router.route);
  const set = $derived(route.params.set);
  const a = $derived(route.query.a ?? '');
  const b = $derived(route.query.b ?? '');

  let data = $state(null);
  let error = $state(null);
  let allMetrics = $state(false);
  let onlyDifferent = $state(true);

  $effect(() => {
    const args = [set, a, b];
    let cancelled = false;
    data = null;
    error = null;
    if (!a || !b) {
      error = t('testing.compare.missingParams');
      return;
    }
    testingApi.compare(...args)
      .then((d) => { if (!cancelled) data = d; })
      .catch((e) => { if (!cancelled) error = e.message; });
    return () => { cancelled = true; };
  });

  const metricRows = $derived((data?.metrics ?? []).filter((m) => allMetrics || isHeadlineMetric(m.key)));
  const messages = $derived((data?.messages ?? []).filter((m) => !onlyDifferent || m.delta === null || Math.abs(m.delta) > 1e-9 || m.a.failed !== m.b.failed));

  const messageColumns = [
    { id: 'messageId', label: t('testing.col.document') },
    { id: 'aDev', label: t('testing.compare.col.devA'), align: 'right', sortValue: (m) => m.a.deviations },
    { id: 'bDev', label: t('testing.compare.col.devB'), align: 'right', sortValue: (m) => m.b.deviations },
    { id: 'delta', label: t('testing.compare.col.delta'), align: 'right', sortValue: (m) => (m.delta === null ? null : Math.abs(m.delta)), defaultDir: 'desc' },
    { id: 'failed', label: t('testing.compare.col.failedAB'), align: 'right', sortValue: (m) => m.a.failed + m.b.failed },
    { id: 'open', label: '', sortable: false },
  ];
</script>

<Crumbs items={[{ label: t('app.nav.testing'), href: router.href('testingDatasets') }, { label: set, href: router.href('testingRuns', { set }) }, { label: t('testing.compare.crumb') }]} />
<div class="dev-page__head">
  <h1>{t('testing.compare.title')}</h1>
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !data}
  <div class="dev-empty">{t('common.loading')}</div>
{:else}
  <div class="dev-card">
    <div class="runline"><strong>A</strong> <a href={router.href('testingRun', { set, file: a })} onclick={onLinkClick}><code>{a}</code></a> <RunSummaryLine summary={data.summaries.a} showFile={false} /></div>
    <div class="runline"><strong>B</strong> <a href={router.href('testingRun', { set, file: b })} onclick={onLinkClick}><code>{b}</code></a> <RunSummaryLine summary={data.summaries.b} showFile={false} /></div>
    {#if !data.sameSelection}
      <div class="dev-alert dev-alert--warning"><span class="dev-alert__text">{t('testing.compare.differentSelection')}</span></div>
    {/if}
    {#if data.sameMetricsVersion === false}
      <div class="dev-alert dev-alert--warning"><span class="dev-alert__text">{t('testing.compare.differentMetricsVersion', { a: data.metricsVersions.a, b: data.metricsVersions.b })}</span></div>
    {/if}

    {#if Math.max(data.summaries.a.repeat, data.summaries.b.repeat) > 1}
      <p class="dev-small dev-muted note">{t('testing.compare.perRepetitionNote')}</p>
    {/if}
  </div>

  <div class="dev-card">
    <div class="dev-card__title">{t('testing.compare.metrics')}
      <label class="dev-chip" class:is-on={allMetrics}><input type="checkbox" bind:checked={allMetrics} /> {t('testing.compare.all', { n: data.metrics.length })}</label>
    </div>
    <div class="dev-table-wrap">
      <table class="dev-table">
        <thead><tr><th>{t('testing.compare.col.metric')}</th><th class="dev-num">A</th><th class="dev-num">B</th><th class="dev-num">{t('testing.compare.col.delta')}</th><th class="dev-num">{t('testing.compare.col.noise')}</th><th>{t('testing.compare.col.verdict')}</th></tr></thead>
        <tbody>
          {#each metricRows as m (m.key)}
            <tr>
              <td title={m.key}>{metricLabel(m.key)}{#if m.perRepetition}<span class="dev-muted dev-small"> {t('testing.compare.perRep')}</span>{/if}</td>
              <td class="dev-num">{formatMetric(m.key, m.a)}</td>
              <td class="dev-num">{formatMetric(m.key, m.b)}</td>
              <td class="dev-num">{formatMetricDelta(m.key, m.delta)}</td>
              <td class="dev-num dev-muted">{m.noise === null ? '—' : formatMetricDelta(m.key, m.noise).replace(/^[+−±]/, '')}</td>
              <td>
                {#if m.delta === null || Math.abs(m.delta) < 1e-9}
                  <span class="dev-muted">{t('testing.compare.unchanged')}</span>
                {:else if m.withinNoise}
                  <span class="dev-state dev-state--neutral">{t('testing.compare.withinNoise')}</span>
                {:else if m.better}
                  <span class="dev-state dev-state--match">{t('testing.compare.better')}</span>
                {:else}
                  <span class="dev-state dev-state--wrong">{t('testing.compare.worse')}</span>
                {/if}
              </td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid-2">
    {#each [['improved', data.fields.improved, 'match'], ['worsened', data.fields.worsened, 'wrong']] as [kind, items, style] (kind)}
      <div class="dev-card">
        <div class="dev-card__title">{kind === 'improved' ? t('testing.compare.improved') : t('testing.compare.worsened')} <span class="dev-muted dev-small">{t('testing.compare.fieldsNote', { threshold: data.fields.threshold })}</span></div>
        {#if items.length === 0}
          <div class="dev-muted dev-small">{t('common.none')}</div>
        {:else}
          <table class="plain">
            <tbody>
              {#each items as f (f.node)}
                <tr><td><code>{f.node}</code></td><td class="dev-num dev-nowrap">{formatNumber(f.a, Number.isInteger(f.a) ? 0 : 1)} → {formatNumber(f.b, Number.isInteger(f.b) ? 0 : 1)} <span class="dev-state dev-state--{style}">{f.delta > 0 ? '+' : ''}{formatNumber(f.delta, 1)}</span></td></tr>
              {/each}
            </tbody>
          </table>
        {/if}
      </div>
    {/each}
  </div>

  <div class="dev-card">
    <div class="dev-card__title">{t('testing.runDetail.documents')}
      <label class="dev-chip" class:is-on={onlyDifferent}><input type="checkbox" bind:checked={onlyDifferent} /> {t('testing.compare.onlyDifferent')}</label>
      <span class="dev-muted dev-small">{t('testing.compare.deviationsNote')}</span>
    </div>
    <DevTable columns={messageColumns} rows={messages} rowKey={(m) => m.messageId} empty={t('testing.compare.noDifferent')}>
      {#snippet cell(m, col)}
        {#if col.id === 'messageId'}
          <a href={router.href('testingMessage', { set, file: a, messageId: m.messageId }, { b })} onclick={onLinkClick}><code>{m.messageId}</code></a>
        {:else if col.id === 'aDev'}
          {m.a.deviations === null ? '—' : formatNumber(m.a.deviations, Number.isInteger(m.a.deviations) ? 0 : 1)}
        {:else if col.id === 'bDev'}
          {m.b.deviations === null ? '—' : formatNumber(m.b.deviations, Number.isInteger(m.b.deviations) ? 0 : 1)}
        {:else if col.id === 'delta'}
          {#if m.delta === null}<span class="dev-muted">—</span>
          {:else if Math.abs(m.delta) < 1e-9}<span class="dev-muted">0</span>
          {:else}<span class="dev-lvl dev-lvl--{m.delta < 0 ? 'ok' : 'bad'}">{m.delta > 0 ? '+' : '−'}{formatNumber(Math.abs(m.delta), Number.isInteger(m.delta) ? 0 : 1)}</span>{/if}
        {:else if col.id === 'failed'}
          <span class:dev-lvl--bad={m.a.failed > 0}>{m.a.failed}</span> / <span class:dev-lvl--bad={m.b.failed > 0}>{m.b.failed}</span>
        {:else if col.id === 'open'}
          <a class="dev-small" href={router.href('testingMessage', { set, file: a, messageId: m.messageId }, { b })} onclick={onLinkClick}>{t('testing.compare.sideBySide')}</a>
        {/if}
      {/snippet}
    </DevTable>
  </div>
{/if}

<style>
  .runline { display: flex; gap: var(--shpd-space-sm); align-items: baseline; flex-wrap: wrap; margin-bottom: var(--shpd-space-xs); }
  .note { margin: var(--shpd-space-sm) 0 0; }
  .dev-card__title { display: flex; gap: var(--shpd-space-md); align-items: center; flex-wrap: wrap; }
  .dev-chip input { margin: 0; }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--shpd-space-lg); margin-top: var(--shpd-space-lg); align-items: start; }
  .grid-2 > .dev-card { margin-top: 0; }
  .grid-2 + .dev-card { margin-top: var(--shpd-space-lg); }
  table.plain { width: 100%; border-collapse: collapse; font-size: var(--shpd-font-size-sm); }
  table.plain td { padding: 0.1875rem 0; border-bottom: 1px solid var(--shpd-color-border-subtle); }
  table.plain tr:last-child td { border-bottom: 0; }
</style>
