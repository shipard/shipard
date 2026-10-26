<script>
  import { t } from '../../i18n/index.js';

  let {
    id,
    /** Aktuální hodnota — pole hodnot z options (list stringů). Bindable. */
    value = $bindable(null),
    /** @type {Array<{value: string|number, label: string}>} */
    options = [],
    required = false,
    disabled = false,
    error = null,
    placeholder = '',
    /** Voláno po jakékoli změně value (toggle, remove). */
    onchange,
  } = $props();

  let open = $state(false);
  let rootEl = $state(null);

  const selected = $derived(Array.isArray(value) ? value : []);

  function labelFor(v) {
    return options.find((o) => o.value === v)?.label ?? String(v);
  }

  function toggleOption(v) {
    if (disabled) return;
    value = selected.includes(v) ? selected.filter((x) => x !== v) : [...selected, v];
    onchange?.();
  }

  function removeValue(v) {
    if (disabled) return;
    value = selected.filter((x) => x !== v);
    onchange?.();
  }

  function toggleOpen() {
    if (disabled) return;
    open = !open;
  }

  function handleFocusOut(e) {
    if (rootEl && e.relatedTarget instanceof Node && rootEl.contains(e.relatedTarget)) return;
    open = false;
  }

  function handleKeydown(e) {
    if (e.key === 'Escape' && open) {
      e.stopPropagation();
      open = false;
    }
  }
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -- keydown jen chytá
     Escape probublaný z vnitřních fokusovatelných prvků, div sám není
     interaktivní cíl. -->
<div
  bind:this={rootEl}
  class="shpd-multiselect"
  class:shpd-multiselect--open={open}
  class:shpd-multiselect--disabled={disabled}
  onfocusout={handleFocusOut}
  onkeydown={handleKeydown}
>
  <button
    {id}
    type="button"
    class="shpd-multiselect__field"
    class:shpd-multiselect__field--error={!!error}
    {disabled}
    aria-haspopup="listbox"
    aria-expanded={open}
    onclick={toggleOpen}
  >
    {#if selected.length === 0}
      <span class="shpd-multiselect__placeholder">{placeholder ?? ''}</span>
    {:else}
      {#each selected as v (v)}
        <span class="shpd-multiselect__chip">
          <span class="shpd-multiselect__chip-label">{labelFor(v)}</span>
          {#if !disabled}
            <span
              class="shpd-multiselect__chip-remove"
              role="button"
              tabindex="-1"
              aria-label={t('common.clear')}
              onclick={(e) => { e.stopPropagation(); removeValue(v); }}
              onkeydown={(e) => { if (e.key === 'Enter') { e.stopPropagation(); removeValue(v); } }}
            >×</span>
          {/if}
        </span>
      {/each}
    {/if}
    <span class="shpd-multiselect__arrow" aria-hidden="true">▾</span>
  </button>

  {#if open}
    <div class="shpd-multiselect__dropdown" role="listbox" aria-multiselectable="true">
      {#each options as option (option.value)}
        {@const checked = selected.includes(option.value)}
        <button
          type="button"
          class="shpd-multiselect__item"
          role="option"
          aria-selected={checked}
          tabindex="-1"
          onmousedown={(e) => { e.preventDefault(); toggleOption(option.value); }}
        >
          <span class="shpd-multiselect__item-check" class:shpd-multiselect__item-check--on={checked} aria-hidden="true">
            {checked ? '✓' : ''}
          </span>
          <span class="shpd-multiselect__item-label">{option.label}</span>
        </button>
      {/each}
    </div>
  {/if}

  {#if error}
    <span class="shpd-multiselect__error">{error}</span>
  {/if}
</div>

<style>
  .shpd-multiselect {
    position: relative;
    width: 100%;
  }

  .shpd-multiselect__field {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--shpd-space-xs);
    width: 100%;
    min-height: calc(var(--shpd-input-padding-y) * 2 + 1.4em);
    padding: var(--shpd-input-padding-y) var(--shpd-space-sm);
    padding-right: calc(var(--shpd-space-lg) + var(--shpd-space-sm));
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    box-sizing: border-box;
    cursor: pointer;
    text-align: left;
    transition: border-color 0.15s ease;
  }

  .shpd-multiselect__field:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-multiselect__field--error {
    border-color: var(--shpd-color-danger);
  }

  .shpd-multiselect__field--error:focus {
    box-shadow: 0 0 0 2px var(--shpd-color-error-ring);
  }

  .shpd-multiselect__field:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-multiselect__placeholder {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-multiselect__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: 0 var(--shpd-space-xs);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg-secondary);
    font-size: var(--shpd-font-size-sm);
    line-height: 1.6;
    white-space: nowrap;
  }

  .shpd-multiselect__chip-remove {
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-multiselect__chip-remove:hover {
    color: var(--shpd-color-danger);
  }

  .shpd-multiselect__arrow {
    position: absolute;
    right: var(--shpd-space-sm);
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-multiselect__dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 20;
    margin-top: 0.125rem;
    max-height: 16rem;
    overflow-y: auto;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg);
    box-shadow: var(--shpd-shadow-md, 0 4px 12px rgba(0, 0, 0, 0.15));
  }

  .shpd-multiselect__item {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: none;
    background: none;
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
    cursor: pointer;
    text-align: left;
  }

  .shpd-multiselect__item:hover {
    background: var(--shpd-color-bg-secondary);
  }

  .shpd-multiselect__item-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1em;
    height: 1em;
    flex-shrink: 0;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-sm);
    color: transparent;
  }

  .shpd-multiselect__item-check--on {
    color: var(--shpd-color-primary, var(--shpd-color-text));
    border-color: var(--shpd-color-primary, var(--shpd-color-border-focus));
  }

  .shpd-multiselect__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    margin-top: var(--shpd-space-xs);
  }
</style>
