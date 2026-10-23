<script>
  // Sidebar shell — kompozice chrome primitiv (components/chrome/):
  // BrandingHeader + collapse toggle, ModeBackBar (settings/account),
  // NavTree / NavIconStrip dle `collapsed`, UserMenu v patce.
  // Vlastní zůstává: fetch navigace per mode, collapsed stav, loading/error.
  import { get } from '../../api/client.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { paletteStore } from '../../stores/palette.svelte.js';
  import { sectionBadgesStore } from '../../stores/sectionBadges.svelte.js';
  import { chatStore } from '../../stores/chat.svelte.js';
  import { chatPanelStore } from '../../stores/chatPanel.svelte.js';
  import { t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import BrandingHeader from '../chrome/BrandingHeader.svelte';
  import ModeBackBar from '../chrome/ModeBackBar.svelte';
  import NavTree from '../chrome/NavTree.svelte';
  import NavIconStrip from '../chrome/NavIconStrip.svelte';
  import UserMenu from '../chrome/UserMenu.svelte';
  import {
    iconCollapse,
    iconExpand,
    iconClose,
    iconSearch,
    iconSpinner,
    iconWarning,
    iconChat,
  } from '../../icons.js';

  // `collapsed` je bindable — AppShell ho zrcadlí kvůli pozici ThemePanel
  // (panel se renderuje v AppShellu, ne tady; drawer má transform, který
  // by position:fixed panel uvěznil). Panel custom vzhledu už neotevírá
  // sidebar (dropdown vzhledu zanikl) — otevírá ho ThemeField na stránce
  // Nastavení účtu → Základní.
  let { onNavigate, onOpenThemePanel, collapsed = $bindable(false) } = $props();

  // App strom vlastní navigationStore (loadAppNavTree volá AppShell —
  // strom potřebují všechny shelly). Lokální fetch zůstává jen pro
  // settings/account strom, který jiný shell nekreslí.
  let modeTree    = $state([]);
  let modeLoading = $state(true);
  let modeError   = $state(null);

  $effect(() => {
    if (navigationStore.mode === 'app') return;

    const url = navigationStore.mode === 'settings'
      ? '/_ui/settings/navigation'
      : '/_ui/account/navigation';

    modeLoading = true;
    modeError   = null;
    modeTree    = [];

    (async () => {
      try {
        const response = await get(url);
        if (response === null) { modeError = t('sidebar.notAuthenticated'); return; }
        if (!response.success) { modeError = response.error?.message ?? t('sidebar.navigationLoadFailed'); return; }
        modeTree = response.data;
      } catch {
        modeError = t('sidebar.navigationLoadFailed');
      } finally {
        modeLoading = false;
      }
    })();
  });

  // App mód: strom + loading/error ze storu (null strom bez chyby =
  // ještě se načítá); ostatní módy z lokálního fetche.
  const navTree = $derived(
    navigationStore.mode === 'app' ? (navigationStore.appNavTree ?? []) : modeTree
  );
  const loading = $derived(
    navigationStore.mode === 'app'
      ? (navigationStore.appNavTree === null && navigationStore.appNavError === null)
      : modeLoading
  );
  const error = $derived.by(() => {
    if (navigationStore.mode !== 'app') return modeError;
    const e = navigationStore.appNavError;
    if (!e) return null;
    if (e.code === 'unauthenticated') return t('sidebar.notAuthenticated');
    return e.message ?? t('sidebar.navigationLoadFailed');
  });

  function handleItemClick(item) {
    const navItem = { id: item.id, label: item.label, type: item.type, table: item.table, viewerId: item.viewerId, pageId: item.pageId, panelId: item.panelId, panelParams: item.panelParams, filter: item.filter, fixedViewGroup: item.fixedViewGroup };
    navigationStore.navigate(navItem);
    onNavigate?.(navItem);
  }

  function toggleCollapse() {
    collapsed = !collapsed;
  }

  // Trigger command palety — tooltip nese zkratku dle platformy (R5/D5).
  const isMac = /Mac|iPhone|iPad/.test(navigator.platform ?? '');
  const searchTitle = t('palette.trigger') + ' · ' + (isMac ? '⌘K' : 'Ctrl+K');

  function openPalette() {
    paletteStore.openPalette();
    // Na mobilu by drawer zůstal přes overlay palety.
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }

  // Vstup do ChatPanelu z chrome (UI shells Fáze 5) — nová konverzace
  // zdědí scope z aktivní sekce. Gate = Chat leaf ve stromu (týž výraz
  // jako capability `chat` v /_ui/dashboard).
  const hasChat = $derived(
    navigationStore.mode === 'app'
      && (navigationStore.appNavTree ?? []).some((n) => n?.type === 'chat'),
  );

  function toggleChatPanel() {
    if (chatPanelStore.isOpen) {
      chatPanelStore.close();
      return;
    }
    chatStore.newConversation(navigationStore.activeSection);
    chatPanelStore.open();
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }
</script>

<nav
  class="shpd-sidebar"
  data-testid="sidebar"
  class:shpd-sidebar--collapsed={collapsed}
>
  <div class="shpd-sidebar__header">
    {#if !collapsed || layoutStore.isMobile}
      <BrandingHeader />
    {/if}
    <div class="shpd-sidebar__header-actions">
      {#if !collapsed || layoutStore.isMobile}
        <!-- Lupa = trigger command palety; ve sbaleném desktopu se
             přesouvá nad NavIconStrip (v hlavičce není místo). -->
        <button
          class="shpd-sidebar__toggle"
          onclick={openPalette}
          title={searchTitle}
          aria-label={searchTitle}
        >
          <Icon icon={iconSearch} size="sm" />
        </button>
        {#if hasChat}
          <button
            class="shpd-sidebar__toggle"
            onclick={toggleChatPanel}
            title={t('chat.panel.title')}
            aria-label={t('chat.panel.title')}
          >
            <Icon icon={iconChat} size="sm" />
          </button>
        {/if}
      {/if}
      {#if layoutStore.isMobile}
        <!-- Na mobilu nahrazuje collapse toggle ✕, které zavře drawer.
             Collapse nedává v draweru smysl (je buď otevřený, nebo zavřený). -->
        <button
          class="shpd-sidebar__toggle"
          onclick={() => layoutStore.closeDrawer()}
          aria-label={t('app.menu.close')}
        >
          <Icon icon={iconClose} size="sm" />
        </button>
      {:else}
        <button class="shpd-sidebar__toggle" onclick={toggleCollapse} title={collapsed ? t('sidebar.expand') : t('sidebar.collapse')}>
          <Icon icon={collapsed ? iconExpand : iconCollapse} size="sm" />
        </button>
      {/if}
    </div>
  </div>

  {#if navigationStore.mode !== 'app'}
    <ModeBackBar compact={collapsed} />
  {/if}

  <div class="shpd-sidebar__nav">
    {#if loading}
      <div class="shpd-sidebar__status">
        <Icon icon={iconSpinner} spin size="sm" />
        {#if !collapsed}<span>{t('common.loading')}</span>{/if}
      </div>
    {:else if error}
      <div class="shpd-sidebar__status shpd-sidebar__status--error">
        {#if collapsed}
          <Icon icon={iconWarning} size="sm" />
        {:else}
          {error}
        {/if}
      </div>
    {:else if collapsed}
      <!-- Trigger palety ve sbaleném pásu ikon — nad navigací. -->
      <div class="shpd-sidebar__search-strip">
        <button
          class="shpd-sidebar__toggle"
          onclick={openPalette}
          title={searchTitle}
          aria-label={searchTitle}
        >
          <Icon icon={iconSearch} size="sm" />
        </button>
        {#if hasChat}
          <button
            class="shpd-sidebar__toggle"
            onclick={toggleChatPanel}
            title={t('chat.panel.title')}
            aria-label={t('chat.panel.title')}
          >
            <Icon icon={iconChat} size="sm" />
          </button>
        {/if}
      </div>
      <NavIconStrip tree={navTree} activeId={navigationStore.activeId} onNavigate={handleItemClick} />
    {:else}
      <!-- Badge stavů sekcí jen v app módu — settings/account strom je nemá. -->
      <NavTree
        tree={navTree}
        activeId={navigationStore.activeId}
        onNavigate={handleItemClick}
        sectionBadges={navigationStore.mode === 'app' ? sectionBadgesStore.badges : {}}
      />
    {/if}
  </div>

  <UserMenu compact={collapsed} {onOpenThemePanel} />
</nav>

<style>
  .shpd-sidebar {
    display: flex;
    flex-direction: column;
    width: var(--shpd-sidebar-width);
    height: 100%;
    /* Shorthand kvůli custom gradientu (--shpd-sidebar-bg-image je
       image, ne barva) — token existuje jen jako inline property
       z theme storu, jinak platí fallback na solid barvu. */
    background: var(--shpd-sidebar-bg-image, var(--shpd-color-bg-sidebar));
    color: var(--shpd-color-text-sidebar);
    flex-shrink: 0;
    transition: width 0.2s ease;
    overflow: hidden;
  }

  .shpd-sidebar--collapsed {
    width: var(--shpd-sidebar-width-collapsed);
    /* Ve sbaleném stavu povolíme overflow, aby user-menu dropdown
       (side varianta v UserMenu) mohl vyčnívat doprava ven ze
       48px proužku. V rozbaleném stavu zůstává `overflow: hidden`
       z `.shpd-sidebar` kvůli skrývání labelů během toggle transition. */
    overflow: visible;
  }

  .shpd-sidebar__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: var(--shpd-header-height);
    padding: 0 var(--shpd-space-md);
    flex-shrink: 0;
    border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
  }

  .shpd-sidebar--collapsed .shpd-sidebar__header {
    justify-content: center;
    padding: 0;
  }

  .shpd-sidebar__nav {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  .shpd-sidebar__header-actions {
    display: flex;
    align-items: center;
    gap: 0.125rem;
    flex-shrink: 0;
  }

  .shpd-sidebar__search-strip {
    display: flex;
    justify-content: center;
    padding-top: var(--shpd-space-sm);
  }

  .shpd-sidebar__toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    color: var(--shpd-color-text-sidebar-muted);
    border-radius: var(--shpd-radius-sm);
    transition: color 0.15s, background-color 0.15s;
    flex-shrink: 0;
  }

  .shpd-sidebar__toggle:hover {
    color: var(--shpd-color-text-sidebar);
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-sidebar__status {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar-muted);
  }

  .shpd-sidebar__status--error {
    color: var(--shpd-color-danger, #ef4444);
  }
</style>
