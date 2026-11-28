<script>
  import { get } from '../../api/client.js';
  import { t } from '../../i18n/index.js';
  import { openLayer } from '../../utils/layerStack.js';
  import { createLookupSearch, lookupLimitFor } from '../../utils/lookupSearch.js';
  import {
    selectedIds,
    withoutSelected,
    addId,
    removeId,
    removeLast,
    chipsOf,
    resolvedFor,
  } from '../../utils/multiLookup.js';

  // Výběr více záznamů z tabulky (prvek formuláře `multilookup`, #176 D7):
  // vybrané záznamy jako štítky ve vstupu (vzhled MultiselectInput), hledání
  // jako LookupInput. Úprava ani založení záznamu z pole není (D6).
  let {
    id,
    /** Hodnota — seznam id odkazovaných záznamů v pořadí výběru. Bindable. */
    value = $bindable([]),
    /** Popisky vybraných z dataResolved — seznam `{id, primary, secondary}`. */
    resolved = [],
    /** Lookup konfigurace — `{table, filter}`. */
    lookup,
    required = false,
    disabled = false,
    error = null,
    placeholder = '',
    /** Voláno po jakékoli změně value (výběr, odebrání). */
    onchange,
    /** Voláno po výběru / odebrání — předává nový seznam popisků. */
    onResolveChange,
  } = $props();

  let rootEl = $state(null);
  let inputEl = $state(null);
  let open = $state(false);
  let searchTerm = $state('');
  /** @type {Array<{id:number|string, primary:string, secondary:string|null}>} */
  let results = $state([]);
  let loading = $state(false);
  let lastError = $state(null);
  let activeIndex = $state(-1);

  const selected = $derived(selectedIds(value));
  const chips = $derived(chipsOf(selected, resolved));
  // Vybrané záznamy se v nabídce skrývají (D6).
  const visible = $derived(withoutSelected(results, selected));

  const search = createLookupSearch({
    get,
    getLookup: () => lookup,
    // Vybrané se skrývají až tady — žádá se o tolik položek navíc.
    getLimit: () => lookupLimitFor(selected.length),
    onState: (patch) => {
      loading = patch.loading;
      lastError = failureText(patch.failure);
      if (patch.items !== undefined) {
        results = patch.items;
        activeIndex = withoutSelected(results, selected).length > 0 ? 0 : -1;
      }
    },
  });

  function failureText(failure) {
    if (!failure) return null;
    return failure.network ? t('common.unknownError') : (failure.error?.message ?? t('lookup.error'));
  }

  // Fokus i klik: po Esc zůstává fokus ve vstupu, takže nabídku znovu
  // otevře až klik (nebo šipka dolů / psaní).
  function openDropdown() {
    if (disabled || open) return;
    open = true;
    search.run(searchTerm);
  }

  function closeDropdown() {
    open = false;
    searchTerm = '';
    activeIndex = -1;
    search.cancel();
  }

  function handleInput(e) {
    if (disabled) return;
    open = true;
    searchTerm = e.currentTarget.value;
    search.schedule(searchTerm);
  }

  function commit(nextIds, added = null) {
    value = nextIds;
    onResolveChange?.(resolvedFor(nextIds, resolved, added));
    onchange?.();
  }

  // Po výběru zůstává nabídka otevřená a vstup prázdný — další záznam jde
  // vybrat hned. Hledaný text se zahodí, nabídka se vrátí na první stránku.
  function handleSelect(item) {
    if (disabled) return;
    const hadTerm = searchTerm !== '';
    commit(addId(selected, item.id), item);
    searchTerm = '';
    search.cancel();
    if (hadTerm) {
      search.run('');
    } else {
      const remaining = withoutSelected(results, addId(selected, item.id)).length;
      activeIndex = remaining === 0 ? -1 : Math.min(Math.max(activeIndex, 0), remaining - 1);
    }
    inputEl?.focus();
  }

  function handleRemove(chipId) {
    if (disabled) return;
    commit(removeId(selected, chipId));
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
      if (visible.length > 0) {
        activeIndex = (activeIndex + 1) % visible.length;
      }
      return;
    }

    if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (!open) return;
      if (visible.length > 0) {
        activeIndex = activeIndex <= 0 ? visible.length - 1 : activeIndex - 1;
      }
      return;
    }

    if (e.key === 'Enter') {
      if (!open || activeIndex < 0 || !visible[activeIndex]) return;
      e.preventDefault();
      handleSelect(visible[activeIndex]);
      return;
    }

    if (e.key === 'Tab') {
      // Tab opouští pole — nabídka se zavře, fokus jde dál (bez preventDefault).
      if (open) closeDropdown();
      return;
    }

    // Backspace v prázdném vstupu odebere poslední štítek (D7).
    if (e.key === 'Backspace' && searchTerm === '' && selected.length > 0) {
      e.preventDefault();
      commit(removeLast(selected));
    }
  }

  // Klik do pole mimo štítek a vstup přenese fokus do vstupu.
  function handleFieldMouseDown(e) {
    if (disabled || e.target === inputEl) return;
    e.preventDefault();
    inputEl?.focus();
  }

  function handleDocumentMouseDown(e) {
    if (open && rootEl && !rootEl.contains(e.target)) {
      closeDropdown();
    }
  }

  $effect(() => {
    if (open) {
      document.addEventListener('mousedown', handleDocumentMouseDown);
      return () => document.removeEventListener('mousedown', handleDocumentMouseDown);
    }
  });

  // Otevřená nabídka je vrstva zásobníku: Esc zavře jen ji, formulář pod
  // ní až dalším stiskem (#160 D121).
  $effect(() => {
    if (!open) return;
    return openLayer({ kind: 'menu', transient: true, onEscape: closeDropdown }).remove;
  });

  // Pole přešlo do stavu jen ke čtení (ukládání, přepočet) — vstup mizí,
  // nabídka s ním.
  $effect(() => {
    if (disabled && open) closeDropdown();
  });
</script>

<div
  bind:this={rootEl}
  class="shpd-multilookup"
  class:shpd-multilookup--open={open}
  class:shpd-multilookup--disabled={disabled}
>
  <!-- svelte-ignore a11y_no_static_element_interactions -->
  <div
    id={disabled ? id : undefined}
    class="shpd-multilookup__field"
    class:shpd-multilookup__field--error={!!error}
    onmousedown={handleFieldMouseDown}
  >
    {#each chips as chip (chip.id)}
      <span class="shpd-multilookup__chip" title={chip.secondary ?? undefined}>
        <span class="shpd-multilookup__chip-label">{chip.primary}</span>
        {#if !disabled}
          <button
            type="button"
            class="shpd-multilookup__chip-remove"
            tabindex="-1"
            aria-label={t('multilookup.remove', { name: chip.primary })}
            title={t('multilookup.remove', { name: chip.primary })}
            onmousedown={(e) => { e.preventDefault(); e.stopPropagation(); }}
            onclick={() => handleRemove(chip.id)}
          >×</button>
        {/if}
      </span>
    {/each}
    {#if !disabled}
      <input
        bind:this={inputEl}
        {id}
        type="text"
        class="shpd-multilookup__input"
        role="combobox"
        aria-expanded={open}
        aria-autocomplete="list"
        aria-controls={`${id}-list`}
        value={searchTerm}
        placeholder={chips.length === 0 ? (placeholder || t('multilookup.placeholder')) : ''}
        required={required && chips.length === 0}
        autocomplete="off"
        autocorrect="off" autocapitalize="off" spellcheck="false"
        oninput={handleInput}
        onfocus={openDropdown}
        onclick={openDropdown}
        onkeydown={handleKeydown}
      />
    {/if}
  </div>

  {#if open}
    <div id={`${id}-list`} class="shpd-multilookup__dropdown" role="listbox">
      {#if loading}
        <div class="shpd-multilookup__status">{t('lookup.loading')}</div>
      {:else if lastError}
        <div class="shpd-multilookup__status shpd-multilookup__status--error">{lastError}</div>
      {:else if visible.length === 0}
        <div class="shpd-multilookup__status">{t('lookup.empty')}</div>
      {:else}
        {#each visible as item, i (item.id)}
          <button
            type="button"
            class="shpd-multilookup__item"
            class:shpd-multilookup__item--active={i === activeIndex}
            role="option"
            aria-selected={i === activeIndex}
            tabindex="-1"
            onmouseenter={() => (activeIndex = i)}
            onmousedown={(e) => { e.preventDefault(); handleSelect(item); }}
          >
            <span class="shpd-multilookup__item-primary">{item.primary}</span>
            {#if item.secondary}
              <span class="shpd-multilookup__item-secondary">{item.secondary}</span>
            {/if}
          </button>
        {/each}
      {/if}
    </div>
  {/if}

  {#if error}
    <span class="shpd-multilookup__error">{error}</span>
  {/if}
</div>

<style>
  .shpd-multilookup {
    position: relative;
    width: 100%;
  }

  /* Pole se štítky — rozměry a odsazení jako MultiselectInput (#156 D106):
     jedna řada štítků dává výšku ovládacího prvku, další řady pole zvyšují
     (na telefonu se štítky zalamují, D7). */
  .shpd-multilookup__field {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--shpd-space-xs);
    width: 100%;
    min-height: var(--shpd-control-height);
    padding: max(0.125rem, calc((var(--shpd-control-height) - 1.6 * var(--shpd-font-size-sm) - 2px) / 2 - 1px)) var(--shpd-space-sm); /* px-ok: rámeček čipu a pole */
    border: 1px solid var(--shpd-color-border-control);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-field);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    box-sizing: border-box;
    cursor: text;
    transition: border-color 0.15s ease;
  }

  .shpd-multilookup__field:focus-within {
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-multilookup__field--error {
    border-color: var(--shpd-color-danger-text);
  }

  .shpd-multilookup__field--error:focus-within {
    box-shadow: 0 0 0 2px var(--shpd-color-error-ring);
  }

  .shpd-multilookup--disabled .shpd-multilookup__field {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--shpd-color-bg-secondary);
    border-color: var(--shpd-color-border);
  }

  .shpd-multilookup__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    max-width: 100%;
    padding: 0 var(--shpd-space-xs);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg-secondary);
    font-size: var(--shpd-font-size-sm);
    line-height: 1.6;
    box-sizing: border-box;
  }

  .shpd-multilookup__chip-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-multilookup__chip-remove {
    flex-shrink: 0;
    padding: 0;
    border: none;
    background: transparent;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    font-family: var(--shpd-font-family);
    line-height: inherit;
    cursor: pointer;
  }

  .shpd-multilookup__chip-remove:hover {
    color: var(--shpd-color-danger-text);
  }

  /* Vstup hledání teče za štítky; řádek má výšku štítku, takže prázdné pole
     i pole se štítky jsou stejně vysoké. */
  .shpd-multilookup__input {
    flex: 1 1 6rem;
    min-width: 6rem;
    padding: 0;
    border: none;
    outline: none;
    background: transparent;
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-field);
    font-family: var(--shpd-font-family);
    line-height: calc(1.6 * var(--shpd-font-size-sm));
  }

  .shpd-multilookup__input::placeholder {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-multilookup__dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 10;
    margin-top: 0.125rem;
    max-height: 18rem;
    overflow-y: auto;
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    box-shadow: var(--shpd-shadow-md, 0 4px 12px rgba(0, 0, 0, 0.1));
  }

  .shpd-multilookup__status {
    padding: var(--shpd-space-sm);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    text-align: center;
  }

  .shpd-multilookup__status--error {
    color: var(--shpd-color-danger-text);
  }

  .shpd-multilookup__item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.125rem;
    width: 100%;
    padding: var(--shpd-space-sm);
    border: none;
    border-radius: 0;
    background: transparent;
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-field);
    font-family: var(--shpd-font-family);
    cursor: pointer;
    text-align: left;
  }

  .shpd-multilookup__item--active {
    background-color: var(--shpd-color-primary-soft, var(--shpd-color-bg-secondary));
  }

  .shpd-multilookup__item-primary {
    font-weight: 500;
  }

  .shpd-multilookup__item-secondary {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  /* Víc chyb jednoho pole (řádky child tabulky) — každá na vlastním řádku. */
  .shpd-multilookup__error {
    display: block;
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger-text);
    white-space: pre-line;
  }
</style>
