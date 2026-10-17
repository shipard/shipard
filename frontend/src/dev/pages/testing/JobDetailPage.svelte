<script>
  // Detail úlohy (F6b D58, D61): hlavička, kroky, průběh evaluace (hotová
  // volání, útrata vs. strop, poslední doklady), výsledek (odkaz na běh
  // v prohlížeči / tabulka ověření / nový zdroj dat), výstup, zrušení.
  // Polling každé 2 s přes offset průběhu, dokud je úloha aktivní.
  import { onMount } from 'svelte';
  import Button from '../../../components/ui/Button.svelte';
  import DevConfirm from '../../components/DevConfirm.svelte';
  import { iconRefresh } from '../../../icons.js';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { ApiError } from '../../api.js';
  import { JOB_TYPE_LABELS, JOB_STATUS_LABELS, STEP_STATUS_LABELS, STATUS_REASON_LABELS, jobStatusClass, isActiveStatus, emptyProgress, reduceProgress, stoppedByLabel } from '../../testing/jobs.js';
  import { formatDateTime, formatUsd, formatMs, formatPercent } from '../../format.js';
  import Crumbs from '../../components/Crumbs.svelte';
  import VerifyResult from './VerifyResult.svelte';

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
      error = e.message;
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
      notice = { kind: 'info', text: force ? 'Úloha násilně ukončena (SIGKILL).' : 'Žádost o zrušení odeslána — běžící volání modelu ještě doběhne, další nezačne.' };
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

<Crumbs items={[{ label: 'Testování', href: router.href('testingDatasets') }, { label: 'Úlohy', href: router.href('testingJobs') }, { label: jobId }]} />

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !job}
  <div class="dev-empty">Načítám…</div>
{:else}
  <div class="dev-page__head">
    <h1>{JOB_TYPE_LABELS[job.type] ?? job.type} <code class="dev-small">{job.id}</code></h1>
    <span class="dev-lvl dev-lvl--{jobStatusClass(job.status)}">{JOB_STATUS_LABELS[job.status] ?? job.status}</span>
    <span class="dev-page__spacer"></span>
    <Button label="Obnovit" icon={iconRefresh} variant="secondary" size="sm" disabled={busy} onclick={() => load()} />
    {#if active && job.status !== 'cancelling'}
      <Button label="Zrušit" variant="secondary" size="sm" disabled={busy || !job.pid} onclick={() => (confirm = { force: false })} />
    {/if}
    {#if job.status === 'cancelling'}
      <Button label="Vynutit ukončení" variant="secondary" size="sm" disabled={busy} onclick={() => (confirm = { force: true })} />
    {/if}
  </div>

  {#if notice}
    <div class="dev-alert dev-alert--{notice.kind}"><span class="dev-alert__text">{notice.text}</span></div>
  {/if}
  {#if job.statusReason}
    <div class="dev-alert dev-alert--warning"><span class="dev-alert__text">{STATUS_REASON_LABELS[job.statusReason] ?? job.statusReason}</span></div>
  {/if}
  {#if job.error}
    <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{job.error}</span></div>
  {/if}

  <div class="dev-split">
    <div class="dev-card">
      <h2 class="dev-card__title">Zadání</h2>
      <dl class="dev-kv">
        <dt>Sada</dt><dd>{#if job.dataset}<a href={router.href('testingRuns', { set: job.dataset })} onclick={onLinkClick}><code>{job.dataset}</code></a>{:else}—{/if}</dd>
        <dt>Zdroj dat</dt><dd>{job.dataSource?.name} <code>{job.dataSource?.id}</code> · <a href="/{job.dataSource?.id}/app/" target="_blank" rel="noopener">Otevřít</a></dd>
        {#if job.variant}<dt>Varianta</dt><dd><a href={router.href('testingVariant', { name: job.variant })} onclick={onLinkClick}><code>{job.variant}</code></a></dd>{/if}
        {#if job.params && Object.keys(job.params).length}<dt>Parametry</dt><dd><code>{JSON.stringify(job.params)}</code></dd>{/if}
        <dt>Vytvořeno</dt><dd>{formatDateTime(job.createdAt)} <span class="dev-muted">z {job.clientIp ?? '?'}</span></dd>
        <dt>Běh</dt><dd>{job.startedAt ? formatDateTime(job.startedAt) : '—'} → {job.finishedAt ? formatDateTime(job.finishedAt) : '…'}{#if job.pid} <span class="dev-muted">pid {job.pid}</span>{/if}</dd>
        {#if job.cancelRequestedAt}<dt>Zrušení</dt><dd>{formatDateTime(job.cancelRequestedAt)}{#if job.forced} <span class="dev-muted">(vynuceno)</span>{/if}</dd>{/if}
      </dl>
    </div>

    <div class="dev-card">
      <h2 class="dev-card__title">Kroky</h2>
      <ol class="steps">
        {#each job.steps as step, i (i)}
          <li>
            <span class="dev-state dev-state--{step.status === 'finished' ? 'match' : step.status === 'failed' ? 'wrong' : step.status === 'cancelled' ? 'missing' : 'neutral'}">{STEP_STATUS_LABELS[step.status] ?? step.status}</span>
            <strong>{step.label}</strong>
            {#if step.exitCode !== null && step.exitCode !== undefined}<span class="dev-muted dev-small">exit {step.exitCode}</span>{/if}
            {#if step.termSignal}<span class="dev-muted dev-small">signál {step.termSignal}</span>{/if}
            <div class="dev-small dev-muted"><code>{step.argv.join(' ')}</code></div>
          </li>
        {/each}
      </ol>
    </div>
  </div>

  {#if job.type === 'eval'}
    <div class="dev-card">
      <h2 class="dev-card__title">Průběh evaluace</h2>
      {#if progress.started || progress.done > 0}
        <dl class="dev-kv">
          <dt>Volání</dt>
          <dd>
            {progress.done} / {progress.total ?? '?'}
            <div class="dev-progress"><div class="dev-progress__bar" style="width: {pct}%"></div></div>
          </dd>
          <dt>Útrata</dt>
          <dd>
            {formatUsd(progress.spentUsd)}{#if progress.maxCostUsd} / strop {formatUsd(progress.maxCostUsd)}{/if}
            {#if spentPct !== null}<div class="dev-progress"><div class="dev-progress__bar" class:dev-progress__bar--warn={spentPct >= 80} style="width: {spentPct}%"></div></div>{/if}
          </dd>
          {#if progress.started}
            <dt>Model</dt><dd><code>{progress.started.model}</code>{#if progress.started.tagsModel} · štítky <code>{progress.started.tagsModel}</code>{/if}</dd>
          {/if}
          {#if progress.finished?.stoppedBy}
            <dt>Konec</dt><dd><span class="dev-state dev-state--missing">{stoppedByLabel(progress.finished.stoppedBy)}</span></dd>
          {/if}
          {#if progress.error}
            <dt>Chyba</dt><dd class="dev-lvl dev-lvl--bad">{progress.error}</dd>
          {/if}
        </dl>
        {#if progress.lastCalls.length}
          <table class="dev-table">
            <thead><tr><th>Doklad</th><th>×</th><th>Stav</th><th class="dev-num">Cena</th><th class="dev-num">Štítky</th><th class="dev-num">Čas</th></tr></thead>
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
        <div class="dev-empty">Zatím žádná událost průběhu (preflight, čekání na první volání…).</div>
      {/if}
    </div>
  {/if}

  {#if !active}
    <div class="dev-card">
      <h2 class="dev-card__title">Výsledek</h2>
      {#if job.type === 'eval'}
        {#if result?.ok === false}
          <div class="dev-alert dev-alert--danger">
            <span class="dev-alert__text">ai-eval odmítnuto:<ul class="dev-blockers">{#each result.problems ?? [] as p (p)}<li>{p}</li>{/each}</ul></span>
          </div>
        {:else if runFile() && job.dataset}
          <p>
            Soubor výsledků <a href={router.href('testingRun', { set: job.dataset, file: runFile() })} onclick={onLinkClick}><code>{runFile()}</code></a>
            {#if result?.stoppedBy}<span class="dev-state dev-state--missing">{stoppedByLabel(result.stoppedBy)}</span>{/if}
            {#if result?.plannedCalls}<span class="dev-muted dev-small"> — {result.metrics?.['calls.total'] ?? '?'} z {result.plannedCalls} volání</span>{/if}
          </p>
          {#if result?.metrics}
            <dl class="dev-kv dev-small">
              <dt>Shoda polí</dt><dd>{formatPercent(result.metrics['fields.all.hit'])}</dd>
              <dt>Bez odchylky</dt><dd>{formatPercent(result.metrics['documents.clean'])}</dd>
              <dt>Selhání</dt><dd>{result.metrics['calls.failed'] ?? 0}</dd>
              <dt>Cena</dt><dd>{formatUsd(result.metrics['cost.totalUsd'] ?? result.metrics['cost.usd'])}</dd>
            </dl>
          {/if}
          <p class="dev-small dev-muted">Soubor leží v <code>tests/eval/{job.dataset}/</code> pracovního stromu — commit dělá člověk.</p>
        {:else}
          <div class="dev-empty">Bez souboru výsledků (zrušeno před prvním voláním, nebo selhání — viz výstup).</div>
        {/if}
      {:else if job.type === 'verify'}
        <VerifyResult report={result} />
      {:else if job.type === 'create'}
        {#if job.status === 'finished'}
          <p>Zdroj dat <code>{job.dataSource?.id}</code> založen a naseedován — <a href="/{job.dataSource?.id}/app/" target="_blank" rel="noopener">Otevřít</a> (bez uživatele; přihlášení přes <code>shpd-ds auth-emergency-login</code>). V panelu sady je teď připravený k evaluaci.</p>
        {:else}
          <div class="dev-empty">Založení nedoběhlo — viz kroky a výstup. Rozpracovaný zdroj dat případně uklidit ručně.</div>
        {/if}
      {:else if job.status === 'finished'}
        <p>Přeseedováno — zdroj dat je čistý s aktuální sadou{#if job.params?.previousDataset} (nahradila sadu <code>{job.params.previousDataset}</code>){/if}.</p>
      {:else}
        <div class="dev-empty">Úloha nedoběhla — viz výstup.</div>
      {/if}
    </div>
  {/if}

  <div class="dev-card">
    <h2 class="dev-card__title">Výstup <span class="dev-small dev-muted">(output.log, posledních 200 řádků)</span></h2>
    <pre class="dev-output">{outputTail || '(zatím prázdný)'}</pre>
  </div>
{/if}

<DevConfirm
  open={confirm !== null}
  title={confirm?.force ? 'Vynutit ukončení' : 'Zrušit úlohu'}
  message={confirm?.force
    ? 'SIGKILL celé skupině procesů — rozpracovaný výsledek se neuloží a zdroj dat může zůstat v půlce (reseed). Použij jen když měkké zrušení nereaguje.'
    : 'SIGTERM: ai-eval nezačne další volání modelu a uloží rozpracovaný běh jako zrušený; dataset-seed / verify se přeruší.'}
  variant="danger"
  confirmLabel={confirm?.force ? 'Vynutit' : 'Zrušit úlohu'}
  cancelLabel="Zpět"
  busy={busy}
  onConfirm={() => cancel(confirm?.force ?? false)}
  onCancel={() => (confirm = null)}
/>

<style>
  .steps { margin: 0; padding-left: 20px; }
  .steps li { margin-bottom: 6px; }
  .steps li > * { margin-right: 6px; }
</style>
