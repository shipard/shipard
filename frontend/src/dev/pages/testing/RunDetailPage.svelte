<script>
  // Detail běhu: metriky po skupinách s rozpětím opakování, doklady,
  // rozpad po variantách, podobách a cestách výpočtu (ai / isdoc, #103
  // D76), nejčastější odchylky, stabilita a seznam dokladů s odchylkami
  // per opakování.
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import {
    fieldMetricRows, informativeMetricRow, metricRows, topDeviations, unstableNodes, rangeText, formatMetric, deviationLevel, sumOf, stateLabel, pathLabel,
    DOCUMENT_METRICS, COST_METRICS,
  } from '../../testing/metrics.js';

  import { t, label } from '../../i18n/index.js';
  import { formatNumber, formatPercent, formatUsd, formatDateTime } from '../../format.js';
  import Crumbs from '../../components/Crumbs.svelte';
  import DevTable from '../../components/DevTable.svelte';
  import RunSummaryLine from '../../components/RunSummaryLine.svelte';
  import StateBadge from '../../components/StateBadge.svelte';

  const route = $derived(router.route);
  const set = $derived(route.params.set);
  const file = $derived(route.params.file);

  let data = $state(null);
  let error = $state(null);

  $effect(() => {
    const s = set;
    const f = file;
    let cancelled = false;
    data = null;
    error = null;
    testingApi.run(s, f)
      .then((d) => { if (!cancelled) data = d; })
      .catch((e) => { if (!cancelled) error = e.message; });
    return () => { cancelled = true; };
  });

  const run = $derived(data?.run ?? null);
  const metrics = $derived(run?.metrics ?? {});
  const fieldRows = $derived(fieldMetricRows(metrics, run?.range));
  const docRows = $derived(metricRows(DOCUMENT_METRICS, metrics, run?.range));
  const costRows = $derived(metricRows(COST_METRICS, metrics, run?.range));
  const deviations = $derived(topDeviations(run?.deviationsByField, metrics['calls.ok'] ?? 0));
  // Informativní pole (#103 D114): jen běh s metricsVersion ≥ 2; starší běh je počítal do shody polí.
  const metricsVersion = $derived(data?.summary?.metricsVersion ?? 1);
  const informative = $derived(informativeMetricRow(metrics, run?.range));
  const informativeNodes = $derived(topDeviations(run?.informativeByField, metrics['calls.ok'] ?? 0));

  const fieldStability = $derived(unstableNodes(run?.fieldStability));
  const formatStability = $derived(unstableNodes(run?.formatStability));
  const repeatCount = $derived(data?.summary?.repeat ?? 1);
  const repeatIds = $derived(Array.from({ length: repeatCount }, (_, i) => i + 1));

  const messageColumns = $derived([
    { id: 'messageId', label: t('testing.col.document') },
    { id: 'variant', label: t('testing.col.variant') },
    { id: 'form', label: t('testing.col.form') },
    { id: 'path', label: t('testing.col.path'), sortValue: (m) => pathLabel(m.path) },
    ...repeatIds.map((n) => ({ id: `r${n}`, label: `×${n}`, align: 'right', sortValue: (m) => m.repeats.find((r) => r.repeat === n)?.deviations ?? null })),
    { id: 'total', label: t('testing.runDetail.col.deviationsSum'), align: 'right', sortValue: (m) => sumOf(m.repeats.map((r) => r.deviations)), defaultDir: 'desc' },
    { id: 'failed', label: t('testing.col.failed'), align: 'right', sortValue: (m) => m.repeats.filter((r) => r.status !== 'ok').length },
    { id: 'cost', label: t('testing.runDetail.col.costSum'), align: 'right', sortValue: (m) => sumOf(m.repeats.map((r) => callCost(r))) },
    { id: 'across', label: '', sortable: false },
  ]);

  function cellOf(byVariant, key) {
    return byVariant?.[key] ?? {};
  }

  /** Cena kyblíku rozpadu = extrakce + štítky, kde jsou (`tags.usd` od D76; starší běh jen extrakce). */
  function bucketCost(c) {
    if (c['cost.usd'] === null || c['cost.usd'] === undefined) return null;
    return c['cost.usd'] + (c['tags.usd'] ?? 0);
  }

  /** Cena volání = extrakce + LLM štítky (F6b D56); null bez obojího. */
  function callCost(r) {
    if ((r.costUsd === null || r.costUsd === undefined) && (r.tagsCostUsd === null || r.tagsCostUsd === undefined)) return null;
    return (r.costUsd ?? 0) + (r.tagsCostUsd ?? 0);
  }
</script>

<Crumbs items={[{ label: t('app.nav.testing'), href: router.href('testingDatasets') }, { label: set, href: router.href('testingRuns', { set }) }, { label: file }]} />

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !data}
  <div class="dev-empty">{t('common.loading')}</div>
{:else}
  <div class="dev-page__head">
    <h1>{t('testing.runDetail.title')} <code>{file}</code></h1>
  </div>
  <div class="dev-card">
    <RunSummaryLine summary={data.summary} showFile={false} />
    <div class="dev-small dev-muted meta">
      {t('testing.runDetail.callsLine', { messages: data.summary.messages, repeat: data.summary.repeat, calls: data.summary.callsTotal })} ·
      {t('testing.runDetail.connection', { connection: data.summary.connection ?? '—', platform: data.summary.platform ?? '—' })} ·
      {t('testing.runDetail.finished', { date: formatDateTime(run.finishedAt) })} ·
      {t('testing.runDetail.cost', { cost: formatUsd(data.summary.costTotalUsd ?? data.summary.costUsd) })}{#if data.summary.tagsUsd !== null && data.summary.tagsUsd !== undefined} {t('testing.runDetail.costBreakdown', { extraction: formatUsd(data.summary.costUsd), tags: formatUsd(data.summary.tagsUsd) })}{/if}
      {#if run.stoppedBy}· <span class="dev-state dev-state--missing">{label('jobs.stoppedBy', run.stoppedBy)} — {t('testing.runDetail.ofPlanned', { calls: data.summary.callsTotal, planned: run.plannedCalls ?? '?' })}</span>{/if}
    </div>
    {#if !data.summary.currentSet}
      <div class="dev-alert dev-alert--warning"><span class="dev-alert__text">{t('testing.runDetail.olderSet', { hash: String(data.summary.setHash).slice(0, 12) })}</span></div>
    {/if}
  </div>

  <div class="grid-2">
    <div>
    <div class="dev-card">
      <div class="dev-card__title">{t('testing.runDetail.fieldsByGroup')} {repeatCount > 1 ? t('testing.runDetail.avgAndRange') : ''}</div>

      <div class="dev-table-wrap">
        <table class="dev-table">
          <thead><tr><th>{t('testing.col.group')}</th><th class="dev-num">{t('testing.col.fields')}</th><th class="dev-num">{t('testing.col.hit')}</th><th class="dev-num">{t('testing.col.normalized')}</th><th class="dev-num">{t('testing.col.missing')}</th><th class="dev-num">{t('testing.col.extra')}</th><th class="dev-num">{t('testing.col.wrong')}</th></tr></thead>
          <tbody>
            {#each fieldRows as r (r.id)}
              <tr class:total={r.id === 'all'}>
                <td>{r.label}</td>
                <td class="dev-num">{formatNumber(r.n)}</td>
                <td class="dev-num dev-nowrap">{rangeText(r.hit.avg, r.hit.min, r.hit.max)}</td>
                <td class="dev-num">{formatPercent(r.normalized)}</td>
                <td class="dev-num">{formatNumber(r.missing)}</td>
                <td class="dev-num">{formatNumber(r.extra)}</td>
                <td class="dev-num">{formatNumber(r.wrong)}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
      <p class="dev-small dev-muted note">{t('testing.runDetail.countsNote')}</p>
    </div>
    <div class="dev-card">
      <div class="dev-card__title">{t('testing.runDetail.informative')}</div>
      {#if metricsVersion < 2}
        <div class="dev-muted dev-small">{t('testing.runDetail.informativeV1')}</div>
      {:else if !informative || informative.n === 0}
        <div class="dev-muted dev-small">{t('testing.runDetail.informativeNone')}</div>
      {:else}
        <div class="dev-table-wrap">
          <table class="dev-table">
            <thead><tr><th>{t('testing.col.fields')}</th><th class="dev-num">{t('testing.col.hit')}</th><th class="dev-num">{t('testing.col.normalized')}</th><th class="dev-num">{t('testing.col.missing')}</th><th class="dev-num">{t('testing.col.extra')}</th><th class="dev-num">{t('testing.col.wrong')}</th></tr></thead>
            <tbody>
              <tr>
                <td class="dev-num">{formatNumber(informative.n)}</td>
                <td class="dev-num dev-nowrap">{rangeText(informative.hit.avg, informative.hit.min, informative.hit.max)}</td>
                <td class="dev-num">{formatPercent(informative.normalized)}</td>
                <td class="dev-num">{formatNumber(informative.missing)}</td>
                <td class="dev-num">{formatNumber(informative.extra)}</td>
                <td class="dev-num">{formatNumber(informative.wrong)}</td>
              </tr>
            </tbody>
          </table>
        </div>
        {#if informativeNodes.length > 0}
          <table class="plain">
            <tbody>
              {#each informativeNodes as d (d.node)}
                <tr><td><code>{d.node}</code></td><td class="dev-num dev-nowrap">{d.count}<span class="dev-muted"> ({formatPercent(d.share, 0)})</span></td></tr>
              {/each}
            </tbody>
          </table>
        {/if}
        <p class="dev-small dev-muted note">{t('testing.runDetail.informativeNote')}</p>
      {/if}
    </div>
    </div>

    <div>
      <div class="dev-card">
        <div class="dev-card__title">{t('testing.runDetail.documents')}</div>

        <table class="plain">
          <tbody>
            {#each docRows as r (r.key)}
              <tr><td>{r.label}</td><td class="dev-num dev-nowrap">{rangeText(r.value, r.min, r.max)}</td></tr>
            {/each}
          </tbody>
        </table>
      </div>
      <div class="dev-card">
        <div class="dev-card__title">{t('testing.runDetail.callsCostTime')}</div>
        <table class="plain">
          <tbody>
            {#each costRows as r (r.key)}
              <tr><td>{r.label}</td><td class="dev-num dev-nowrap">{formatMetric(r.key, r.value)}{#if r.min !== null && r.max !== null && r.min !== r.max}<span class="dev-muted"> ({formatMetric(r.key, r.min)}–{formatMetric(r.key, r.max)})</span>{/if}</td></tr>
            {/each}
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {#snippet cellTable(kind, cells)}
    <div class="dev-table-wrap">
      <table class="dev-table">
        <thead><tr><th>{kind === 'variant' ? t('testing.col.variant') : kind === 'form' ? t('testing.col.form') : t('testing.col.path')}</th><th class="dev-num">{t('metrics.metric.calls.total')}</th><th class="dev-num">{t('testing.col.hit')}</th><th class="dev-num">{t('testing.col.clean')}</th><th class="dev-num">{t('testing.col.amounts')}</th><th class="dev-num">{t('metrics.group.rows')}</th><th class="dev-num">{t('testing.col.cost')}</th></tr></thead>
        <tbody>
          {#each Object.keys(cells ?? {}) as key (key)}
            {@const c = cellOf(cells, key)}
            <tr>
              <td>{kind === 'path' ? pathLabel(key) : key}</td>
              <td class="dev-num dev-nowrap">{c['calls.ok'] ?? 0} / {c['calls.total'] ?? 0}</td>
              <td class="dev-num dev-nowrap">{formatPercent(c['fields.all.hit'])}</td>
              <td class="dev-num dev-nowrap">{formatPercent(c['documents.clean'])}</td>
              <td class="dev-num dev-nowrap">{formatPercent(c['documents.amountsCorrect'])}</td>
              <td class="dev-num dev-nowrap">{formatPercent(c['documents.rowCountOk'])}</td>
              <td class="dev-num dev-nowrap">{formatUsd(bucketCost(c))}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/snippet}

  <div class="grid-2">
    <div class="dev-card">
      <div class="dev-card__title">{t('testing.runDetail.byVariant')}</div>
      {@render cellTable('variant', run.byVariant)}
    </div>
    <div>
      <div class="dev-card">
        <div class="dev-card__title">{t('testing.runDetail.byForm')}</div>
        {@render cellTable('form', run.byForm)}
      </div>
      <div class="dev-card">
        <div class="dev-card__title">{t('testing.runDetail.byPath')}</div>
        {#if run.byPath}
          {@render cellTable('path', run.byPath)}
          <p class="dev-small dev-muted note">{t('testing.runDetail.byPathNote')}</p>
        {:else}
          <div class="dev-muted dev-small">{t('testing.runDetail.noByPath')}</div>
        {/if}
      </div>
    </div>
  </div>

  <div class="grid-3">
    <div class="dev-card">
      <div class="dev-card__title">{t('testing.runDetail.topDeviations')}</div>
      {#if deviations.length === 0}
        <div class="dev-muted dev-small">{t('common.none')}</div>
      {:else}
        <table class="plain">
          <tbody>
            {#each deviations as d (d.node)}
              <tr><td><code>{d.node}</code></td><td class="dev-num dev-nowrap">{d.count}<span class="dev-muted"> ({formatPercent(d.share, 0)})</span></td></tr>
            {/each}
          </tbody>
        </table>
        <p class="dev-small dev-muted note">{t('testing.runDetail.topDeviationsNote')}</p>
      {/if}
    </div>
    <div class="dev-card">
      <div class="dev-card__title">{t('testing.runDetail.fieldStability')}</div>
      {#if fieldStability === null}
        <div class="dev-muted dev-small">{t('testing.runDetail.oneRepeat')}</div>
      {:else if fieldStability.length === 0}
        <div class="dev-muted dev-small">{t('testing.runDetail.allStable')}</div>
      {:else}
        <table class="plain">
          <tbody>
            {#each fieldStability as s (s.node)}
              <tr><td><code>{s.node}</code></td><td class="dev-num">{formatPercent(s.ratio, 0)}</td></tr>
            {/each}
          </tbody>
        </table>
        <p class="dev-small dev-muted note">{t('testing.runDetail.fieldStabilityNote')}</p>
      {/if}
    </div>
    <div class="dev-card">
      <div class="dev-card__title">{t('testing.runDetail.formatStability')}</div>
      {#if formatStability === null}
        <div class="dev-muted dev-small">{t('testing.runDetail.oneRepeatOrOld')}</div>
      {:else if formatStability.length === 0}
        <div class="dev-muted dev-small">{t('testing.runDetail.allSameShape')}</div>
      {:else}
        <table class="plain">
          <tbody>
            {#each formatStability as s (s.node)}
              <tr><td><code>{s.node}</code></td><td class="dev-num">{formatPercent(s.ratio, 0)}</td></tr>
            {/each}
          </tbody>
        </table>
        <p class="dev-small dev-muted note">{t('testing.runDetail.formatStabilityNote')}</p>
      {/if}
    </div>
  </div>

  <div class="dev-card">
    <div class="dev-card__title">{t('testing.runDetail.documentsCount', { n: data.messages.length })}</div>
    <DevTable
      columns={messageColumns}
      rows={data.messages}
      rowKey={(m) => m.messageId}
      empty={t('testing.runDetail.noCalls')}
    >
      {#snippet cell(m, col)}
        {#if col.id === 'messageId'}
          <a href={router.href('testingMessage', { set, file, messageId: m.messageId })} onclick={onLinkClick}><code>{m.messageId}</code></a>
          {#if m.stem}<span class="dev-muted dev-small"> {m.stem}</span>{/if}
        {:else if col.id.startsWith('r')}
          {@const rep = m.repeats.find((r) => r.repeat === Number(col.id.slice(1)))}
          {#if !rep}
            <span class="dev-muted">—</span>
          {:else if rep.status !== 'ok'}
            <a class="dev-lvl dev-lvl--failed" href={router.href('testingMessage', { set, file, messageId: m.messageId }, { repeat: rep.repeat })} onclick={onLinkClick} title={rep.errorType ?? ''}>{stateLabel('failed')}</a>
          {:else}
            <a class="dev-lvl dev-lvl--{deviationLevel(rep.deviations)}" href={router.href('testingMessage', { set, file, messageId: m.messageId }, { repeat: rep.repeat })} onclick={onLinkClick}>{rep.deviations}</a>
          {/if}
        {:else if col.id === 'total'}
          {formatNumber(sumOf(m.repeats.map((r) => r.deviations)))}
        {:else if col.id === 'failed'}
          {@const n = m.repeats.filter((r) => r.status !== 'ok').length}
          {#if n > 0}<StateBadge state="failed" label={`${n}×`} />{:else}<span class="dev-muted">0</span>{/if}
        {:else if col.id === 'cost'}
          {formatUsd(sumOf(m.repeats.map((r) => callCost(r))))}
        {:else if col.id === 'path'}
          <span class="dev-state dev-state--neutral">{pathLabel(m.path)}</span>
        {:else if col.id === 'across'}
          <a class="dev-small" href={router.href('testingMessageRuns', { set, messageId: m.messageId })} onclick={onLinkClick}>{t('testing.runDetail.acrossRuns')}</a>
        {:else}
          {m[col.id] ?? '—'}
        {/if}
      {/snippet}
    </DevTable>
  </div>
{/if}

<style>
  .meta { margin-top: var(--shpd-space-sm); }
  .grid-2 { display: grid; grid-template-columns: 3fr 2fr; gap: var(--shpd-space-lg); margin-top: var(--shpd-space-lg); align-items: start; }
  .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--shpd-space-lg); margin-top: var(--shpd-space-lg); align-items: start; }
  .grid-2 > .dev-card + .dev-card, .grid-3 > .dev-card + .dev-card { margin-top: 0; }
  .grid-2 .dev-card { margin-top: 0; }
  .grid-2 > div > .dev-card + .dev-card { margin-top: var(--shpd-space-lg); }
  .grid-3 + .dev-card { margin-top: var(--shpd-space-lg); }
  @media (max-width: 1100px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }
  table.plain { width: 100%; border-collapse: collapse; font-size: var(--shpd-font-size-sm); }
  table.plain td { padding: 3px 0; border-bottom: 1px solid var(--shpd-color-border-subtle); vertical-align: top; }
  table.plain tr:last-child td { border-bottom: 0; }
  tr.total td { font-weight: 600; border-top: 2px solid var(--shpd-color-border); }
  .note { margin: var(--shpd-space-sm) 0 0; }
</style>
