<script>
  // Verze v pruhu dashboardu jako tlačítko s bublinou (tasks/dev-dashboard-update.md
  // § 6, #129 D23–D25; nahrazuje UpdateChip z D19–D21). Tlačítko: ikona commitu,
  // `v0.1.1 · 6aaa2a47 · stable` (versionLabel), uvnitř neinteraktivní štítek
  // stavu podle chipKind („N pozadu“, plný nad 7 dní; „nelze ověřit“), šipka —
  // při běžící kontrole spinner. Bublina nad sdíleným Popover (vzor
  // DevKebabMenu: Esc, klik mimo, fokus zpět na tlačítko) se otevře v každém
  // stavu: verze, hash jako odkaz na commit, větev, datum commitu, řádek stavu
  // (statusKind), vždy Zkontrolovat teď; při stavu pozadu navíc commity,
  // pojistky a na větvi stable Aktualizovat. „aktualizuji…“ (odkaz na úlohu)
  // a Obnovit stránku jsou samostatné prvky vedle tlačítka. Data:
  // info.svelte.js (verze) a updates.svelte.js (stav); pojistky kreslí jen
  // UI — rozhoduje server (409).
  import { tick } from 'svelte';
  import Popover from '../../components/ui/Popover.svelte';
  import Button from '../../components/ui/Button.svelte';
  import Icon from '../../components/ui/Icon.svelte';
  import { iconRefresh, iconOpenExternal, iconSpinner, iconWarning, iconCloudDownload, iconCommit, iconChevronDown, iconConfirm, iconQuestion } from '../../icons.js';
  import { router, onLinkClick } from '../router.svelte.js';
  import { devInfo } from '../info.svelte.js';
  import { updates } from '../updates.svelte.js';
  import { chipKind, statusKind, daysSince, minutesSince, isOlderThanDays, blockerText, checkErrorText, versionAriaLabel } from '../updates.js';
  import { COMMIT_URL } from '../links.js';
  import { versionLabel, formatDateTime } from '../format.js';
  import { t } from '../i18n/index.js';

  let open = $state(false);
  let buttonEl = $state(null);
  let now = $state(Date.now());

  // Verze z /_dev/api/info: při načítání „…“, při chybě „?“ — tlačítko i tak otevře bublinu.
  const version = $derived(devInfo.value?.version ?? null);
  const pending = $derived(devInfo.error ? '?' : '…');
  const commitTitle = $derived(version?.commitDate ? t('app.stripe.commitTitle', { date: formatDateTime(version.commitDate) }) : t('app.stripe.commitTitleNoDate'));

  const status = $derived(updates.status);
  const state = $derived(status?.state ?? null);
  const job = $derived(updates.job);
  const kind = $derived(chipKind(status, job, updates.reloadNeeded)); // reload | updating | behind | unverified | null
  const badge = $derived(kind === 'behind' || kind === 'unverified' ? kind : null);
  const bubbleKind = $derived(statusKind(status)); // current | behind | unverified | unknown
  const behind = $derived(state?.behind ?? 0);
  const strong = $derived(badge === 'behind' && isOlderThanDays(state?.oldestMissingAt, now));
  const blockers = $derived(status?.blockers ?? []);
  const notStable = $derived(blockers.find((b) => b.code === 'notStable') ?? null);
  const canUpdate = $derived(Boolean(status?.canUpdate));
  const checking = $derived(Boolean(status?.checking));
  const updating = $derived(kind === 'updating');
  const branch = $derived(state?.upstream?.branch ?? 'stable');
  const failed = $derived(updates.finishedJob && updates.finishedJob.status !== 'finished' ? updates.finishedJob : null);

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
    if (open) closeBubble();
    else openBubble();
  }

  /** Odkaz routeru z bubliny: zavřít bez vrácení fokusu (stránka se mění) a navigovat. */
  function follow(e) {
    closeBubble(false);
    onLinkClick(e);
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

{#if updating}
  <a class="dev-stripe__chip" href={router.href('testingJob', { jobId: job.id })} onclick={onLinkClick} title={t('updates.bubble.progress')}>
    <Icon icon={iconSpinner} size="sm" spin />{t('updates.chip.updating')}
  </a>
{:else if kind === 'reload'}
  <button type="button" class="dev-stripe__chip dev-stripe__chip--strong" onclick={() => updates.reloadPage()}>
    <Icon icon={iconRefresh} size="sm" />{t('updates.chip.reload')}
  </button>
{/if}

<button
  type="button"
  class="dev-stripe__version-btn"
  bind:this={buttonEl}
  aria-haspopup="dialog"
  aria-expanded={open}
  aria-label={versionAriaLabel(version, badge, behind)}
  title={t('updates.version.hint')}
  onclick={toggle}
>
  <Icon icon={iconCommit} size="sm" />
  <span class="dev-stripe__version">{version ? versionLabel(version) : pending}</span>
  {#if badge === 'behind'}
    <span class="dev-stripe__badge" class:dev-stripe__badge--strong={strong}>{t('updates.badge.behind', { n: behind })}</span>
  {:else if badge === 'unverified'}
    <span class="dev-stripe__badge"><Icon icon={iconWarning} size="sm" />{t('updates.badge.unverified')}</span>
  {/if}
  <span class="dev-stripe__caret"><Icon icon={checking ? iconSpinner : iconChevronDown} size="sm" spin={checking} /></span>
</button>

{#if open}
  <Popover open={true} anchor={buttonEl} placement="bottom" width="440px" onClose={() => closeBubble()}>
    <div class="bubble" role="document">
      <div class="bubble__head">
        <h3 class="bubble__title">{t('updates.bubble.versionTitle', { version: version?.version ?? pending })}</h3>
        <p class="dev-small dev-muted bubble__meta">
          {#if version?.fullHash}
            <a class="bubble__hash" href={COMMIT_URL + version.fullHash} target="_blank" rel="noopener" title={commitTitle}>{version.hash}</a>
            <span>· {version.branch ?? t('updates.blocker.detached')}</span>
            {#if version.commitDate}<span>· {formatDateTime(version.commitDate)}</span>{/if}
          {:else if version}
            {t('updates.bubble.noGit')}
          {:else if devInfo.error}
            {t('updates.bubble.infoError')}
          {:else}
            {pending}
          {/if}
        </p>
      </div>

      {#if bubbleKind === 'current'}
        <p class="bubble__status bubble__status--ok"><Icon icon={iconConfirm} size="sm" /><span>{t('updates.bubble.current', { branch, checked: agoMinutes(state?.checkedAt) })}</span></p>
      {:else if bubbleKind === 'unverified'}
        <div>
          <p class="bubble__strong">{t('updates.bubble.unverifiedTitle')}</p>
          <p class="dev-small">{checkErrorText(state)}</p>
          <p class="dev-small dev-muted">{t('updates.bubble.checkedAt', { when: agoMinutes(state?.checkedAt) })}</p>
        </div>
      {:else if bubbleKind === 'behind'}
        <div>
          <p class="bubble__strong">{t('updates.bubble.title', { n: behind })}</p>
          <p class="dev-small dev-muted">
            {t('updates.bubble.meta', { branch, oldest: agoDays(state?.oldestMissingAt), checked: agoMinutes(state?.checkedAt) })}
          </p>
        </div>
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
      {:else}
        <p class="bubble__status dev-muted"><Icon icon={checking ? iconSpinner : iconQuestion} size="sm" spin={checking} /><span>{checking ? t('updates.bubble.unknownChecking') : t('updates.bubble.unknown')}</span></p>
      {/if}

      <!-- Jiná větev než stable mimo stav pozadu: porovnání je jen informativní, Aktualizovat se nenabízí. -->
      {#if bubbleKind !== 'behind' && notStable}
        <div class="dev-alert dev-alert--warning bubble__alert">
          <span class="dev-alert__text">{blockerText(notStable)}</span>
        </div>
      {/if}

      {#if failed}
        <div class="dev-alert dev-alert--danger bubble__alert">
          <span class="dev-alert__text">{t('updates.bubble.failed')} <a href={router.href('testingJob', { jobId: failed.id })} onclick={follow}>{t('updates.bubble.progress')}</a></span>
        </div>
      {/if}
      {#if updates.actionError}
        <div class="dev-alert dev-alert--danger bubble__alert">
          <span class="dev-alert__text">
            {updates.actionError.text}
            {#if updates.actionError.jobId}
              · <a href={router.href('testingJob', { jobId: updates.actionError.jobId })} onclick={follow}>{t('common.runningJob', { id: updates.actionError.jobId })}</a>
            {/if}
          </span>
        </div>
      {/if}

      <div class="bubble__check">
        <button type="button" class="bubble__link" disabled={updates.busy || checking || updating} onclick={() => updates.checkNow()}>
          <Icon icon={checking ? iconSpinner : iconRefresh} size="sm" spin={checking} />{checking ? t('updates.bubble.checking') : t('updates.bubble.checkNow')}
        </button>
        {#if updating}
          <a class="bubble__link" href={router.href('testingJob', { jobId: job.id })} onclick={follow}>
            <Icon icon={iconSpinner} size="sm" spin />{t('updates.bubble.progress')}
          </a>
        {/if}
      </div>

      {#if bubbleKind === 'behind'}
        {#if blockers.length > 0}
          <div class="dev-alert dev-alert--warning bubble__alert">
            <span class="dev-alert__text">
              {#each blockers as blocker (blocker.code)}
                <span class="bubble__blocker">{blockerText(blocker)}</span>
              {/each}
            </span>
          </div>
        {/if}
        {#if !notStable}
          <p class="dev-small dev-muted bubble__note">{t('updates.bubble.note')}</p>
          <div class="dev-btnrow">
            <Button label={t('updates.bubble.update')} variant="primary" size="sm" icon={iconCloudDownload} disabled={!canUpdate || updates.busy || updating} loading={updates.busy} onclick={run} />
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
  .bubble__head {
    padding-bottom: var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border-subtle);
  }
  .bubble__title {
    margin: 0;
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
  }
  .bubble__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }
  .bubble__strong { font-weight: 600; }
  .bubble__status {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .bubble__status--ok { color: var(--shpd-color-success); }
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
  .bubble__check {
    display: flex;
    gap: var(--shpd-space-lg);
  }
  .bubble__link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0;
    border: 0;
    background: none;
    color: var(--shpd-color-primary);
    font: inherit;
    text-decoration: none;
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
