<script>
  // Seznam úloh na pozadí (F6b D58): posledních 14 dní, filtr sada / zdroj
  // dat, stav, čas a IP klienta, odkaz na detail. Obnovuje se, dokud je
  // nějaká úloha aktivní.
  import { onMount } from 'svelte';
  import Button from '../../../components/ui/Button.svelte';
  import { iconRefresh } from '../../../icons.js';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { JOB_TYPE_LABELS, JOB_STATUS_LABELS, jobStatusClass, isActiveStatus } from '../../testing/jobs.js';
  import { formatDateTime } from '../../format.js';
  import Crumbs from '../../components/Crumbs.svelte';
  import DevTable from '../../components/DevTable.svelte';

  let jobs = $state(null);
  let error = $state(null);
  let filterDataset = $state(router.route.query.dataset ?? '');
  let filterDs = $state(router.route.query.ds ?? '');

  async function load() {
    try {
      jobs = (await testingApi.jobs({ dataset: filterDataset || undefined, ds: filterDs || undefined })).jobs;
      error = null;
    } catch (e) {
      error = e.message;
    }
  }

  onMount(() => {
    load();
    const timer = setInterval(() => {
      if ((jobs ?? []).some((j) => isActiveStatus(j.status))) load();
    }, 5000);
    return () => clearInterval(timer);
  });

  const columns = [
    { id: 'id', label: 'Úloha', defaultDir: 'desc' },
    { id: 'type', label: 'Typ' },
    { id: 'dataset', label: 'Sada' },
    { id: 'dataSource', label: 'Zdroj dat', sortValue: (j) => j.dataSource?.name ?? '' },
    { id: 'status', label: 'Stav' },
    { id: 'createdAt', label: 'Vytvořeno', defaultDir: 'desc' },
    { id: 'clientIp', label: 'Odkud' },
    { id: 'result', label: 'Výsledek', sortable: false },
  ];
</script>

<Crumbs items={[{ label: 'Testování', href: router.href('testingDatasets') }, { label: 'Úlohy' }]} />
<div class="dev-page__head">
  <h1>Úlohy na pozadí</h1>
  <span class="dev-page__spacer"></span>
  <Button label="Obnovit" icon={iconRefresh} variant="secondary" size="sm" onclick={load} />
</div>

<div class="dev-toolbar">
  <input type="search" placeholder="sada" bind:value={filterDataset} onchange={load} />
  <input type="search" placeholder="id zdroje dat" bind:value={filterDs} onchange={load} />
  <span class="dev-small dev-muted">Adresář úlohy: <code>/opt/shipard/run/dev-jobs/&lt;id&gt;/</code>; starší než 14 dní se mažou.</span>
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !jobs}
  <div class="dev-empty">Načítám…</div>
{:else}
  <DevTable {columns} rows={jobs} rowKey={(j) => j.id} initialSort={{ id: 'id', dir: 'desc' }} empty="Žádná úloha.">
    {#snippet cell(job, col)}
      {#if col.id === 'id'}
        <a href={router.href('testingJob', { jobId: job.id })} onclick={onLinkClick}><code>{job.id}</code></a>
      {:else if col.id === 'type'}
        {JOB_TYPE_LABELS[job.type] ?? job.type}
      {:else if col.id === 'dataSource'}
        {job.dataSource?.name} <span class="dev-small dev-muted"><code>{job.dataSource?.id}</code></span>
      {:else if col.id === 'status'}
        <span class="dev-lvl dev-lvl--{jobStatusClass(job.status)}">{JOB_STATUS_LABELS[job.status] ?? job.status}</span>
      {:else if col.id === 'createdAt'}
        <span class="dev-nowrap">{formatDateTime(job.createdAt)}</span>
      {:else if col.id === 'result'}
        {#if job.hasResult}<span class="dev-muted">výsledek v detailu</span>{:else}<span class="dev-muted">—</span>{/if}
      {:else}
        {job[col.id] ?? '—'}
      {/if}
    {/snippet}
  </DevTable>
{/if}
