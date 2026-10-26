<script>
  // Detail dokladu v běhu: vlevo PDF, vpravo pole projekce ve skupinách
  // (očekáváno / výstup / stav), řádky jako tabulka, štítek a výjimky,
  // klasifikace; přepínač opakování (?repeat=), filtr „jen odchylky“,
  // odkaz na doklad napříč běhy. S `?b=<soubor>` druhý běh vedle sebe.
  import Button from '../../../components/ui/Button.svelte';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { formatUsd, formatMs, formatNumber } from '../../format.js';
  import { pathLabel } from '../../testing/metrics.js';
  import Crumbs from '../../components/Crumbs.svelte';
  import PdfPane from '../../components/PdfPane.svelte';
  import StateBadge from '../../components/StateBadge.svelte';
  import ValueCell from '../../components/ValueCell.svelte';
  import RunSummaryLine from '../../components/RunSummaryLine.svelte';
  import FieldGroupTable from './FieldGroupTable.svelte';
  import RowsTable from './RowsTable.svelte';
  import { t } from '../../i18n/index.js';

  const route = $derived(router.route);
  const set = $derived(route.params.set);
  const file = $derived(route.params.file);
  const messageId = $derived(route.params.messageId);
  const repeat = $derived(Math.max(1, Number.parseInt(route.query.repeat ?? '1', 10) || 1));
  const fileB = $derived(route.query.b || null);

  let detail = $state(null);
  let detailB = $state(null);
  let error = $state(null);
  let errorB = $state(null);
  let onlyDeviations = $state(false);
  let showPdf = $state(true);

  $effect(() => {
    const args = [set, file, messageId, repeat];
    let cancelled = false;
    detail = null;
    error = null;
    testingApi.message(...args)
      .then((d) => { if (!cancelled) detail = d; })
      .catch((e) => { if (!cancelled) error = e.message; });
    return () => { cancelled = true; };
  });

  $effect(() => {
    const b = fileB;
    const args = [set, b, messageId, repeat];
    let cancelled = false;
    detailB = null;
    errorB = null;
    if (!b) return;
    testingApi.message(...args)
      .then((d) => { if (!cancelled) detailB = d; })
      .catch((e) => { if (!cancelled) errorB = e.message; });
    return () => { cancelled = true; };
  });

  const compare = $derived(fileB !== null);
  const pdfSrc = $derived(detail?.hasPdf ? testingApi.pdfUrl(set, messageId) : null);
  const tablesByPath = $derived(Object.fromEntries((detail?.tables ?? []).map((t) => [t.path, t])));
  const tablesByPathB = $derived(Object.fromEntries((detailB?.tables ?? []).map((t) => [t.path, t])));
  const groupsB = $derived(Object.fromEntries((detailB?.groups ?? []).map((g) => [g.id, g])));

  /** Skupina → tabulka pole, která se v ní vykreslí místo seznamu polí. */
  const TABLE_OF_GROUP = { rows: 'rows', vatRecap: 'vatRecap', rowExceptions: '_resolve.contentTag.rowExceptions' };

  function setRepeat(n) {
    router.replaceQuery({ ...route.query, repeat: n === 1 ? '' : String(n) });
  }

  function arrayNote(a) {
    const more = a.actual > a.expected;
    return t(more ? 'testing.message.arrayMore' : 'testing.message.arrayFewer', { label: a.label, expected: a.expected, actual: a.actual });
  }
</script>

<Crumbs items={[
  { label: t('app.nav.testing'), href: router.href('testingDatasets') },
  { label: set, href: router.href('testingRuns', { set }) },
  { label: file, href: router.href('testingRun', { set, file }) },
  { label: messageId },
]} />

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !detail}
  <div class="dev-empty">{t('common.loading')}</div>
{:else}
  <div class="dev-page__head">
    <h1>{t('testing.col.document')} <code>{messageId}</code></h1>
    <span class="dev-small dev-muted">{detail.cell.stem ?? ''} · {t('testing.message.variantForm', { variant: detail.cell.variant ?? '—', form: detail.cell.form ?? '—' })} · {t('testing.message.path', { path: pathLabel(detail.path) })}</span>
    <span class="dev-page__spacer"></span>
    <span class="repeats">
      <span class="dev-small dev-muted">{t('testing.message.repeats')}</span>
      {#each detail.repeats as n (n)}
        <button type="button" class="dev-chip" class:is-on={n === repeat} onclick={() => setRepeat(n)}>×{n}</button>
      {/each}
    </span>
    <label class="dev-chip" class:is-on={onlyDeviations}><input type="checkbox" bind:checked={onlyDeviations} /> {t('testing.message.onlyDeviations')}</label>
    <label class="dev-chip" class:is-on={showPdf}><input type="checkbox" bind:checked={showPdf} /> PDF</label>
    <a class="dev-small" href={router.href('testingMessageRuns', { set, messageId })} onclick={onLinkClick}>{t('testing.message.sameAcrossRuns')}</a>
  </div>

  <div class="dev-card head">
    <div class="runline"><strong>A</strong> <RunSummaryLine summary={detail.run} /></div>
    {#if compare}
      <div class="runline"><strong>B</strong>
        {#if detailB}<RunSummaryLine summary={detailB.run} />{:else if errorB}<span class="dev-lvl--bad">{errorB}</span>{:else}<span class="dev-muted">{t('common.loading')}</span>{/if}
        <a class="dev-small" href={router.href('testingMessage', { set, file, messageId }, { repeat: repeat === 1 ? '' : repeat })} onclick={onLinkClick}>{t('testing.message.cancelCompare')}</a>
      </div>
    {/if}
    <div class="facts dev-small">
      {#each (compare && detailB ? [['A', detail], ['B', detailB]] : [['', detail]]) as [side, d] (side)}
        <div class="facts__row">
          {#if side}<strong>{side}</strong>{/if}
          <StateBadge state={d.status === 'ok' ? 'ok' : 'failed'} label={d.status === 'ok' ? t('testing.message.callOk') : t('testing.message.callFailed', { type: d.errorType ?? '?' })} title={d.note ?? ''} />
          <span><span class="dev-muted">model</span> <code>{d.model ?? '—'}</code></span>
          <span><span class="dev-muted">{t('testing.col.path')}</span> <StateBadge state="neutral" label={pathLabel(d.path)} /></span>
          <span><span class="dev-muted">{t('testing.message.facts.cost')}</span> {formatUsd(d.costUsd)}</span>
          <span><span class="dev-muted">{t('testing.message.facts.tokens')}</span> {formatNumber(d.tokensIn)} / {formatNumber(d.tokensOut)}</span>
          {#if d.tags}
            <span><span class="dev-muted">{t('testing.message.facts.tags')}</span> {formatUsd(d.tags.costUsd)} ({formatNumber(d.tags.tokensIn)} / {formatNumber(d.tags.tokensOut)}, <code>{d.tags.model ?? '—'}</code>)</span>
          {/if}
          <span><span class="dev-muted">{t('testing.message.facts.time')}</span> {formatMs(d.durationMs)}</span>
          <span><span class="dev-muted">{t('testing.message.facts.classification')}</span> <ValueCell value={d.classification?.expected} muted /> → <ValueCell value={d.classification?.actual} /> <StateBadge state={d.classification?.state} /></span>
          <span><span class="dev-muted">{t('testing.message.facts.states')}</span> {t('testing.message.counts', { match: d.counts.match, normalized: d.counts.matchNormalized, missing: d.counts.missing, extra: d.counts.extra, wrong: d.counts.wrong })}{#if d.counts.notCarried}{t('testing.message.countsNotCarried', { n: d.counts.notCarried })}{/if}{#if (d.run?.metricsVersion ?? 1) >= 2 && d.informativeCounts}{t('testing.message.countsInformative', { n: d.informativeCounts.missing + d.informativeCounts.extra + d.informativeCounts.wrong })}{/if}</span>

        </div>
      {/each}
    </div>
    {#if !detail.currentSet}
      <div class="dev-alert dev-alert--warning"><span class="dev-alert__text">{t('testing.message.olderSet')}</span></div>
    {:else if detail.countsMatch === false}
      <div class="dev-alert dev-alert--warning"><span class="dev-alert__text">{t('testing.message.countsMismatch', { counts: JSON.stringify({ ...detail.counts, informative: detail.informativeCounts }), fileCounts: JSON.stringify({ ...detail.fileCounts, informative: detail.fileInformativeCounts }) })}</span></div>

    {/if}
    {#if detail.status !== 'ok'}
      <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{t('testing.message.callFailedAlert', { type: detail.errorType ?? '?', note: detail.note ? `: ${detail.note}` : '' })}</span></div>
    {/if}
    {#each detail.arrays as a (a.path)}
      <div class="dev-alert dev-alert--info"><span class="dev-alert__text">{arrayNote(a)}</span></div>
    {/each}
    {#if detailB}
      {#each detailB.arrays as a (a.path)}
        <div class="dev-alert dev-alert--info"><span class="dev-alert__text">B — {arrayNote(a)}</span></div>
      {/each}
    {/if}
  </div>

  <div class:dev-split={showPdf} class="body">
    {#if showPdf}
      <div><PdfPane src={pdfSrc} title={`PDF ${messageId}`} /></div>
    {/if}
    <div>
      {#each detail.groups as group (group.id)}
        {#if TABLE_OF_GROUP[group.id]}
          <RowsTable
            table={tablesByPath[TABLE_OF_GROUP[group.id]]}
            tableB={compare && detailB ? tablesByPathB[TABLE_OF_GROUP[group.id]] ?? null : null}
            {onlyDeviations}
            label={group.label}
          />
        {:else}
          <FieldGroupTable
            {group}
            fields={group.fields}
            fieldsB={compare && detailB ? groupsB[group.id]?.fields ?? [] : null}
            {onlyDeviations}
          />
        {/if}
      {/each}
    </div>
  </div>
{/if}

<style>
  .repeats { display: inline-flex; align-items: center; gap: var(--shpd-space-xs); }
  .dev-chip input { margin: 0; }
  .head { margin-bottom: var(--shpd-space-lg); }
  .runline { display: flex; gap: var(--shpd-space-sm); align-items: baseline; flex-wrap: wrap; margin-bottom: var(--shpd-space-xs); }
  .facts { display: flex; flex-direction: column; gap: var(--shpd-space-xs); margin-top: var(--shpd-space-sm); }
  .facts__row { display: flex; flex-wrap: wrap; gap: var(--shpd-space-xs) var(--shpd-space-md); align-items: center; }
  .body:not(.dev-split) { display: block; }
  /* Sloupec s PDF musí být vysoký jako celý řádek mřížky (.dev-split má
     align-items: start), jinak sticky PdfPane nemá v čem držet a odroluje. */
  .body.dev-split > div:first-child { align-self: stretch; }
</style>
