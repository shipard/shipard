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
      error = 'Chybí parametry a a b (soubory běhů). Vyber dva běhy v seznamu běhů sady.';
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
    { id: 'messageId', label: 'Doklad' },
    { id: 'aDev', label: 'Odchylek A', align: 'right', sortValue: (m) => m.a.deviations },
    { id: 'bDev', label: 'Odchylek B', align: 'right', sortValue: (m) => m.b.deviations },
    { id: 'delta', label: 'Rozdíl', align: 'right', sortValue: (m) => (m.delta === null ? null : Math.abs(m.delta)), defaultDir: 'desc' },
    { id: 'failed', label: 'Selhání A / B', align: 'right', sortValue: (m) => m.a.failed + m.b.failed },
    { id: 'open', label: '', sortable: false },
  ];
</script>

<Crumbs items={[{ label: 'Testování', href: router.href('testingDatasets') }, { label: set, href: router.href('testingRuns', { set }) }, { label: 'Porovnání' }]} />
<div class="dev-page__head">
  <h1>Porovnání běhů</h1>
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !data}
  <div class="dev-empty">Načítám…</div>
{:else}
  <div class="dev-card">
    <div class="runline"><strong>A</strong> <a href={router.href('testingRun', { set, file: a })} onclick={onLinkClick}><code>{a}</code></a> <RunSummaryLine summary={data.summaries.a} showFile={false} /></div>
    <div class="runline"><strong>B</strong> <a href={router.href('testingRun', { set, file: b })} onclick={onLinkClick}><code>{b}</code></a> <RunSummaryLine summary={data.summaries.b} showFile={false} /></div>
    {#if !data.sameSelection}
      <div class="dev-alert dev-alert--warning"><span class="dev-alert__text">Běhy mají jiný výběr zpráv (<code>--only</code> / <code>--limit</code>) — porovnávej s rezervou.</span></div>
    {/if}
    {#if Math.max(data.summaries.a.repeat, data.summaries.b.repeat) > 1}
      <p class="dev-small dev-muted note">Součty (volání, tokeny, cena, čas, počty polí) jsou přepočtené na jedno opakování. „V rámci šumu“ = rozdíl nepřesahuje největší rozpětí opakování některého z běhů.</p>
    {/if}
  </div>

  <div class="dev-card">
    <div class="dev-card__title">Metriky
      <label class="dev-chip" class:is-on={allMetrics}><input type="checkbox" bind:checked={allMetrics} /> všechny ({data.metrics.length})</label>
    </div>
    <div class="dev-table-wrap">
      <table class="dev-table">
        <thead><tr><th>Metrika</th><th class="dev-num">A</th><th class="dev-num">B</th><th class="dev-num">Rozdíl</th><th class="dev-num">Šum</th><th>Hodnocení</th></tr></thead>
        <tbody>
          {#each metricRows as m (m.key)}
            <tr>
              <td title={m.key}>{metricLabel(m.key)}{#if m.perRepetition}<span class="dev-muted dev-small"> /opak.</span>{/if}</td>
              <td class="dev-num">{formatMetric(m.key, m.a)}</td>
              <td class="dev-num">{formatMetric(m.key, m.b)}</td>
              <td class="dev-num">{formatMetricDelta(m.key, m.delta)}</td>
              <td class="dev-num dev-muted">{m.noise === null ? '—' : formatMetricDelta(m.key, m.noise).replace(/^[+−±]/, '')}</td>
              <td>
                {#if m.delta === null || Math.abs(m.delta) < 1e-9}
                  <span class="dev-muted">beze změny</span>
                {:else if m.withinNoise}
                  <span class="dev-state dev-state--neutral">v rámci šumu</span>
                {:else if m.better}
                  <span class="dev-state dev-state--match">lepší</span>
                {:else}
                  <span class="dev-state dev-state--wrong">horší</span>
                {/if}
              </td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid-2">
    {#each [['Pole zlepšená', data.fields.improved, 'match'], ['Pole zhoršená', data.fields.worsened, 'wrong']] as [title, items, style] (title)}
      <div class="dev-card">
        <div class="dev-card__title">{title} <span class="dev-muted dev-small">(volání s odchylkou na opakování A → B, změna pod {data.fields.threshold} se nepočítá)</span></div>
        {#if items.length === 0}
          <div class="dev-muted dev-small">Žádné.</div>
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
    <div class="dev-card__title">Doklady
      <label class="dev-chip" class:is-on={onlyDifferent}><input type="checkbox" bind:checked={onlyDifferent} /> jen rozdílné</label>
      <span class="dev-muted dev-small">odchylky = průměr na úspěšné volání</span>
    </div>
    <DevTable columns={messageColumns} rows={messages} rowKey={(m) => m.messageId} empty="Žádný doklad s rozdílem.">
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
          <a class="dev-small" href={router.href('testingMessage', { set, file: a, messageId: m.messageId }, { b })} onclick={onLinkClick}>oba běhy vedle sebe</a>
        {/if}
      {/snippet}
    </DevTable>
  </div>
{/if}

<style>
  .runline { display: flex; gap: var(--shpd-space-sm); align-items: baseline; flex-wrap: wrap; margin-bottom: 4px; }
  .note { margin: var(--shpd-space-sm) 0 0; }
  .dev-card__title { display: flex; gap: var(--shpd-space-md); align-items: center; flex-wrap: wrap; }
  .dev-chip input { margin: 0; }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--shpd-space-lg); margin-top: var(--shpd-space-lg); align-items: start; }
  .grid-2 > .dev-card { margin-top: 0; }
  .grid-2 + .dev-card { margin-top: var(--shpd-space-lg); }
  table.plain { width: 100%; border-collapse: collapse; font-size: var(--shpd-font-size-sm); }
  table.plain td { padding: 3px 0; border-bottom: 1px solid var(--shpd-color-border-subtle); }
  table.plain tr:last-child td { border-bottom: 0; }
</style>
