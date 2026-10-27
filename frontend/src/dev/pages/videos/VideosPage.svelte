<script>
  // Oddíl Videa (#103 F7b D122, D126, D127): scénáře z demo/scenarios/ se
  // stavem posledního ověření a natočení, akce Ověřit (`check`) a Natočit
  // (`build`) jako úloha `video` nad vyhrazeným zdrojem dat sady (účel
  // „videa — <sada>“; chybí-li, tlačítko ho založí úlohou `create`),
  // přehrávač hotového videa (server posílá s HTTP Range) a stažení.
  // Pojistku (jen development, jen free) dělá server; chybějící nástroje
  // přijdou jako `toolProblems` a akce jsou neaktivní s nápovědou.
  import { onMount } from 'svelte';
  import Button from '../../../components/ui/Button.svelte';
  import Icon from '../../../components/ui/Icon.svelte';
  import DevConfirm from '../../components/DevConfirm.svelte';
  import Crumbs from '../../components/Crumbs.svelte';
  import { iconRefresh, iconAdd, iconPlay, iconDownload } from '../../../icons.js';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { videosApi } from '../../videos/api.js';
  import { ApiError } from '../../api.js';
  import { jobTypeLabel, jobStatusLabel } from '../../testing/jobs.js';
  import { t } from '../../i18n/index.js';
  import { formatDateTime } from '../../format.js';
  import { blockerText, checkState, datasetsOf, formatBytes, formatDuration } from '../../videos/videos.js';

  let data = $state(null);
  let error = $state(null);
  let busy = $state(false);
  let notice = $state(null); // {kind: 'danger'|'info', text, jobId?}
  let confirm = $state(null); // {title, message, variant, confirmLabel, run: () => Promise}
  let playing = $state(null); // id scénáře s otevřeným přehrávačem

  const hasActiveJob = $derived((data?.scenarios ?? []).some((r) => r.activeJob));
  const datasets = $derived(datasetsOf(data?.scenarios ?? []));

  async function load() {
    try {
      data = await videosApi.list();
      error = null;
    } catch (e) {
      error = e.message;
    }
  }

  onMount(() => {
    load();
    const timer = setInterval(() => {
      if (hasActiveJob) load();
    }, 5000);
    return () => clearInterval(timer);
  });

  function failed(e) {
    if (e instanceof ApiError) {
      notice = { kind: 'danger', text: `${e.message} (${e.code})`, jobId: e.details?.jobId ?? null };
    } else {
      notice = { kind: 'danger', text: t('common.networkError', { message: e.message }) };
    }
  }

  async function launch(action) {
    busy = true;
    notice = null;
    try {
      const { job } = await action();
      router.go('testingJob', { jobId: job.id });
    } catch (e) {
      failed(e);
    } finally {
      busy = false;
      confirm = null;
    }
  }

  function askRun(row, verb) {
    const build = verb === 'build';
    confirm = {
      title: t(build ? 'videos.buildTitle' : 'videos.checkTitle'),
      message: t(build ? 'videos.buildMessage' : 'videos.checkMessage', { id: row.id, set: row.dataset, ds: row.dataSource?.name ?? '' }),
      variant: 'primary',
      confirmLabel: t(build ? 'videos.build' : 'videos.check'),
      run: () => launch(() => videosApi.run(row.id, verb)),
    };
  }

  function askCreate(ds) {
    confirm = {
      title: t('videos.dsCreateTitle'),
      message: t('videos.dsCreateMessage', { title: ds.title ?? ds.name, set: ds.name }),
      variant: 'primary',
      confirmLabel: t('videos.dsCreate'),
      run: () => launch(() => videosApi.createDataSource(ds.name)),
    };
  }
</script>

<Crumbs items={[{ label: t('app.nav.videos') }]} />

<div class="dev-page__head">
  <h1>{t('app.nav.videos')}</h1>
  <span class="dev-page__spacer"></span>
  <Button label={t('common.refresh')} icon={iconRefresh} variant="secondary" size="sm" disabled={busy} onclick={load} testid="videos-refresh" />
</div>
<p class="dev-muted intro">{t('videos.intro')}</p>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !data}
  <div class="dev-empty">{t('common.loading')}</div>
{:else}
  {#if data.toolProblems.length}
    <div class="dev-alert dev-alert--warning" data-testid="videos-tools-missing">
      <span class="dev-alert__text">
        {t('videos.toolsMissing')}
        <ul class="dev-blockers">{#each data.toolProblems as p, i (i)}<li>{p}</li>{/each}</ul>
      </span>
    </div>
  {/if}
  {#if notice}
    <div class="dev-alert dev-alert--{notice.kind}">
      <span class="dev-alert__text">
        {notice.text}
        {#if notice.jobId} — <a href={router.href('testingJob', { jobId: notice.jobId })} onclick={onLinkClick}>{t('common.runningJob', { id: notice.jobId })}</a>{/if}
      </span>
    </div>
  {/if}

  <div class="dev-card">
    <h2 class="dev-card__title">{t('videos.dsTitle')}</h2>
    {#each datasets as ds (ds.name)}
      <div class="ds-row" data-testid="videos-ds-{ds.name}">
        <strong>{ds.title ?? ds.name}</strong> <code>{ds.name}</code>
        {#if ds.dataSource}
          <span>— {ds.dataSource.name} <code>{ds.dataSource.id}</code> · <a href="/{ds.dataSource.id}/app/" target="_blank" rel="noopener">{t('common.open')}</a></span>
          {#if ds.activeJob}
            <a href={router.href('testingJob', { jobId: ds.activeJob.id })} onclick={onLinkClick}>{jobTypeLabel(ds.activeJob.type)} · {jobStatusLabel(ds.activeJob.status)}</a>
          {/if}
        {:else if ds.known}
          <span class="dev-muted">{t('videos.dsMissing', { set: ds.name })}</span>
          <Button label={t('videos.dsCreate')} icon={iconAdd} variant="secondary" size="sm" disabled={busy} onclick={() => askCreate(ds)} testid="videos-ds-create-{ds.name}" />
        {:else}
          <span class="dev-muted">{t('videos.blocker.dataset', { set: ds.name })}</span>
        {/if}
      </div>
    {:else}
      <div class="dev-empty">{t('videos.empty')}</div>
    {/each}
  </div>

  <div class="dev-card">
    <h2 class="dev-card__title">{t('videos.scenarios')}</h2>
    <div class="dev-table-wrap">
      <table class="dev-table">
        <thead>
          <tr>
            <th>{t('videos.col.scenario')}</th>
            <th>{t('common.dataset')}</th>
            <th>{t('videos.col.check')}</th>
            <th>{t('videos.col.video')}</th>
            <th>{t('videos.col.actions')}</th>
          </tr>
        </thead>
        <tbody>
          {#each data.scenarios as row (row.id)}
            {@const blocker = blockerText(row, data.toolProblems)}
            {@const check = checkState(row.check)}
            <tr data-testid="videos-row-{row.id}">
              <td>
                <strong>{row.title}</strong> <code class="dev-small">{row.id}</code>
                {#if row.description}<div class="dev-small dev-muted">{row.description}</div>{/if}
                {#if row.problem}<div class="dev-small"><span class="dev-lvl dev-lvl--bad">{row.problem}</span></div>{/if}
              </td>
              <td>
                {#if row.dataset}<code>{row.dataset}</code>{#if row.datasetTitle}<div class="dev-small dev-muted">{row.datasetTitle}</div>{/if}{:else}<span class="dev-muted">—</span>{/if}
              </td>
              <td>
                {#if check}
                  <span class="dev-lvl dev-lvl--{check.cls}">{check.label}</span>
                  <div class="dev-small dev-muted">{formatDateTime(row.check.at)} · {t('videos.anchor', { anchor: row.check.anchor })}</div>
                  {#if row.check.error?.message}<div class="dev-small"><span class="dev-lvl dev-lvl--bad">{row.check.error.message}</span></div>{/if}
                {:else}
                  <span class="dev-muted">{t('videos.noCheck')}</span>
                {/if}
              </td>
              <td>
                {#if row.hasVideo && row.meta}
                  <div>{formatDateTime(row.meta.recordedAt)}</div>
                  <div class="dev-small dev-muted">
                    {formatDuration(row.meta.durationMs)} · {formatBytes(row.meta.bytes)} · {t('videos.anchor', { anchor: row.meta.anchor })}
                    {#if row.meta.commit} · <code>{String(row.meta.commit).slice(0, 8)}</code>{/if}
                  </div>
                {:else}
                  <span class="dev-muted">{t('videos.noVideo')}</span>
                {/if}
              </td>
              <td>
                <div class="dev-btnrow">
                  <span title={blocker ?? ''}>
                    <Button label={t('videos.check')} variant="secondary" size="sm" disabled={busy || blocker !== null} onclick={() => askRun(row, 'check')} testid="videos-check-{row.id}" />
                  </span>
                  <span title={blocker ?? ''}>
                    <Button label={t('videos.build')} variant="secondary" size="sm" disabled={busy || blocker !== null} onclick={() => askRun(row, 'build')} testid="videos-build-{row.id}" />
                  </span>
                  {#if row.hasVideo}
                    <Button label={playing === row.id ? t('videos.hide') : t('videos.play')} icon={iconPlay} variant="secondary" size="sm" onclick={() => (playing = playing === row.id ? null : row.id)} testid="videos-play-{row.id}" />
                    <a class="download" href={videosApi.fileUrl(row.id, true)} download={row.meta?.file ?? `${row.id}.mp4`} data-testid="videos-download-{row.id}">
                      <Icon icon={iconDownload} size="sm" /> {t('videos.download')}
                    </a>
                  {/if}
                </div>
                {#if row.activeJob}
                  <div class="dev-small"><a href={router.href('testingJob', { jobId: row.activeJob.id })} onclick={onLinkClick}>{t('common.runningJob', { id: row.activeJob.id })}</a></div>
                {:else if blocker}
                  <div class="dev-small dev-muted">{t('videos.blocked', { reason: blocker })}</div>
                {/if}
              </td>
            </tr>
            {#if playing === row.id}
              <tr>
                <td colspan="5">
                  <!-- svelte-ignore a11y_media_has_caption -->
                  <video class="player" controls preload="metadata" src={videosApi.fileUrl(row.id)} data-testid="videos-player-{row.id}"></video>
                </td>
              </tr>
            {/if}
          {:else}
            <tr><td colspan="5" class="dev-empty">{t('videos.empty')}</td></tr>
          {/each}
        </tbody>
      </table>
    </div>
  </div>
{/if}

<DevConfirm
  open={confirm !== null}
  title={confirm?.title ?? ''}
  message={confirm?.message ?? ''}
  variant={confirm?.variant ?? 'primary'}
  confirmLabel={confirm?.confirmLabel ?? t('common.confirm')}
  cancelLabel={t('common.cancel')}
  busy={busy}
  onConfirm={() => confirm?.run()}
  onCancel={() => (confirm = null)}
/>

<style>
  .intro { margin-top: 0; }
  .ds-row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--shpd-space-sm); margin-bottom: var(--shpd-space-sm); }
  .download { display: inline-flex; align-items: center; gap: var(--shpd-space-xs); }
  .player { display: block; width: 100%; max-width: 960px; border: 1px solid var(--shpd-color-border); border-radius: var(--shpd-radius-md); background: #000; }
</style>
