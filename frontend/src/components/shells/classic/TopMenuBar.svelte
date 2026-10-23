<script>
  // Horní menu classic shellu (R6/R7): BrandingHeader, domeček (`_top`,
  // jen ikona, D2), sekce = root skupiny app stromu (server je řadí dle
  // navSections.order a prázdné vynechává — žádný klientský filtr);
  // vpravo trigger palety a UserMenu (compact, dropdown dolů).
  //
  // Klik na sekci aktivuje její první leaf (D1, depth-first přes
  // flattenLeaves); klik na domeček první root-level leaf. Aktivní
  // sekce = navigationStore.activeSection, domeček aktivní při null.
  // Badge čte shell přímo ze sectionBadgesStore (shelly nejsou hloupé
  // primitivy) — sekce pod svým id, domeček pod `_top`.
  import Icon from '../../ui/Icon.svelte';
  import BrandingHeader from '../../chrome/BrandingHeader.svelte';
  import UserMenu from '../../chrome/UserMenu.svelte';
  import { navigationStore } from '../../../stores/navigation.svelte.js';
  import { paletteStore } from '../../../stores/palette.svelte.js';
  import { sectionBadgesStore } from '../../../stores/sectionBadges.svelte.js';
  import { chatStore } from '../../../stores/chat.svelte.js';
  import { chatPanelStore } from '../../../stores/chatPanel.svelte.js';
  import { flattenLeaves } from '../../../utils/navTree.js';
  import { t, tn } from '../../../i18n/index.js';
  import { iconHome, iconSearch, iconChat } from '../../../icons.js';

  let { onNavigate, onOpenThemePanel } = $props();

  const tree = $derived(navigationStore.appNavTree ?? []);
  // Sekce = root uzly se children (leafy `_top`/dashboard/chat mají type).
  const sections = $derived(tree.filter((n) => !n?.type && n?.children?.length));
  const rootLeaves = $derived(tree.filter((n) => n?.type));

  const activeSection = $derived(navigationStore.activeSection);

  function badgeCount(count) {
    return count > 99 ? '99+' : String(count);
  }

  function selectSection(section) {
    const leaf = flattenLeaves(section.children)[0];
    if (leaf) onNavigate?.(leaf);
  }

  function selectHome() {
    if (rootLeaves.length > 0) onNavigate?.(rootLeaves[0]);
  }

  // Trigger palety — tooltip se zkratkou dle platformy (vzor Sidebar).
  const isMac = /Mac|iPhone|iPad/.test(navigator.platform ?? '');
  const searchTitle = t('palette.trigger') + ' · ' + (isMac ? '⌘K' : 'Ctrl+K');

  // Vstup do ChatPanelu z chrome (UI shells Fáze 5) — nová konverzace
  // zdědí scope z aktivní sekce; gate = Chat leaf ve stromu (vzor Sidebar).
  const hasChat = $derived(tree.some((n) => n?.type === 'chat'));

  function toggleChatPanel() {
    if (chatPanelStore.isOpen) {
      chatPanelStore.close();
      return;
    }
    chatStore.newConversation(navigationStore.activeSection);
    chatPanelStore.open();
  }
</script>

<header class="shpd-topmenu">
  <div class="shpd-topmenu__brand">
    <BrandingHeader />
  </div>

  <nav class="shpd-topmenu__nav" aria-label={t('shell.classic.sections')}>
    <!-- Domeček — root-level leafy (`_top`, dashboard, chat) v pásu. -->
    <button
      class="shpd-topmenu__section shpd-topmenu__section--home"
      class:shpd-topmenu__section--active={activeSection === null}
      onclick={selectHome}
      title={t('shell.classic.home')}
      aria-label={t('shell.classic.home')}
    >
      <Icon icon={iconHome} size="sm" />
      {#if sectionBadgesStore.badges._top?.count > 0}
        <span
          class="shpd-topmenu__badge"
          class:shpd-topmenu__badge--danger={sectionBadgesStore.badges._top.severity === 'danger'}
          aria-label={tn('sidebar.sectionBadge', sectionBadgesStore.badges._top.count)}
        >
          <span class="shpd-topmenu__badge-dot" aria-hidden="true"></span>
          {badgeCount(sectionBadgesStore.badges._top.count)}
        </span>
      {/if}
    </button>

    {#each sections as section (section.id)}
      <button
        class="shpd-topmenu__section"
        class:shpd-topmenu__section--active={activeSection === section.id}
        onclick={() => selectSection(section)}
      >
        <span>{section.label}</span>
        {#if sectionBadgesStore.badges[section.id]?.count > 0}
          <span
            class="shpd-topmenu__badge"
            class:shpd-topmenu__badge--danger={sectionBadgesStore.badges[section.id].severity === 'danger'}
            aria-label={tn('sidebar.sectionBadge', sectionBadgesStore.badges[section.id].count)}
          >
            <span class="shpd-topmenu__badge-dot" aria-hidden="true"></span>
            {badgeCount(sectionBadgesStore.badges[section.id].count)}
          </span>
        {/if}
      </button>
    {/each}
  </nav>

  <div class="shpd-topmenu__actions">
    <button
      class="shpd-topmenu__tool"
      onclick={() => paletteStore.openPalette()}
      title={searchTitle}
      aria-label={searchTitle}
    >
      <Icon icon={iconSearch} size="sm" />
    </button>
    {#if hasChat}
      <button
        class="shpd-topmenu__tool"
        onclick={toggleChatPanel}
        title={t('chat.panel.title')}
        aria-label={t('chat.panel.title')}
      >
        <Icon icon={iconChat} size="sm" />
      </button>
    {/if}
    <UserMenu compact direction="down" {onOpenThemePanel} />
  </div>
</header>

<style>
  /* Bar sdílí barevný systém sidebaru (vč. custom theme gradientu) —
     chrome je chrome, ať ho barví stejné tokeny. */
  .shpd-topmenu {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    height: var(--shpd-header-height);
    padding: 0 var(--shpd-space-md);
    background: var(--shpd-sidebar-bg-image, var(--shpd-color-bg-sidebar));
    color: var(--shpd-color-text-sidebar);
    border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
    flex-shrink: 0;
  }

  .shpd-topmenu__brand {
    flex-shrink: 0;
    min-width: 0;
  }

  .shpd-topmenu__nav {
    display: flex;
    align-items: center;
    gap: 0.125rem;
    flex: 1;
    min-width: 0;
    overflow-x: auto;
  }

  .shpd-topmenu__section {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar);
    border-radius: var(--shpd-radius-sm);
    white-space: nowrap;
    transition: background-color 0.15s, opacity 0.15s;
    opacity: 0.85;
  }

  .shpd-topmenu__section:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
    opacity: 1;
  }

  .shpd-topmenu__section--active {
    background-color: var(--shpd-color-sidebar-active-bg);
    opacity: 1;
    font-weight: 500;
  }

  .shpd-topmenu__section--active:hover {
    background-color: var(--shpd-color-sidebar-active-bg-hover);
  }

  /* Spodní accent proužek u aktivní sekce — horizontální obdoba levého
     proužku v NavTree. */
  .shpd-topmenu__section--active::after {
    content: '';
    position: absolute;
    left: 4px;
    right: 4px;
    bottom: 0;
    height: 3px;
    border-radius: 0.125rem 0.125rem 0 0;
    background-color: var(--shpd-color-accent);
  }

  /* Badge — stejná řeč jako NavTree (tečka severity + počet). */
  .shpd-topmenu__badge {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    font-size: 0.7rem;
    font-weight: 600;
  }

  .shpd-topmenu__badge-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background-color: var(--shpd-color-warning, #d97706);
  }

  .shpd-topmenu__badge--danger .shpd-topmenu__badge-dot {
    background-color: var(--shpd-color-danger, #ef4444);
  }

  .shpd-topmenu__actions {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
  }

  .shpd-topmenu__tool {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    color: var(--shpd-color-text-sidebar-muted);
    border-radius: var(--shpd-radius-sm);
    transition: color 0.15s, background-color 0.15s;
  }

  .shpd-topmenu__tool:hover {
    color: var(--shpd-color-text-sidebar);
    background-color: var(--shpd-color-bg-sidebar-hover);
  }
</style>
