<script>
  /**
   * Karty sekce v prázdné scoped konverzaci (UI shells Fáze 5, D4/R6) —
   * skutečné dashboard karty (`GET /_ui/dashboard?section=`) s funkčními
   * akcemi, žádná AI generace. Obsluhují se jen navigační druhy akcí
   * (open_viewer/open_panel/open_form/open_detail) — ostatní se z karty
   * odfiltrují; těžké dashboard flow (apply, undo…) sem nepatří.
   * Chyba fetche / prázdná sekce → blok se tiše vynechá.
   */
  import FeedCard from '../dashboard/FeedCard.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import ViewerDetailModal from '../viewer/ViewerDetailModal.svelte';
  import { fetchDashboard } from '../../api/dashboard.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { t } from '../../i18n/index.js';

  let { section } = $props();

  const SUPPORTED_ACTION_KINDS = ['open_viewer', 'open_panel', 'open_form', 'open_detail'];

  let cards = $state([]);
  let formModal = $state({ open: false, table: '', recordId: null });
  let detailModal = $state({ open: false, viewerId: '', recordId: null, tabId: null });

  $effect(() => {
    load(section);
  });

  async function load(sec) {
    if (!sec) {
      cards = [];
      return;
    }
    try {
      const data = await fetchDashboard(sec);
      cards = (data?.cards ?? []).map((c) => ({
        ...c,
        actions: (c.actions ?? []).filter((a) => SUPPORTED_ACTION_KINDS.includes(a.kind)),
      }));
    } catch {
      cards = [];
    }
  }

  function handleCardAction(card, action) {
    const target = action.target ?? {};
    switch (action.kind) {
      case 'open_viewer':
        return navigationStore.navigateToViewer(target.viewerId, target.recordId ?? null, target.viewGroup ?? null, target.filters ?? null);
      case 'open_panel':
        return navigationStore.navigateToPanel(target.panelId, action.label ?? null);
      case 'open_form':
        formModal = { open: true, table: target.table, recordId: target.recordId ?? target.id ?? null };
        return;
      case 'open_detail':
        detailModal = { open: true, viewerId: target.viewerId, recordId: target.recordId, tabId: target.tabId ?? null };
        return;
    }
  }
</script>

{#if cards.length > 0}
  <section class="shpd-section-cards" aria-label={t('chat.sectionCards.title')}>
    <h3 class="shpd-section-cards__title">{t('chat.sectionCards.title')}</h3>
    <div class="shpd-section-cards__list">
      {#each cards as card (card.id)}
        <FeedCard {card} onAction={(action) => handleCardAction(card, action)} />
      {/each}
    </div>
  </section>
{/if}

{#if formModal.open}
  <FormDialog
    table={formModal.table}
    recordId={formModal.recordId}
    open={formModal.open}
    onSaved={() => (formModal = { open: false, table: '', recordId: null })}
    onClose={() => (formModal = { open: false, table: '', recordId: null })}
  />
{/if}

<ViewerDetailModal
  open={detailModal.open}
  viewerId={detailModal.viewerId}
  recordId={detailModal.recordId}
  tabId={detailModal.tabId}
  onClose={() => (detailModal = { open: false, viewerId: '', recordId: null, tabId: null })}
/>

<style>
  .shpd-section-cards {
    margin-top: var(--shpd-space-lg);
  }

  .shpd-section-cards__title {
    margin: 0 0 var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .shpd-section-cards__list {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }
</style>
