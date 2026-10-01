<script>
  import { getContext } from 'svelte';
  import { get, post } from '../../api/client.js';
  import { FORM_DATA_CONTEXT } from '../form/formContext.js';
  import { t } from '../../i18n/index.js';
  import Icon from './Icon.svelte';
  import { iconEdit, iconAdd } from '../../icons.js';
  // Přímý import FormDialog — stejný vzor jako FormSubTable.svelte. Vite zvládá
  // cyklickou závislost (FormDialog → FormEditor → … → LookupInput → FormDialog),
  // protože komponentu instancuje až runtime, kdy už jsou moduly nahrané.
  import FormDialog from '../form/FormDialog.svelte';

  let {
    id,
    /** Aktuální hodnota (FK id, int|string|null). Bindable. */
    value = $bindable(null),
    /** Iniciální display popis z dataResolved — `{id, primary, secondary}`. */
    resolved = $bindable(null),
    /** Lookup konfigurace — `{table, filter, edit_form?, create_form?, create_defaults?}`. */
    lookup,
    required = false,
    disabled = false,
    error = null,
    placeholder = '',
    /** Voláno po jakékoli změně value (výběr, clear, create). */
    onchange,
    /** Voláno po výběru / clearu — předává nově resolved popis nebo null. */
    onResolveChange,
  } = $props();

  let inputEl = $state(null);
  let open = $state(false);
  let searchTerm = $state('');
  /** @type {Array<{id:number|string, primary:string, secondary:string|null}>} */
  let results = $state([]);
  let loading = $state(false);
  let lastError = $state(null);
  let activeIndex = $state(-1);
  let debounceTimer = null;
  /** Token nejnovějšího fetche — starší odpovědi ignoruje. */
  let currentFetchToken = 0;

  // ── Sub-form dialog state ────────────────────────────────────────────────
  // Otevírá vnořený FormDialog pro editaci aktuálního výběru (edit) nebo
  // založení nového záznamu v cílové tabulce (create). Modal stack v
  // Modal.svelte řeší Esc — vnořený dialog má prioritu nad rodičovským.
  let subDialogOpen = $state(false);
  let subDialogMode = $state(null); // 'edit' | 'create' | null
  let subDialogRecordId = $state(null);
  // Prefill nového záznamu z rodičovského formuláře (lookup.create_defaults).
  let subDialogDefaults = $state({});
  // Data formuláře, ve kterém pole je, a jeho rodiče (formContext.js);
  // mimo FormEditor null — pak se výchozí hodnoty neposílají.
  const formContext = getContext(FORM_DATA_CONTEXT) ?? null;

  const displayLabel = $derived(resolved?.primary ?? '');
  const hasValue = $derived(value !== null && value !== '' && value !== undefined);
  const canEdit = $derived(!!lookup?.edit_form && hasValue && !disabled);
  const canCreate = $derived(!!lookup?.create_form && !disabled);

  function buildSearchUrl(term) {
    const params = new URLSearchParams();
    params.set('q', term);
    if (lookup?.filter) {
      for (const [k, v] of Object.entries(lookup.filter)) {
        if (v !== null && v !== undefined) {
          params.set(`filter[${k}]`, String(v));
        }
      }
    }
    return `/_ui/lookup/${lookup.table}/search?${params.toString()}`;
  }

  async function runSearch(term) {
    if (!lookup?.table) {
      results = [];
      loading = false;
      return;
    }
    const myToken = ++currentFetchToken;
    loading = true;
    lastError = null;
    const res = await get(buildSearchUrl(term));
    // Starší fetch overlap: odpověď zahodíme, novější už běží.
    if (myToken !== currentFetchToken) {
      return;
    }
    loading = false;
    if (res === null) {
      lastError = t('common.unknownError');
      results = [];
      return;
    }
    if (!res.success) {
      lastError = res.error?.message ?? t('lookup.error');
      results = [];
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
    }, 300);
  }

  function openDropdown() {
    if (disabled || open) return;
    open = true;
    // Vyplň searchTerm aktuálním display labelem (jméno vybraného záznamu),
    // ať uživatel po fokusu vidí, kdo je vybraný. Po renderu text vyselectujeme,
    // aby první stisk klávesy nahradil celé jméno (standardní combobox UX).
    // Pokud nějaké value není, displayLabel = '' — chovaní jako předtím.
    searchTerm = displayLabel;
    // Iniciální načtení — prázdné q = první stránka záznamů.
    runSearch('');
    // Select all v inputu — odložit do mikrotasku, až Svelte propiše value.
    queueMicrotask(() => {
      inputEl?.select();
    });
  }

  function closeDropdown() {
    open = false;
    searchTerm = '';
    activeIndex = -1;
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer);
      debounceTimer = null;
    }
  }

  function handleFocus() {
    openDropdown();
  }

  function handleInput(e) {
    if (disabled) return;
    if (!open) {
      open = true;
    }
    searchTerm = e.currentTarget.value;
    scheduleSearch(searchTerm);
  }

  function handleSelect(item) {
    value = item.id;
    resolved = { id: item.id, primary: item.primary, secondary: item.secondary ?? null };
    onResolveChange?.(resolved);
    onchange?.();
    closeDropdown();
    inputEl?.focus();
  }

  function handleClear() {
    if (disabled || !hasValue) return;
    value = null;
    resolved = null;
    onResolveChange?.(null);
    onchange?.();
    inputEl?.focus();
  }

  function handleKeydown(e) {
    if (disabled) return;

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!open) {
        openDropdown();
        return;
      }
      if (results.length > 0) {
        activeIndex = (activeIndex + 1) % results.length;
      }
      return;
    }

    if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (!open) return;
      if (results.length > 0) {
        activeIndex = activeIndex <= 0 ? results.length - 1 : activeIndex - 1;
      }
      return;
    }

    if (e.key === 'Enter') {
      if (!open || activeIndex < 0 || !results[activeIndex]) return;
      e.preventDefault();
      handleSelect(results[activeIndex]);
      return;
    }

    if (e.key === 'Escape') {
      if (open) {
        e.preventDefault();
        closeDropdown();
      }
      return;
    }

    if (e.key === 'Tab') {
      // Tab opouští pole — zavři dropdown, ale nech default chování proběhnout
      // (žádný preventDefault), aby fokus přešel na další pole formuláře.
      // Položky dropdownu mají tabindex=-1, takže do nich Tab nemůže zabloudit.
      if (open) {
        closeDropdown();
      }
      return;
    }

    if (e.key === 'Backspace' && searchTerm === '' && hasValue) {
      e.preventDefault();
      handleClear();
    }
  }

  function handleDocumentClick(e) {
    if (!open) return;
    const root = inputEl?.closest('.shpd-lookup');
    if (root && !root.contains(e.target)) {
      closeDropdown();
    }
  }

  $effect(() => {
    if (open) {
      document.addEventListener('mousedown', handleDocumentClick);
      return () => document.removeEventListener('mousedown', handleDocumentClick);
    }
  });

  // ── Sub-form handlers ────────────────────────────────────────────────────

  function handleEdit() {
    if (!canEdit) return;
    // Zavři dropdown, ať modal nepřebíjí vizuálně s otevřenou roletkou.
    closeDropdown();
    subDialogMode = 'edit';
    subDialogRecordId = value;
    subDialogOpen = true;
  }

  async function handleCreate() {
    if (!canCreate) return;
    closeDropdown();
    // Výchozí hodnoty se načtou PŘED otevřením — FormEditor čte defaultData
    // jen při prvním loadu nového záznamu.
    subDialogDefaults = await fetchCreateDefaults();
    subDialogMode = 'create';
    subDialogRecordId = null;
    subDialogOpen = true;
  }

  /**
   * Výchozí hodnoty nového záznamu podle formuláře, ze kterého se zakládá
   * (server: TableLookup::createDefaults). Chyba ani chybějící context
   * založení neblokují — formulář se otevře prázdný.
   */
  async function fetchCreateDefaults() {
    if (!lookup?.create_defaults || !lookup?.table || formContext === null) return {};
    const res = await post(`/_ui/lookup/${lookup.table}/create-defaults`, {
      row: formContext.data?.() ?? {},
      head: formContext.parent?.data?.() ?? {},
    });
    const defaults = res?.success ? res.data?.defaults : null;
    return defaults && typeof defaults === 'object' && !Array.isArray(defaults) ? defaults : {};
  }

  function handleSubDialogClose() {
    subDialogOpen = false;
    subDialogMode = null;
    subDialogRecordId = null;
    subDialogDefaults = {};
  }

  async function handleSubDialogSaved(record) {
    // FormEditor.save vrací { id, data, dataResolved }. ID najdeme primárně
    // v record.id (nový záznam) nebo v record.data.id (po reloadu).
    //
    // !!! Modal se ZDE NEzavírá !!! onSaved se volá i po prostém "Uložit" a po
    // přechodu stavu s `closeForm: 0` (např. "Oprava" 40 → 80). V obou
    // případech má formulář zůstat otevřený, jako u běžných (ne-nested) formů.
    // Modal se zavře až přes onClose (V pořádku s closeForm: 1, křížek, Esc,
    // overlay click) — ten je navěšený na handleSubDialogClose.
    const newId = record?.id ?? record?.data?.id ?? null;
    const mode = subDialogMode;

    if (newId == null) {
      return;
    }

    if (mode === 'edit') {
      // Edit existujícího záznamu: value se nemění, jen detaily (jméno, cena, …).
      // Re-resolvuj display popis. Recalculate v rodičovském formuláři triggerni
      // jen pokud má lookup zapnutý `edit_triggers` (default false) — typicky
      // tam, kde edit "vlije" data zpět do rodiče (např. položka → řádek
      // přepisuje description/unit_price/unit). U Partnera je default OK,
      // protože cascade reset adresy/banky by byl po edit překvapivý.
      await refetchResolved(newId);
      if (lookup?.edit_triggers) {
        onchange?.();
      }
    } else if (mode === 'create') {
      // Create: použij nový záznam jako vybranou hodnotu. FormEditor po save
      // sám přepne do edit módu (currentId = newId, loadForm), takže další
      // klik Uložit už půjde jako PUT na ten nově vytvořený záznam.
      // Aktualizujeme subDialogMode/RecordId, aby state v LookupInput byl
      // konzistentní pro případný další onSaved cyklus.
      value = newId;
      subDialogMode = 'edit';
      subDialogRecordId = newId;
      await refetchResolved(newId);
      onchange?.();
    }
  }

  async function refetchResolved(id) {
    if (!lookup?.table || id == null) return;
    const res = await get(`/_ui/lookup/${lookup.table}/resolve?ids=${id}`);
    if (res?.success && Array.isArray(res.data?.items) && res.data.items[0]) {
      const item = res.data.items[0];
      resolved = {
        id: item.id,
        primary: item.primary,
        secondary: item.secondary ?? null,
      };
      onResolveChange?.(resolved);
    }
  }
</script>

<div
  class="shpd-lookup"
  class:shpd-lookup--open={open}
  class:shpd-lookup--error={!!error}
  class:shpd-lookup--disabled={disabled}
>
  <div class="shpd-lookup__field">
    <input
      bind:this={inputEl}
      {id}
      type="text"
      class="shpd-lookup__input"
      class:shpd-lookup__input--error={!!error}
      class:shpd-lookup__input--with-edit={canEdit}
      value={open ? searchTerm : displayLabel}
      placeholder={hasValue ? '' : placeholder}
      {required}
      {disabled}
      autocomplete="off"
      oninput={handleInput}
      onfocus={handleFocus}
      onkeydown={handleKeydown}
    />
    <div class="shpd-lookup__actions">
      {#if canEdit}
        <button
          type="button"
          class="shpd-lookup__action shpd-lookup__action--edit"
          onclick={handleEdit}
          aria-label={t('lookup.editItem')}
          title={t('lookup.editItem')}
          tabindex="-1"
        >
          <Icon icon={iconEdit} size="sm" />
        </button>
      {/if}
      {#if hasValue && !disabled}
        <button
          type="button"
          class="shpd-lookup__action shpd-lookup__action--clear"
          onclick={handleClear}
          aria-label={t('common.clear')}
          tabindex="-1"
        >
          ×
        </button>
      {/if}
    </div>
  </div>

  {#if open}
    <div class="shpd-lookup__dropdown" role="listbox">
      <div class="shpd-lookup__results">
        {#if loading}
          <div class="shpd-lookup__status">{t('lookup.loading')}</div>
        {:else if lastError}
          <div class="shpd-lookup__status shpd-lookup__status--error">{lastError}</div>
        {:else if results.length === 0}
          <div class="shpd-lookup__status">{t('lookup.empty')}</div>
        {:else}
          {#each results as item, i (item.id)}
            <button
              type="button"
              class="shpd-lookup__item"
              class:shpd-lookup__item--active={i === activeIndex}
              role="option"
              aria-selected={i === activeIndex}
              tabindex="-1"
              onmouseenter={() => (activeIndex = i)}
              onmousedown={(e) => { e.preventDefault(); handleSelect(item); }}
            >
              <span class="shpd-lookup__item-primary">{item.primary}</span>
              {#if item.secondary}
                <span class="shpd-lookup__item-secondary">{item.secondary}</span>
              {/if}
            </button>
          {/each}
        {/if}
      </div>

      {#if canCreate}
        <button
          type="button"
          class="shpd-lookup__create"
          tabindex="-1"
          onmousedown={(e) => { e.preventDefault(); handleCreate(); }}
        >
          <Icon icon={iconAdd} size="sm" />
          <span>{t('lookup.createNew')}</span>
        </button>
      {/if}
    </div>
  {/if}

  {#if error}
    <span class="shpd-lookup__error">{error}</span>
  {/if}
</div>

<!--
  Vnořený FormDialog pro edit/create. Renderuje se vždy (i když subDialogOpen
  je false), aby Modal.svelte mohl sám rozhodnout o renderingu přes svůj
  `open` prop. Po save volá handleSubDialogSaved, který re-resolvuje value
  a (u create) spustí onchange (recalculate v rodičovském formuláři).
-->
<FormDialog
  table={lookup?.table ?? ''}
  recordId={subDialogRecordId}
  open={subDialogOpen}
  defaultData={subDialogDefaults}
  onClose={handleSubDialogClose}
  onSaved={handleSubDialogSaved}
/>

<style>
  .shpd-lookup {
    position: relative;
    width: 100%;
  }

  .shpd-lookup__field {
    position: relative;
    display: flex;
    align-items: stretch;
  }

  .shpd-lookup__input {
    width: 100%;
    padding: var(--shpd-input-padding-y) var(--shpd-space-sm);
    /* Rezerva pro clear button. Při edit + clear se zvětší přes modifier. */
    padding-right: calc(var(--shpd-space-lg) + var(--shpd-space-sm));
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    box-sizing: border-box;
    transition: border-color 0.15s ease;
  }

  /* Dvě tlačítka (edit + clear) → větší rezerva, aby text nezasahoval. */
  .shpd-lookup__input--with-edit {
    padding-right: calc(2 * var(--shpd-space-lg) + var(--shpd-space-sm));
  }

  .shpd-lookup__input:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-lookup__input--error {
    border-color: var(--shpd-color-danger);
  }

  .shpd-lookup__input--error:focus {
    box-shadow: 0 0 0 2px var(--shpd-color-error-ring);
  }

  .shpd-lookup__input:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--shpd-color-bg-secondary);
  }

  /* Kontejner napravo s edit + clear buttons. Flex z důvodu jednoduchého
     řazení vedle sebe — žádné absolute kalkulace s right offsetem. */
  .shpd-lookup__actions {
    position: absolute;
    right: var(--shpd-space-xs);
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    gap: 2px;
  }

  .shpd-lookup__action {
    width: 1.5rem;
    height: 1.5rem;
    padding: 0;
    border: none;
    background: transparent;
    color: var(--shpd-color-text-secondary);
    line-height: 1;
    cursor: pointer;
    border-radius: var(--shpd-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .shpd-lookup__action:hover {
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-lookup__action--clear {
    font-size: 1.25rem;
  }

  .shpd-lookup__dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    margin-top: 2px;
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    box-shadow: var(--shpd-shadow-md, 0 4px 12px rgba(0, 0, 0, 0.1));
    max-height: 22rem;
    /* Layout: scrollovatelný .__results + sticky-style .__create footer.
       Overflow je na .__results, ne na .__dropdown, aby create button zůstal
       vždy viditelný. */
    display: flex;
    flex-direction: column;
    z-index: 10;
  }

  .shpd-lookup__results {
    overflow-y: auto;
    max-height: 18rem;
  }

  .shpd-lookup__status {
    padding: var(--shpd-space-sm);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    text-align: center;
  }

  .shpd-lookup__status--error {
    color: var(--shpd-color-danger);
  }

  .shpd-lookup__item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    width: 100%;
    padding: var(--shpd-space-sm);
    border: none;
    background: transparent;
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    cursor: pointer;
    text-align: left;
    border-radius: 0;
  }

  .shpd-lookup__item--active {
    background-color: var(--shpd-color-primary-soft, var(--shpd-color-bg-secondary));
  }

  .shpd-lookup__item-primary {
    font-weight: 500;
  }

  .shpd-lookup__item-secondary {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  /* "+ Vytvořit nový" — footer dropdownu. Border-top vizuálně odděluje od
     výsledků. Vždy viditelný (sticky díky flex layoutu .__dropdown). */
  .shpd-lookup__create {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-sm);
    border: none;
    border-top: 1px solid var(--shpd-color-border);
    background: transparent;
    color: var(--shpd-color-primary, var(--shpd-color-text));
    font-size: var(--shpd-font-size-sm);
    font-family: var(--shpd-font-family);
    font-weight: 500;
    cursor: pointer;
    text-align: left;
  }

  .shpd-lookup__create:hover {
    background-color: var(--shpd-color-primary-soft, var(--shpd-color-bg-secondary));
  }

  .shpd-lookup__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    margin-top: var(--shpd-space-xs);
  }
</style>
