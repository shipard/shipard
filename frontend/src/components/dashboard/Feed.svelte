<script>
  /**
   * Feed rozdělený do sekcí podle toku práce (#101): sekci každé karty
   * určuje server (`card.feedSection`), frontend jen seskupuje a renderuje
   * v pořadí D8 — Položky k založení → Připraveno → Ke kontrole →
   * K vyřízení (tasks/mail-other-attention.md D4) → Nepodařilo se
   * zpracovat → Upozornění → Ostatní. Uvnitř sekce pořadí = pořadí
   * serveru. Rozvržení se řídí sekcí, ne pásmem (`kind`): failed
   * full-width karty, newItems / review / attention / alerts grid, ready
   * sbalené pruhy (FeedReadySection), other tlumené kompaktní řádky.
   * Sekce Ostatní má v hlavičce „Archivovat vše (N)“ — N posílá server
   * v `sections[].archivable` (všechny info / promo řádky, i nad stropem);
   * klik emituje syntetickou akci `archive_informational` (D5).
   *
   * Strop 30 karet platí per sekce a `sections` ze serveru nese pravdivé
   * počty (`total`/`shown`). Hlavička ukazuje `total` snížený o karty
   * optimisticky odebrané z feedu (`total − (shown − present)`), pod sekcí
   * je odkaz „a N dalších" (N = total − shown). Bez `sections` (záložka
   * filtru, starší server) se sekce odvodí z karet — počet = viditelné karty.
   * Sekce s nulou doručených karet, ale zbytkem na serveru, se dál renderuje
   * (hlavička + odkaz), aby zbytek nezmizel po jednokliku na poslední kartu.
   *
   * Akce bublou nahoru přes onCardAction(card, action); rodič (Dashboard)
   * drží preview modal / reject prompt / toast. emptyText: per-záložkový
   * empty stav filtru; null → globální „Vše zpracováno". onWalkthrough =
   * sériový průchod omezený na ready pásmo (D9).
   */
  import { t } from '../../i18n/index.js';
  import Button from '../ui/Button.svelte';
  import FeedCard from './FeedCard.svelte';
  import FeedReadySection from './FeedReadySection.svelte';
  import FeedRowCompact from './FeedRowCompact.svelte';

  let {
    cards = [],
    sections = null,
    readySummary = null,
    onCardAction = () => {},
    onWalkthrough = () => {},
    busyCardId = null,
    emptyText = null,
  } = $props();

  // Pořadí sekcí = FeedCollector::SECTION_ORDER serveru (#101 D8, #105 D4).
  const SECTION_ORDER = ['newItems', 'ready', 'review', 'attention', 'failed', 'alerts', 'other'];

  // Fallback pro kartu bez feedSection (starší server) — zrcadlí
  // FeedCollector::DEFAULT_SECTION_BY_KIND; neznámý kind → Ostatní.
  const DEFAULT_SECTION_BY_KIND = { urgent: 'failed', review: 'review', ready: 'ready', info: 'other' };

  // Cíl odkazu „a N dalších" (open_viewer) per sekce; newItems odkaz nemá —
  // karty položek nemají viewer, do kterého by zbytek vedl.
  const MORE_VIEWER = {
    ready: 'core.mail.incoming',
    review: 'core.mail.incoming',
    attention: 'core.mail.incoming',
    failed: 'core.mail.incoming',
    other: 'core.mail.incoming',
    alerts: 'core.alerts.alerts',
  };

  function sectionOf(card) {
    return SECTION_ORDER.includes(card.feedSection)
      ? card.feedSection
      : (DEFAULT_SECTION_BY_KIND[card.kind] ?? 'other');
  }

  const grouped = $derived.by(() => {
    const by = Object.fromEntries(SECTION_ORDER.map((id) => [id, []]));
    for (const card of cards) {
      by[sectionOf(card)].push(card);
    }
    return by;
  });

  // Sekce k renderu: serverové počty (záložka Vše) nebo odvozené z karet
  // (total = shown = present). `count` odečítá optimisticky odebrané karty,
  // `more` = karty nad stropem na serveru.
  const sectionInfos = $derived.by(() => {
    const byId = sections ? Object.fromEntries(sections.map((s) => [s.id, s])) : {};
    return SECTION_ORDER
      .map((id) => {
        const present = grouped[id].length;
        const total = byId[id]?.total ?? present;
        const shown = byId[id]?.shown ?? present;
        return {
          id,
          cards: grouped[id],
          present,
          count: Math.max(present, total - (shown - present)),
          more: Math.max(0, total - shown),
          archivable: byId[id]?.archivable ?? 0,
        };
      })
      .filter((s) => s.present > 0 || s.more > 0);
  });

  // Ready sekce se dělí per kategorie (D11): pruh přijatých faktur
  // a samostatný pruh Spisovny — zrcadlí skupiny readySummary
  // (bez kategorie → invoices, stejný defenzivní default jako server).
  const readyGroups = $derived.by(() => {
    const g = { invoices: [], registry: [] };
    for (const card of grouped.ready) {
      (card.category === 'registry' ? g.registry : g.invoices).push(card);
    }
    return g;
  });

  const isEmpty = $derived(sectionInfos.length === 0);

  // „a N dalších" = syntetická open_viewer akce přes stávající cestu
  // onCardAction (vzor chipu „+N" příloh ve FeedCard); card je jen nosič id.
  function openMore(sectionId) {
    const viewerId = MORE_VIEWER[sectionId];
    if (!viewerId) return;
    onCardAction(
      { id: `section:${sectionId}` },
      { id: 'openMore', kind: 'open_viewer', target: { viewerId } },
    );
  }

  // „Archivovat vše“ v hlavičce Ostatní — stejná syntetická cesta jako
  // openMore; bez potvrzovacího dialogu, akce je vratná z toastu (D5).
  function archiveAll() {
    onCardAction({ id: 'section:other' }, { id: 'archiveAll', kind: 'archive_informational' });
  }
</script>

{#if isEmpty}
  <div class="shpd-feed__empty">{emptyText ?? t('dashboard.feed.empty')}</div>
{:else}
  <div class="shpd-feed" data-testid="feed">
    {#each sectionInfos as section (section.id)}
      <section class="shpd-feed__section" data-section={section.id}>
        <h2 class="shpd-feed__section-title">
          <span class="shpd-feed__section-dot shpd-feed__section-dot--{section.id}" aria-hidden="true"></span>
          {t(`dashboard.feed.section.${section.id}`)}
          <span class="shpd-feed__section-count">({section.count})</span>
          {#if section.id === 'other' && section.archivable > 0}
            <span class="shpd-feed__section-action">
              <Button
                label={t('dashboard.feed.archiveAll', { n: section.archivable })}
                variant="secondary"
                size="sm"
                disabled={busyCardId !== null}
                testid="feed-archive-all"
                onclick={archiveAll}
              />
            </span>
          {/if}
        </h2>
        {#if section.id === 'ready'}
          {#if readyGroups.invoices.length > 0}
            <FeedReadySection
              cards={readyGroups.invoices}
              summary={readySummary?.invoices ?? null}
              variant="invoices"
              {busyCardId}
              {onCardAction}
              {onWalkthrough}
            />
          {/if}
          {#if readyGroups.registry.length > 0}
            <FeedReadySection
              cards={readyGroups.registry}
              summary={readySummary?.registry ?? null}
              variant="registry"
              {busyCardId}
              {onCardAction}
            />
          {/if}
        {:else if section.id === 'failed'}
          <div class="shpd-feed__stack">
            {#each section.cards as card (card.id)}
              <FeedCard
                {card}
                busy={busyCardId === card.id}
                onAction={(action) => onCardAction(card, action)}
              />
            {/each}
          </div>
        {:else if section.id === 'other'}
          <div class="shpd-feed__rows">
            {#each section.cards as card (card.id)}
              <FeedRowCompact
                {card}
                mode="info"
                busy={busyCardId === card.id}
                onAction={(action) => onCardAction(card, action)}
              />
            {/each}
          </div>
        {:else}
          <div class="shpd-feed__grid">
            {#each section.cards as card (card.id)}
              <FeedCard
                {card}
                busy={busyCardId === card.id}
                onAction={(action) => onCardAction(card, action)}
              />
            {/each}
          </div>
        {/if}
        {#if section.more > 0}
          <div class="shpd-feed__more">
            {#if MORE_VIEWER[section.id]}
              <button type="button" class="shpd-feed__more-link" onclick={() => openMore(section.id)}>
                {t('dashboard.feed.sectionMore', { n: section.more })}
              </button>
            {:else}
              <span class="shpd-feed__more-text">{t('dashboard.feed.sectionMore', { n: section.more })}</span>
            {/if}
          </div>
        {/if}
      </section>
    {/each}
  </div>
{/if}

<style>
  .shpd-feed {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
  }

  .shpd-feed__section {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }

  .shpd-feed__section-title {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    margin: 0;
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-feed__section-count {
    font-weight: 500;
  }

  /* Akce sekce (Archivovat vše) — vpravo v hlavičce, bez uppercase nadpisu. */
  .shpd-feed__section-action {
    margin-left: auto;
    text-transform: none;
    letter-spacing: normal;
  }

  /* Barevná tečka sekce — failed/review/ready zrcadlí barvy stavových
     proužků karet; alerts sdílí warning (sekce mísí závažnosti), newItems
     primary (odlišná od stavových barev), other tlumená. */
  .shpd-feed__section-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .shpd-feed__section-dot--newItems { background: var(--shpd-color-primary); }
  .shpd-feed__section-dot--ready    { background: var(--shpd-color-success); }
  .shpd-feed__section-dot--review   { background: var(--shpd-color-warning); }
  .shpd-feed__section-dot--attention { background: var(--shpd-color-warning); }
  .shpd-feed__section-dot--failed   { background: var(--shpd-color-danger); }
  .shpd-feed__section-dot--alerts   { background: var(--shpd-color-warning); }
  .shpd-feed__section-dot--other    { background: var(--shpd-color-text-secondary); }

  /* Failed: full-width karty pod sebou (D2). */
  .shpd-feed__stack {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  /* Grid (newItems / review / alerts): karty v řádku mají stejnou výšku
     (stretch), rozbalený detail vedle sbalené karty nechá u sousedky volné
     místo dole (záměr, žádný masonry, nerozbíjí prioritní pořadí). */
  .shpd-feed__grid {
    display: grid;
    /* min(360px, 100%) — na displeji užším než 360px nesmí karta přetéct. */
    grid-template-columns: repeat(auto-fill, minmax(min(360px, 100%), 1fr));
    gap: var(--shpd-space-md);
    align-items: stretch;
  }

  /* Other: sousední kompaktní řádky odděluje vlasová linka. Kreslí ji
     rodič — uvnitř FeedRowCompact by sibling selektor mezi instancemi
     komponenty scoped styl vyhodil jako nepoužitý. */
  .shpd-feed__rows > :global(.shpd-feed-row + .shpd-feed-row) {
    border-top: 1px solid var(--shpd-color-border);
  }

  /* „a N dalších" pod sekcí — textový odkaz (vzor toggle v FeedReadySection). */
  .shpd-feed__more {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-feed__more-link {
    padding: 0;
    border: none;
    background: none;
    color: var(--shpd-color-primary);
    font-family: var(--shpd-font-family);
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
  }

  .shpd-feed__more-link:hover {
    text-decoration: underline;
  }

  .shpd-feed__empty {
    padding: var(--shpd-space-xl);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }
</style>
