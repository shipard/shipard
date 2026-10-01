<script>
  // Panel volby kódu DPH řádku v popoveru nad badge kódu
  // (tasks/exchange-preview-vat-choices.md D16, #87 task B).
  //
  // Nabídka přichází ze serveru (`_resolve.vatCodeOptions` — kandidáti
  // k efektivnímu místu plnění a DUZP, včetně kráceného odpočtu); panel
  // nic nehledá ani nevytváří, proto nestojí na ResolveDecisionPanelu
  // (ten je postavený na entitách s id a lookup endpointu).
  //
  // Výstup přes `onDecide(action)`:
  //   - volba kódu          → onDecide(`useCode:${code}`)
  //   - „Zrušit výběr“       → onDecide(null)
  // Bulk režim (bulkCount > 0): rodič zapíše volbu do všech řádků s nabídkou
  // naráz — jedno volání onUserActionsChange, jedno uložení, jeden refresh.

  import { t } from '../../i18n/index.js';

  let {
    options = [],              // [{code, label, pct, reverseCharge, reducedDeduction, supplyKind}]
    currentUserAction = null,  // 'useCode:<kód>' | null
    bulkCount = 0,
    bulkDecidedCount = 0,
    onDecide = () => {},
  } = $props();

  const currentCode = $derived(
    typeof currentUserAction === 'string' && currentUserAction.startsWith('useCode:')
      ? currentUserAction.slice('useCode:'.length)
      : null,
  );

  function formatPct(pct) {
    try {
      return `${new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 2 }).format(pct)} %`;
    } catch {
      return `${pct} %`;
    }
  }
</script>

<div class="shpd-vatcode">
  {#if currentCode}
    <div class="shpd-vatcode__current">
      <span>{t('exchange.preview.vatCode.current', { code: currentCode })}</span>
      <button type="button" class="shpd-vatcode__unselect" onclick={() => onDecide(null)}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </div>
  {:else if bulkCount > 0 && bulkDecidedCount > 0}
    <div class="shpd-vatcode__current">
      <span>{t('exchange.preview.bulk.decided', { count: bulkDecidedCount })}</span>
      <button type="button" class="shpd-vatcode__unselect" onclick={() => onDecide(null)}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </div>
  {/if}

  {#if bulkCount > 0}
    <p class="shpd-vatcode__hint">{t('exchange.preview.vatCode.bulkHint', { count: bulkCount })}</p>
  {/if}

  <div class="shpd-vatcode__heading">{t('exchange.preview.vatCode.heading')}</div>
  <div class="shpd-vatcode__list" role="listbox" aria-label={t('exchange.preview.vatCode.heading')}>
    {#each options as option (option.code)}
      <button
        type="button"
        role="option"
        aria-selected={option.code === currentCode}
        class="shpd-vatcode__option"
        class:shpd-vatcode__option--active={option.code === currentCode}
        onclick={() => onDecide(`useCode:${option.code}`)}
      >
        <span class="shpd-vatcode__code">{option.code}</span>
        <span class="shpd-vatcode__label">{option.label}</span>
        <span class="shpd-vatcode__pct">{formatPct(option.pct)}</span>
        {#if option.reverseCharge}
          <span class="shpd-vatcode__tag">{t('exchange.preview.vatCode.reverseCharge')}</span>
        {/if}
        {#if option.reducedDeduction}
          <span class="shpd-vatcode__tag">{t('exchange.preview.vatCode.reducedDeduction')}</span>
        {/if}
      </button>
    {/each}
  </div>
</div>

<style>
  .shpd-vatcode {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    font-size: 0.875rem;
    flex: 1 1 auto;
    min-height: 0;
  }

  .shpd-vatcode__current {
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

  .shpd-vatcode__unselect {
    background: transparent;
    border: 0;
    color: inherit;
    text-decoration: underline;
    cursor: pointer;
    font-size: 0.75rem;
    flex-shrink: 0;
  }

  .shpd-vatcode__hint {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--shpd-color-text-muted);
  }

  .shpd-vatcode__heading {
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--shpd-color-text-secondary);
    letter-spacing: 0.5px;
  }

  .shpd-vatcode__list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    overflow-y: auto;
    min-height: 0;
  }

  .shpd-vatcode__option {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs);
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

  .shpd-vatcode__option:hover,
  .shpd-vatcode__option:focus-visible {
    background: var(--shpd-color-surface-alt, #f5f5f5);
    outline: none;
  }

  .shpd-vatcode__option--active {
    border-color: var(--shpd-color-primary);
    background: var(--shpd-color-primary-soft);
  }

  .shpd-vatcode__code {
    font-family: var(--shpd-font-mono, monospace);
    font-size: 0.75rem;
    color: var(--shpd-color-text-muted);
  }

  .shpd-vatcode__label {
    flex: 1 1 auto;
  }

  .shpd-vatcode__pct {
    font-variant-numeric: tabular-nums;
    font-weight: 500;
  }

  .shpd-vatcode__tag {
    font-size: 0.6875rem;
    padding: 0 4px;
    border-radius: 3px;
    background: var(--shpd-color-surface-alt, #f5f5f5);
    color: var(--shpd-color-text-muted);
  }
</style>
