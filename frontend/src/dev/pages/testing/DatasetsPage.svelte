<script>
  // Sady z tests/datasets s počtem běhů — vstup do sekce Testování; nad nimi
  // karta testovacího kola (#103 F8a): poslední kolo se souhrnem a spuštění.
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { formatDateTime } from '../../format.js';
  import Crumbs from '../../components/Crumbs.svelte';
  import Button from '../../../components/ui/Button.svelte';
  import RoundRunDialog from './RoundRunDialog.svelte';
  import { roundStatusClass, roundStatusLabel, roundSummaryText, roundSelectionText } from '../../testing/round.js';
  import { jobStatusLabel } from '../../testing/jobs.js';
  import { t } from '../../i18n/index.js';

  let datasets = $state(null);
  let error = $state(null);
  let round = $state(null); // {last, active, options}
  let roundError = $state(null);
  let dialogOpen = $state(false);

  async function loadRound() {
    try {
      round = await testingApi.round();
      roundError = null;
    } catch (e) {
      roundError = e.message;
    }
  }

  $effect(() => {
    let cancelled = false;
    testingApi.datasets()
      .then((d) => { if (!cancelled) datasets = d; })
      .catch((e) => { if (!cancelled) error = e.message; });
    loadRound();
    const timer = setInterval(() => { if (!cancelled && round?.active) loadRound(); }, 5000);
    return () => { cancelled = true; clearInterval(timer); };
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

<div class="dev-card">
  <h2 class="dev-card__title">{t('testing.round.title')} <span class="dev-small dev-muted">{t('testing.round.subtitle')}</span></h2>
  {#if roundError}
    <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{roundError}</span></div>
  {:else if !round}
    <div class="dev-empty">{t('common.loading')}</div>
  {:else}
    {#if round.active}
      <p>
        <span class="dev-lvl dev-lvl--neutral">{t('testing.round.active')}</span>
        <a href={router.href('testingJob', { jobId: round.active.id })} onclick={onLinkClick}><code>{round.active.id}</code></a>
        · {jobStatusLabel(round.active.status)}
      </p>
    {:else if round.last}
      {@const last = round.last}
      <p>
        <strong>{t('testing.round.last')}</strong>
        <a href={router.href('testingJob', { jobId: last.job.id })} onclick={onLinkClick}><code>{last.job.id}</code></a>
        · {formatDateTime(last.job.createdAt)}
        {#if last.result}
          · <span class="dev-lvl dev-lvl--{roundStatusClass(last.result.status)}">{roundStatusLabel(last.result.status)}</span>
          · {roundSummaryText(last.result)}
          {#if last.result.git?.commit} · <code>{last.result.git.commit.slice(0, 8)}</code>{#if last.result.git.dirty} <span class="dev-lvl dev-lvl--warn">{t('testing.round.dirty')}</span>{/if}{/if}
          <div class="dev-small dev-muted">{roundSelectionText(last.result.selection)}</div>
        {:else}
          · {jobStatusLabel(last.job.status)}
        {/if}
      </p>
    {:else}
      <p class="dev-muted">{t('testing.round.none')}</p>
    {/if}
    <div class="dev-btnrow">
      <Button label={t('testing.round.run')} size="sm" disabled={!!round.active} onclick={() => (dialogOpen = true)} />
    </div>
  {/if}
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

{#if dialogOpen && round}
  <RoundRunDialog open={dialogOpen} options={round.options} onClose={() => (dialogOpen = false)} onStarted={(job) => { dialogOpen = false; router.go('testingJob', { jobId: job.id }); }} />
{/if}
