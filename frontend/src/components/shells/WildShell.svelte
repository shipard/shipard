<script>
  // Wild shell — AI-first, maximálně kompaktní (Fáze 6): svislý rail
  // sekcí s badge vlevo, obsah se záložkami-ikonami nahoře, první
  // záložka = AI asistent sekce (D3). Desktop-only a jen app mód —
  // garantuje resolver v AppShellu; mobil a settings/account dostávají
  // SidebarShell.
  //
  // Stav prohlížení je shell-lokální (D5, store wildShell.svelte.js):
  // klik na rail mění jen prohlíženou sekci, klik na leaf záložku
  // naviguje (activeSection dojede derivací). Paměť posledního stavu
  // per sekce (D7) — návrat obnoví záložku (vč. AI), AI záložka jen při
  // prvním vstupu v session; přežije settings mód, reload ne.
  import { untrack } from 'svelte';
  import ContentArea from '../layout/ContentArea.svelte';
  import SectionRail from './wild/SectionRail.svelte';
  import NavTabStrip from '../chrome/NavTabStrip.svelte';
  import SectionAssistant from '../chat/SectionAssistant.svelte';
  import Icon from '../ui/Icon.svelte';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { wildShellStore } from '../../stores/wildShell.svelte.js';
  import { resolveLanding } from '../../utils/wildLanding.js';
  import { findLeafById, flattenLeaves } from '../../utils/navTree.js';
  import { iconChat } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  let {
    onOpenThemePanel,
    // ThemePanel kreslí AppShell — wild hlásí konstantní offset šířky
    // railu (CSS délka nad tokeny, vzor ClassicShell).
    themePanelLeftOffset = $bindable('calc(var(--shpd-rail-width) + var(--shpd-space-sm))'),
  } = $props();

  $effect(() => {
    themePanelLeftOffset = 'calc(var(--shpd-rail-width) + var(--shpd-space-sm))';
  });

  const tree = $derived(navigationStore.appNavTree ?? []);
  // Gate AI záložky = Chat leaf ve stromu (tentýž výraz jako chrome
  // tlačítko chatu ostatních shellů).
  const hasChat = $derived(tree.some((n) => n?.type === 'chat'));

  // Záložky: úroveň 2 prohlížené sekce; domeček → root-level leafy
  // (`_top`, dashboard, chat) — vzor ClassicShell.stripNodes.
  const tabNodes = $derived.by(() => {
    const sectionId = wildShellStore.browsingSection;
    if (sectionId === null) {
      return tree.filter((n) => n?.type);
    }
    return tree.find((n) => n.id === sectionId)?.children ?? [];
  });

  // Aktivní záložka prohlížené sekce = její poslední záznam (každá změna
  // záložky i externí navigace ho zapisují) — AI záložka je stav, ne route.
  const onAiTab = $derived.by(() => {
    const sectionId = wildShellStore.browsingSection;
    return sectionId !== null && hasChat
      && wildShellStore.getLastTab(sectionId)?.tab === 'ai';
  });

  // Na AI záložce žádný leaf nesvítí; jinak jen když aktivní leaf patří
  // prohlížené sekci (prohlížení B s aktivním leafem v A → nic nesvítí).
  const stripActiveId = $derived(
    onAiTab ? null
      : navigationStore.activeSection === wildShellStore.browsingSection
        ? navigationStore.activeId
        : null
  );

  // Mount: první v session (soft swap volby) adoptuje aktivní položku;
  // remount po settings módu adoptuje jen když mezitím proběhla navigace
  // (paleta ze settings) — synchronně před renderem, efekt níže by stihl
  // až frame po něm. Beze změny navigace paměť drží.
  if (!wildShellStore.initialized
    || wildShellStore.navigationChanged(navigationStore.activeSection, navigationStore.activeId)) {
    wildShellStore.adoptNavigation(navigationStore.activeSection, navigationStore.activeId);
  }

  // R3 — externí navigace (paleta, deep link, karty dashboardu) srovná
  // prohlížení. Reaguje jen na ZMĚNU proti poslední adoptované navigaci
  // (drží ji store, aby přežila unmount — paleta ze settings módu
  // naviguje bez namontovaného shellu a mount ji musí dohnat; shodná
  // navigace = no-op, settings roundtrip nepřepíše záznam 'ai'). Klik
  // na rail mění jen browsingSection (netrackováno) → efekt mlčí,
  // prohlížení se nevrací.
  $effect(() => {
    const section = navigationStore.activeSection;
    const activeId = navigationStore.activeId;
    if (!wildShellStore.navigationChanged(section, activeId)) return;
    untrack(() => wildShellStore.adoptNavigation(section, activeId));
  });

  // Guard: refetch stromu mohl prohlíženou sekci odstranit (zprázdněla) —
  // srovnej prohlížení dle skutečné navigace.
  $effect(() => {
    if (tree.length === 0) return;
    const sectionId = untrack(() => wildShellStore.browsingSection);
    if (sectionId !== null && !tree.some((n) => !n?.type && n?.id === sectionId)) {
      untrack(() => wildShellStore.adoptNavigation(
        navigationStore.activeSection, navigationStore.activeId,
      ));
    }
  });

  // Stejná normalizace položky jako ClassicShell.handleNavigate —
  // navigate() si bere jen whitelist polí (+ icon pro recents palety).
  function navigateLeaf(item) {
    navigationStore.navigate({
      id: item.id,
      label: item.label,
      type: item.type,
      table: item.table,
      viewerId: item.viewerId,
      pageId: item.pageId,
      panelId: item.panelId,
      panelParams: item.panelParams,
      filter: item.filter,
      fixedViewGroup: item.fixedViewGroup,
      icon: item.icon,
    });
  }

  // Klik na leaf záložku (i z dropdownu skupiny) — zapiš + naviguj (R6).
  function handleTabNavigate(leaf) {
    wildShellStore.recordTab(wildShellStore.browsingSection, leaf.id);
    navigateLeaf(leaf);
  }

  // Klik na AI záložku — jen stav, žádná navigace (D5).
  function handleAiTab() {
    wildShellStore.recordTab(wildShellStore.browsingSection, 'ai');
  }

  // Klik na rail (R6/D3/D7): obnov poslední stav sekce, první vstup → AI
  // záložka; domeček → poslední `_top` leaf, jinak dashboard.
  function handleSelectSection(sectionId) {
    wildShellStore.setBrowsing(sectionId);
    const nodes = sectionId === null
      ? tree.filter((n) => n?.type)
      : tree.find((n) => n.id === sectionId)?.children ?? [];
    const target = resolveLanding(wildShellStore.getLastTab(sectionId), sectionId);

    if (target === 'ai' && hasChat && sectionId !== null) {
      wildShellStore.recordTab(sectionId, 'ai');
      return;
    }
    let leaf = typeof target === 'string' && target !== 'ai'
      ? findLeafById(nodes, target)
      : null;
    if (!leaf) {
      // Stale záznam (strom se změnil) / bez chatu / domeček bez záznamu.
      if (sectionId !== null && hasChat) {
        wildShellStore.recordTab(sectionId, 'ai');
        return;
      }
      leaf = sectionId === null
        ? nodes.find((n) => n.type === 'dashboard') ?? nodes[0] ?? null
        : flattenLeaves(nodes)[0] ?? null;
    }
    if (!leaf) return; // prázdná sekce — server ji nevrací, defenzivně
    wildShellStore.recordTab(sectionId, leaf.id);
    // Už aktivní leaf → jen prohlížení, bez redundantního navigate.
    if (leaf.id !== navigationStore.activeId) navigateLeaf(leaf);
  }
</script>

<div class="shpd-wild" data-testid="app-shell">
  <SectionRail
    browsingSection={wildShellStore.browsingSection}
    onSelectSection={handleSelectSection}
    {onOpenThemePanel}
  />

  <div class="shpd-wild__main">
    <div class="shpd-wild__tabs">
      {#if hasChat && wildShellStore.browsingSection !== null}
        <!-- AI záložka — kompozice vedle NavTabStrip (R1), primitiv o AI
             nic neví. Ikona chatu, ne „sparkle" (R8). -->
        <button
          class="shpd-wild__ai-tab"
          class:shpd-wild__ai-tab--active={onAiTab}
          onclick={handleAiTab}
          title={t('shell.wild.aiTab')}
          aria-label={t('shell.wild.aiTab')}
        >
          <Icon icon={iconChat} size="md" />
          {#if onAiTab}
            <span class="shpd-wild__ai-tab-label">{t('shell.wild.aiTab')}</span>
          {/if}
        </button>
        <span class="shpd-wild__tabs-sep" aria-hidden="true"></span>
      {/if}
      <NavTabStrip
        nodes={tabNodes}
        activeId={stripActiveId}
        onNavigate={handleTabNavigate}
      />
    </div>

    <div class="shpd-wild__content">
      {#if onAiTab}
        <SectionAssistant section={wildShellStore.browsingSection} />
      {/if}
      <!-- ContentArea zůstává mounted (jen skrytý) — {#if} swap by při
           každé návštěvě AI záložky remountoval viewery a ztrácel
           scroll/selection. -->
      <div class="shpd-wild__content-pane" class:shpd-wild__content-pane--hidden={onAiTab}>
        <ContentArea activeItem={navigationStore.activeItem} {onOpenThemePanel} />
      </div>
    </div>
  </div>
</div>

<style>
  .shpd-wild {
    display: flex;
    /* Stejný dvh pattern jako SidebarShell — ořez pod adresní lištou. */
    height: 100vh;
    height: 100dvh;
    overflow: hidden;
    position: relative;
  }

  .shpd-wild__main {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
    min-height: 0;
  }

  /* Pruh záložek sdílí barevný systém sidebaru (chrome je chrome). */
  .shpd-wild__tabs {
    display: flex;
    align-items: center;
    gap: 0.125rem;
    height: 44px;
    padding: 0 var(--shpd-space-sm);
    background: var(--shpd-sidebar-bg-image, var(--shpd-color-bg-sidebar));
    border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
    flex-shrink: 0;
  }

  /* AI záložka — stejná řeč jako položky NavTabStrip. */
  .shpd-wild__ai-tab {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    min-width: 36px;
    height: 36px;
    padding: 0 var(--shpd-space-sm);
    color: var(--shpd-color-text-sidebar);
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.15s, opacity 0.15s;
    opacity: 0.85;
    flex-shrink: 0;
  }

  .shpd-wild__ai-tab-label {
    max-width: 200px;
    font-size: var(--shpd-font-size-sm);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .shpd-wild__ai-tab:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
    opacity: 1;
  }

  .shpd-wild__ai-tab--active {
    background-color: var(--shpd-color-sidebar-active-bg);
    opacity: 1;
  }

  .shpd-wild__ai-tab--active::after {
    content: '';
    position: absolute;
    left: 4px;
    right: 4px;
    bottom: 0;
    height: 3px;
    border-radius: 0.125rem 0.125rem 0 0;
    background-color: var(--shpd-color-accent);
  }

  .shpd-wild__tabs-sep {
    width: 1px;
    height: 22px;
    margin: 0 var(--shpd-space-xs);
    background-color: var(--shpd-color-bg-sidebar-border);
    flex-shrink: 0;
  }

  .shpd-wild__content {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
    min-height: 0;
  }

  .shpd-wild__content-pane {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
    min-height: 0;
  }

  .shpd-wild__content-pane--hidden {
    display: none;
  }
</style>
