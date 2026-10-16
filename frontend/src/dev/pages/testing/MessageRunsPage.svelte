<script>
  // Jeden doklad napříč běhy sady: matice běh × opakování s počtem
  // odchylek, klik vede do detailu dokladu v tom běhu a opakování.
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { deviationLevel, maxRepeat } from '../../testing/metrics.js';
  import { formatDateTime } from '../../format.js';
  import Crumbs from '../../components/Crumbs.svelte';

  const route = $derived(router.route);
  const set = $derived(route.params.set);
  const messageId = $derived(route.params.messageId);

  let data = $state(null);
  let error = $state(null);

  $effect(() => {
    const args = [set, messageId];
    let cancelled = false;
    data = null;
    error = null;
    testingApi.messageRuns(...args)
      .then((d) => { if (!cancelled) data = d; })
      .catch((e) => { if (!cancelled) error = e.message; });
    return () => { cancelled = true; };
  });

  const columns = $derived(Array.from({ length: maxRepeat(data?.runs ?? []) }, (_, i) => i + 1));
</script>

<Crumbs items={[{ label: 'Testování', href: router.href('testingDatasets') }, { label: set, href: router.href('testingRuns', { set }) }, { label: messageId }]} />
<div class="dev-page__head">
  <h1>Doklad <code>{messageId}</code> napříč běhy</h1>
  {#if data}<span class="dev-small dev-muted">{data.message.stem ?? ''} · varianta {data.message.variant ?? '—'} · podoba {data.message.form ?? '—'}</span>{/if}
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !data}
  <div class="dev-empty">Načítám…</div>
{:else}
  <p class="dev-small dev-muted note">Číslo = počet odchylek volání (0 zeleně, 1–3 oranžově, víc červeně), „selhalo“ = volání bez výstupu. Běhy šedě jsou nad starší verzí sady.</p>
  <div class="dev-table-wrap">
    <table class="dev-table">
      <thead>
        <tr>
          <th>Běh</th><th>Varianta</th><th>Model</th><th>Štítky</th><th>Datum</th>
          {#each columns as n (n)}<th class="dev-num">×{n}</th>{/each}
        </tr>
      </thead>
      <tbody>
        {#each data.runs as run (run.file)}
          <tr class:is-dim={!run.currentSet}>
            <td><a href={router.href('testingRun', { set, file: run.file })} onclick={onLinkClick}><code>{run.file}</code></a>{#if !run.currentSet} <span class="dev-state dev-state--missing">jiná verze sady</span>{/if}</td>
            <td>{run.variant}</td>
            <td><code>{run.model ?? '—'}</code></td>
            <td>{run.tagsModel && run.tagsModel !== run.model ? run.tagsModel : '—'}</td>
            <td class="dev-nowrap dev-muted">{formatDateTime(run.startedAt)}</td>
            {#each columns as n (n)}
              {@const rep = run.repeats.find((r) => r.repeat === n)}
              <td class="dev-num">
                {#if !rep}
                  <span class="dev-muted">—</span>
                {:else if rep.status !== 'ok'}
                  <a class="dev-lvl dev-lvl--failed" href={router.href('testingMessage', { set, file: run.file, messageId }, { repeat: n === 1 ? '' : n })} onclick={onLinkClick} title={rep.errorType ?? ''}>selhalo</a>
                {:else}
                  <a class="dev-lvl dev-lvl--{deviationLevel(rep.deviations)}" href={router.href('testingMessage', { set, file: run.file, messageId }, { repeat: n === 1 ? '' : n })} onclick={onLinkClick}>{rep.deviations}</a>
                {/if}
              </td>
            {/each}
          </tr>
        {:else}
          <tr><td colspan={5 + columns.length} class="dev-empty">Sada nemá žádný běh.</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}

<style>
  .note { margin: 0 0 var(--shpd-space-sm); }
</style>
