<script>
  // Chrome primitiv: avatar + jméno v patce s dropdown menu — Nastavení
  // účtu / Nastavení aplikace, vzhled, rozložení a jazyk (podmenu), odhlásit. `compact` = jen kruhový
  // avatar, dropdown vyjíždí do strany (side-overlay). `direction`:
  // 'up' (default, patka sidebaru) | 'down' (horizontální top bar
  // classic shellu — dropdown dolů, zarovnaný doprava, bez patkového
  // rámečku).
  //
  // Mode akce volá přímo navigationStore (enterAccount/enterSettings);
  // logout si UserMenu řeší samo (logout() API + clearAuth) — je to
  // jediné místo s tlačítkem Odhlásit ve všech shellech. Menu se
  // při logoutu záměrně NEZAVÍRÁ, celý chrome zmizí sám, jakmile
  // clearAuth přepne aplikaci
  // na LoginScreen (viz docs/frontend.md §9 Dropdown / popover komponenty).
  import { logout } from '../../api/auth.js';
  import { authStore } from '../../stores/auth.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { themeStore } from '../../stores/theme.svelte.js';
  import { shellStore } from '../../stores/shell.svelte.js';
  import { avatarStore } from '../../stores/avatar.svelte.js';
  import { language, t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import {
    iconLogout,
    iconChevronDown,
    iconChevronUp,
    iconChevronLeft,
    iconChevronRight,
    iconSettings,
    iconAppSettings,
    iconConfirm,
    iconPalette,
    iconLanguage,
    iconLayout,
  } from '../../icons.js';

  let { compact = false, direction = 'up', onOpenThemePanel } = $props();

  let userMenuOpen = $state(false);
  let userMenuRoot = $state(null);
  let openSubmenu = $state(null);     // 'appearance' | 'shell' | 'language' | null
  let submenuFixedBottom = $state(0); // px od spodku viewportu
  let submenuFixedLeft = $state(0);   // px zleva — pravá hrana položky + 6
  // Kotvy flyoutu (fixed varianta) — wrapper každé položky s podmenu.
  let appearanceWrapEl = $state(null);
  let shellWrapEl = $state(null);
  let languageWrapEl = $state(null);

  // Plný sidebar: flyout musí být fixed (únik z overflow:hidden),
  // vertikálně se zarovnává na položku — pozice se měří při otevření.
  const fixedFlyout = $derived(!compact && direction === 'up' && !layoutStore.isMobile);

  function toggleUserMenu() {
    userMenuOpen = !userMenuOpen;
    openSubmenu = null;
  }

  function closeUserMenu() {
    userMenuOpen = false;
    openSubmenu = null;
  }

  function handleSettings() {
    navigationStore.enterAccount();
    // Menu se zavře přes $effect na změnu módu níže.
    // Na mobilu navíc zavři drawer (enterAccount neprochází handleNavigate).
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }

  function handleAppSettings() {
    navigationStore.enterSettings();
    // Menu se zavře přes $effect níže při změně módu.
    // Na mobilu navíc zavři drawer — enterSettings nejde přes
    // AppShell.handleNavigate, takže by drawer zůstal otevřený.
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }

  // Při změně módu zavři user menu
  $effect(() => {
    void navigationStore.mode;
    closeUserMenu();
  });

  // Položky jazyka — přepnutí volá location.reload() v storu, takže menu
  // se zavírat nemusí (stránka se beztak překreslí celá).
  const languageOptions = [
    { value: 'cs',   labelKey: 'sidebar.language.cs' },
    { value: 'en',   labelKey: 'sidebar.language.en' },
    { value: 'auto', labelKey: 'sidebar.language.auto' },
  ];

  function handleLanguageChange(value) {
    language.setMode(value);
  }

  // Podmenu Vzhled — user-scope volba vzhledu (Issue #46). Volba módu
  // implikuje override (setMode uvnitř nastavuje follow=false), „Podle
  // aplikace“ vrací follow. Menu po volbě zůstává otevřené (živý
  // náhled), zavírá ho až „Vlastní“ (otvírá ThemePanel) nebo klik mimo.
  // Strana flyoutu: menu zarovnané vpravo (classic top bar) → doleva,
  // jinak doprava. Mobil místo flyoutu rozbaluje inline (akordeon).
  const submenuSide = $derived(direction === 'down' ? 'left' : 'right');

  const appearanceModes = [
    { value: 'light',  labelKey: 'sidebar.appearance.light' },
    { value: 'dark',   labelKey: 'sidebar.appearance.dark' },
    { value: 'custom', labelKey: 'sidebar.appearance.custom' },
  ];

  // Otevřené je vždy nejvýš jedno podmenu; u fixed varianty se při
  // otevření změří kotva (spodek flyoutu = spodek položky, roste nahoru).
  function toggleSubmenu(name, wrapEl) {
    openSubmenu = openSubmenu === name ? null : name;
    if (openSubmenu && fixedFlyout && wrapEl) {
      // Stejné kotvy jako absolute varianta (bottom:0, left:100%+6px),
      // jen přepočtené do viewport souřadnic.
      const rect = wrapEl.getBoundingClientRect();
      submenuFixedBottom = window.innerHeight - rect.bottom;
      submenuFixedLeft = rect.right + 6;
    }
  }

  function selectAppearanceFollow() {
    themeStore.setFollow(true);
  }

  function selectAppearanceMode(value) {
    themeStore.setMode(value);
    if (value === 'custom') {
      // ThemePanel si po otevření registruje document click listener —
      // otevření deferujeme za aktuální klik (vzor ThemeField).
      closeUserMenu();
      setTimeout(() => { onOpenThemePanel?.(); }, 0);
    }
  }

  // Podmenu Rozložení — user-scope volba shellu (Issue #46, fáze 3).
  // Volba spouští okamžitý soft swap (vzor ShellField); na mobilu se
  // položka skrývá — resolver tam volbu ignoruje (D5).
  const shellOptions = [
    { value: 'sidebar', labelKey: 'shell.option.sidebar' },
    { value: 'classic', labelKey: 'shell.option.classic' },
    { value: 'wild',    labelKey: 'shell.option.wild' },
  ];

  function selectShellFollow() {
    shellStore.setFollow(true);
  }

  function selectShellOverride(value) {
    shellStore.setOverride(value);
  }

  async function handleLogoutFromMenu() {
    // Záměrně nezavíráme menu předem — celý chrome zmizí sám,
    // jakmile clearAuth přepne aplikaci na LoginScreen.
    try {
      await logout();
    } catch (err) {
      console.warn('Logout API call failed (continuing):', err);
    }
    authStore.clearAuth();
  }

  // Zavři menu při kliknutí mimo něj nebo při Escape.
  $effect(() => {
    if (!userMenuOpen) return;

    function onDocClick(e) {
      if (userMenuRoot && !userMenuRoot.contains(e.target)) {
        closeUserMenu();
      }
    }
    function onKeyDown(e) {
      if (e.key === 'Escape') closeUserMenu();
    }

    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('click', onDocClick);
      document.removeEventListener('keydown', onKeyDown);
    };
  });

  // Při přepnutí do kompaktu zavři otevřené menu (jinak by zůstalo viset).
  $effect(() => {
    if (compact) closeUserMenu();
  });
</script>

<div
  class="shpd-usermenu"
  class:shpd-usermenu--bar={direction === 'down'}
  bind:this={userMenuRoot}
>
  <button
    class="shpd-usermenu__button"
    class:shpd-usermenu__button--compact={compact}
    class:shpd-usermenu__button--open={userMenuOpen}
    onclick={toggleUserMenu}
    title={authStore.user?.full_name ?? ''}
    aria-haspopup="menu"
    aria-expanded={userMenuOpen}
  >
    <span class="shpd-usermenu__avatar">
      {#if avatarStore.objectUrl}
        <img class="shpd-usermenu__avatar-img" src={avatarStore.objectUrl} alt="" />
      {:else}
        {(authStore.user?.full_name ?? '?').charAt(0)}
      {/if}
    </span>
    {#if !compact}
      <span class="shpd-usermenu__username">{authStore.user?.full_name ?? ''}</span>
      <span class="shpd-usermenu__chevron">
        <Icon icon={userMenuOpen ? iconChevronDown : iconChevronUp} size="xs" />
      </span>
    {/if}
  </button>

  {#if userMenuOpen}
    <div
      class="shpd-usermenu__menu"
      class:shpd-usermenu__menu--side={compact && direction === 'up'}
      class:shpd-usermenu__menu--down={direction === 'down'}
      role="menu"
    >
      {#if navigationStore.mode !== 'account'}
        <button class="shpd-usermenu__menu-item" onclick={handleSettings} role="menuitem">
          <Icon icon={iconSettings} size="sm" />
          <span>{t('sidebar.accountSettings')}</span>
        </button>
      {/if}

      <!-- Nastavení aplikace jen adminovi — server settings pages už
           chrání, tady se jen skrývá mrtvý odkaz (princip D9). -->
      {#if navigationStore.mode !== 'settings' && authStore.isAdmin}
        <button class="shpd-usermenu__menu-item" onclick={handleAppSettings} role="menuitem">
          <Icon icon={iconAppSettings} size="sm" />
          <span>{t('sidebar.appSettings')}</span>
        </button>
      {/if}

      <div class="shpd-usermenu__menu-divider"></div>

      <!-- Vzhled — podmenu: desktop flyout do strany, mobil inline
           akordeon (flyout by přetekl drawer). -->
      <div class="shpd-usermenu__subwrap" bind:this={appearanceWrapEl}>
        <button
          class="shpd-usermenu__menu-item"
          class:shpd-usermenu__menu-item--active={openSubmenu === 'appearance'}
          onclick={() => toggleSubmenu('appearance', appearanceWrapEl)}
          role="menuitem"
          aria-haspopup="menu"
          aria-expanded={openSubmenu === 'appearance'}
        >
          <Icon icon={iconPalette} size="sm" />
          <span class="shpd-usermenu__menu-item-label">{t('sidebar.appearance')}</span>
          <span class="shpd-usermenu__menu-item-chevron">
            {#if layoutStore.isMobile}
              <Icon icon={openSubmenu === 'appearance' ? iconChevronDown : iconChevronRight} size="xs" />
            {:else}
              <Icon icon={submenuSide === 'left' ? iconChevronLeft : iconChevronRight} size="xs" />
            {/if}
          </span>
        </button>

        {#if openSubmenu === 'appearance'}
          <div
            class="shpd-usermenu__submenu"
            class:shpd-usermenu__submenu--inline={layoutStore.isMobile}
            class:shpd-usermenu__submenu--left={submenuSide === 'left'}
            class:shpd-usermenu__submenu--top={direction === 'down'}
            class:shpd-usermenu__submenu--fixed={fixedFlyout}
            style:bottom={fixedFlyout ? submenuFixedBottom + 'px' : null}
            style:left={fixedFlyout ? submenuFixedLeft + 'px' : null}
            role="menu"
          >
            <button
              class="shpd-usermenu__menu-item"
              class:shpd-usermenu__menu-item--active={themeStore.follow}
              onclick={selectAppearanceFollow}
              role="menuitemradio"
              aria-checked={themeStore.follow}
            >
              <span class="shpd-usermenu__menu-item-label">{t('sidebar.appearance.follow')}</span>
              {#if themeStore.follow}
                <span class="shpd-usermenu__menu-item-check">
                  <Icon icon={iconConfirm} size="xs" />
                </span>
              {/if}
            </button>
            <div class="shpd-usermenu__menu-divider"></div>
            {#each appearanceModes as opt}
              <button
                class="shpd-usermenu__menu-item"
                class:shpd-usermenu__menu-item--active={!themeStore.follow && themeStore.mode === opt.value}
                onclick={() => selectAppearanceMode(opt.value)}
                role="menuitemradio"
                aria-checked={!themeStore.follow && themeStore.mode === opt.value}
              >
                <span class="shpd-usermenu__menu-item-label">{t(opt.labelKey)}{opt.value === 'custom' ? '…' : ''}</span>
                {#if !themeStore.follow && themeStore.mode === opt.value}
                  <span class="shpd-usermenu__menu-item-check">
                    <Icon icon={iconConfirm} size="xs" />
                  </span>
                {/if}
              </button>
            {/each}
          </div>
        {/if}
      </div>

      <!-- Rozložení — podmenu nad shellStore. Na mobilu skryto:
           volba shellu se tam ignoruje (resolver v AppShellu, D5). -->
      {#if !layoutStore.isMobile}
        <div class="shpd-usermenu__subwrap" bind:this={shellWrapEl}>
          <button
            class="shpd-usermenu__menu-item"
            class:shpd-usermenu__menu-item--active={openSubmenu === 'shell'}
            onclick={() => toggleSubmenu('shell', shellWrapEl)}
            role="menuitem"
            aria-haspopup="menu"
            aria-expanded={openSubmenu === 'shell'}
          >
            <Icon icon={iconLayout} size="sm" />
            <span class="shpd-usermenu__menu-item-label">{t('sidebar.layout')}</span>
            <span class="shpd-usermenu__menu-item-chevron">
              <Icon icon={submenuSide === 'left' ? iconChevronLeft : iconChevronRight} size="xs" />
            </span>
          </button>

          {#if openSubmenu === 'shell'}
            <div
              class="shpd-usermenu__submenu"
              class:shpd-usermenu__submenu--left={submenuSide === 'left'}
              class:shpd-usermenu__submenu--top={direction === 'down'}
              class:shpd-usermenu__submenu--fixed={fixedFlyout}
              style:bottom={fixedFlyout ? submenuFixedBottom + 'px' : null}
              style:left={fixedFlyout ? submenuFixedLeft + 'px' : null}
              role="menu"
            >
              <button
                class="shpd-usermenu__menu-item"
                class:shpd-usermenu__menu-item--active={shellStore.follow}
                onclick={selectShellFollow}
                role="menuitemradio"
                aria-checked={shellStore.follow}
              >
                <span class="shpd-usermenu__menu-item-label">{t('shell.followApp')}</span>
                {#if shellStore.follow}
                  <span class="shpd-usermenu__menu-item-check">
                    <Icon icon={iconConfirm} size="xs" />
                  </span>
                {/if}
              </button>
              <div class="shpd-usermenu__menu-divider"></div>
              {#each shellOptions as opt}
                <button
                  class="shpd-usermenu__menu-item"
                  class:shpd-usermenu__menu-item--active={!shellStore.follow && shellStore.override === opt.value}
                  onclick={() => selectShellOverride(opt.value)}
                  role="menuitemradio"
                  aria-checked={!shellStore.follow && shellStore.override === opt.value}
                >
                  <span class="shpd-usermenu__menu-item-label">{t(opt.labelKey)}</span>
                  {#if !shellStore.follow && shellStore.override === opt.value}
                    <span class="shpd-usermenu__menu-item-check">
                      <Icon icon={iconConfirm} size="xs" />
                    </span>
                  {/if}
                </button>
              {/each}
            </div>
          {/if}
        </div>
      {/if}

      <!-- Jazyk — podmenu, stejný mechanismus jako Vzhled. Přepnutí
           volá location.reload(), zavírání netřeba řešit. -->
      <div class="shpd-usermenu__subwrap" bind:this={languageWrapEl}>
        <button
          class="shpd-usermenu__menu-item"
          class:shpd-usermenu__menu-item--active={openSubmenu === 'language'}
          onclick={() => toggleSubmenu('language', languageWrapEl)}
          role="menuitem"
          aria-haspopup="menu"
          aria-expanded={openSubmenu === 'language'}
        >
          <Icon icon={iconLanguage} size="sm" />
          <span class="shpd-usermenu__menu-item-label">{t('sidebar.language')}</span>
          <span class="shpd-usermenu__menu-item-chevron">
            {#if layoutStore.isMobile}
              <Icon icon={openSubmenu === 'language' ? iconChevronDown : iconChevronRight} size="xs" />
            {:else}
              <Icon icon={submenuSide === 'left' ? iconChevronLeft : iconChevronRight} size="xs" />
            {/if}
          </span>
        </button>

        {#if openSubmenu === 'language'}
          <div
            class="shpd-usermenu__submenu"
            class:shpd-usermenu__submenu--inline={layoutStore.isMobile}
            class:shpd-usermenu__submenu--left={submenuSide === 'left'}
            class:shpd-usermenu__submenu--top={direction === 'down'}
            class:shpd-usermenu__submenu--fixed={fixedFlyout}
            style:bottom={fixedFlyout ? submenuFixedBottom + 'px' : null}
            style:left={fixedFlyout ? submenuFixedLeft + 'px' : null}
            role="menu"
          >
            {#each languageOptions as opt}
              <button
                class="shpd-usermenu__menu-item"
                class:shpd-usermenu__menu-item--active={language.mode === opt.value}
                onclick={() => handleLanguageChange(opt.value)}
                role="menuitemradio"
                aria-checked={language.mode === opt.value}
              >
                <span class="shpd-usermenu__menu-item-label">{t(opt.labelKey)}</span>
                {#if language.mode === opt.value}
                  <span class="shpd-usermenu__menu-item-check">
                    <Icon icon={iconConfirm} size="xs" />
                  </span>
                {/if}
              </button>
            {/each}
          </div>
        {/if}
      </div>

      <div class="shpd-usermenu__menu-divider"></div>
      <button class="shpd-usermenu__menu-item" onclick={handleLogoutFromMenu} role="menuitem">
        <Icon icon={iconLogout} size="sm" />
        <span>{t('sidebar.logout')}</span>
      </button>
    </div>
  {/if}
</div>

<style>
  .shpd-usermenu {
    position: relative; /* anchor pro dropdown */
    padding: var(--shpd-space-sm);
    flex-shrink: 0;
    border-top: 1px solid var(--shpd-color-bg-sidebar-border);
  }

  /* User button — celá řádka v patce je klikatelná.
   * Layout: avatar (32px) | jméno (flex 1) | chevron. */
  .shpd-usermenu__button {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background: transparent;
    border: none;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-sidebar);
    cursor: pointer;
    text-align: left;
    transition: background-color 0.15s;
  }

  .shpd-usermenu__button:hover,
  .shpd-usermenu__button--open {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-usermenu__button--compact {
    justify-content: center;
    padding: var(--shpd-space-xs);
  }

  .shpd-usermenu__chevron {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-text-sidebar-muted);
    flex-shrink: 0;
  }

  .shpd-usermenu__avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text-sidebar);
    background-color: var(--shpd-color-accent);
    border-radius: 50%;
    flex-shrink: 0;
    overflow: hidden;
  }

  /* Avatar fotka vyplní kolečko; bez fotky zůstává accent kolečko s iniciálou. */
  .shpd-usermenu__avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .shpd-usermenu__username {
    flex: 1;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* Dropdown menu — otevírá se nahoru z patky.
   * V plném režimu: full width nad tlačítkem.
   * V kompaktu: vedle chrome vpravo (varianta --side).
   *
   * Barva pozadí navazuje na sidebar (modrá), aby dropdown
   * vizuálně patřil k němu — jen o stupeň světlejší, aby šlo
   * poznat kde sidebar končí a dropdown začíná. */
  .shpd-usermenu__menu {
    position: absolute;
    bottom: calc(100% - 1px);
    left: var(--shpd-space-sm);
    right: var(--shpd-space-sm);
    background-color: var(--shpd-color-bg-sidebar-elevated);
    color: var(--shpd-color-text-sidebar);
    border: 1px solid var(--shpd-color-bg-sidebar-border);
    border-radius: var(--shpd-radius-md);
    box-shadow: var(--shpd-shadow-md);
    padding: var(--shpd-space-xs);
    z-index: 200;
  }

  .shpd-usermenu__menu--side {
    bottom: var(--shpd-space-sm);
    left: calc(100% - 4px);
    right: auto;
    min-width: 12.5rem;
  }

  /* Top bar varianta: root bez patkového rámečku/odsazení, dropdown
     vyjíždí dolů a zarovnává se k pravému okraji tlačítka. */
  .shpd-usermenu--bar {
    border-top: none;
    padding: 0;
  }

  .shpd-usermenu__menu--down {
    top: calc(100% + 4px);
    bottom: auto;
    left: auto;
    right: 0;
    min-width: 13.75rem;
  }

  .shpd-usermenu__menu-item {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background: transparent;
    border: none;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-sidebar);
    font-size: var(--shpd-font-size-sm);
    text-align: left;
    cursor: pointer;
    transition: background-color 0.12s;
  }

  .shpd-usermenu__menu-item:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  /* Aktivní varianta položky (zatím používá jen volba jazyka).
   * Lehké zvýraznění pozadí + zatržítko vpravo říká "toto je vybraná volba". */
  .shpd-usermenu__menu-item--active {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-usermenu__menu-item-label {
    flex: 1;
  }

  .shpd-usermenu__menu-item-check {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-accent);
    flex-shrink: 0;
  }

  .shpd-usermenu__menu-divider {
    height: 1px; /* px-ok: čára */
    margin: var(--shpd-space-xs) 0;
    background-color: var(--shpd-color-bg-sidebar-border);
  }

  /* Podmenu (flyout) — kotva je wrapper položky. Vertikálně roste
     po směru menu: up = od spodku položky nahoru, down = od vrchu
     dolů, aby neuteklo z obrazovky. */
  .shpd-usermenu__subwrap {
    position: relative;
  }

  .shpd-usermenu__submenu {
    position: absolute;
    bottom: 0;
    left: calc(100% + 6px);
    min-width: 11.25rem;
    background-color: var(--shpd-color-bg-sidebar-elevated);
    border: 1px solid var(--shpd-color-bg-sidebar-border);
    border-radius: var(--shpd-radius-md);
    box-shadow: var(--shpd-shadow-md);
    padding: var(--shpd-space-xs);
    z-index: 210;
  }

  .shpd-usermenu__submenu--left {
    left: auto;
    right: calc(100% + 6px);
  }

  .shpd-usermenu__submenu--top {
    bottom: auto;
    top: 0;
  }

  /* Mobil: akordeon uvnitř menu místo flyoutu. */
  .shpd-usermenu__submenu--inline {
    position: static;
    min-width: 0;
    padding: 0 0 0 var(--shpd-space-lg);
    background: transparent;
    border: none;
    box-shadow: none;
  }

  .shpd-usermenu__menu-item-chevron {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-text-sidebar-muted);
    flex-shrink: 0;
  }

  /* Plný (nesbalený) sidebar má overflow:hidden (skrývání
     labelů při toggle transition), absolute flyout by ořízl na
     hraně sidebaru. Fixed uniká z clipu — stejný trik jako
     ThemePanel; kotva = pravý okraj sidebaru přes token, bez JS. */
  .shpd-usermenu__submenu--fixed {
    position: fixed;
    /* left/bottom dodává inline měření při otevření (viz
       toggleSubmenu) — tady jen fallback, kdyby měření selhalo. */
    left: calc(var(--shpd-sidebar-width) - var(--shpd-space-sm));
    right: auto;
    bottom: var(--shpd-space-sm);
    top: auto;
  }
</style>
