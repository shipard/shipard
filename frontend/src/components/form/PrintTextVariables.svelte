<script>
  /**
   * Nabídka proměnných pro text na tiscích (element `component`,
   * component_name `printTextVariables`, #90 D51). Klik vloží zápis proměnné
   * do pole textu na místo kurzoru.
   *
   * `params` staví backend (`PrintTextsForm`): `column` = sloupec s textem,
   * `slot` a `prints` = pro co se proměnné nabízejí. Formulář se po změně
   * umístění nebo tisků přepočítá a komponenta dostane nové params — seznam
   * se znovu načítá jen tehdy, když se změní.
   *
   * Do pole se zapisuje přes DOM (`setRangeText` + událost `input`):
   * komponenta formuláře nemá přístup k datům formuláře, ale `bind:value`
   * pole si změnu z události převezme.
   */
  import { fetchPrintTextVariables } from '../../api/prints.js';
  import { t } from '../../i18n/index.js';

  let { params = {} } = $props();

  let rootEl = $state(null);
  let variables = $state([]);
  let failed = $state(false);

  const column = $derived(params?.column ?? 'text');
  const slot = $derived(params?.slot ?? null);
  const printsKey = $derived((params?.prints ?? []).join(','));

  $effect(() => {
    const currentSlot = slot;
    const prints = printsKey === '' ? [] : printsKey.split(',');
    let cancelled = false;

    (async () => {
      const response = await fetchPrintTextVariables(prints, currentSlot);
      if (cancelled) return;
      failed = !response?.success;
      variables = response?.success ? (response.data ?? []) : [];
    })();

    return () => { cancelled = true; };
  });

  // Pole textu téhož formuláře: nejbližší předek, který ho obsahuje.
  function findField() {
    for (let node = rootEl?.parentElement; node; node = node.parentElement) {
      const field = node.querySelector(`textarea[id^="shpd-${column}-"]`);
      if (field) return field;
    }
    return null;
  }

  function insert(snippet) {
    const field = findField();
    if (!field || field.disabled || field.readOnly) return;

    const start = field.selectionStart ?? field.value.length;
    const end = field.selectionEnd ?? start;
    field.focus();
    field.setRangeText(snippet, start, end, 'end');
    field.dispatchEvent(new Event('input', { bubbles: true }));
  }
</script>

<div class="shpd-print-vars" bind:this={rootEl} data-testid="print-text-variables">
  {#if variables.length > 0}
    <div class="shpd-print-vars__title">{t('printTexts.variables.title')}</div>
    <p class="shpd-print-vars__hint">{t('printTexts.variables.hint')}</p>
    <div class="shpd-print-vars__list">
      {#each variables as variable (variable.path)}
        <button
          type="button"
          class="shpd-print-vars__item"
          title={variable.example}
          onclick={() => insert(variable.example)}
        >
          <span class="shpd-print-vars__label">{variable.label}</span>
          <code class="shpd-print-vars__code">{variable.example}</code>
        </button>
      {/each}
    </div>
  {:else if failed}
    <p class="shpd-print-vars__hint">{t('printTexts.variables.loadFailed')}</p>
  {/if}
</div>

<style>
  .shpd-print-vars {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-print-vars:empty {
    display: none;
  }

  .shpd-print-vars__title {
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-print-vars__hint {
    margin: 0;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-print-vars__list {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs);
  }

  .shpd-print-vars__item {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font: inherit;
    text-align: left;
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
  }

  .shpd-print-vars__item:hover,
  .shpd-print-vars__item:focus-visible {
    border-color: var(--shpd-color-primary);
  }

  .shpd-print-vars__code {
    font-size: 0.85em;
    color: var(--shpd-color-text-secondary);
    overflow-wrap: anywhere;
  }
</style>
