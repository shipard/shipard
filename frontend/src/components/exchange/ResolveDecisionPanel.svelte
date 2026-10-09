<script>
  // Inline resolve-decision panel rendered inside a Popover anchored to a
  // clicked status badge in DocumentExchangePreview. It is a compact
  // typeahead — search input + result list (max 8) + "create new" button
  // — driving `_resolve.*.userAction` decisions.
  //
  // Outcomes (via `onDecide(action, meta)`; `meta.label` = lidský název
  // zvoleného záznamu pro optimistický štítek, než nový náhled přinese
  // `display` ze serveru — #111 D3; chybí, když ho panel nezná):
  //   - pick a result            → onDecide(`useExisting:${item.id}`, {label: item.primary})
  //   - "create new" → save      → onDecide(`useExisting:${newId}`, {label})  (real id, no side-create)
  //   - "skip row"  (items only) → onDecide('skip')
  //   - "account only" (items with account) → onDecide('noItem')
  //   - "create"   (bank only)   → onDecide('create')  // fallback when no parent
  //   - clear current selection  → onDecide(null)
  //
  // Search hits the shared lookup endpoint
  //   GET /_ui/lookup/{table}/search?q=…&limit=8[&filter[person]=…]
  // The endpoint owns the primary/secondary display formatting — this panel
  // does not need per-table searchFields/displayPattern.
  //
  // Special bankAccount behaviour: BankAccountsLookup requires
  // `filter[person]`, so the panel only shows search + create when
  // `parentMatchedId` is known. Otherwise it just shows a hint nudging the
  // user to decide on the supplier first.

  import { onMount } from 'svelte';
  import { get } from '../../api/client.js';
  import { t } from '../../i18n/index.js';
  import FormDialog from '../form/FormDialog.svelte';

  let {
    resolveBlock,
    referenceKind = 'party',            // 'party' | 'item' | 'bankAccount'
    entityTable = 'base_persons_persons',
    createPayload = null,
    currentUserAction = null,
    parentMatchedId = null,
    // Bulk režim (Issue #29): bulkCount > 0 ⇒ rozhodnutí se aplikuje na
    // bulkCount řádků naráz; bulkDecidedCount = kolik z nich už nějaké
    // rozhodnutí má (řídí zobrazení hromadného „Zrušit výběr“).
    bulkCount = 0,
    bulkDecidedCount = 0,
    // „Jen účet — bez položky" (D24) — jen referenceKind 'item' a jen když
    // řádek (u bulku všechny řádky) nese účet; rozhoduje rodič.
    allowNoItem = false,
    // Napárovaná položka (#111 D5): „Napárováno automaticky: kód název
    // (zdroj)" nad hledáním — jen nad `matched` blokem položky; rodič text
    // složí (lokalizace zdroje), panel jen renderuje.
    automaticLabel = null,
    // Lidský popisek aktuální volby pro „Vybráno: …" (U4) — název zvolené
    // položky místo „Použít #id"; null = výchozí popis akce.
    currentLabel = null,
    onDecide = () => {},
    // Quick-add z registru (Issue #28) — jen referenceKind === 'party'.
    // Stav i logika žijí v DocumentExchangePreview (sdílené s kartou strany),
    // panel jen renderuje.
    registryHit = null,          // SearchResultRow | null
    registryBusy = false,
    registryError = null,        // lokalizovaná chyba posledního pokusu
    onRegistryQuickAdd = null,   // () => void
    onOpenRegistrySearch = null, // () => void — otevře wizard, zavře popover
  } = $props();

  let searchTerm = $state('');
  /** @type {Array<{id:number|string, primary:string, secondary:string|null}>} */
  let results = $state([]);
  let loading = $state(false);
  let lastError = $state(null);
  let activeIndex = $state(-1);
  let debounceTimer = null;
  /** Token of the latest fetch — older responses are dropped. */
  let currentFetchToken = 0;

  let createDialogOpen = $state(false);

  // ── Derived ─────────────────────────────────────────────────────────────

  const candidates = $derived(resolveBlock?.candidates ?? []);

  // For bankAccount we need a person FK to search and to create. Without it
  // the panel collapses to a hint.
  const bankBlocked = $derived(
    referenceKind === 'bankAccount' && (parentMatchedId === null || parentMatchedId === undefined),
  );

  const createLabel = $derived(
    referenceKind === 'item'
      ? t('exchange.preview.decide.createItem')
      : referenceKind === 'bankAccount'
        ? t('exchange.preview.decide.createBankAccount')
        : t('exchange.preview.decide.createParty'),
  );

  // Pre-fill: pass-through createPayload (snake_case keys match DB columns
  // for base_persons_persons / economy_items / base_persons_bank_accounts).
  // For bank we also inject the resolved supplier id as `person` FK.
  const createDefaults = $derived.by(() => {
    const base = createPayload && typeof createPayload === 'object' ? { ...createPayload } : {};
    if (referenceKind === 'bankAccount' && parentMatchedId != null && base.person == null) {
      base.person = parentMatchedId;
    }
    return base;
  });

  const currentLabelText = $derived(currentLabel ?? formatCurrentUserAction(currentUserAction));

  function formatCurrentUserAction(action) {
    if (action === null || action === undefined) return '';
    if (typeof action === 'string' && action.startsWith('useExisting:')) {
      return t('exchange.preview.decide.useCandidate', { id: action.slice('useExisting:'.length) });
    }
    if (action === 'create') return t('exchange.preview.decide.create');
    if (action === 'skip') return t('exchange.preview.decide.skip');
    if (action === 'noItem') return t('exchange.preview.decide.noItem');
    return String(action);
  }

  // ── Search ──────────────────────────────────────────────────────────────

  function buildSearchUrl(term) {
    const params = new URLSearchParams();
    params.set('q', term);
    params.set('limit', '8');
    if (referenceKind === 'bankAccount' && parentMatchedId != null) {
      params.set('filter[person]', String(parentMatchedId));
    }
    return `/_ui/lookup/${entityTable}/search?${params.toString()}`;
  }

  async function runSearch(term) {
    if (!entityTable || bankBlocked) {
      results = [];
      loading = false;
      return;
    }
    const myToken = ++currentFetchToken;
    loading = true;
    lastError = null;
    const res = await get(buildSearchUrl(term));
    if (myToken !== currentFetchToken) return;
    loading = false;
    if (res === null) {
      results = [];
      lastError = t('common.unknownError');
      return;
    }
    if (!res.success) {
      results = [];
      lastError = res.error?.message ?? t('exchange.preview.decide.errorPrefix');
      return;
    }
    results = res.data?.items ?? [];
    activeIndex = results.length > 0 ? 0 : -1;
  }

  function scheduleSearch(term) {
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(() => {
      debounceTimer = null;
      runSearch(term);
    }, 250);
  }

  function handleInput(e) {
    searchTerm = e.currentTarget.value;
    scheduleSearch(searchTerm);
  }

  function handleKeydown(e) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (results.length > 0) {
        activeIndex = (activeIndex + 1) % results.length;
      }
      return;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (results.length > 0) {
        activeIndex = activeIndex <= 0 ? results.length - 1 : activeIndex - 1;
      }
      return;
    }
    if (e.key === 'Enter') {
      if (activeIndex >= 0 && results[activeIndex]) {
        e.preventDefault();
        chooseResult(results[activeIndex]);
      }
      return;
    }
    // Escape bubbles up to Popover's keydown handler → onClose.
  }

  // ── Actions ─────────────────────────────────────────────────────────────

  function chooseResult(item) {
    onDecide(`useExisting:${item.id}`, { label: item.primary ?? null });
  }

  function chooseCandidate(id, name = null) {
    onDecide(`useExisting:${id}`, { label: name ?? null });
  }

  function chooseSkip() {
    onDecide('skip');
  }

  function chooseNoItem() {
    onDecide('noItem');
  }

  function chooseUnselect() {
    onDecide(null);
  }

  function chooseCreateViaApplier() {
    // Fallback for bankAccount when supplier is not yet matched/decided.
    // Backend runSideCreates attaches the new account to the freshly
    // created supplier inside the apply transaction.
    onDecide('create');
  }

  function openCreateDialog() {
    if (bankBlocked) return;
    createDialogOpen = true;
  }

  function handleCreateClose() {
    createDialogOpen = false;
  }

  function handleCreated(record) {
    const newId = record?.id ?? record?.data?.id ?? null;
    if (newId == null) return;
    createDialogOpen = false;
    const saved = record?.data && typeof record.data === 'object' ? record.data : record;
    const code = typeof saved?.code === 'string' ? saved.code.trim() : '';
    const name = typeof saved?.name === 'string' ? saved.name.trim() : '';
    const label = code && name ? `${code} \u2014 ${name}` : (name || code || null);
    onDecide(`useExisting:${newId}`, { label });
  }

  // ── Lifecycle ──────────────────────────────────────────────────────────

  onMount(() => {
    if (!bankBlocked) {
      runSearch('');
    }
  });
</script>

<div class="shpd-resolve">
  {#if bulkCount > 0}
    <p class="shpd-resolve__hint">{t('exchange.preview.bulk.hint', { count: bulkCount })}</p>
  {/if}

  {#if automaticLabel}
    <p class="shpd-resolve__hint shpd-resolve__automatic">{automaticLabel}</p>
  {/if}

  {#if currentUserAction !== null && currentUserAction !== undefined}
    <div class="shpd-resolve__current">
      <span>{t('exchange.preview.decide.selected', { label: currentLabelText })}</span>
      <button type="button" class="shpd-resolve__unselect" onclick={chooseUnselect}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </div>
  {:else if bulkCount > 0 && bulkDecidedCount > 0}
    <div class="shpd-resolve__current">
      <span>{t('exchange.preview.bulk.decided', { count: bulkDecidedCount })}</span>
      <button type="button" class="shpd-resolve__unselect" onclick={chooseUnselect}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </div>
  {/if}

  {#if resolveBlock?.status === 'ambiguous' && candidates.length > 0}
    <div class="shpd-resolve__candidates">
      <div class="shpd-resolve__heading">
        {t('exchange.preview.decide.candidates')}
      </div>
      <div class="shpd-resolve__candidate-list">
        {#each candidates as c (c.id)}
          <button
            type="button"
            class="shpd-resolve__candidate"
            onclick={() => chooseCandidate(c.id, c.name ?? null)}
          >
            <span class="shpd-resolve__candidate-id">#{c.id}</span>
            <span class="shpd-resolve__candidate-name">{c.name ?? '—'}</span>
          </button>
        {/each}
      </div>
    </div>
  {/if}

  {#if referenceKind === 'party' && registryHit && onRegistryQuickAdd}
    <div class="shpd-resolve__registry">
      <button
        type="button"
        class="shpd-resolve__create"
        onclick={onRegistryQuickAdd}
        disabled={registryBusy}
      >
        + {registryBusy
          ? t('exchange.preview.registry.creating')
          : t('exchange.preview.registry.quickAdd', { name: registryHit.fullName })}
      </button>
      {#if registryError}
        <div class="shpd-resolve__status shpd-resolve__status--error">{registryError}</div>
      {/if}
    </div>
  {/if}

  {#if bankBlocked}
    <p class="shpd-resolve__hint">
      {t('exchange.preview.decide.bankRequiresSupplier')}
    </p>
    <div class="shpd-resolve__actions">
      <button
        type="button"
        class="shpd-resolve__create"
        onclick={chooseCreateViaApplier}
      >
        + {createLabel}
      </button>
    </div>
  {:else}
    <input
      type="text"
      class="shpd-resolve__input"
      placeholder={t('exchange.preview.decide.searchPlaceholder')}
      value={searchTerm}
      oninput={handleInput}
      onkeydown={handleKeydown}
    />

    <div class="shpd-resolve__results">
      {#if loading}
        <div class="shpd-resolve__status">{t('exchange.preview.decide.loading')}</div>
      {:else if lastError}
        <div class="shpd-resolve__status shpd-resolve__status--error">
          {t('exchange.preview.decide.errorPrefix')}{lastError}
        </div>
      {:else if results.length === 0}
        <div class="shpd-resolve__status">{t('exchange.preview.decide.empty')}</div>
      {:else}
        {#each results as item, i (item.id)}
          <button
            type="button"
            class="shpd-resolve__item"
            class:shpd-resolve__item--active={i === activeIndex}
            onmouseenter={() => (activeIndex = i)}
            onclick={() => chooseResult(item)}
          >
            <span class="shpd-resolve__item-primary">{item.primary}</span>
            {#if item.secondary}
              <span class="shpd-resolve__item-secondary">{item.secondary}</span>
            {/if}
          </button>
        {/each}
      {/if}
    </div>

    <div class="shpd-resolve__actions">
      <button
        type="button"
        class="shpd-resolve__create"
        onclick={openCreateDialog}
      >
        + {createLabel}
      </button>
      {#if referenceKind === 'party' && onOpenRegistrySearch}
        <button
          type="button"
          class="shpd-resolve__registry-search"
          onclick={onOpenRegistrySearch}
        >
          {t('exchange.preview.registry.search')}
        </button>
      {/if}
      {#if referenceKind === 'item' && allowNoItem}
        <button
          type="button"
          class="shpd-resolve__skip"
          onclick={chooseNoItem}
        >
          {t('exchange.preview.decide.noItem')}
        </button>
      {/if}
      {#if referenceKind === 'item'}
        <button
          type="button"
          class="shpd-resolve__skip"
          onclick={chooseSkip}
        >
          {bulkCount > 0
            ? t('exchange.preview.bulk.skipRows')
            : t('exchange.preview.decide.skipRow')}
        </button>
      {/if}
    </div>
  {/if}
</div>

<FormDialog
  table={entityTable}
  recordId={null}
  open={createDialogOpen}
  defaultData={createDefaults}
  onClose={handleCreateClose}
  onSaved={handleCreated}
/>

<style>
  .shpd-resolve {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    font-size: 0.875rem;
    /* Když Popover omezí výšku (shpd-popover--constrained je flex sloupec),
       panel se zmenší místo něj a zmenšení nese seznam výsledků — tlačítka
       Vytvořit / Vynechat zůstávají viditelná bez scrollování popoveru. */
    flex: 1 1 auto;
    min-height: 0;
  }

  .shpd-resolve > * {
    flex-shrink: 0;
  }

  .shpd-resolve__current {
    background-color: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--shpd-space-sm);
    font-size: 0.8125rem;
  }

  .shpd-resolve__unselect {
    background: transparent;
    border: 0;
    color: inherit;
    text-decoration: underline;
    cursor: pointer;
    font-size: 0.75rem;
    flex-shrink: 0;
  }

  .shpd-resolve__candidates {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .shpd-resolve__heading {
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--shpd-color-text-secondary);
    letter-spacing: 0.5px;
    margin-bottom: 2px;
  }

  .shpd-resolve__candidate-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .shpd-resolve__candidate {
    display: flex;
    align-items: baseline;
    gap: var(--shpd-space-xs);
    width: 100%;
    text-align: left;
    background: transparent;
    border: 1px solid var(--shpd-color-border);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    color: var(--shpd-color-text);
    font-size: 0.8125rem;
  }

  .shpd-resolve__candidate:hover {
    background-color: var(--shpd-color-primary-soft);
  }

  .shpd-resolve__candidate-id {
    font-family: var(--shpd-font-mono, monospace);
    color: var(--shpd-color-text-secondary);
    font-size: 0.75rem;
  }

  .shpd-resolve__input {
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    font-size: 0.875rem;
    font-family: inherit;
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    box-sizing: border-box;
  }

  .shpd-resolve__input:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus, var(--shpd-color-primary));
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring, var(--shpd-color-primary-soft));
  }

  .shpd-resolve__results {
    display: flex;
    flex-direction: column;
    max-height: 280px;
    /* Jediný pružný prvek panelu — zmenšuje se, když je popover omezený
       výškou (viz .shpd-resolve). */
    flex: 1 1 auto;
    overflow-y: auto;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
  }

  /* V omezeném popoveru nepustit seznam pod ≈ 3 řádky výsledků. V běžném
     stavu min-height nenastavujeme, aby stav „Načítám…“ / „Nic nenalezeno“
     zůstal kompaktní. */
  :global(.shpd-popover--constrained) .shpd-resolve__results {
    min-height: 6rem;
  }

  .shpd-resolve__status {
    padding: var(--shpd-space-sm);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    font-size: 0.8125rem;
  }

  .shpd-resolve__status--error {
    color: var(--shpd-color-danger);
  }

  .shpd-resolve__item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 0;
    background: transparent;
    color: var(--shpd-color-text);
    font-family: inherit;
    font-size: 0.8125rem;
    cursor: pointer;
    text-align: left;
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-resolve__item:last-child {
    border-bottom: 0;
  }

  .shpd-resolve__item--active {
    background-color: var(--shpd-color-primary-soft);
  }

  .shpd-resolve__item:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-resolve__item-primary {
    font-weight: 500;
  }

  .shpd-resolve__item-secondary {
    font-size: 0.75rem;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-resolve__actions {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-resolve__create {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-primary);
    font-family: inherit;
    font-size: 0.8125rem;
    font-weight: 500;
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    text-align: left;
  }

  .shpd-resolve__create:hover {
    background-color: var(--shpd-color-primary-soft);
  }

  .shpd-resolve__skip,
  .shpd-resolve__registry-search {
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text-secondary);
    font-family: inherit;
    font-size: 0.8125rem;
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    text-align: left;
  }

  .shpd-resolve__skip:hover,
  .shpd-resolve__registry-search:hover {
    background-color: var(--shpd-color-bg-secondary);
    color: var(--shpd-color-text);
  }

  .shpd-resolve__registry {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-resolve__create:disabled {
    cursor: default;
    opacity: 0.7;
  }

  .shpd-resolve__hint {
    margin: 0;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background-color: var(--shpd-color-bg-secondary);
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-secondary);
    font-size: 0.8125rem;
  }

  /* Napárovaná položka nad hledáním (#111 D5) — tišší než „Vybráno“,
     zalamuje dlouhé názvy. */
  .shpd-resolve__automatic {
    overflow-wrap: anywhere;
  }
</style>
