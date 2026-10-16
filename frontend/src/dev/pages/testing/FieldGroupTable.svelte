<script>
  // Skupina polí projekce: Pole · Očekáváno · Výstup · Stav (+ podobnost
  // u volného textu). V režimu dvou běhů (`fieldsB`) přibude Výstup B
  // a Stav B; řádky se párují podle cesty. Filtr „jen odchylky“ skryje
  // čisté shody.
  import StateBadge from '../../components/StateBadge.svelte';
  import ValueCell from '../../components/ValueCell.svelte';
  import { formatPercent } from '../../format.js';

  let { group, fields = [], fieldsB = null, onlyDeviations = false } = $props();

  const rows = $derived.by(() => {
    const byPathB = new Map((fieldsB ?? []).map((f) => [f.path, f]));
    const out = fields.map((a) => ({ path: a.path, a, b: byPathB.get(a.path) ?? null }));
    const seen = new Set(fields.map((f) => f.path));
    for (const b of fieldsB ?? []) {
      if (!seen.has(b.path)) out.push({ path: b.path, a: null, b });
    }
    if (!onlyDeviations) return out;
    return out.filter((r) => (r.a && r.a.state !== 'match') || (r.b && r.b.state !== 'match'));
  });

  const hidden = $derived(fields.length + (fieldsB?.length ?? 0) - rows.length);
  const compare = $derived(fieldsB !== null);
  const counts = $derived(group.counts ?? {});
</script>

{#if fields.length > 0 || (fieldsB?.length ?? 0) > 0}
  <section class="group">
    <h3 class="group__title">
      {group.label}
      <span class="group__counts dev-small">
        {#if counts.missing}<span class="dev-state dev-state--missing">{counts.missing} chybí</span>{/if}
        {#if counts.extra}<span class="dev-state dev-state--extra">{counts.extra} navíc</span>{/if}
        {#if counts.wrong}<span class="dev-state dev-state--wrong">{counts.wrong} špatně</span>{/if}
        {#if counts.matchNormalized}<span class="dev-state dev-state--matchNormalized">{counts.matchNormalized} po norm.</span>{/if}
      </span>
    </h3>
    {#if rows.length === 0}
      <div class="dev-muted dev-small group__empty">Bez odchylek ({hidden} shod skryto).</div>
    {:else}
      <div class="dev-table-wrap">
        <table class="dev-table fields">
          <thead>
            <tr>
              <th>Pole</th>
              <th>Očekáváno</th>
              <th>{compare ? 'Výstup A' : 'Výstup'}</th>
              <th>{compare ? 'Stav A' : 'Stav'}</th>
              {#if compare}<th>Výstup B</th><th>Stav B</th>{/if}
            </tr>
          </thead>
          <tbody>
            {#each rows as row (row.path)}
              {@const primary = row.a ?? row.b}
              <tr class="dev-row--{primary.state}">
                <td><code>{row.path}</code></td>
                <td class="dev-cell-expected"><ValueCell value={primary.expected} /></td>
                {#each (compare ? [row.a, row.b] : [row.a]) as side, i (i)}
                  {#if side}
                    <td class="dev-cell-actual dev-cellstate--{side.state}">
                      {#if side.state === 'notCompared'}
                        <span class="dev-muted">—</span>
                      {:else}
                        <ValueCell value={side.actual} />
                        {#if side.state === 'matchNormalized' && !side.actualKnown}
                          <span class="dev-small dev-muted" title="Starší běh ukládal u shody po normalizaci jen cestu — ukazuje se očekávání"> (tvar výstupu neuložen)</span>
                        {/if}
                      {/if}
                    </td>
                    <td class="dev-nowrap">
                      <StateBadge state={side.state} />
                      {#if side.similarity !== null && side.similarity !== undefined}
                        <span class="dev-small dev-muted" title="podobnost volného textu"> {formatPercent(side.similarity, 0)}</span>
                      {/if}
                    </td>
                  {:else}
                    <td class="dev-muted">—</td><td class="dev-muted">—</td>
                  {/if}
                {/each}
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
      {#if hidden > 0}<div class="dev-small dev-muted group__empty">{hidden} shod skryto.</div>{/if}
    {/if}
  </section>
{/if}

<style>
  .group { margin-bottom: var(--shpd-space-lg); }
  .group__title { font-size: var(--shpd-font-size-base); font-weight: 600; margin-bottom: var(--shpd-space-xs); display: flex; gap: var(--shpd-space-sm); align-items: center; flex-wrap: wrap; }
  .group__counts { display: inline-flex; gap: 4px; }
  .group__empty { padding: 2px 0 0; }
  .fields td:first-child { white-space: nowrap; }
</style>
