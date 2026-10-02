<script>
  /**
   * Kompaktní jednořádková položka feedu (Issue #32/2, D2/D4) — sdílená
   * ready a info sekcí. mode='ready': donut jistoty, partner tučně,
   * typ · datum, částka, Použít (jednoklik apply) + oko (review modal).
   * mode='info': tlumený řádek title/subtitle, akce z card.actions vpravo
   * (heterogenní — Koš/Archiv, open_viewer, open_form, undo_auto_archive…).
   * Žádná vlastní logika — vše deleguje onAction(action) rodiči (Dashboard
   * přes Feed); busy disabluje všechna tlačítka (jednoklik apply běží
   * i mimo plnou kartu).
   */
  import { t } from '../../i18n/index.js';
  import { iconPreview, iconWarning } from '../../icons.js';
  import Button from '../ui/Button.svelte';
  import Icon from '../ui/Icon.svelte';

  let { card, mode = 'info', busy = false, onAction = () => {} } = $props();

  // Meta řádek: typ dokladu (headline) / subtitle fallback + datum doručení
  // — stejné složení jako subLine ve FeedCard.
  const metaLine = $derived(
    [card.headline ? card.headline.typeLabel : card.subtitle, card.receivedDateText]
      .filter(Boolean)
      .join(' · '),
  );

  const applyAction = $derived(card.actions?.find((a) => a.kind === 'apply_message'));
  const reviewAction = $derived(card.actions?.find((a) => a.kind === 'review_message'));

  // Alert akce nesou vlastní lokalizovaný label; mail akce se lokalizují
  // klientsky podle action.id (vzor FeedCard).
  function actionLabel(action) {
    return action.label ?? t(`dashboard.card.action.${action.id}`);
  }

  // Donut jistoty — zmenšená varianta donutu z FeedCard (jen ready řádky).
  const DONUT_R = 15.5;
  const DONUT_C = 2 * Math.PI * DONUT_R;
  const donutArc = $derived(((card.confidencePct ?? 0) / 100) * DONUT_C);
</script>

<div class="shpd-feed-row shpd-feed-row--{mode}">
  {#if mode === 'ready' && card.confidencePct != null}
    <svg
      class="shpd-feed-row__donut"
      viewBox="0 0 36 36"
      role="img"
      aria-label={t('dashboard.card.confidence', { pct: card.confidencePct })}
    >
      <circle class="shpd-feed-row__donut-track" cx="18" cy="18" r={DONUT_R} />
      <circle
        class="shpd-feed-row__donut-arc"
        cx="18"
        cy="18"
        r={DONUT_R}
        stroke-dasharray="{donutArc} {DONUT_C}"
      />
      <text class="shpd-feed-row__donut-text" x="18" y="18">{card.confidencePct}</text>
    </svg>
  {/if}
  <div class="shpd-feed-row__main">
    <span class="shpd-feed-row__title">{card.headline ? card.headline.partnerName : card.title}</span>
    {#if card.warning}
      <!-- Kompaktní řádek nemá hint řádky — upozornění předzpracování
           (tasks/mail-preprocess-error-messages.md D3c, U2) jen jako ikona
           varování s tooltipem; plný řádek má FeedCard. -->
      <span class="shpd-feed-row__warning" title={card.warning} role="img" aria-label={card.warning}>
        <Icon icon={iconWarning} size="sm" />
      </span>
    {/if}
    {#if metaLine}
      <span class="shpd-feed-row__meta">{metaLine}</span>
    {/if}
    {#if mode === 'info' && card.emailSubject}
      <span class="shpd-feed-row__meta shpd-feed-row__meta--subject">„{card.emailSubject}"</span>
    {/if}
  </div>
  {#if mode === 'ready' && card.headline?.amountText}
    <span class="shpd-feed-row__amount">{card.headline.amountText}</span>
  {/if}
  <div class="shpd-feed-row__actions">
    {#if mode === 'ready'}
      {#if applyAction}
        <Button
          label={actionLabel(applyAction)}
          variant="primary"
          size="sm"
          disabled={busy}
          onclick={() => onAction(applyAction)}
        />
      {/if}
      {#if reviewAction}
        <Button
          label={t('dashboard.card.action.review')}
          icon={iconPreview}
          iconOnly
          variant="secondary"
          size="sm"
          disabled={busy}
          onclick={() => onAction(reviewAction)}
        />
      {/if}
    {:else}
      {#each card.actions ?? [] as action (action.id)}
        <Button
          label={actionLabel(action)}
          variant={action.primary ? 'secondary' : 'ghost'}
          size="sm"
          disabled={busy}
          onclick={() => onAction(action)}
        />
      {/each}
    {/if}
  </div>
</div>

<style>
  .shpd-feed-row {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    color: var(--shpd-color-text);
  }

  /* Vlasovou linku mezi sousedními řádky kreslí rodič (Feed,
     FeedReadySection) — sibling kombinátor mezi instancemi komponenty
     by scoped styl vyhodil jako nepoužitý. */

  /* Info řádky jsou degradované — tlumené, bez rámu a pozadí. */
  .shpd-feed-row--info {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
  }

  .shpd-feed-row--info .shpd-feed-row__title {
    font-weight: 500;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-feed-row__donut {
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    color: var(--shpd-color-success);
  }

  .shpd-feed-row__donut-track {
    fill: none;
    stroke: var(--shpd-color-border);
    stroke-width: 3;
  }

  .shpd-feed-row__donut-arc {
    fill: none;
    stroke: currentColor;
    stroke-width: 3;
    stroke-linecap: round;
    transform: rotate(-90deg);
    transform-origin: center;
  }

  .shpd-feed-row__donut-text {
    fill: var(--shpd-color-text);
    font-size: 0.8rem;
    font-weight: 600;
    text-anchor: middle;
    dominant-baseline: central;
  }

  /* Titulek + meta na jednom řádku; na úzkém okně se meta zalomí pod
     titulek (flex-wrap), nic nepřetéká. */
  .shpd-feed-row__main {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: baseline;
    column-gap: var(--shpd-space-sm);
    flex-wrap: wrap;
  }

  .shpd-feed-row__title {
    font-weight: 600;
  }

  /* Ikona upozornění (card.warning) — varovná barva, text jen v tooltipu. */
  .shpd-feed-row__warning {
    display: inline-flex;
    align-items: center;
    align-self: center;
    flex-shrink: 0;
    color: var(--shpd-color-warning);
    cursor: help;
  }

  .shpd-feed-row__meta {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
  }

  .shpd-feed-row__meta--subject {
    font-style: italic;
  }

  .shpd-feed-row__amount {
    flex-shrink: 0;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }

  .shpd-feed-row__actions {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
  }
</style>
