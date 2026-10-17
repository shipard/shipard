<script>
  // Seznam úloh na pozadí (F6b D58): posledních 14 dní, filtr sada / zdroj
  // dat, stav, čas a IP klienta, odkaz na detail. Obnovuje se, dokud je
  // nějaká úloha aktivní.
  import { onMount } from 'svelte';
  import Button from '../../../components/ui/Button.svelte';
  import { iconRefresh } from '../../../icons.js';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { jobTypeLabel, jobStatusLabel, jobStatusClass, isActiveStatus } from '../../testing/jobs.js';
  import { t } from '../../i18n/index.js';
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
    { id: 'id', label: t('testing.jobs.col.job'), defaultDir: 'desc' },
    { id: 'type', label: t('testing.jobs.col.type') },
    { id: 'dataset', label: t('common.dataset') },
    { id: 'dataSource', label: t('common.dataSource'), sortValue: (j) => j.dataSource?.name ?? '' },
    { id: 'status', label: t('testing.col.state') },
    { id: 'createdAt', label: t('ds.col.created'), defaultDir: 'desc' },
    { id: 'clientIp', label: t('testing.jobs.col.from') },
    { id: 'result', label: t('testing.jobs.col.result'), sortable: false },
  ];
</script>

<Crumbs items={[{ label: t('app.nav.testing'), href: router.href('testingDatasets') }, { label: t('app.title.jobs') }]} />
<div class="dev-page__head">
  <h1>{t('testing.jobs.title')}</h1>
  <span class="dev-page__spacer"></span>
  <Button label={t('common.refresh')} icon={iconRefresh} variant="secondary" size="sm" onclick={load} />
</div>

<div class="dev-toolbar">
  <input type="search" placeholder={t('testing.jobs.filterDataset')} bind:value={filterDataset} onchange={load} />
  <input type="search" placeholder={t('testing.jobs.filterDs')} bind:value={filterDs} onchange={load} />
  <span class="dev-small dev-muted">{t('testing.jobs.dirNote')}</span>
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !jobs}
  <div class="dev-empty">{t('common.loading')}</div>
{:else}
  <DevTable {columns} rows={jobs} rowKey={(j) => j.id} initialSort={{ id: 'id', dir: 'desc' }} empty={t('testing.jobs.empty')}>
    {#snippet cell(job, col)}
      {#if col.id === 'id'}
        <a href={router.href('testingJob', { jobId: job.id })} onclick={onLinkClick}><code>{job.id}</code></a>
      {:else if col.id === 'type'}
        {jobTypeLabel(job.type)}
      {:else if col.id === 'dataSource'}
        {job.dataSource?.name} <span class="dev-small dev-muted"><code>{job.dataSource?.id}</code></span>
      {:else if col.id === 'status'}
        <span class="dev-lvl dev-lvl--{jobStatusClass(job.status)}">{jobStatusLabel(job.status)}</span>
      {:else if col.id === 'createdAt'}
        <span class="dev-nowrap">{formatDateTime(job.createdAt)}</span>
      {:else if col.id === 'result'}
        {#if job.hasResult}<span class="dev-muted">{t('testing.jobs.resultInDetail')}</span>{:else}<span class="dev-muted">—</span>{/if}
      {:else}
        {job[col.id] ?? '—'}
      {/if}
    {/snippet}
  </DevTable>
{/if}
