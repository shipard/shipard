<script>
  // Výsledek úlohy `e2e` (`dataset-e2e --json`, F7a D96): stav, karty použité /
  // selhané (krok, důvod, cesta reference, trace ke stažení), chyby
  // prohlížeče, odpovědi API ≥ 400 a kontroly dataset-verify (VerifyResult
  // nad `report.verify`). Stahování trace jde přes server, který vydá jen
  // soubory jmenované v reportu.
  import { t, label } from '../../i18n/index.js';
  import { testingApi } from '../../testing/api.js';
  import { e2eStepLabel } from '../../testing/jobs.js';
  import { formatMs } from '../../format.js';
  import VerifyResult from './VerifyResult.svelte';

  let { report, jobId } = $props();

  const STATUS_CLS = { ok: 'ok', failed: 'bad', cancelled: 'warn' };
  const STEP_ORDER = ['reseed', 'walkthrough', 'verify'];
  const cards = $derived(report?.cards ?? null);
  const failed = $derived(cards?.failed ?? []);
  const applied = $derived(cards?.applied ?? []);
  const browserErrors = $derived(report?.browserErrors ?? []);
  const apiResponses = $derived(report?.apiResponses ?? []);
  const steps = $derived(report?.steps ?? {});
  const problems = $derived(report?.problems ?? []);
  let showApplied = $state(false);

  function reasonText(f) {
    return `${f.step ?? '?'}/${f.reason ?? '?'}`;
  }

  function detailText(f) {
    return [f.message, f.alert, f.httpStatus ? `HTTP ${f.httpStatus}` : null, Array.isArray(f.pendingPaths) && f.pendingPaths.length > 1 ? f.pendingPaths.join(', ') : null]
      .filter(Boolean)
      .join(' · ');
  }
</script>

{#if !report}
  <div class="dev-empty">{t('testing.e2e.noResult')}</div>
{:else}
  <dl class="dev-kv dev-small">
    <dt>{t('testing.e2e.summary')}</dt>
    <dd>
      <span class="dev-lvl dev-lvl--{STATUS_CLS[report.status] ?? 'neutral'}">{label('jobs.e2eStatus', report.status)}</span>
      {#if cards}
        · {t('testing.e2e.cards', { applied: applied.length, expected: cards.expected ?? '?', failed: failed.length })}
      {/if}
      {#if report.exitCode !== undefined}<span class="dev-muted"> · exit {report.exitCode}</span>{/if}
      {#if problems.length}
        <ul class="dev-blockers">
          {#each problems as p, i (i)}<li>{p}</li>{/each}
        </ul>
      {/if}
    </dd>
    <dt>{t('testing.e2e.steps')}</dt>
    <dd>
      {#each STEP_ORDER as name (name)}
        {@const s = steps[name]}
        <span class="dev-state dev-state--{!s || s.ran === false ? 'neutral' : s.exitCode === 0 ? 'match' : 'wrong'}">
          {e2eStepLabel(name)}{#if !s || s.ran === false} · {t('testing.e2e.stepSkipped')}{:else}{#if s.exitCode !== null && s.exitCode !== undefined} · exit {s.exitCode}{/if}{#if s.durationMs} · {formatMs(s.durationMs)}{/if}{/if}
        </span>
      {/each}
    </dd>
    {#if report.outputDir}
      <dt>{t('testing.e2e.outputDir')}</dt>
      <dd><code>{report.outputDir}</code> <span class="dev-muted">{t('testing.e2e.outputDirNote')}</span></dd>
    {/if}
  </dl>

  <h3 class="dev-card__title">{t('testing.e2e.failedCards')}</h3>
  {#if failed.length === 0}
    <div class="dev-empty">{t('testing.e2e.none')}</div>
  {:else}
    <div class="dev-table-wrap">
      <table class="dev-table">
        <thead><tr><th>{t('testing.e2e.col.card')}</th><th>{t('testing.e2e.col.reason')}</th><th>{t('testing.e2e.col.path')}</th><th>{t('testing.e2e.col.detail')}</th><th>{t('testing.e2e.col.trace')}</th></tr></thead>
        <tbody>
          {#each failed as f (f.cardId)}
            <tr>
              <td><code>{f.cardId}</code></td>
              <td><span class="dev-state dev-state--wrong">{reasonText(f)}</span></td>
              <td>{#if f.path}<code>{f.path}</code>{:else}<span class="dev-muted">—</span>{/if}</td>
              <td class="dev-small">{detailText(f)}</td>
              <td>{#if f.trace}<a href={testingApi.jobFileUrl(jobId, f.trace)} download={f.trace}>{t('testing.e2e.download')}</a>{:else}<span class="dev-muted">—</span>{/if}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}

  <h3 class="dev-card__title">{t('testing.e2e.appliedCards')}</h3>
  {#if applied.length === 0}
    <div class="dev-empty">{t('testing.e2e.none')}</div>
  {:else}
    <button type="button" class="dev-chip" class:is-on={showApplied} onclick={() => (showApplied = !showApplied)}>
      {showApplied ? t('testing.e2e.hideApplied') : t('testing.e2e.showApplied', { n: applied.length })}
    </button>
    {#if showApplied}
      <div class="dev-table-wrap">
        <table class="dev-table">
          <thead><tr><th>{t('testing.e2e.col.card')}</th><th>{t('testing.e2e.col.document')}</th><th class="dev-num">{t('testing.job.col.time')}</th></tr></thead>
          <tbody>
            {#each applied as a (a.cardId)}
              <tr><td><code>{a.cardId}</code></td><td>{a.savedDocId ? `#${a.savedDocId}` : '—'}</td><td class="dev-num">{formatMs(a.durationMs)}</td></tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  {/if}

  <h3 class="dev-card__title">{t('testing.e2e.browserErrors')}</h3>
  {#if browserErrors.length === 0}
    <div class="dev-empty">{t('testing.e2e.none')}</div>
  {:else}
    <ul class="dev-blockers">
      {#each browserErrors as e, i (i)}<li><code>{e.cardId ?? '—'}</code> {e.type}: {e.message}</li>{/each}
    </ul>
  {/if}

  <h3 class="dev-card__title">{t('testing.e2e.apiResponses')}</h3>
  {#if apiResponses.length === 0}
    <div class="dev-empty">{t('testing.e2e.none')}</div>
  {:else}
    <ul class="dev-blockers">
      {#each apiResponses as r, i (i)}
        <li><span class="dev-lvl dev-lvl--{r.status >= 500 ? 'bad' : 'neutral'}">{r.status}</span> {r.method} <code>{r.url}</code> {#if r.cardId}<span class="dev-muted">({r.cardId})</span>{/if}</li>
      {/each}
    </ul>
  {/if}

  <h3 class="dev-card__title">{t('testing.e2e.verify')}</h3>
  {#if report.verify}
    <VerifyResult report={report.verify} />
  {:else if steps.verify?.refused}
    <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{t('testing.e2e.verifyRefused', { reason: steps.verify.refused })}</span></div>
  {:else}
    <div class="dev-empty">{t('testing.e2e.verifySkipped')}</div>
  {/if}
{/if}

<style>
  h3.dev-card__title { margin-top: var(--shpd-space-lg); }
</style>
