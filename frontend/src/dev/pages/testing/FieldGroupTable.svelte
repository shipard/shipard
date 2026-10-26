<script>
  // Skupina polí projekce: Pole · Očekáváno · Výstup · Stav (+ podobnost
  // u volného textu). V režimu dvou běhů (`fieldsB`) přibude Výstup B
  // a Stav B; řádky se párují podle cesty. Filtr „jen odchylky“ skryje
  // čisté shody i pole, která zdroj nenese (cesta ISDOC, #103 D76).
  import StateBadge from '../../components/StateBadge.svelte';
  import ValueCell from '../../components/ValueCell.svelte';
  import { formatPercent } from '../../format.js';
  import { isBlankState, INFORMATIVE_GROUP_ID } from '../../testing/metrics.js';
  import { t } from '../../i18n/index.js';

  let { group, fields = [], fieldsB = null, onlyDeviations = false } = $props();

  // Informativní skupina (#103 D114): porovnané, ale mimo shodu polí a doklady bez odchylky.
  const informative = $derived(group.id === INFORMATIVE_GROUP_ID);


  const rows = $derived.by(() => {
    const byPathB = new Map((fieldsB ?? []).map((f) => [f.path, f]));
    const out = fields.map((a) => ({ path: a.path, a, b: byPathB.get(a.path) ?? null }));
    const seen = new Set(fields.map((f) => f.path));
    for (const b of fieldsB ?? []) {
      if (!seen.has(b.path)) out.push({ path: b.path, a: null, b });
    }
    if (!onlyDeviations) return out;
    const shown = (f) => f && f.state !== 'match' && f.state !== 'notCarried';
    return out.filter((r) => shown(r.a) || shown(r.b));
  });

  const hidden = $derived(fields.length + (fieldsB?.length ?? 0) - rows.length);
  const compare = $derived(fieldsB !== null);
  const counts = $derived(group.counts ?? {});
</script>

{#if fields.length > 0 || (fieldsB?.length ?? 0) > 0}
  <section class="group">
    <h3 class="group__title">
      {group.label}
      {#if informative}<span class="dev-state dev-state--informative dev-small" title={t('testing.fields.informativeTitle')}>{t('testing.fields.informative')}</span>{/if}
      <span class="group__counts dev-small">

        {#if counts.missing}<span class="dev-state dev-state--missing">{t('testing.fields.countMissing', { n: counts.missing })}</span>{/if}
        {#if counts.extra}<span class="dev-state dev-state--extra">{t('testing.fields.countExtra', { n: counts.extra })}</span>{/if}
        {#if counts.wrong}<span class="dev-state dev-state--wrong">{t('testing.fields.countWrong', { n: counts.wrong })}</span>{/if}
        {#if counts.matchNormalized}<span class="dev-state dev-state--matchNormalized">{t('testing.fields.countNormalized', { n: counts.matchNormalized })}</span>{/if}
        {#if counts.notCarried}<span class="dev-state dev-state--notCarried">{t('testing.fields.countNotCarried', { n: counts.notCarried })}</span>{/if}
      </span>
    </h3>
    {#if rows.length === 0}
      <div class="dev-muted dev-small group__empty">{t('testing.fields.noDeviations', { hidden })}</div>
    {:else}
      <div class="dev-table-wrap">
        <table class="dev-table fields">
          <thead>
            <tr>
              <th>{t('testing.fields.col.field')}</th>
              <th>{t('testing.fields.col.expected')}</th>
              <th>{compare ? t('testing.fields.col.outputA') : t('testing.fields.col.output')}</th>
              <th>{compare ? t('testing.fields.col.stateA') : t('testing.col.state')}</th>
              {#if compare}<th>{t('testing.fields.col.outputB')}</th><th>{t('testing.fields.col.stateB')}</th>{/if}
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
                      {#if isBlankState(side.state)}
                        <span class="dev-muted">—</span>
                      {:else}
                        <ValueCell value={side.actual} />
                        {#if side.state === 'matchNormalized' && !side.actualKnown}
                          <span class="dev-small dev-muted" title={t('testing.fields.shapeNotStoredTitle')}> {t('testing.fields.shapeNotStored')}</span>
                        {/if}
                      {/if}
                    </td>
                    <td class="dev-nowrap">
                      <StateBadge state={side.state} title={side.reason ?? null} />
                      {#if side.similarity !== null && side.similarity !== undefined}
                        <span class="dev-small dev-muted" title={t('testing.fields.similarityTitle')}> {formatPercent(side.similarity, 0)}</span>
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
      {#if hidden > 0}<div class="dev-small dev-muted group__empty">{t('testing.fields.matchesHidden', { hidden })}</div>{/if}
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
