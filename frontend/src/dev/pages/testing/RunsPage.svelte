<script>
  // Běhy sady: řaditelná tabulka souhrnů (EvalRunSummary), zaškrtnutí dvou
  // běhů → porovnání. Běhy nad starší verzí sady jsou šedé se štítkem.
  import Button from '../../../components/ui/Button.svelte';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { rangeText } from '../../testing/metrics.js';
  import { formatDateTime, formatPercent, formatUsd, formatMs, formatCommit } from '../../format.js';
  import Crumbs from '../../components/Crumbs.svelte';
  import DevTable from '../../components/DevTable.svelte';
  import DataSourcesPanel from './DataSourcesPanel.svelte';
  import { t } from '../../i18n/index.js';

  const route = $derived(router.route);
  const set = $derived(route.params.set);

  let data = $state(null);
  let error = $state(null);
  let selected = $state([]);

  $effect(() => {
    const current = set;
    let cancelled = false;
    data = null;
    error = null;
    selected = [];
    testingApi.runs(current)
      .then((d) => { if (!cancelled) data = d; })
      .catch((e) => { if (!cancelled) error = e.message; });
    return () => { cancelled = true; };
  });

  function toggleSelected(file) {
    if (selected.includes(file)) selected = selected.filter((f) => f !== file);
    else selected = [...selected, file].slice(-2);
  }

  function compareSelected() {
    if (selected.length !== 2) return;
    // starší běh jako A, novější jako B
    const byFile = Object.fromEntries((data?.runs ?? []).map((r) => [r.file, r]));
    const [x, y] = selected;
    const [a, b] = (byFile[x]?.startedAt ?? '') <= (byFile[y]?.startedAt ?? '') ? [x, y] : [y, x];
    router.go('testingCompare', { set }, { a, b });
  }

  const columns = [
    { id: 'sel', label: '', sortable: false },
    { id: 'file', label: t('testing.runs.col.file') },
    { id: 'startedAt', label: t('testing.col.date'), defaultDir: 'desc' },
    { id: 'variant', label: t('testing.col.variant') },
    { id: 'model', label: 'Model' },
    { id: 'tagsModel', label: t('testing.col.tags') },
    { id: 'promptVersion', label: 'Prompt' },
    { id: 'repeat', label: '×', align: 'right' },
    { id: 'hit', label: t('testing.runs.col.fieldMatch'), align: 'right', sortValue: (r) => r.hit?.avg ?? null, defaultDir: 'desc' },
    { id: 'normalized', label: t('testing.col.normalized'), align: 'right', defaultDir: 'desc' },
    { id: 'clean', label: t('testing.col.clean'), align: 'right', sortValue: (r) => r.clean?.avg ?? null, defaultDir: 'desc' },
    { id: 'amountsCorrect', label: t('testing.col.amounts'), align: 'right', sortValue: (r) => r.amountsCorrect?.avg ?? null, defaultDir: 'desc' },
    { id: 'failed', label: t('testing.col.failed'), align: 'right' },
    { id: 'costPerDocument', label: t('testing.runs.col.costPerDoc'), align: 'right', sortValue: (r) => r.costTotalPerDocument ?? r.costPerDocument ?? null },
    { id: 'timePerDocumentMs', label: t('testing.runs.col.timePerDoc'), align: 'right' },
    { id: 'commit', label: 'Commit' },
  ];
</script>

<Crumbs items={[{ label: t('app.nav.testing'), href: router.href('testingDatasets') }, { label: set }]} />
<div class="dev-page__head">
  <h1>{t('testing.runs.title', { set })}{data?.dataset?.title ? ` — ${data.dataset.title}` : ''}</h1>
  <span class="dev-page__spacer"></span>
  <a href={router.href('testingJobs', {}, { dataset: set })} onclick={onLinkClick} class="dev-small">{t('testing.runs.jobsLink')}</a>
</div>

{#key set}
  <DataSourcesPanel {set} datasetTitle={data?.dataset?.title ?? ''} />
{/key}

<div class="dev-page__head">
  <h2>{t('testing.runs.heading')}</h2>
  <span class="dev-page__spacer"></span>
  <span class="dev-small dev-muted">{t('testing.runs.selected', { n: selected.length })}</span>
  <Button label={t('testing.runs.compare')} size="sm" disabled={selected.length !== 2} onclick={compareSelected} />
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !data}
  <div class="dev-empty">{t('common.loading')}</div>
{:else}
  <p class="dev-small dev-muted hint">{t('testing.runs.hint', { hash: data.currentHash.slice(0, 12) })}</p>
  <DevTable
    {columns}
    rows={data.runs}
    rowKey={(r) => r.file}
    initialSort={{ id: 'startedAt', dir: 'desc' }}
    rowClass={(r) => (r.currentSet ? '' : 'is-dim')}
    empty={t('testing.runs.empty')}
  >
    {#snippet cell(row, col)}
      {#if col.id === 'sel'}
        <input type="checkbox" checked={selected.includes(row.file)} onchange={() => toggleSelected(row.file)} aria-label={t('testing.runs.selectForCompare')} />
      {:else if col.id === 'file'}
        <a href={router.href('testingRun', { set, file: row.file })} onclick={onLinkClick}><code>{row.file}</code></a>
        {#if !row.currentSet}<span class="dev-state dev-state--missing" title={t('testing.runs.otherSetTitle')}>{t('testing.run.otherSetVersion')}</span>{/if}
        {#if (row.metricsVersion ?? 1) < 2}<span class="dev-state dev-state--neutral" title={t('testing.run.metricsV1Title')}>{t('testing.run.metricsV1')}</span>{/if}

      {:else if col.id === 'startedAt'}
        <span class="dev-nowrap">{formatDateTime(row.startedAt)}</span>
      {:else if col.id === 'model' || col.id === 'tagsModel'}
        <code>{row[col.id] ?? '—'}</code>
      {:else if col.id === 'hit'}
        <span class="dev-nowrap">{rangeText(row.hit?.avg, row.hit?.min, row.hit?.max)}</span>
      {:else if col.id === 'normalized'}
        {formatPercent(row.normalized)}
      {:else if col.id === 'clean' || col.id === 'amountsCorrect'}
        <span class="dev-nowrap">{rangeText(row[col.id]?.avg, row[col.id]?.min, row[col.id]?.max)}</span>
      {:else if col.id === 'failed'}
        <span class:dev-lvl--bad={row.failed > 0} class="dev-lvl">{row.failed}</span>
      {:else if col.id === 'costPerDocument'}
        <span title={row.tagsUsd === null || row.tagsUsd === undefined ? t('testing.runs.costExtractionOnly') : t('testing.runs.costBreakdown', { extraction: formatUsd(row.costPerDocument), tags: formatUsd(row.tagsUsd / Math.max(1, row.callsTotal)) })}>{formatUsd(row.costTotalPerDocument ?? row.costPerDocument)}</span>
      {:else if col.id === 'timePerDocumentMs'}
        {formatMs(row.timePerDocumentMs)}
      {:else if col.id === 'commit'}
        <span class="dev-muted" title={row.dirty ? t('testing.runs.dirty') : ''}>{formatCommit(row.commit, row.dirty)}</span>
      {:else}
        {row[col.id] ?? '—'}
      {/if}
    {/snippet}
  </DevTable>
{/if}

<style>
  .hint { margin: 0 0 var(--shpd-space-sm); }
</style>
