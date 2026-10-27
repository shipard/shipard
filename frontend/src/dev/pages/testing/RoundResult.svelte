<script>
  // Souhrn testovacího kola (`result.json` úlohy `round`, #103 F8a D133):
  // stav a součty, commit a necommitnuté změny, zdroje dat (založené kolem),
  // tabulka kroků po vrstvách s odkazy na dílčí úlohy, počty testů, první
  // selhání a u evaluací shoda polí a cena. Orchestrátor souhrn píše
  // průběžně, takže táž tabulka slouží jako živý průběh i konečný výsledek.
  import { t } from '../../i18n/index.js';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { formatDateTime, formatMs } from '../../format.js';
  import { roundStatusClass, roundStatusLabel, roundStepClass, roundLayerLabel, roundSummaryText, groupRoundSteps, roundStepNote, roundSelectionText } from '../../testing/round.js';

  let { result = null } = $props();

  const groups = $derived(groupRoundSteps(result?.steps ?? []));
  const git = $derived(result?.git ?? {});
</script>

{#if !result}
  <div class="dev-empty">{t('testing.round.noResult')}</div>
{:else}
  <dl class="dev-kv dev-small">
    <dt>{t('testing.e2e.summary')}</dt>
    <dd>
      <span class="dev-lvl dev-lvl--{roundStatusClass(result.status)}">{roundStatusLabel(result.status)}</span>
      · {roundSummaryText(result)}
      {#if result.exitCode !== null && result.exitCode !== undefined}<span class="dev-muted"> · exit {result.exitCode}</span>{/if}
      <div class="dev-muted">{roundSelectionText(result.selection)}</div>
    </dd>
    <dt>{t('testing.round.git')}</dt>
    <dd><code>{git.commit ? git.commit.slice(0, 8) : '?'}</code> ({git.branch ?? '?'}){#if git.dirty} <span class="dev-lvl dev-lvl--warn">{t('testing.round.dirty')}</span>{/if}</dd>
    <dt>{t('testing.col.run')}</dt>
    <dd>{result.startedAt ? formatDateTime(result.startedAt) : '—'} → {result.finishedAt ? formatDateTime(result.finishedAt) : '…'}</dd>
    {#if result.dataSources?.length}
      <dt>{t('testing.round.dataSources')}</dt>
      <dd>
        {#each result.dataSources as ds, i (i)}
          <div>{roundLayerLabel(ds.role === 'videos' ? 'videos' : 'datasets')} <code>{ds.dataset}</code> → {ds.name} <code>{ds.id}</code>{#if ds.created} <span class="dev-lvl dev-lvl--neutral">{t('testing.round.created')}</span>{/if}{#if ds.jobId} · <a href={router.href('testingJob', { jobId: ds.jobId })} onclick={onLinkClick}><code>{ds.jobId}</code></a>{/if}</div>
        {/each}
      </dd>
    {/if}
  </dl>

  <div class="dev-table-wrap">
    <table class="dev-table">
      <thead>
        <tr><th>{t('testing.round.col.layer')}</th><th>{t('testing.round.col.dataset')}</th><th>{t('testing.round.col.scenario')}</th><th>{t('testing.round.col.step')}</th><th>{t('testing.col.state')}</th><th class="dev-num">{t('testing.round.col.duration')}</th><th>{t('testing.round.col.job')}</th><th>{t('testing.round.col.note')}</th></tr>
      </thead>
      <tbody>
        {#each groups as group (group.layer)}
          {#each group.steps as step, i (`${group.layer}:${i}`)}
            <tr>
              <td>{roundLayerLabel(group.layer)}</td>
              <td>{#if step.dataset}<code>{step.dataset}</code>{:else}—{/if}</td>
              <td>{#if step.scenario}<code>{step.scenario}</code>{:else}—{/if}</td>
              <td>{step.label ?? step.step}</td>
              <td><span class="dev-state dev-state--{roundStepClass(step.status)}">{roundStatusLabel(step.status)}</span></td>
              <td class="dev-num">{step.durationMs ? formatMs(step.durationMs) : ''}</td>
              <td>{#if step.jobId}<a href={router.href('testingJob', { jobId: step.jobId })} onclick={onLinkClick}><code>{step.jobId}</code></a>{/if}</td>
              <td class="note">{roundStepNote(step)}{#if step.firstFailure && step.firstFailure !== step.reason}<div class="dev-muted dev-small">{step.firstFailure}</div>{/if}</td>
            </tr>
          {/each}
        {:else}
          <tr><td colspan="8" class="dev-empty">{t('testing.job.noProgress')}</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}

<style>
  .note { max-width: 28rem; overflow-wrap: anywhere; }
</style>
