<script>
  // Tabulka pole projekce (řádky, rekapitulace, výjimky štítků): řádek =
  // prvek, sloupec = list prvku; buňka nese očekávání a výstup a barvu
  // podle stavu. Záznam na celém prvku (`node` — výjimka navíc / chybí)
  // je jeden řádek přes všechny sloupce. V režimu dvou běhů se v buňce
  // ukáže i výstup B.
  import StateBadge from '../../components/StateBadge.svelte';
  import ValueCell from '../../components/ValueCell.svelte';
  import { stateLabel, isBlankState } from '../../testing/metrics.js';
  import { t } from '../../i18n/index.js';

  let { table, tableB = null, onlyDeviations = false, label = null } = $props();

  const columns = $derived.by(() => {
    const cols = [...table.columns];
    for (const c of tableB?.columns ?? []) if (!cols.includes(c)) cols.push(c);
    return cols;
  });

  const rows = $derived.by(() => {
    const byKeyB = new Map((tableB?.rows ?? []).map((r) => [r.key, r]));
    const out = table.rows.map((a) => ({ key: a.key, a, b: byKeyB.get(a.key) ?? null }));
    const seen = new Set(table.rows.map((r) => r.key));
    for (const b of tableB?.rows ?? []) if (!seen.has(b.key)) out.push({ key: b.key, a: null, b });
    out.sort((x, y) => x.key - y.key);
    if (!onlyDeviations) return out;
    const shown = (c) => c.state !== 'match' && c.state !== 'notCarried';
    const dev = (r) => (r?.node && shown(r.node)) || Object.values(r?.cells ?? {}).some(shown);
    return out.filter((r) => dev(r.a) || dev(r.b));
  });

  const hidden = $derived(Math.max(table.rows.length, tableB?.rows.length ?? 0) - rows.length);
  const compare = $derived(tableB !== null);
  const keyLabel = $derived(table.path.endsWith('rowExceptions') ? 'rowIndex' : '#');

  function worst(a, b) {
    const order = ['wrong', 'missing', 'extra', 'matchNormalized', 'notCompared', 'notCarried', 'match'];
    const states = [a?.state, b?.state].filter(Boolean);
    return order.find((s) => states.includes(s)) ?? 'match';
  }
</script>

<section class="group">
  <h3 class="group__title">{label ?? table.label} <span class="dev-muted dev-small">({table.rows.length}{compare && tableB.rows.length !== table.rows.length ? ` / B ${tableB.rows.length}` : ''})</span></h3>
  {#if table.rows.length === 0 && (tableB?.rows.length ?? 0) === 0}
    <div class="dev-muted dev-small">{t('testing.rows.empty')}</div>
  {:else if rows.length === 0}
    <div class="dev-muted dev-small">{t('testing.rows.noDeviations', { hidden })}</div>
  {:else}
    <div class="dev-table-wrap">
      <table class="dev-table rows">
        <thead>
          <tr>
            <th>{keyLabel}</th>
            {#each columns as c (c)}<th>{c}</th>{/each}
          </tr>
        </thead>
        <tbody>
          {#each rows as row (row.key)}
            {@const nodeA = row.a?.node}
            {@const nodeB = row.b?.node}
            {#if (nodeA || nodeB) && !(row.a?.cells && Object.keys(row.a.cells).length)}
              <tr class="dev-row--{worst(nodeA, nodeB)}">
                <td>{row.key}</td>
                <td colspan={columns.length}>
                  {#each (compare ? [['A', nodeA], ['B', nodeB]] : [['', nodeA]]) as [side, node] (side)}
                    {#if node}
                      <div class="node">
                        {#if side}<span class="dev-muted">{side}:</span>{/if}
                        <StateBadge state={node.state} />
                        <span class="dev-cell-expected">{t('testing.rows.expectedShort')} <ValueCell value={node.expected} /></span>
                        <span class="dev-cell-actual">{t('testing.rows.output')} <ValueCell value={node.actual} /></span>
                      </div>
                    {:else if side}
                      <div class="node"><span class="dev-muted">{side}: —</span></div>
                    {/if}
                  {/each}
                </td>
              </tr>
            {:else}
              <tr>
                <td>{row.key}</td>
                {#each columns as c (c)}
                  {@const ca = row.a?.cells?.[c]}
                  {@const cb = row.b?.cells?.[c]}
                  {@const primary = ca ?? cb}
                  <td class="dev-cellstate--{worst(ca, cb)}" title={primary ? `${primary.path}: ${primary.state}${primary.reason ? ` — ${primary.reason}` : ''}` : ''}>
                    {#if !primary}
                      <span class="dev-muted">—</span>
                    {:else}
                      <div class="cell">
                        <span class="dev-cell-expected"><ValueCell value={primary.expected} /></span>
                        {#if ca && ca.state !== 'match'}
                          <span class="dev-cell-actual">{compare ? 'A: ' : ''}{#if isBlankState(ca.state)}<span class="dev-muted">{stateLabel(ca.state)}</span>{:else}<ValueCell value={ca.actual} />{/if}</span>
                        {/if}
                        {#if cb && cb.state !== 'match'}
                          <span class="dev-cell-actual">B: {#if isBlankState(cb.state)}<span class="dev-muted">{stateLabel(cb.state)}</span>{:else}<ValueCell value={cb.actual} />{/if}</span>
                        {/if}
                      </div>
                    {/if}
                  </td>
                {/each}
              </tr>
            {/if}
          {/each}
        </tbody>
      </table>
    </div>
    {#if hidden > 0}<div class="dev-small dev-muted">{t('testing.rows.rowsHidden', { hidden })}</div>{/if}
  {/if}
</section>

<style>
  .group { margin-bottom: var(--shpd-space-lg); }
  .group__title { font-size: var(--shpd-font-size-base); font-weight: 600; margin-bottom: var(--shpd-space-xs); }
  .rows th, .rows td { font-size: 0.8rem; }
  .cell { display: flex; flex-direction: column; gap: 0.125rem; min-width: 60px; }
  .node { display: flex; gap: var(--shpd-space-sm); align-items: baseline; flex-wrap: wrap; }
</style>
