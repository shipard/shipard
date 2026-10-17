<script>
  // Sady z tests/datasets s počtem běhů — vstup do sekce Testování.
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { formatDateTime } from '../../format.js';
  import Crumbs from '../../components/Crumbs.svelte';
  import { t } from '../../i18n/index.js';

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

<Crumbs items={[{ label: t('app.nav.testing') }]} />
<div class="dev-page__head">
  <h1>{t('testing.datasets.title')}</h1>
  <span class="dev-muted dev-small">{t('testing.datasets.subtitle')}</span>
  <span class="dev-page__spacer"></span>
  <a href={router.href('testingJobs')} onclick={onLinkClick}>{t('testing.jobs.title')}</a>
  <a href={router.href('testingVariants')} onclick={onLinkClick}>{t('app.title.variants')}</a>
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !datasets}
  <div class="dev-empty">{t('common.loading')}</div>
{:else}
  <div class="dev-table-wrap">
    <table class="dev-table">
      <thead>
        <tr><th>{t('common.dataset')}</th><th>{t('testing.datasets.col.title')}</th><th>{t('dsCreate.countryLabel')}</th><th>{t('testing.col.profile')}</th><th class="dev-num">{t('testing.datasets.col.messages')}</th><th class="dev-num">{t('testing.datasets.col.runs')}</th><th>{t('testing.datasets.col.lastRun')}</th></tr>
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
          <tr><td colspan="7" class="dev-empty">{t('dsLoad.noDatasets')}.</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}
