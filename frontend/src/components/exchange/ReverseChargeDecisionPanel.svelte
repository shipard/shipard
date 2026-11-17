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
  import Panel from '../ui/Panel.svelte';
  import OptionRow from '../ui/OptionRow.svelte';

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
    <Panel variant="compact" tone="info" class="shpd-rcsubject__current">
      <span>{t('exchange.preview.subject.current', { code: currentCode })}</span>
      <button type="button" class="shpd-rcsubject__unselect" onclick={() => onDecide(null)}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </Panel>
  {:else if bulkCount > 0 && bulkDecidedCount > 0}
    <Panel variant="compact" tone="info" class="shpd-rcsubject__current">
      <span>{t('exchange.preview.bulk.decided', { count: bulkDecidedCount })}</span>
      <button type="button" class="shpd-rcsubject__unselect" onclick={() => onDecide(null)}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </Panel>
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
      <OptionRow
        size="compact"
        role="option"
        selected={option.code === activeCode}
        data-testid="subject-option:{option.code}"
        onselect={() => onDecide(`useValue:${option.code}`)}
      >
        {option.label}
      </OptionRow>
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

  /* „Aktuální volba“ je sdílený Panel (compact, tón info — #149 D75);
     třída nese jen rozložení a barvu textu. */
  .shpd-rcsubject :global(.shpd-rcsubject__current) {
    color: var(--shpd-color-primary-text);
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
</style>
