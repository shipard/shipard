<script>
  // Štítek zastaralosti v pruhu dashboardu + bublina s Aktualizovat
  // (tasks/dev-dashboard-update.md § 5, #129 D19–D21, U7). Štítek je
  // tlačítko (`aria-expanded`), bublina sdílený Popover jako u DevKebabMenu:
  // Esc nebo klik mimo zavře a fokus se vrátí na štítek. Data ze store
  // updates.svelte.js; pojistky kreslí jen UI — rozhoduje server (409).
  import { tick } from 'svelte';
  import Popover from '../../components/ui/Popover.svelte';
  import Button from '../../components/ui/Button.svelte';
  import Icon from '../../components/ui/Icon.svelte';
  import { iconRefresh, iconOpenExternal, iconSpinner, iconWarning, iconCloudDownload } from '../../icons.js';
  import { router, onLinkClick } from '../router.svelte.js';
  import { updates } from '../updates.svelte.js';
  import { chipKind, daysSince, minutesSince, isOlderThanDays, blockerText, checkErrorText } from '../updates.js';
  import { COMMIT_URL } from '../links.js';
  import { formatDateTime } from '../format.js';
  import { t } from '../i18n/index.js';

  let open = $state(false);
  let buttonEl = $state(null);
  let now = $state(Date.now());

  const status = $derived(updates.status);
  const state = $derived(status?.state ?? null);
  const job = $derived(updates.job);
  const kind = $derived(chipKind(status, job, updates.reloadNeeded));
  const behind = $derived(state?.behind ?? 0);
  const strong = $derived(kind === 'behind' && isOlderThanDays(state?.oldestMissingAt, now));
  const blockers = $derived(status?.blockers ?? []);
  const onStable = $derived(!blockers.some((b) => b.code === 'notStable'));
  const canUpdate = $derived(Boolean(status?.canUpdate));
  const checking = $derived(Boolean(status?.checking));
  const failed = $derived(updates.finishedJob && updates.finishedJob.status !== 'finished' ? updates.finishedJob : null);

  // Štítek zmizel (aktualizováno jinudy, kontrola skončila „aktuální“) → bublina nemá co ukázat.
  $effect(() => {
    if (open && kind === null) closeBubble(false);
  });

  async function openBubble() {
    if (open) return;
    now = Date.now();
    open = true;
    await tick();
  }

  function closeBubble(restoreFocus = true) {
    if (!open) return;
    open = false;
    if (restoreFocus) buttonEl?.focus();
  }

  function toggle() {
    if (kind === 'reload') {
      updates.reloadPage();
      return;
    }
    if (open) closeBubble();
    else openBubble();
  }

  async function run() {
    const started = await updates.run();
    if (started) now = Date.now();
  }

  function agoDays(iso) {
    const d = daysSince(iso, now);
    return d === null ? '—' : t('updates.bubble.daysAgo', { n: d });
  }

  function agoMinutes(iso) {
    const m = minutesSince(iso, now);
    return m === null ? '—' : t('updates.bubble.minutesAgo', { n: m });
  }
</script>

{#if kind === 'updating'}
  <a class="dev-stripe__chip" href={router.href('testingJob', { jobId: job.id })} onclick={onLinkClick} title={t('updates.bubble.progress')}>
    <Icon icon={iconSpinner} size="sm" spin />{t('updates.chip.updating')}
  </a>
{:else if kind !== null}
  <button
    type="button"
    class="dev-stripe__chip"
    class:dev-stripe__chip--strong={strong || kind === 'reload'}
    bind:this={buttonEl}
    aria-haspopup={kind === 'reload' ? undefined : 'dialog'}
    aria-expanded={kind === 'reload' ? undefined : open}
    title={kind === 'behind' ? t('updates.chip.title') : undefined}
    onclick={toggle}
  >
    {#if kind === 'behind'}
      <Icon icon={iconCloudDownload} size="sm" />{t('updates.chip.behind', { n: behind })}
    {:else if kind === 'unverified'}
      <Icon icon={iconWarning} size="sm" />{t('updates.chip.unverified')}
    {:else}
      <Icon icon={iconRefresh} size="sm" />{t('updates.chip.reload')}
    {/if}
  </button>
{/if}

{#if open && kind !== null && kind !== 'reload'}
  <Popover open={true} anchor={buttonEl} placement="bottom" width="440px" onClose={() => closeBubble()}>
    <div class="bubble" role="document">
      {#if kind === 'unverified'}
        <h3 class="bubble__title">{t('updates.bubble.unverifiedTitle')}</h3>
        <p class="dev-small">{checkErrorText(state)}</p>
        <p class="dev-small dev-muted">{t('updates.bubble.checkedAt', { when: agoMinutes(state?.checkedAt) })}</p>
      {:else}
        <h3 class="bubble__title">{t('updates.bubble.title', { n: behind })}</h3>
        <p class="dev-small dev-muted">
          {t('updates.bubble.meta', { branch: state?.upstream?.branch ?? 'stable', oldest: agoDays(state?.oldestMissingAt), checked: agoMinutes(state?.checkedAt) })}
        </p>
        <ul class="bubble__commits">
          {#each state?.commits ?? [] as commit (commit.fullHash)}
            <li>
              <a class="bubble__hash" href={COMMIT_URL + commit.fullHash} target="_blank" rel="noopener" title={formatDateTime(commit.date)}>{commit.hash}</a>
              <span class="bubble__subject">{commit.subject}</span>
            </li>
          {/each}
        </ul>
        {#if behind > (state?.commits?.length ?? 0)}
          <p class="dev-small"><a href={state?.compareUrl} target="_blank" rel="noopener">{t('updates.bubble.more', { n: behind - (state?.commits?.length ?? 0) })}</a></p>
        {/if}
      {/if}

      <div class="bubble__check">
        <button type="button" class="bubble__link" disabled={updates.busy || checking} onclick={() => updates.checkNow()}>
          <Icon icon={checking ? iconSpinner : iconRefresh} size="sm" spin={checking} />{checking ? t('updates.bubble.checking') : t('updates.bubble.checkNow')}
        </button>
      </div>

      {#if kind === 'behind'}
        {#if failed}
          <div class="dev-alert dev-alert--danger bubble__alert">
            <span class="dev-alert__text">{t('updates.bubble.failed')} <a href={router.href('testingJob', { jobId: failed.id })} onclick={(e) => { closeBubble(false); onLinkClick(e); }}>{t('updates.bubble.progress')}</a></span>
          </div>
        {/if}
        {#if updates.actionError}
          <div class="dev-alert dev-alert--danger bubble__alert">
            <span class="dev-alert__text">
              {updates.actionError.text}
              {#if updates.actionError.jobId}
                · <a href={router.href('testingJob', { jobId: updates.actionError.jobId })} onclick={(e) => { closeBubble(false); onLinkClick(e); }}>{t('common.runningJob', { id: updates.actionError.jobId })}</a>
              {/if}
            </span>
          </div>
        {/if}
        {#if blockers.length > 0}
          <div class="dev-alert dev-alert--warning bubble__alert">
            <span class="dev-alert__text">
              {#each blockers as blocker (blocker.code)}
                <span class="bubble__blocker">{blockerText(blocker)}</span>
              {/each}
            </span>
          </div>
        {/if}
        {#if onStable}
          <p class="dev-small dev-muted bubble__note">{t('updates.bubble.note')}</p>
          <div class="dev-btnrow">
            <Button label={t('updates.bubble.update')} variant="primary" size="sm" icon={iconCloudDownload} disabled={!canUpdate || updates.busy} loading={updates.busy} onclick={run} />
            <a class="dev-btn-outline" href={state?.compareUrl} target="_blank" rel="noopener"><Icon icon={iconOpenExternal} size="sm" />{t('updates.bubble.github')}</a>
          </div>
        {/if}
      {/if}
    </div>
  </Popover>
{/if}

<style>
  .bubble {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xs);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
  }
  .bubble p { margin: 0; }
  .bubble__title {
    margin: 0;
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
  }
  .bubble__commits {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .bubble__commits li {
    display: flex;
    gap: var(--shpd-space-sm);
    align-items: baseline;
    min-width: 0;
  }
  .bubble__hash {
    flex: none;
    font-family: var(--dev-font-mono);
    color: var(--shpd-color-primary);
    text-decoration: none;
  }
  .bubble__hash:hover { text-decoration: underline; }
  .bubble__subject {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .bubble__check { display: flex; }
  .bubble__link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0;
    border: 0;
    background: none;
    color: var(--shpd-color-primary);
    font: inherit;
    cursor: pointer;
  }
  .bubble__link:hover { text-decoration: underline; }
  .bubble__link:disabled { color: var(--shpd-color-text-secondary); cursor: default; text-decoration: none; }
  /* Alerty v bublině bez vnějších okrajů karty. */
  .bubble :global(.bubble__alert) { margin: 0; padding: var(--shpd-space-sm) var(--shpd-space-md); }
  .bubble__blocker { display: block; }
  .bubble__blocker + .bubble__blocker { margin-top: 4px; }
  .bubble__note { padding-top: var(--shpd-space-xs); border-top: 1px solid var(--shpd-color-border-subtle); }
</style>
