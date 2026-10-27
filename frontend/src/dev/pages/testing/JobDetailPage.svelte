<script>
  // Detail úlohy (F6b D58, D61): hlavička, kroky, průběh evaluace (hotová
  // volání, útrata vs. strop, poslední doklady) nebo proklikání sady (kroky
  // běhu, karty, F7a), výsledek (odkaz na běh v prohlížeči / tabulka ověření
  // / report proklikání s trace / nový zdroj dat), výstup, zrušení.
  // Polling každé 2 s přes offset průběhu, dokud je úloha aktivní.
  import { onMount } from 'svelte';
  import Button from '../../../components/ui/Button.svelte';
  import DevConfirm from '../../components/DevConfirm.svelte';
  import { iconRefresh } from '../../../icons.js';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { ApiError } from '../../api.js';
  import { jobTypeLabel, jobStatusLabel, stepStatusLabel, statusReasonLabel, jobStatusClass, isActiveStatus, emptyProgress, reduceProgress, stoppedByLabel, e2eStepLabel } from '../../testing/jobs.js';
  import { t } from '../../i18n/index.js';
  import { formatDateTime, formatUsd, formatMs, formatPercent } from '../../format.js';
  import Crumbs from '../../components/Crumbs.svelte';
  import VerifyResult from './VerifyResult.svelte';
  import E2eResult from './E2eResult.svelte';
  import VideoResult from '../videos/VideoResult.svelte';
  import { videoStepLabel } from '../../videos/videos.js';

  const route = $derived(router.route);
  const jobId = $derived(route.params.jobId);

  let job = $state(null);
  let result = $state(null);
  let outputTail = $state('');
  let progress = $state(emptyProgress());
  let offset = $state(0);
  let error = $state(null);
  let notice = $state(null);
  let busy = $state(false);
  let confirm = $state(null); // {force: bool}

  const active = $derived(job ? isActiveStatus(job.status) : false);
  const pct = $derived(progress.total ? Math.min(100, Math.round((progress.done / progress.total) * 100)) : 0);
  const spentPct = $derived(progress.maxCostUsd ? Math.min(100, Math.round((progress.spentUsd / progress.maxCostUsd) * 100)) : null);
  const cardsDone = $derived(progress.cards.applied + progress.cards.failed);
  const cardsPct = $derived(progress.cards.total ? Math.min(100, Math.round((cardsDone / progress.cards.total) * 100)) : 0);

  async function load(reset = false) {
    try {
      const d = await testingApi.job(jobId, reset ? 0 : offset);
      job = d.job;
      result = d.result;
      outputTail = d.outputTail;
      progress = reduceProgress(reset ? emptyProgress() : progress, d.progress.events);
      offset = d.progress.nextOffset;
      error = null;
    } catch (e) {
      // Chvilkové selhání pollingu u běžící úlohy nechává poslední stav —
      // úloha `update` přepisuje PHP i soubory dashboardu (#129 U7).
      if (job === null || !isActiveStatus(job.status)) error = e.message;
    }
  }

  $effect(() => {
    const current = jobId;
    job = null;
    result = null;
    progress = emptyProgress();
    offset = 0;
    error = null;
    let cancelled = false;
    load(true).then(() => { if (cancelled) return; });
    const timer = setInterval(() => {
      if (!cancelled && (job === null || isActiveStatus(job.status))) load();
    }, 2000);
    return () => { cancelled = true; clearInterval(timer); void current; };
  });

  async function cancel(force) {
    busy = true;
    notice = null;
    try {
      const d = await testingApi.cancelJob(jobId, force);
      job = d.job;
      notice = { kind: 'info', text: force ? t('testing.job.cancelledForced') : t('testing.job.cancelledSoft') };
    } catch (e) {
      notice = { kind: 'danger', text: e instanceof ApiError ? `${e.message} (${e.code})` : e.message };
    } finally {
      busy = false;
      confirm = null;
      load();
    }
  }

  function runFile() {
    return result?.file ?? progress.finished?.file?.split('/').pop() ?? null;
  }
</script>

<Crumbs items={[{ label: t('app.nav.testing'), href: router.href('testingDatasets') }, { label: t('app.title.jobs'), href: router.href('testingJobs') }, { label: jobId }]} />

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !job}
  <div class="dev-empty">{t('common.loading')}</div>
{:else}
  <div class="dev-page__head">
    <h1>{jobTypeLabel(job.type)} <code class="dev-small">{job.id}</code></h1>
    <span class="dev-lvl dev-lvl--{jobStatusClass(job.status)}">{jobStatusLabel(job.status)}</span>
    <span class="dev-page__spacer"></span>
    <Button label={t('common.refresh')} icon={iconRefresh} variant="secondary" size="sm" disabled={busy} onclick={() => load()} />
    {#if active && job.type === 'update'}
      <span class="dev-small dev-muted">{t('testing.job.notCancellable')}</span>
    {:else}
      {#if active && job.status !== 'cancelling'}
        <Button label={t('common.cancel')} variant="secondary" size="sm" disabled={busy || !job.pid} onclick={() => (confirm = { force: false })} />
      {/if}
      {#if job.status === 'cancelling'}
        <Button label={t('testing.job.forceKill')} variant="secondary" size="sm" disabled={busy} onclick={() => (confirm = { force: true })} />
      {/if}
    {/if}
  </div>

  {#if notice}
    <div class="dev-alert dev-alert--{notice.kind}"><span class="dev-alert__text">{notice.text}</span></div>
  {/if}
  {#if job.statusReason}
    <div class="dev-alert dev-alert--warning"><span class="dev-alert__text">{statusReasonLabel(job.statusReason)}</span></div>
  {/if}
  {#if job.error}
    <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{job.error}</span></div>
  {/if}

  <div class="dev-split">
    <div class="dev-card">
      <h2 class="dev-card__title">{t('testing.job.spec')}</h2>
      <dl class="dev-kv">
        <dt>{t('common.dataset')}</dt><dd>{#if job.dataset}<a href={router.href('testingRuns', { set: job.dataset })} onclick={onLinkClick}><code>{job.dataset}</code></a>{:else}—{/if}</dd>
        <dt>{t('common.dataSource')}</dt><dd>{#if job.dataSource}{job.dataSource.name} <code>{job.dataSource.id}</code> · <a href="/{job.dataSource.id}/app/" target="_blank" rel="noopener">{t('common.open')}</a>{:else}<span class="dev-muted">{t('jobs.server')}</span>{/if}</dd>
        {#if job.variant}<dt>{t('testing.col.variant')}</dt><dd><a href={router.href('testingVariant', { name: job.variant })} onclick={onLinkClick}><code>{job.variant}</code></a></dd>{/if}
        {#if job.params && Object.keys(job.params).length}<dt>{t('testing.job.params')}</dt><dd><code>{JSON.stringify(job.params)}</code></dd>{/if}
        <dt>{t('ds.col.created')}</dt><dd>{formatDateTime(job.createdAt)} <span class="dev-muted">{t('testing.job.from', { ip: job.clientIp ?? '?' })}</span></dd>
        <dt>{t('testing.col.run')}</dt><dd>{job.startedAt ? formatDateTime(job.startedAt) : '—'} → {job.finishedAt ? formatDateTime(job.finishedAt) : '…'}{#if job.pid} <span class="dev-muted">pid {job.pid}</span>{/if}</dd>
        {#if job.cancelRequestedAt}<dt>{t('testing.job.cancellation')}</dt><dd>{formatDateTime(job.cancelRequestedAt)}{#if job.forced} <span class="dev-muted">{t('testing.job.forced')}</span>{/if}</dd>{/if}
      </dl>
    </div>

    <div class="dev-card">
      <h2 class="dev-card__title">{t('testing.job.steps')}</h2>
      <ol class="steps">
        {#each job.steps as step, i (i)}
          <li>
            <span class="dev-state dev-state--{step.status === 'finished' ? 'match' : step.status === 'failed' ? 'wrong' : step.status === 'cancelled' ? 'missing' : 'neutral'}">{stepStatusLabel(step.status)}</span>
            <strong>{step.label}</strong>
            {#if step.exitCode !== null && step.exitCode !== undefined}<span class="dev-muted dev-small">exit {step.exitCode}</span>{/if}
            {#if step.termSignal}<span class="dev-muted dev-small">{t('testing.job.signal', { n: step.termSignal })}</span>{/if}
            <div class="dev-small dev-muted"><code>{step.argv.join(' ')}</code></div>
          </li>
        {/each}
      </ol>
    </div>
  </div>

  {#if job.type === 'eval'}
    <div class="dev-card">
      <h2 class="dev-card__title">{t('testing.job.evalProgress')}</h2>
      {#if progress.started || progress.done > 0}
        <dl class="dev-kv">
          <dt>{t('metrics.metric.calls.total')}</dt>
          <dd>
            {progress.done} / {progress.total ?? '?'}
            <div class="dev-progress"><div class="dev-progress__bar" style="width: {pct}%"></div></div>
          </dd>
          <dt>{t('testing.job.spent')}</dt>
          <dd>
            {formatUsd(progress.spentUsd)}{#if progress.maxCostUsd} {t('testing.job.cap', { cap: formatUsd(progress.maxCostUsd) })}{/if}
            {#if spentPct !== null}<div class="dev-progress"><div class="dev-progress__bar" class:dev-progress__bar--warn={spentPct >= 80} style="width: {spentPct}%"></div></div>{/if}
          </dd>
          {#if progress.started}
            <dt>Model</dt><dd><code>{progress.started.model}</code>{#if progress.started.tagsModel} · {t('testing.run.tags')} <code>{progress.started.tagsModel}</code>{/if}</dd>
          {/if}
          {#if progress.finished?.stoppedBy}
            <dt>{t('testing.job.end')}</dt><dd><span class="dev-state dev-state--missing">{stoppedByLabel(progress.finished.stoppedBy)}</span></dd>
          {/if}
          {#if progress.error}
            <dt>{t('testing.job.error')}</dt><dd class="dev-lvl dev-lvl--bad">{progress.error}</dd>
          {/if}
        </dl>
        {#if progress.lastCalls.length}
          <table class="dev-table">
            <thead><tr><th>{t('testing.col.document')}</th><th>×</th><th>{t('testing.col.state')}</th><th class="dev-num">{t('testing.col.cost')}</th><th class="dev-num">{t('testing.col.tags')}</th><th class="dev-num">{t('testing.job.col.time')}</th></tr></thead>
            <tbody>
              {#each [...progress.lastCalls].reverse() as c, i (i)}
                <tr>
                  <td>{c.stem ?? c.messageId}</td>
                  <td>{c.repeat}</td>
                  <td><span class="dev-state dev-state--{c.status === 'ok' ? 'match' : 'failed'}">{c.status}{c.errorType ? ` · ${c.errorType}` : ''}</span></td>
                  <td class="dev-num">{formatUsd(c.costUsd)}</td>
                  <td class="dev-num">{c.tagsCostUsd === null || c.tagsCostUsd === undefined ? '—' : formatUsd(c.tagsCostUsd)}</td>
                  <td class="dev-num">{formatMs(c.durationMs)}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        {/if}
      {:else}
        <div class="dev-empty">{t('testing.job.noProgress')}</div>
      {/if}
    </div>
  {/if}

  {#if job.type === 'e2e'}
    <div class="dev-card">
      <h2 class="dev-card__title">{t('testing.job.e2eProgress')}</h2>
      {#if progress.started || progress.steps.length}
        <dl class="dev-kv">
          <dt>{t('testing.job.e2eStep')}</dt>
          <dd>
            {#each progress.steps as s (s.step)}
              <span class="dev-state dev-state--{s.status === 'finished' ? 'match' : s.status === 'failed' ? 'wrong' : 'neutral'}">{e2eStepLabel(s.step)}{#if s.exitCode !== null} · exit {s.exitCode}{/if}</span>
            {/each}
          </dd>
          <dt>{t('testing.job.e2eCards')}</dt>
          <dd>
            {cardsDone} / {progress.cards.total ?? '?'} — {t('testing.job.e2eCardsLine', { applied: progress.cards.applied, failed: progress.cards.failed })}
            {#if progress.cards.total}<div class="dev-progress"><div class="dev-progress__bar" class:dev-progress__bar--warn={progress.cards.failed > 0} style="width: {cardsPct}%"></div></div>{/if}
          </dd>
          {#if progress.error}
            <dt>{t('testing.job.error')}</dt><dd class="dev-lvl dev-lvl--bad">{progress.error}</dd>
          {/if}
        </dl>
        {#if progress.lastCards.length}
          <table class="dev-table">
            <thead><tr><th>{t('testing.e2e.col.card')}</th><th>{t('testing.col.state')}</th><th>{t('testing.e2e.col.reason')}</th><th class="dev-num">{t('testing.job.col.time')}</th></tr></thead>
            <tbody>
              {#each [...progress.lastCards].reverse() as c, i (i)}
                <tr>
                  <td><code>{c.cardId}</code></td>
                  <td><span class="dev-state dev-state--{c.status === 'applied' ? 'match' : 'wrong'}">{c.status}</span></td>
                  <td>{c.status === 'failed' ? `${c.step ?? '?'}/${c.reason ?? '?'}${c.path ? ` (${c.path})` : ''}` : (c.savedDocId ? `#${c.savedDocId}` : '')}</td>
                  <td class="dev-num">{formatMs(c.durationMs)}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        {/if}
      {:else}
        <div class="dev-empty">{t('testing.job.noProgress')}</div>
      {/if}
    </div>
  {/if}

  {#if job.type === 'video'}
    <div class="dev-card">
      <h2 class="dev-card__title">{t('testing.job.videoProgress')}</h2>
      {#if progress.started || progress.steps.length}
        <dl class="dev-kv">
          <dt>{t('testing.job.e2eStep')}</dt>
          <dd>
            {#each progress.steps as s (s.step)}
              <span class="dev-state dev-state--{s.status === 'finished' ? 'match' : s.status === 'failed' ? 'wrong' : 'neutral'}">{videoStepLabel(s.step)}{#if s.exitCode !== null} · exit {s.exitCode}{/if}</span>
            {/each}
          </dd>
          {#if progress.started?.scenario}
            <dt>{t('videos.col.scenario')}</dt><dd><code>{progress.started.scenario}</code> · <code>{progress.started.verb}</code> · {t('videos.anchor', { anchor: progress.started.anchor })}</dd>
          {/if}
          {#if progress.error}
            <dt>{t('testing.job.error')}</dt><dd class="dev-lvl dev-lvl--bad">{progress.error}</dd>
          {/if}
        </dl>
      {:else}
        <div class="dev-empty">{t('testing.job.noProgress')}</div>
      {/if}
    </div>
  {/if}

  {#if !active}
    <div class="dev-card">
      <h2 class="dev-card__title">{t('testing.jobs.col.result')}</h2>
      {#if job.type === 'eval'}
        {#if result?.ok === false}
          <div class="dev-alert dev-alert--danger">
            <span class="dev-alert__text">{t('testing.job.evalRejected')}<ul class="dev-blockers">{#each result.problems ?? [] as p (p)}<li>{p}</li>{/each}</ul></span>
          </div>
        {:else if runFile() && job.dataset}
          <p>
            {t('testing.job.resultFile')} <a href={router.href('testingRun', { set: job.dataset, file: runFile() })} onclick={onLinkClick}><code>{runFile()}</code></a>
            {#if result?.stoppedBy}<span class="dev-state dev-state--missing">{stoppedByLabel(result.stoppedBy)}</span>{/if}
            {#if result?.plannedCalls}<span class="dev-muted dev-small"> — {t('testing.runDetail.ofPlanned', { calls: result.metrics?.['calls.total'] ?? '?', planned: result.plannedCalls })}</span>{/if}
          </p>
          {#if result?.metrics}
            <dl class="dev-kv dev-small">
              <dt>{t('testing.runs.col.fieldMatch')}</dt><dd>{formatPercent(result.metrics['fields.all.hit'])}</dd>
              <dt>{t('testing.col.clean')}</dt><dd>{formatPercent(result.metrics['documents.clean'])}</dd>
              <dt>{t('testing.col.failed')}</dt><dd>{result.metrics['calls.failed'] ?? 0}</dd>
              <dt>{t('testing.col.cost')}</dt><dd>{formatUsd(result.metrics['cost.totalUsd'] ?? result.metrics['cost.usd'])}</dd>
            </dl>
          {/if}
          <p class="dev-small dev-muted">{t('testing.job.resultFileNote', { dataset: job.dataset })}</p>
        {:else}
          <div class="dev-empty">{t('testing.job.noResultFile')}</div>
        {/if}
      {:else if job.type === 'verify'}
        <VerifyResult report={result} />
      {:else if job.type === 'e2e'}
        <E2eResult report={result} jobId={job.id} />
      {:else if job.type === 'video'}
        <VideoResult report={result} />
      {:else if job.type === 'create'}
        {#if job.status === 'finished'}
          <p>{t('testing.job.created', { id: job.dataSource?.id })} — <a href="/{job.dataSource?.id}/app/" target="_blank" rel="noopener">{t('common.open')}</a> {t('testing.job.createdNote')}</p>
        {:else}
          <div class="dev-empty">{t('testing.job.createFailed')}</div>
        {/if}
      {:else if job.type === 'update'}
        {#if job.status === 'finished'}
          <p>{t('testing.job.updated')}</p>
          <div class="dev-btnrow"><Button label={t('testing.job.reloadPage')} variant="primary" size="sm" icon={iconRefresh} onclick={() => location.reload()} /></div>
        {:else}
          <div class="dev-empty">{t('testing.job.updateFailed')}</div>
        {/if}
      {:else if job.status === 'finished'}
        <p>{job.params?.previousDataset ? t('testing.job.reseededReplaced', { previous: job.params.previousDataset }) : t('testing.job.reseeded')}</p>
      {:else}
        <div class="dev-empty">{t('testing.job.notFinished')}</div>
      {/if}
    </div>
  {/if}

  <div class="dev-card">
    <h2 class="dev-card__title">{t('common.output')} <span class="dev-small dev-muted">{t('testing.job.outputNote')}</span></h2>
    <pre class="dev-output">{outputTail || t('testing.job.outputEmpty')}</pre>
  </div>
{/if}

<DevConfirm
  open={confirm !== null}
  title={confirm?.force ? t('testing.job.forceKill') : t('testing.job.cancelJob')}
  message={confirm?.force ? t('testing.job.forceMessage') : job?.type === 'e2e' ? t('testing.job.cancelMessageE2e') : job?.type === 'video' ? t('testing.job.cancelMessageVideo') : t('testing.job.cancelMessage')}
  variant="danger"
  confirmLabel={confirm?.force ? t('testing.job.force') : t('testing.job.cancelJob')}
  cancelLabel={t('common.back')}
  busy={busy}
  onConfirm={() => cancel(confirm?.force ?? false)}
  onCancel={() => (confirm = null)}
/>

<style>
  .steps { margin: 0; padding-left: 1.25rem; }
  .steps li { margin-bottom: 0.375rem; }
  .steps li > * { margin-right: 0.375rem; }
</style>
