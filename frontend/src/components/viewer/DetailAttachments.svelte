<script>
  // Sdílená sekce příloh detailu záznamu (#136 D21) — kreslí
  // `detail.attachments.groups`, které skládá builder detailu na serveru.
  // Hostitel je ViewerDetail: na konci Přehledu (první tab) nebo ve
  // vlastním tabu s obsahem typu `attachments`.
  //
  // Skupiny:
  //  - `mail` — přílohy zdrojové zprávy došlé pošty: nadpis je krátký kód
  //    zprávy jako odkaz (plný kód v tooltipu) a datum přijetí;
  //  - `record` (a cokoli jiného) — vlastní přílohy záznamu, nadpis „Přílohy“.
  //
  // Přepínač Miniatury / Velké náhledy sedí v řádku nadpisu první skupiny
  // (snippet `aside` sdíleného SectionHeading, #137 D28) a platí pro všechny
  // skupiny — režim drží sdílený attachmentViewStore (stejná volba jako
  // v detailu došlé pošty). Vzhled převzatý beze změny z někdejší sekce
  // příloh DocumentDetail; písmo přes typografické role
  // (--shpd-font-size-heading / body / meta, #135 D15, D16).
  import { t } from '../../i18n/index.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { attachmentViewStore } from '../../stores/attachmentView.svelte.js';
  import AttachmentGrid from './AttachmentGrid.svelte';
  import SectionHeading from '../ui/SectionHeading.svelte';

  let { groups = [], onAction = null } = $props();

  let visibleGroups = $derived((groups ?? []).filter((group) => group));

  function groupKey(group, i) {
    return group.kind === 'mail' ? `mail-${group.message_ndx ?? i}` : `${group.kind ?? 'record'}-${i}`;
  }

  // Klik na kód zdrojové zprávy (tasks/mail-source-message-link.md D6, D10):
  // s hostitelem, který obsluhuje akce (Viewer.svelte), otevře read-only
  // detail zprávy v modalu a viewer se nepřepne. Akční id 'openSourceMessage'
  // není ve sdíleném slovníku vestavěných akcí, propadne na obsluhu podle
  // kind. Bez onAction (uvnitř read-only ViewerDetailModal) zůstává přechod
  // do vieweru Došlá pošta.
  function openSourceMessage(group) {
    if (!group.sourceViewerId) return;
    if (onAction) {
      onAction('openSourceMessage', {
        id: 'openSourceMessage',
        kind: 'open_detail',
        target: { viewerId: group.sourceViewerId, recordId: group.message_ndx, tabId: 'content' },
      });
      return;
    }
    navigationStore.navigateToViewer(group.sourceViewerId, group.message_ndx);
  }
</script>

{#snippet viewToggle()}
  <!-- Přepínač režimu náhledů — v řádku nadpisu první skupiny vpravo,
       platí pro všechny skupiny (sdílený store). -->
  <button
    type="button"
    class="shpd-detail-att__toggle"
    onclick={() => attachmentViewStore.toggle()}
  >
    {attachmentViewStore.mode === 'full'
      ? t('viewer.attachments.viewGrid')
      : t('viewer.attachments.viewFull')}
  </button>
{/snippet}

{#if visibleGroups.length > 0}
  <section class="shpd-detail-att" data-testid="detail-attachments">
    {#each visibleGroups as group, i (groupKey(group, i))}
      <div>
        <SectionHeading level={3} aside={i === 0 ? viewToggle : undefined}>
          {#if group.kind === 'mail'}
            <!-- Krátký kód zprávy, plný v tooltipu (D9). -->
            {#if group.sourceViewerId}
              <button
                type="button"
                class="shpd-detail-att__msglink"
                title={group.message_id}
                onclick={() => openSourceMessage(group)}
              >
                #{group.message_code_short ?? group.message_id}
              </button>
            {:else}
              <span title={group.message_id}>#{group.message_code_short ?? group.message_id}</span>
            {/if}
            {#if group.received_at}
              <span class="shpd-detail-att__msgdate">· {group.received_at}</span>
            {/if}
          {:else}
            {t('viewer.attachments.record')}
          {/if}
        </SectionHeading>
        <AttachmentGrid attachments={group.attachments ?? []} mode={attachmentViewStore.mode} />
      </div>
    {/each}
  </section>
{/if}

<style>
  /* Skupiny pod sebou; oddělení od obsahu nad sekcí (čára, odsazení) dává
     hostitel — ve vlastním tabu Přílohy čára nahoře nemá co oddělovat. */
  .shpd-detail-att {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
    font-size: var(--shpd-font-size-body);
  }

  /* Nadpis skupiny a řádek s přepínačem kreslí sdílený SectionHeading
     (#137 D28); mezeru pod nadpisem dává on. */
  .shpd-detail-att__msglink {
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-primary);
    cursor: pointer;
  }

  .shpd-detail-att__msglink:hover {
    text-decoration: underline;
  }

  .shpd-detail-att__msgdate {
    font-weight: var(--shpd-font-weight-normal);
  }

  .shpd-detail-att__toggle {
    border: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text-secondary);
    border-radius: var(--shpd-radius-md);
    padding: 0.125rem 0.625rem;
    font: inherit;
    font-size: var(--shpd-font-size-meta);
    white-space: nowrap;
    cursor: pointer;
  }

  .shpd-detail-att__toggle:hover {
    color: var(--shpd-color-text);
    border-color: var(--shpd-color-text-secondary);
  }
</style>
