<script>
  // Portálový přehled „Moje zdroje dat" — panel hlavní navigace
  // (tasks/hosting-07-portal-in-shell.md, D1/D2). Seznam „moje DS" se čte
  // výhradně z /_hosting/portal/my-datasources (server scopuje na session
  // uživatele); hosting tabulky jsou pro ne-adminy zavřené (D9).
  // Self-service zakládání DS (tasks/hosting-08-self-service-ds.md):
  // tlačítko + wizard dle create-meta, pending karty s pollingem.
  import { onMount, onDestroy } from 'svelte';
  import { fetchMyDatasources, fetchCreateMeta } from '../../api/portal.js';
  import { t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import Button from '../ui/Button.svelte';
  import NewDatasourceModal from './NewDatasourceModal.svelte';
  import { iconAdd, iconDatabase, iconOpenExternal, iconSpinner, iconSuccess, iconWarning } from '../../icons.js';

  let items = $state(null);   // null = načítá se
  let error = $state(false);
  let createMeta = $state(null);   // null = nenačteno / feature nedostupná
  let modalOpen = $state(false);

  // Snapshot starší než hodina se nevydává za aktuální — badge se nekreslí
  // vůbec (D7, tasks/hosting-06-stats.md).
  const STATS_FRESH_MS = 60 * 60 * 1000;

  // Polling pending karet (D5) — refetch po ~15 s, dokud existuje karta
  // „Připravuje se…"; po dokončení se limity změnily → refetch i create-meta.
  const POLL_MS = 15 * 1000;
  let pollTimer = null;

  function freshStats(ds) {
    if (!ds.stats?.collected_at) return null;
    // NaN neprojde; záporné age (drobný clock skew) je čerstvé.
    const age = Date.now() - Date.parse(ds.stats.collected_at);
    if (!(age < STATS_FRESH_MS)) return null;
    return ds.stats;
  }

  function statsTotal(stats) {
    return (stats.alerts ?? 0) + (stats.mail ?? 0);
  }

  function statsTitle(stats) {
    return t('portal.stats.tooltip', { alerts: stats.alerts ?? 0, mail: stats.mail ?? 0 });
  }

  async function load() {
    error = false;
    items = null;
    const result = await fetchMyDatasources();
    if (result === null) {
      error = true;
      items = [];
    } else {
      items = result;
    }
    schedulePoll();
  }

  async function loadCreateMeta() {
    const res = await fetchCreateMeta();
    // Selhání (starší server bez endpointu, síť) → tlačítko se nekreslí.
    createMeta = res?.success ? res.data : null;
  }

  // ── Polling pending karet ──────────────────────────────────────────────────

  function hasCreating(list) {
    return (list ?? []).some((ds) => ds.state === 'creating');
  }

  function schedulePoll() {
    if (pollTimer !== null) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }
    if (!hasCreating(items)) return;
    pollTimer = setTimeout(() => void refresh(), POLL_MS);
  }

  async function refresh() {
    pollTimer = null;
    const before = hasCreating(items);
    const result = await fetchMyDatasources();
    if (result !== null) {
      items = result;
      if (before && !hasCreating(items)) {
        // Požadavek doběhl (active/failed) — limity se změnily.
        void loadCreateMeta();
      }
    }
    schedulePoll();
  }

  onDestroy(() => {
    if (pollTimer !== null) clearTimeout(pollTimer);
    clearTimeout(toastTimer);
  });

  // ── Wizard ────────────────────────────────────────────────────────────────

  const canCreate = $derived(createMeta?.canCreate === true);
  // Bez default serveru je feature vypnutá — tlačítko se nekreslí vůbec (D7).
  const showCreateButton = $derived(createMeta !== null && createMeta.reason !== 'no_server');
  const createDisabledHint = $derived.by(() => {
    if (canCreate) return null;
    if (createMeta?.reason === 'open_request') return t('portal.create.reasonOpenRequest');
    if (createMeta?.reason === 'max_owned') return t('portal.create.reasonMaxOwned');
    return null;
  });

  function handleCreated(item) {
    modalOpen = false;
    if (item) {
      items = [item, ...(items ?? [])];
    }
    showToast(t('portal.create.toast'));
    schedulePoll();
    // Otevřený požadavek změnil limity (D6) — tlačítko hned zešedne.
    void loadCreateMeta();
  }

  // ── Toast (lokální — app nemá toast infra, vzor Dashboard.svelte) ─────────

  let toast = $state({ visible: false, message: '' });
  let toastTimer = null;

  function showToast(message) {
    clearTimeout(toastTimer);
    toast = { visible: true, message };
    toastTimer = setTimeout(dismissToast, 8000);
  }

  function dismissToast() {
    clearTimeout(toastTimer);
    toast = { visible: false, message: '' };
  }

  onMount(() => {
    void load();
    void loadCreateMeta();
  });
</script>

<div class="portal">
  <div class="portal__header">
    <h1 class="portal__title">{t('portal.subtitle')}</h1>
    {#if showCreateButton}
      <span title={createDisabledHint ?? undefined}>
        <Button
          label={t('portal.create.button')}
          icon={iconAdd}
          size="sm"
          disabled={!canCreate}
          onclick={() => (modalOpen = true)}
        />
      </span>
    {/if}
  </div>

  {#if items === null}
    <div class="portal__status">{t('portal.loading')}</div>
  {:else if error}
    <div class="portal__error">
      {t('portal.error')}
      <button class="portal__retry" type="button" onclick={load}>
        {t('portal.retry')}
      </button>
    </div>
  {:else if items.length === 0}
    <div class="portal__status">
      {t('portal.empty')}
      {#if showCreateButton && canCreate}
        <div class="portal__empty-cta">
          <Button
            label={t('portal.create.button')}
            icon={iconAdd}
            size="sm"
            onclick={() => (modalOpen = true)}
          />
        </div>
      {/if}
    </div>
  {:else}
    <div class="portal__grid">
      {#each items as ds (ds.id)}
        {@const stats = freshStats(ds)}
        <div class="portal__card" class:portal__card--pending={ds.state === 'creating' || ds.state === 'failed'}>
          <div class="portal__card-icon">
            <Icon icon={iconDatabase} size="lg" />
          </div>
          <div class="portal__card-body">
            <div class="portal__card-name">
              {ds.name}
              {#if ds.role === 'admin'}
                <span class="portal__badge">{t('portal.role.admin')}</span>
              {/if}
            </div>
            {#if ds.state === 'creating'}
              <div class="portal__card-stats">
                <span class="portal__creating">
                  <span class="portal__spinner"><Icon icon={iconSpinner} size="sm" /></span>
                  {t('portal.state.creating')}
                </span>
              </div>
            {:else if ds.state === 'failed'}
              <div class="portal__card-stats">
                <span class="portal__failed">
                  <Icon icon={iconWarning} size="sm" />
                  {t('portal.state.failed')}
                </span>
                <span class="portal__failed-hint">{t('portal.state.failedHint')}</span>
              </div>
            {:else if stats}
              <div class="portal__card-stats" title={statsTitle(stats)}>
                {#if statsTotal(stats) > 0}
                  <span class="portal__pending">{t('portal.stats.pending', { count: statsTotal(stats) })}</span>
                {:else}
                  <span class="portal__all-done">
                    <Icon icon={iconSuccess} size="sm" />
                    {t('portal.stats.allDone')}
                  </span>
                {/if}
              </div>
            {/if}
          </div>
          {#if ds.state !== 'creating' && ds.state !== 'failed'}
            <a
              class="portal__enter"
              href={ds.url_app}
              target="_blank"
              rel="noopener"
            >
              {t('portal.enter')}
              <Icon icon={iconOpenExternal} size="sm" />
            </a>
          {/if}
        </div>
      {/each}
    </div>
  {/if}
</div>

<NewDatasourceModal
  open={modalOpen}
  meta={createMeta}
  onClose={() => (modalOpen = false)}
  onCreated={handleCreated}
/>

{#if toast.visible}
  <div class="shpd-toast" role="status">
    <span class="shpd-toast__msg">{toast.message}</span>
    <button type="button" class="shpd-toast__close" onclick={dismissToast} aria-label={t('common.close')}>×</button>
  </div>
{/if}

<style>
  .portal {
    width: 100%;
    max-width: 720px;
    margin: 0 auto;
    padding: var(--shpd-space-xl);
  }

  .portal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--shpd-space-md);
    margin-bottom: var(--shpd-space-lg);
  }

  .portal__title {
    margin: 0;
    font-size: var(--shpd-font-size-lg);
    font-weight: 700;
    color: var(--shpd-color-text);
  }

  .portal__status {
    padding: var(--shpd-space-xl);
    text-align: center;
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text-secondary);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-lg);
  }

  .portal__empty-cta {
    margin-top: var(--shpd-space-md);
  }

  .portal__error {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-xl);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    background-color: var(--shpd-color-danger-soft);
    border: 1px solid var(--shpd-color-danger);
    border-radius: var(--shpd-radius-lg);
  }

  .portal__retry {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    cursor: pointer;
  }

  .portal__grid {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .portal__card {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-md) var(--shpd-space-lg);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-sm);
  }

  .portal__card--pending {
    opacity: 0.85;
  }

  .portal__card-icon {
    flex-shrink: 0;
    color: var(--shpd-color-text-secondary);
  }

  .portal__card-body {
    flex: 1;
    min-width: 0;
  }

  .portal__card-name {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .portal__badge {
    padding: 0.0625rem var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-accent);
    background-color: var(--shpd-color-accent-soft);
    border-radius: var(--shpd-radius-full);
  }

  .portal__card-stats {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
  }

  .portal__pending {
    display: inline-block;
    padding: 0.0625rem var(--shpd-space-sm);
    font-weight: 500;
    color: var(--shpd-color-accent);
    background-color: var(--shpd-color-accent-soft);
    border-radius: var(--shpd-radius-full);
  }

  .portal__creating {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: 0.0625rem var(--shpd-space-sm);
    font-weight: 500;
    color: var(--shpd-color-accent);
    background-color: var(--shpd-color-accent-soft);
    border-radius: var(--shpd-radius-full);
  }

  .portal__spinner {
    display: inline-flex;
    animation: portal-spin 1s linear infinite;
  }

  @keyframes portal-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }

  .portal__failed {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: 0.0625rem var(--shpd-space-sm);
    font-weight: 500;
    color: var(--shpd-color-danger);
    background-color: var(--shpd-color-danger-soft);
    border-radius: var(--shpd-radius-full);
  }

  .portal__failed-hint {
    color: var(--shpd-color-text-secondary);
  }

  .portal__all-done {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    color: var(--shpd-color-success);
  }

  .portal__enter {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: #ffffff;
    background-color: var(--shpd-color-primary);
    border-radius: var(--shpd-radius-md);
    text-decoration: none;
    transition: background-color 0.15s;
  }

  .portal__enter:hover {
    background-color: var(--shpd-color-primary-hover);
  }

  /* Minimální toast — fixed dole na střed, auto-dismiss ~8 s
     (vzor Dashboard.svelte — app nemá sdílenou toast infra). */
  .shpd-toast {
    position: fixed;
    left: 50%;
    bottom: var(--shpd-space-lg);
    transform: translateX(-50%);
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    max-width: min(90vw, 560px);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background: var(--shpd-color-text);
    color: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-md);
    box-shadow: var(--shpd-shadow-lg, 0 4px 16px rgba(0, 0, 0, 0.25));
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-toast__msg {
    flex: 1;
    min-width: 0;
  }

  .shpd-toast__close {
    flex-shrink: 0;
    border: none;
    background: none;
    color: var(--shpd-color-bg);
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 var(--shpd-space-xs);
    opacity: 0.7;
  }

  .shpd-toast__close:hover {
    opacity: 1;
  }
</style>
