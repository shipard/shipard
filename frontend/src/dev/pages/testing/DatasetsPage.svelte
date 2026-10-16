<script>
  // Sady z tests/datasets s počtem běhů — vstup do sekce Testování.
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { formatDateTime } from '../../format.js';
  import Crumbs from '../../components/Crumbs.svelte';

  let datasets = $state(null);
  let error = $state(null);

  $effect(() => {
    let cancelled = false;
    testingApi.datasets()
      .then((d) => { if (!cancelled) datasets = d; })
      .catch((e) => { if (!cancelled) error = e.message; });
    return () => { cancelled = true; };
  });
</script>

<Crumbs items={[{ label: 'Testování' }]} />
<div class="dev-page__head">
  <h1>Testovací sady</h1>
  <span class="dev-muted dev-small">evaluace AI nad korpusem — čte <code>tests/datasets</code> a <code>tests/eval</code>, nic nespouští</span>
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !datasets}
  <div class="dev-empty">Načítám…</div>
{:else}
  <div class="dev-table-wrap">
    <table class="dev-table">
      <thead>
        <tr><th>Sada</th><th>Titulek</th><th>Země</th><th>Profil</th><th class="dev-num">Zpráv</th><th class="dev-num">Běhů</th><th>Poslední běh</th></tr>
      </thead>
      <tbody>
        {#each datasets as ds (ds.name)}
          <tr>
            <td><a href={router.href('testingRuns', { set: ds.name })} onclick={onLinkClick}><code>{ds.name}</code></a></td>
            <td>{ds.title}</td>
            <td>{ds.country ?? '—'}</td>
            <td>{ds.profile ?? '—'}</td>
            <td class="dev-num">{ds.messages}</td>
            <td class="dev-num">{ds.runs}</td>
            <td class="dev-muted">{ds.lastRunAt ? formatDateTime(ds.lastRunAt) : '—'}</td>
          </tr>
        {:else}
          <tr><td colspan="7" class="dev-empty">Žádná sada v <code>tests/datasets</code>.</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}
