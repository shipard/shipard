<script>
  // Výsledek úlohy `video` (`video-run --json`, #103 F7b): stav, verb, scénář,
  // sada a kotva, pevný čas v prohlížeči, kroky (sestavení sady i z mezipaměti,
  // přeseedování, ověření / natáčení, skládání), krok a selektor, na kterém
  // scénář selhal, a u natočení délka a velikost videa s odkazem do oddílu Videa.
  import { t } from '../../i18n/index.js';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { formatMs } from '../../format.js';
  import { videoStepLabel, videoStatusLabel, formatBytes, formatDuration } from '../../videos/videos.js';

  let { report } = $props();

  const STATUS_CLS = { ok: 'ok', failed: 'bad', cancelled: 'warn' };
  const steps = $derived(report?.steps ?? {});
  const problems = $derived(report?.problems ?? []);
  const failedStep = $derived(report?.result?.error?.step ?? null);
</script>

{#if !report}
  <div class="dev-empty">{t('videos.result.none')}</div>
{:else if report.ok === false && !report.status}
  <div class="dev-alert dev-alert--danger">
    <span class="dev-alert__text">{t('videos.result.refused')}<ul class="dev-blockers">{#each problems as p, i (i)}<li>{p}</li>{/each}</ul></span>
  </div>
{:else}
  <dl class="dev-kv dev-small">
    <dt>{t('testing.e2e.summary')}</dt>
    <dd>
      <span class="dev-lvl dev-lvl--{STATUS_CLS[report.status] ?? 'neutral'}">{videoStatusLabel(report.status)}</span>
      · <code>{report.verb}</code> <code>{report.scenario?.id}</code>
      · {t('common.dataset')} <code>{report.dataset?.name}</code> · {t('videos.anchor', { anchor: report.anchor })}
      {#if report.fixedTime} · {t('videos.result.browserTime', { time: report.fixedTime })}{/if}
      {#if report.exitCode !== undefined}<span class="dev-muted"> · exit {report.exitCode}</span>{/if}
      {#if problems.length}
        <ul class="dev-blockers">{#each problems as p, i (i)}<li>{p}</li>{/each}</ul>
      {/if}
    </dd>
    <dt>{t('testing.e2e.steps')}</dt>
    <dd>
      {#each Object.keys(steps) as name (name)}
        {@const s = steps[name]}
        <span class="dev-state dev-state--{!s?.ran ? 'neutral' : s.cached || s.exitCode === 0 ? 'match' : 'wrong'}">
          {videoStepLabel(name)}{#if !s?.ran} · {t('testing.e2e.stepSkipped')}{:else if s.cached} · {t('videos.result.cached')}{:else}{#if s.exitCode !== null && s.exitCode !== undefined} · exit {s.exitCode}{/if}{#if s.durationMs} · {formatMs(s.durationMs)}{/if}{/if}
        </span>
      {/each}
    </dd>
    {#if failedStep}
      <dt>{t('videos.result.failedStep')}</dt>
      <dd>
        <span class="dev-state dev-state--wrong">#{failedStep.index} {failedStep.verb} <code>{failedStep.value ?? ''}</code></span>
        {#if report.result?.error?.message} {report.result.error.message}{/if}
        {#if report.result?.error?.hint}<div class="dev-muted">{report.result.error.hint}</div>{/if}
      </dd>
    {/if}
    {#if report.video}
      <dt>{t('videos.result.video')}</dt>
      <dd>
        <code>{report.video.path}</code> · {formatDuration(report.video.durationMs)} · {formatBytes(report.video.bytes)}
        · <a href={router.href('videos')} onclick={onLinkClick}>{t('videos.result.open')}</a>
      </dd>
    {/if}
    <dt>{t('common.dataSource')}</dt>
    <dd>{report.dataSource?.name} <code>{report.dataSource?.id}</code>{#if report.appUrl} · <a href={report.appUrl} target="_blank" rel="noopener">{report.appUrl}</a>{/if}</dd>
  </dl>
{/if}
