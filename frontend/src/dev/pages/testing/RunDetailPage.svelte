<script>
  // Detail běhu: metriky po skupinách s rozpětím opakování, doklady,
  // rozpad po variantách a podobách, nejčastější odchylky, stabilita
  // a seznam dokladů s odchylkami per opakování.
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import {
    fieldMetricRows, metricRows, topDeviations, unstableNodes, rangeText, formatMetric, deviationLevel, sumOf,
    DOCUMENT_METRICS, COST_METRICS,
  } from '../../testing/metrics.js';
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
  const fieldStability = $derived(unstableNodes(run?.fieldStability));
  const formatStability = $derived(unstableNodes(run?.formatStability));
  const repeatCount = $derived(data?.summary?.repeat ?? 1);
  const repeatIds = $derived(Array.from({ length: repeatCount }, (_, i) => i + 1));

  const messageColumns = $derived([
    { id: 'messageId', label: 'Doklad' },
    { id: 'variant', label: 'Varianta' },
    { id: 'form', label: 'Podoba' },
    ...repeatIds.map((n) => ({ id: `r${n}`, label: `×${n}`, align: 'right', sortValue: (m) => m.repeats.find((r) => r.repeat === n)?.deviations ?? null })),
    { id: 'total', label: 'Odchylek Σ', align: 'right', sortValue: (m) => sumOf(m.repeats.map((r) => r.deviations)), defaultDir: 'desc' },
    { id: 'failed', label: 'Selhání', align: 'right', sortValue: (m) => m.repeats.filter((r) => r.status !== 'ok').length },
    { id: 'cost', label: 'Cena Σ', align: 'right', sortValue: (m) => sumOf(m.repeats.map((r) => callCost(r))) },
    { id: 'across', label: '', sortable: false },
  ]);

  function cellOf(byVariant, key) {
    return byVariant?.[key] ?? {};
  }

  /** Cena volání = extrakce + LLM štítky (F6b D56); null bez obojího. */
  function callCost(r) {
    if ((r.costUsd === null || r.costUsd === undefined) && (r.tagsCostUsd === null || r.tagsCostUsd === undefined)) return null;
    return (r.costUsd ?? 0) + (r.tagsCostUsd ?? 0);
  }
</script>

<Crumbs items={[{ label: 'Testování', href: router.href('testingDatasets') }, { label: set, href: router.href('testingRuns', { set }) }, { label: file }]} />

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !data}
  <div class="dev-empty">Načítám…</div>
{:else}
  <div class="dev-page__head">
    <h1>Běh <code>{file}</code></h1>
  </div>
  <div class="dev-card">
    <RunSummaryLine summary={data.summary} showFile={false} />
    <div class="dev-small dev-muted meta">
      {data.summary.messages} zpráv × {data.summary.repeat} = {data.summary.callsTotal} volání ·
      připojení {data.summary.connection ?? '—'} ({data.summary.platform ?? '—'}) ·
      dokončeno {formatDateTime(run.finishedAt)} ·
      cena {formatUsd(data.summary.costTotalUsd ?? data.summary.costUsd)}{#if data.summary.tagsUsd !== null && data.summary.tagsUsd !== undefined} (extrakce {formatUsd(data.summary.costUsd)} + štítky {formatUsd(data.summary.tagsUsd)}){/if}
      {#if run.stoppedBy}· <span class="dev-state dev-state--missing">{run.stoppedBy === 'maxCost' ? 'zastaveno stropem útraty' : 'zrušeno'} — {data.summary.callsTotal} z {run.plannedCalls ?? '?'} volání</span>{/if}
    </div>
    {#if !data.summary.currentSet}
      <div class="dev-alert dev-alert--warning"><span class="dev-alert__text">Běh vznikl nad starší verzí sady (hash <code>{String(data.summary.setHash).slice(0, 12)}</code>). Očekávání v detailu dokladu jsou z aktuální sady a mohou se lišit od těch, proti kterým běh vznikl.</span></div>
    {/if}
  </div>

  <div class="grid-2">
    <div class="dev-card">
      <div class="dev-card__title">Shoda polí po skupinách {repeatCount > 1 ? '(průměr a rozpětí opakování)' : ''}</div>
      <div class="dev-table-wrap">
        <table class="dev-table">
          <thead><tr><th>Skupina</th><th class="dev-num">Polí</th><th class="dev-num">Shoda</th><th class="dev-num">Po norm.</th><th class="dev-num">Chybí</th><th class="dev-num">Navíc</th><th class="dev-num">Špatně</th></tr></thead>
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
      <p class="dev-small dev-muted note">Počty chybí / navíc / špatně jsou součty přes všechna opakování.</p>
    </div>

    <div>
      <div class="dev-card">
        <div class="dev-card__title">Doklady</div>
        <table class="plain">
          <tbody>
            {#each docRows as r (r.key)}
              <tr><td>{r.label}</td><td class="dev-num dev-nowrap">{rangeText(r.value, r.min, r.max)}</td></tr>
            {/each}
          </tbody>
        </table>
      </div>
      <div class="dev-card">
        <div class="dev-card__title">Volání, cena a čas</div>
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

  <div class="grid-2">
    {#each [['Varianty korpusu', run.byVariant], ['Podoby dokladu', run.byForm]] as [title, cells] (title)}
      <div class="dev-card">
        <div class="dev-card__title">{title}</div>
        <div class="dev-table-wrap">
          <table class="dev-table">
            <thead><tr><th>{title === 'Varianty korpusu' ? 'Varianta' : 'Podoba'}</th><th class="dev-num">Volání</th><th class="dev-num">Shoda</th><th class="dev-num">Bez odchylky</th><th class="dev-num">Částky</th><th class="dev-num">Řádky</th><th class="dev-num">Cena</th></tr></thead>
            <tbody>
              {#each Object.keys(cells ?? {}) as key (key)}
                {@const c = cellOf(cells, key)}
                <tr>
                  <td>{key}</td>
                  <td class="dev-num dev-nowrap">{c['calls.ok'] ?? 0} / {c['calls.total'] ?? 0}</td>
                  <td class="dev-num dev-nowrap">{formatPercent(c['fields.all.hit'])}</td>
                  <td class="dev-num dev-nowrap">{formatPercent(c['documents.clean'])}</td>
                  <td class="dev-num dev-nowrap">{formatPercent(c['documents.amountsCorrect'])}</td>
                  <td class="dev-num dev-nowrap">{formatPercent(c['documents.rowCountOk'])}</td>
                  <td class="dev-num dev-nowrap">{formatUsd(c['cost.usd'])}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      </div>
    {/each}
  </div>

  <div class="grid-3">
    <div class="dev-card">
      <div class="dev-card__title">Nejčastější odchylky</div>
      {#if deviations.length === 0}
        <div class="dev-muted dev-small">Žádné.</div>
      {:else}
        <table class="plain">
          <tbody>
            {#each deviations as d (d.node)}
              <tr><td><code>{d.node}</code></td><td class="dev-num dev-nowrap">{d.count}<span class="dev-muted"> ({formatPercent(d.share, 0)})</span></td></tr>
            {/each}
          </tbody>
        </table>
        <p class="dev-small dev-muted note">Počet úspěšných volání s odchylkou na uzlu a podíl z nich.</p>
      {/if}
    </div>
    <div class="dev-card">
      <div class="dev-card__title">Věcná stabilita</div>
      {#if fieldStability === null}
        <div class="dev-muted dev-small">Jedno opakování — stabilita se neměří.</div>
      {:else if fieldStability.length === 0}
        <div class="dev-muted dev-small">Každé pole dopadlo ve všech opakováních stejně.</div>
      {:else}
        <table class="plain">
          <tbody>
            {#each fieldStability as s (s.node)}
              <tr><td><code>{s.node}</code></td><td class="dev-num">{formatPercent(s.ratio, 0)}</td></tr>
            {/each}
          </tbody>
        </table>
        <p class="dev-small dev-muted note">Podíl dokladů, kde pole dopadlo (shoda / neshoda) ve všech opakováních stejně.</p>
      {/if}
    </div>
    <div class="dev-card">
      <div class="dev-card__title">Formátová stabilita</div>
      {#if formatStability === null}
        <div class="dev-muted dev-small">Jedno opakování nebo starší běh — neměří se.</div>
      {:else if formatStability.length === 0}
        <div class="dev-muted dev-small">Shodné hodnoty měly ve všech opakováních stejný tvar.</div>
      {:else}
        <table class="plain">
          <tbody>
            {#each formatStability as s (s.node)}
              <tr><td><code>{s.node}</code></td><td class="dev-num">{formatPercent(s.ratio, 0)}</td></tr>
            {/each}
          </tbody>
        </table>
        <p class="dev-small dev-muted note">Jen u polí shodných ve všech opakováních: podíl se stejným tvarem (mezery v IBAN, PSČ…).</p>
      {/if}
    </div>
  </div>

  <div class="dev-card">
    <div class="dev-card__title">Doklady ({data.messages.length})</div>
    <DevTable
      columns={messageColumns}
      rows={data.messages}
      rowKey={(m) => m.messageId}
      empty="Běh nemá žádná volání."
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
            <a class="dev-lvl dev-lvl--failed" href={router.href('testingMessage', { set, file, messageId: m.messageId }, { repeat: rep.repeat })} onclick={onLinkClick} title={rep.errorType ?? ''}>selhalo</a>
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
        {:else if col.id === 'across'}
          <a class="dev-small" href={router.href('testingMessageRuns', { set, messageId: m.messageId })} onclick={onLinkClick}>napříč běhy</a>
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
