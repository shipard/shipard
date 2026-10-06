<script>
  /**
   * Jeden pruh ready pásma feedu (Issue #32/2, D3/D4/D6 + D11): defaultně
   * sbalený souhrnný pruh, rozbalený = orámovaný blok kompaktních řádků
   * (FeedRowCompact). Stav rozbalení je lokální a nepersistuje (D6).
   *
   * variant='invoices' (přijaté faktury): počet, součty per měna, rozsah
   * jistoty, akce Projít (ready-only průchod) + Zobrazit, patička „Projít
   * frontu". variant='registry' (Spisovna, D11): vlastní titulek, bez
   * součtů částek a bez Projít — průchod Spisovnou se přidá později.
   *
   * Počet v titulku = summary.count − (summary.shown − cards.length)
   * (#101 D3b): serverový počet všech připravených dokladů skupiny snížený
   * o karty optimisticky odebrané z feedu — titulek a součty tak mluví
   * o téže množině. Bez summary (nebo bez `shown` ze staršího serveru)
   * fallback na cards.length. Částky a jistoty ze serverového summary
   * (skupina z readySummary, zdroj pravdy, D8) — chybějící summary jen
   * vynechá řádek souhrnu. Rozbalený seznam ukazuje doručené karty;
   * „a N dalších" řeší sekce (Feed), pruh ne.
   */
  import { t } from '../../i18n/index.js';
  import { iconChevronDown, iconChevronUp } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';
  import Button from '../ui/Button.svelte';
  import FeedRowCompact from './FeedRowCompact.svelte';

  let {
    cards = [],
    summary = null,
    variant = 'invoices',
    busyCardId = null,
    onCardAction = () => {},
    onWalkthrough = () => {},
  } = $props();

  let expanded = $state(false);

  const isInvoices = $derived(variant === 'invoices');

  const count = $derived(
    typeof summary?.shown === 'number' && typeof summary?.count === 'number'
      ? Math.max(cards.length, summary.count - (summary.shown - cards.length))
      : cards.length,
  );

  // „1 234,56 CZK" — stejný formát jako serverový number_format(x, 2, ',', ' ')
  // v amountText karet (formatAmount v MailSuggestionsSource).
  function formatAmount(total, currency) {
    const [int, frac] = total.toFixed(2).split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return `${grouped},${frac} ${currency}`;
  }

  // „Celkem 96 420,00 CZK + 120,00 EUR · jistota 91–98 %" — měny se nikdy
  // nesčítají napříč, jen se vypisují spojené „ + ". Registry pruh je bez
  // součtů (D11) — jeho karty částky nenesou, amounts je prázdné.
  const summaryLine = $derived.by(() => {
    if (!summary) return '';
    const parts = [];
    const amounts = summary.amounts ?? [];
    if (amounts.length > 0) {
      const joined = amounts.map((a) => formatAmount(a.total, a.currency)).join(' + ');
      parts.push(t('dashboard.feed.readyStrip.total', { amounts: joined }));
    }
    const min = summary.confidenceMin;
    const max = summary.confidenceMax;
    if (min != null && max != null) {
      parts.push(
        min === max
          ? t('dashboard.feed.readyStrip.confidenceSingle', { pct: min })
          : t('dashboard.feed.readyStrip.confidence', { min, max }),
      );
    }
    return parts.join(' · ');
  });
</script>

<div class="shpd-ready">
  <div class="shpd-ready__strip">
    <div class="shpd-ready__summary">
      <div class="shpd-ready__title">
        {t(isInvoices ? 'dashboard.feed.readyStrip.title' : 'dashboard.feed.readyStrip.titleRegistry', { n: count })}
      </div>
      {#if summaryLine}
        <div class="shpd-ready__totals">{summaryLine}</div>
      {/if}
    </div>
    <div class="shpd-ready__strip-actions">
      {#if isInvoices}
        <Button
          variant="primary"
          size="sm"
          label={t('dashboard.feed.readyStrip.browse')}
          onclick={onWalkthrough}
        />
      {/if}
      <button
        type="button"
        class="shpd-ready__toggle"
        aria-expanded={expanded}
        onclick={() => (expanded = !expanded)}
      >
        {t(expanded ? 'dashboard.feed.readyStrip.hide' : 'dashboard.feed.readyStrip.show')}
        <Icon icon={expanded ? iconChevronUp : iconChevronDown} size="xs" />
      </button>
    </div>
  </div>
  {#if expanded}
    <div class="shpd-ready__rows">
      {#each cards as card (card.id)}
        <FeedRowCompact
          {card}
          mode="ready"
          busy={busyCardId === card.id}
          onAction={(action) => onCardAction(card, action)}
        />
      {/each}
    </div>
    {#if isInvoices}
      <div class="shpd-ready__footer">
        <button type="button" class="shpd-ready__walkthrough" onclick={onWalkthrough}>
          {t('dashboard.feed.readyStrip.walkthrough')}
        </button>
      </div>
    {/if}
  {/if}
</div>

<style>
  /* Blok ready pásma — levý proužek v success barvě zrcadlí barvu pásma
     (paralela k hornímu proužku FeedCard u docState_done). */
  .shpd-ready {
    position: relative;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg);
    overflow: hidden;
  }

  .shpd-ready::before {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 4px;
    background: var(--shpd-color-success);
  }

  /* Pruh: souhrn vlevo, akce vpravo; na úzkém okně se akce zalomí pod
     souhrn (flex-wrap), nic nepřetéká. */
  .shpd-ready__strip {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm) var(--shpd-space-md);
    padding: var(--shpd-space-md);
    padding-left: calc(var(--shpd-space-md) + 4px);
  }

  .shpd-ready__summary {
    flex: 1;
    min-width: 0;
  }

  .shpd-ready__title {
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-ready__totals {
    margin-top: 2px;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-ready__strip-actions {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  /* Zobrazit/Skrýt — textové tlačítko v link vzhledu (vzor detail toggle
     FeedCard). */
  .shpd-ready__toggle {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: 0;
    border: none;
    background: none;
    color: var(--shpd-color-primary);
    font-family: var(--shpd-font-family);
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
  }

  .shpd-ready__toggle:hover {
    text-decoration: underline;
  }

  .shpd-ready__rows {
    border-top: 1px solid var(--shpd-color-border);
    padding-left: 4px;
  }

  /* Vlasová linka mezi sousedními řádky — kreslí ji rodič, uvnitř
     FeedRowCompact by sibling selektor mezi instancemi komponenty
     scoped styl vyhodil jako nepoužitý. */
  .shpd-ready__rows > :global(.shpd-feed-row + .shpd-feed-row) {
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-ready__footer {
    border-top: 1px solid var(--shpd-color-border);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    padding-left: calc(var(--shpd-space-md) + 4px);
  }

  .shpd-ready__walkthrough {
    padding: 0;
    border: none;
    background: none;
    color: var(--shpd-color-primary);
    font-family: var(--shpd-font-family);
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    cursor: pointer;
  }

  .shpd-ready__walkthrough:hover {
    text-decoration: underline;
  }
</style>
