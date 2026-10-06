<script>
  /**
   * Jedna karta feedu: stavový proužek nahoře (kind→stateStyle přes globální
   * .docState_* třídy), sémantická ikona, strukturovaná hlavička (partner /
   * typ dokladu / částka / donut jistoty), předmět e-mailu, chipy příloh,
   * rozbalovací detail a řada akčních tlačítek. Karty bez `headline`
   * (alerty, chybové, položky k založení) renderují title/subtitle fallback.
   * Chování akcí drží rodič (Dashboard) — FeedCard jen emituje onAction(action).
   */
  import { resolveIcon, iconMail, iconWarning, iconChevronDown, iconChevronUp } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';
  import Button from '../ui/Button.svelte';
  import FeedCardAttachment from './FeedCardAttachment.svelte';
  import { t } from '../../i18n/index.js';

  let { card, onAction = () => {}, busy = false } = $props();

  const stateClass = $derived(card.stateStyle ? `docState_${card.stateStyle}` : '');

  // Podtitulek: typ dokladu (headline) / subtitle (fallback), volitelně
  // + datum doručení pošty (receivedDateText, jen mail karty).
  const subLine = $derived(
    [card.headline ? card.headline.typeLabel : card.subtitle, card.receivedDateText]
      .filter(Boolean)
      .join(' · '),
  );

  // Alert akce nesou vlastní lokalizovaný label; mail akce se lokalizují
  // klientsky podle action.id (i18n klíče dashboard.card.action.*).
  function actionLabel(action) {
    return action.label ?? t(`dashboard.card.action.${action.id}`);
  }

  const hiddenAttachments = $derived(
    (card.attachmentsTotal ?? 0) - (card.attachments?.length ?? 0),
  );

  // Rozbalení detailu — lokální stav; nepřežije refetch (feed se po akci
  // stejně mění), to je OK.
  let detailOpen = $state(false);

  // Donut jistoty — inline SVG, oblouk přes stroke-dasharray.
  const DONUT_R = 15.5;
  const DONUT_C = 2 * Math.PI * DONUT_R;
  const donutArc = $derived(((card.confidencePct ?? 0) / 100) * DONUT_C);
  const donutColor = $derived(
    card.kind === 'ready'
      ? 'var(--shpd-color-success)'
      : card.kind === 'review'
        ? 'var(--shpd-color-warning)'
        : 'var(--shpd-color-text-secondary)',
  );

  // „+N" = syntetická open_detail akce — otevře read-only náhled zprávy
  // v modalu, stejně jako akce openMail na kartě (handler v Dashboard.svelte).
  function openMessage() {
    onAction({
      id: 'openMail',
      kind: 'open_detail',
      target: { viewerId: 'core.mail.incoming', recordId: card.context?.messageNdx, tabId: 'content' },
    });
  }
</script>

<div class="shpd-feed-card {stateClass}" data-testid="feed-card">
  <span class="shpd-feed-card__bar"></span>
  {#if card.icon}
    <span class="shpd-feed-card__icon">
      <Icon icon={resolveIcon(card.icon)} size="md" />
    </span>
  {/if}
  <div class="shpd-feed-card__body">
    <div class="shpd-feed-card__head">
      <div class="shpd-feed-card__heading">
        <div class="shpd-feed-card__title">{card.headline ? card.headline.partnerName : card.title}</div>
        {#if subLine}
          <div class="shpd-feed-card__subtitle">{subLine}</div>
        {/if}
      </div>
      {#if card.confidencePct != null}
        <svg
          class="shpd-feed-card__donut"
          style="color: {donutColor}"
          viewBox="0 0 36 36"
          role="img"
          aria-label={t('dashboard.card.confidence', { pct: card.confidencePct })}
        >
          <circle class="shpd-feed-card__donut-track" cx="18" cy="18" r={DONUT_R} />
          <circle
            class="shpd-feed-card__donut-arc"
            cx="18"
            cy="18"
            r={DONUT_R}
            stroke-dasharray="{donutArc} {DONUT_C}"
          />
          <text class="shpd-feed-card__donut-text" x="18" y="18">{card.confidencePct}</text>
        </svg>
      {/if}
    </div>
    {#if card.headline?.amountText}
      <div class="shpd-feed-card__amount">{card.headline.amountText}</div>
    {/if}
    {#if card.emailSubject}
      <div class="shpd-feed-card__subject" title={card.emailSubject}>
        <Icon icon={iconMail} size="sm" />
        <span class="shpd-feed-card__subject-text">„{card.emailSubject}"</span>
      </div>
    {/if}
    {#if card.secondaryFindings?.length}
      <!-- Hint dalších nálezů běhu (D7 z mail-message-centric) — jen
           informativní řádek, žádné akce. -->
      <div class="shpd-feed-card__findings">
        {#each card.secondaryFindings as finding}
          <div class="shpd-feed-card__finding">
            + {finding.type_label}{finding.note ? ` — ${finding.note}` : ''}
          </div>
        {/each}
      </div>
    {/if}
    {#if card.warning}
      <!-- Upozornění předzpracování (tasks/mail-preprocess-error-messages.md
           D3c): návrh / klasifikace vznikly bez dokumentu, který mělo
           předzpracování vytvořit. Text už lokalizovaný ze serveru, žádné
           akce; druh karty se nemění. -->
      <div class="shpd-feed-card__warning" role="note">
        <Icon icon={iconWarning} size="sm" />
        <span class="shpd-feed-card__warning-text" title={card.warning}>{card.warning}</span>
      </div>
    {/if}
    {#if card.attachments?.length}
      <div class="shpd-feed-card__attachments">
        {#each card.attachments as att (att.id)}
          <FeedCardAttachment {att} />
        {/each}
        {#if hiddenAttachments > 0}
          <button
            type="button"
            class="shpd-feed-att__more"
            title={t('dashboard.card.attachments.more', { n: hiddenAttachments })}
            aria-label={t('dashboard.card.attachments.more', { n: hiddenAttachments })}
            disabled={busy}
            onclick={openMessage}
          >+{hiddenAttachments}</button>
        {/if}
      </div>
    {/if}
    {#if card.details?.length}
      <button
        type="button"
        class="shpd-feed-card__detail-toggle"
        aria-expanded={detailOpen}
        onclick={() => (detailOpen = !detailOpen)}
      >
        {t(detailOpen ? 'dashboard.card.hideDetail' : 'dashboard.card.showDetail')}
        <Icon icon={detailOpen ? iconChevronUp : iconChevronDown} size="xs" />
      </button>
      {#if detailOpen}
        <div class="shpd-feed-card__details">
          {#each card.details as row (row.label)}
            <div class="shpd-feed-card__detail-row">
              <span class="shpd-feed-card__detail-label">{row.label}</span>
              <span class="shpd-feed-card__detail-value">{row.value}</span>
            </div>
          {/each}
        </div>
      {/if}
    {/if}
    {#if card.actions?.length}
      <div class="shpd-feed-card__actions">
        {#each card.actions as action (action.id)}
          <Button
            label={actionLabel(action)}
            testid={`card-action-${action.id}`}
            variant={action.primary ? 'primary' : 'secondary'}
            size="sm"
            disabled={busy}
            onclick={() => onAction(action)}
          />
        {/each}
      </div>
    {/if}
  </div>
</div>

<style>
  .shpd-feed-card {
    --shpd-row-bar: transparent;

    position: relative;
    display: flex;
    align-items: flex-start;
    gap: var(--shpd-space-sm);
    padding: calc(var(--shpd-space-md) + 4px) var(--shpd-space-md) var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
  }

  /* Horní stavový proužek — 4px, barva z globální .docState_* třídy.
     (Návrat na levou pozici = jen jiná geometrie tady, --shpd-row-bar
     zůstává.) */
  .shpd-feed-card__bar {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    border-radius: var(--shpd-radius-md) var(--shpd-radius-md) 0 0;
    background: var(--shpd-row-bar);
  }

  /* Dashboardový override: globální .docState_done proužek záměrně nemá
     (done je default stav ve viewerech), ale s horním proužkem by ready
     karty byly jediné bez barvy → zelená jen tady. Snadno odstranitelné. */
  .shpd-feed-card:global(.docState_done) {
    --shpd-row-bar: var(--shpd-color-success);
  }

  .shpd-feed-card__icon {
    color: var(--shpd-color-text-secondary);
    flex-shrink: 0;
    margin-top: 2px;
  }

  .shpd-feed-card__body {
    flex: 1;
    min-width: 0;
  }

  /* Hlavička: blok titulku vlevo, donut jistoty vpravo nahoře. */
  .shpd-feed-card__head {
    display: flex;
    align-items: flex-start;
    gap: var(--shpd-space-sm);
  }

  .shpd-feed-card__heading {
    flex: 1;
    min-width: 0;
  }

  .shpd-feed-card__title {
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-feed-card__subtitle {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    margin-top: 2px;
  }

  .shpd-feed-card__donut {
    width: 36px;
    height: 36px;
    flex-shrink: 0;
  }

  .shpd-feed-card__donut-track {
    fill: none;
    stroke: var(--shpd-color-border);
    stroke-width: 3;
  }

  .shpd-feed-card__donut-arc {
    fill: none;
    stroke: currentColor;
    stroke-width: 3;
    stroke-linecap: round;
    transform: rotate(-90deg);
    transform-origin: center;
  }

  .shpd-feed-card__donut-text {
    fill: var(--shpd-color-text);
    font-size: 0.7rem;
    font-weight: 600;
    text-anchor: middle;
    dominant-baseline: central;
  }

  .shpd-feed-card__amount {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-xl);
    font-weight: 700;
  }

  /* Předmět zdrojového e-mailu — jeden řádek s ellipsis, plný text v title. */
  .shpd-feed-card__subject {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    min-width: 0;
  }

  /* Hint dalších nálezů (secondary_findings) — tlumený informativní řádek. */
  .shpd-feed-card__findings {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-feed-card__finding {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-feed-card__subject-text {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Řádek upozornění (card.warning) — jako hint řádek, ale ve varovné
     barvě; jeden řádek s ellipsis, plný text v title. */
  .shpd-feed-card__warning {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-warning);
    min-width: 0;
  }

  .shpd-feed-card__warning-text {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Řada chipů příloh — na mobilu se zalamuje, nic nepřetéká. */
  .shpd-feed-card__attachments {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-sm);
  }

  /* „+N" — vzhledem ladí s chipem přílohy (FeedCardAttachment). */
  .shpd-feed-att__more {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    font-family: var(--shpd-font-family);
    line-height: 1;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  .shpd-feed-att__more:hover:not(:disabled) {
    border-color: var(--shpd-color-primary);
    box-shadow: var(--shpd-shadow-md);
  }

  .shpd-feed-att__more:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  /* Toggle detailu — textové tlačítko v link vzhledu. */
  .shpd-feed-card__detail-toggle {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    margin-top: var(--shpd-space-sm);
    padding: 0;
    border: none;
    background: none;
    color: var(--shpd-color-primary);
    font-family: var(--shpd-font-family);
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
  }

  .shpd-feed-card__detail-toggle:hover {
    text-decoration: underline;
  }

  .shpd-feed-card__details {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    margin-top: var(--shpd-space-sm);
    padding: var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg-secondary);
  }

  .shpd-feed-card__detail-row {
    display: flex;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-feed-card__detail-label {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-feed-card__detail-value {
    color: var(--shpd-color-text);
    font-weight: 500;
    text-align: right;
  }

  .shpd-feed-card__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-sm);
  }
</style>
