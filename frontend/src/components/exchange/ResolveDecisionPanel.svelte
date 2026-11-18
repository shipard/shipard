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
  import Panel from '../ui/Panel.svelte';
  import OptionRow from '../ui/OptionRow.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconAdd } from '../../icons.js';
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
    // Napárovaná položka (#111 D5): „Napárováno automaticky: kód název
    // (zdroj)" nad hledáním — jen nad `matched` blokem položky; rodič text
    // složí (lokalizace zdroje), panel jen renderuje.
    automaticLabel = null,
    // Lidský popisek aktuální volby pro „Vybráno: …" (U4) — název zvolené
    // položky místo „Použít #id"; null = výchozí popis akce.
    currentLabel = null,
    onDecide = () => {},
    // Cesta rozhodované reference (`supplier`, `rows[3].item`) jen pro
    // testid tlačítka Vytvořit (`review-ref-create:{path}`, #103 F7a);
    // null = hromadný režim (`review-ref-create`). Logiku neřídí.
    path = null,
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

<div class="shpd-resolve" data-testid="review-ref-panel">
  {#if bulkCount > 0}
    <Panel variant="compact" as="p" class="shpd-resolve__hint">{t('exchange.preview.bulk.hint', { count: bulkCount })}</Panel>
  {/if}

  {#if automaticLabel}
    <Panel variant="compact" as="p" class="shpd-resolve__hint shpd-resolve__automatic">{automaticLabel}</Panel>
  {/if}

  {#if currentUserAction !== null && currentUserAction !== undefined}
    <Panel variant="compact" tone="info" class="shpd-resolve__current">
      <span>{t('exchange.preview.decide.selected', { label: currentLabelText })}</span>
      <button type="button" class="shpd-resolve__unselect" onclick={chooseUnselect}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </Panel>
  {:else if bulkCount > 0 && bulkDecidedCount > 0}
    <Panel variant="compact" tone="info" class="shpd-resolve__current">
      <span>{t('exchange.preview.bulk.decided', { count: bulkDecidedCount })}</span>
      <button type="button" class="shpd-resolve__unselect" onclick={chooseUnselect}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </Panel>
  {/if}

  {#if resolveBlock?.status === 'ambiguous' && candidates.length > 0}
    <div class="shpd-resolve__candidates">
      <div class="shpd-resolve__heading">
        {t('exchange.preview.decide.candidates')}
      </div>
      <div
        class="shpd-resolve__candidate-list"
        role="listbox"
        aria-label={t('exchange.preview.decide.candidates')}
      >
        {#each candidates as c (c.id)}
          <OptionRow
            size="compact"
            role="option"
            selected={currentUserAction === `useExisting:${c.id}`}
            onselect={() => chooseCandidate(c.id, c.name ?? null)}
          >
            <span class="shpd-resolve__candidate-id">#{c.id}</span>
            <span class="shpd-resolve__candidate-name">{c.name ?? '—'}</span>
          </OptionRow>
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
        <Icon icon={iconAdd} size="sm" />
        <span>{registryBusy
          ? t('exchange.preview.registry.creating')
          : t('exchange.preview.registry.quickAdd', { name: registryHit.fullName })}</span>
      </button>
      {#if registryError}
        <div class="shpd-resolve__status shpd-resolve__status--error">{registryError}</div>
      {/if}
    </div>
  {/if}

  {#if bankBlocked}
    <Panel variant="compact" as="p" class="shpd-resolve__hint">
      {t('exchange.preview.decide.bankRequiresSupplier')}
    </Panel>
    <div class="shpd-resolve__actions">
      <button
        type="button"
        class="shpd-resolve__create"
        onclick={chooseCreateViaApplier}
      >
        <Icon icon={iconAdd} size="sm" />
        <span>{createLabel}</span>
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
        data-testid={path ? `review-ref-create:${path}` : 'review-ref-create'}
        onclick={openCreateDialog}
      >
        <Icon icon={iconAdd} size="sm" />
        <span>{createLabel}</span>
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
    font-size: var(--shpd-font-size-body);
    /* Když Popover omezí výšku (shpd-popover--constrained je flex sloupec),
       panel se zmenší místo něj a zmenšení nese seznam výsledků — tlačítka
       Vytvořit / Vynechat zůstávají viditelná bez scrollování popoveru. */
    flex: 1 1 auto;
    min-height: 0;
  }

  /* `:global(*)` — přímým potomkem je i kořen sdíleného Panelu (nápověda,
     „Vybráno“), který třídu rozsahu této komponenty nenese. */
  .shpd-resolve > :global(*) {
    flex-shrink: 0;
  }

  /* „Aktuální volba“ je sdílený Panel (compact, tón info — #149 D75);
     třída nese jen rozložení a barvu textu. */
  .shpd-resolve :global(.shpd-resolve__current) {
    color: var(--shpd-color-primary-text);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-body);
  }

  .shpd-resolve__unselect {
    background: transparent;
    border: 0;
    color: inherit;
    text-decoration: underline;
    cursor: pointer;
    font-size: var(--shpd-font-size-meta);
    flex-shrink: 0;
  }

  .shpd-resolve__candidates {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
  }

  .shpd-resolve__heading {
    font-size: var(--shpd-font-size-meta);
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text-secondary);
    margin-bottom: 0.125rem;
  }

  .shpd-resolve__candidate-list {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
  }

  .shpd-resolve__candidate-id {
    font-family: var(--shpd-font-family-mono);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-meta);
  }

  /* Výška, písmo a rámeček ovládacího prvku z tokenů (#156 D106–D109). */
  .shpd-resolve__input {
    width: 100%;
    height: var(--shpd-control-height);
    padding: 0 var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border-control);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-field);
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
    max-height: 17.5rem;
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
    font-size: var(--shpd-font-size-body);
  }

  .shpd-resolve__status--error {
    color: var(--shpd-color-danger-text);
  }

  .shpd-resolve__item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.125rem;
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 0;
    background: transparent;
    color: var(--shpd-color-text);
    font-family: inherit;
    font-size: var(--shpd-font-size-body);
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

  .shpd-resolve__item-secondary {
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-resolve__actions {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  /* Tlačítka „vytvořit“ (nový záznam, rychlé založení z registru): „+“ je
     ikona, ne součást textu. Dlouhý popisek s názvem firmy se zalomí —
     ikona sedí na účaří prvního řádku, posunutá jako ikona v textu. */
  /* Tlačítko přes šířku panelu, popisek se smí zalomit: výška ovládacího
     prvku jako minimum, písmo a rámeček z tokenů (#156 D106–D109). */
  /* Mřížka místo flexu (#155): `align-items: baseline` drží ikonu na účaří
     prvního řádku i u zalomeného popisku, `align-content: center` dává
     řádek mřížky svisle doprostřed tlačítka — flex s baseline nechával
     jednořádkový popisek nahoře (na telefonu o 3 px). */
  .shpd-resolve__create {
    display: grid;
    grid-template-columns: auto 1fr;
    align-items: baseline;
    align-content: center;
    gap: var(--shpd-space-xs);
    width: 100%;
    box-sizing: border-box;
    min-height: var(--shpd-control-height);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border-control);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-primary-text);
    font-family: inherit;
    font-size: var(--shpd-font-size-control);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    text-align: left;
  }

  .shpd-resolve__create :global(.shpd-icon) {
    position: relative;
    top: 0.125em;
  }

  .shpd-resolve__create span {
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .shpd-resolve__create:hover {
    background-color: var(--shpd-color-primary-soft);
  }

  .shpd-resolve__skip,
  /* Tlačítko přes šířku panelu, popisek se smí zalomit: výška ovládacího
     prvku jako minimum, písmo a rámeček z tokenů (#156 D106–D109). */
  .shpd-resolve__registry-search {
    width: 100%;
    box-sizing: border-box;
    min-height: var(--shpd-control-height);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border-control);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text-secondary);
    font-family: inherit;
    font-size: var(--shpd-font-size-control);
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

  /* Nápověda je sdílený Panel (compact — #149 D75); třída nese jen písmo. */
  .shpd-resolve :global(.shpd-resolve__hint) {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-meta);
  }

  /* Napárovaná položka nad hledáním (#111 D5) — tišší než „Vybráno“,
     zalamuje dlouhé názvy. */
  .shpd-resolve :global(.shpd-resolve__automatic) {
    overflow-wrap: anywhere;
  }
</style>
