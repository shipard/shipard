<script>
  // Panel volby předmětu plnění řádku u tuzemského přenesení daňové
  // povinnosti v popoveru nad odznakem předmětu
  // (tasks/vat-reverse-charge-subjects.md D12, #89). Vzor
  // VatCodeDecisionPanel: nabídka přichází ze serveru
  // (`_resolve.reverseChargeOptions` — předměty platné k datu sazby dokladu),
  // panel nic nehledá ani nevytváří.
  //
  // Výstup přes `onDecide(action)`:
  //   - volba předmětu   → onDecide(`useValue:${code}`)
  //   - „Zrušit výběr“    → onDecide(null)
  // Bulk režim (bulkCount > 0): rodič zapíše volbu do všech řádků s kódem
  // přenesení naráz — jedno volání onUserActionsChange, jedno uložení.

  import { t } from '../../i18n/index.js';

  let {
    options = [],              // [{code, label}]
    currentUserAction = null,  // 'useValue:<kód>' | null
    documentValue = null,      // předmět z dokladu (jen informace v panelu)
    bulkCount = 0,
    bulkDecidedCount = 0,
    onDecide = () => {},
  } = $props();

  const currentCode = $derived(
    typeof currentUserAction === 'string' && currentUserAction.startsWith('useValue:')
      ? currentUserAction.slice('useValue:'.length)
      : null,
  );
  // Aktivní volba v seznamu: zvolený předmět, jinak předmět z dokladu.
  const activeCode = $derived(currentCode ?? (bulkCount > 0 ? null : documentValue));
</script>

<div class="shpd-rcsubject">
  {#if currentCode}
    <div class="shpd-rcsubject__current">
      <span>{t('exchange.preview.subject.current', { code: currentCode })}</span>
      <button type="button" class="shpd-rcsubject__unselect" onclick={() => onDecide(null)}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </div>
  {:else if bulkCount > 0 && bulkDecidedCount > 0}
    <div class="shpd-rcsubject__current">
      <span>{t('exchange.preview.bulk.decided', { count: bulkDecidedCount })}</span>
      <button type="button" class="shpd-rcsubject__unselect" onclick={() => onDecide(null)}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </div>
  {/if}

  {#if bulkCount > 0}
    <p class="shpd-rcsubject__hint">{t('exchange.preview.subject.bulkHint', { count: bulkCount })}</p>
  {:else}
    <p class="shpd-rcsubject__hint">{t('exchange.preview.subject.hint')}</p>
  {/if}

  <div class="shpd-rcsubject__heading">{t('exchange.preview.subject.heading')}</div>
  {#if options.length === 0}
    <!-- Prázdná nabídka (D98): odznak je klikací i tak — uživatel se musí
         dozvědět, proč nemá z čeho vybrat. -->
    <p class="shpd-rcsubject__hint">{t('exchange.preview.subject.noOptions')}</p>
  {/if}
  <div class="shpd-rcsubject__list" role="listbox" aria-label={t('exchange.preview.subject.heading')}>
    {#each options as option (option.code)}
      <button
        type="button"
        role="option"
        aria-selected={option.code === activeCode}
        class="shpd-rcsubject__option"
        class:shpd-rcsubject__option--active={option.code === activeCode}
        data-testid="subject-option:{option.code}"
        onclick={() => onDecide(`useValue:${option.code}`)}
      >
        {option.label}
      </button>
    {/each}
  </div>
</div>

<style>
  .shpd-rcsubject {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-body);
    flex: 1 1 auto;
    min-height: 0;
  }

  .shpd-rcsubject__current {
    background-color: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-body);
  }

  .shpd-rcsubject__unselect {
    background: transparent;
    border: 0;
    color: inherit;
    text-decoration: underline;
    cursor: pointer;
    font-size: var(--shpd-font-size-meta);
    flex-shrink: 0;
  }

  .shpd-rcsubject__hint {
    margin: 0;
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-rcsubject__heading {
    font-size: var(--shpd-font-size-meta);
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-rcsubject__list {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    overflow-y: auto;
    min-height: 0;
  }

  .shpd-rcsubject__option {
    width: 100%;
    text-align: left;
    background: transparent;
    border: 1px solid var(--shpd-color-border);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    color: var(--shpd-color-text);
    font: inherit;
  }

  .shpd-rcsubject__option:hover,
  .shpd-rcsubject__option:focus-visible {
    background: var(--shpd-color-bg-secondary);
    outline: none;
  }

  .shpd-rcsubject__option--active {
    border-color: var(--shpd-color-primary);
    background: var(--shpd-color-primary-soft);
  }
</style>
