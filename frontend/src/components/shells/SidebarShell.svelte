<script>
  // Výchozí shell — levý sidebar (desktop) / top bar + drawer (mobil).
  // 1:1 extrakce layout části původního AppShellu (UI shells Fáze 4);
  // AppShellu zůstaly globální starosti (paleta, ThemePanel, ChatPanel,
  // badge polling, resolver shellu).
  import Sidebar from '../layout/Sidebar.svelte';
  import ContentArea from '../layout/ContentArea.svelte';
  import MobileTopBar from '../layout/MobileTopBar.svelte';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';

  let {
    onOpenThemePanel,
    // ThemePanel se renderuje v AppShellu (mobilní drawer má transform =
    // containing block pro position:fixed, panel uvnitř by se uvěznil) —
    // shell mu jen hlásí levý offset jako CSS délku, ať zůstanou tokeny.
    themePanelLeftOffset = $bindable('calc(var(--shpd-sidebar-width) + var(--shpd-space-sm))'),
  } = $props();

  let sidebarCollapsed = $state(false);

  $effect(() => {
    themePanelLeftOffset = sidebarCollapsed
      ? 'calc(var(--shpd-sidebar-width-collapsed) + var(--shpd-space-sm))'
      : 'calc(var(--shpd-sidebar-width) + var(--shpd-space-sm))';
  });

  function handleNavigate(item) {
    navigationStore.navigate(item);
    // Na mobilu klik na položku zavře drawer (jinak by zůstal přes obsah).
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }

  // Esc zavírá drawer (jen mobil + otevřený drawer).
  $effect(() => {
    if (!layoutStore.isMobile || !layoutStore.drawerOpen) return;
    function onKeyDown(e) {
      if (e.key === 'Escape') layoutStore.closeDrawer();
    }
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  });
</script>

<div class="shpd-shell" data-testid="app-shell" class:shpd-shell--mobile={layoutStore.isMobile}>
  {#if layoutStore.isMobile}
    <!-- Mobilní režim: top bar nahoře, sidebar jako overlay drawer. -->
    <MobileTopBar />

    {#if layoutStore.drawerOpen}
      <div
        class="shpd-shell__overlay"
        onclick={() => layoutStore.closeDrawer()}
        aria-hidden="true"
      ></div>
    {/if}

    <div
      class="shpd-shell__drawer"
      class:shpd-shell__drawer--open={layoutStore.drawerOpen}
    >
      <Sidebar onNavigate={handleNavigate} {onOpenThemePanel} />
    </div>

    <div class="shpd-shell__main">
      <ContentArea activeItem={navigationStore.activeItem} {onOpenThemePanel} />
    </div>
  {:else}
    <!-- Desktop režim: pevný sidebar + obsah vedle. -->
    <Sidebar
      onNavigate={handleNavigate}
      {onOpenThemePanel}
      bind:collapsed={sidebarCollapsed}
    />
    <div class="shpd-shell__main">
      <ContentArea activeItem={navigationStore.activeItem} {onOpenThemePanel} />
    </div>
  {/if}
</div>

<style>
  .shpd-shell {
    display: flex;
    /* 100dvh (dynamic viewport height) řeší ořez spodku shellu pod
       adresní lištou mobilních prohlížečů (sticky launcher, composer) —
       stejný pattern jako fullscreen Modal. Staré prohlížeče dvh
       ignorují a zůstanou na 100vh. */
    height: 100vh;
    height: 100dvh;
    overflow: hidden;
    position: relative;
  }

  /* Mobilní režim: shell je vertikální (top bar nad obsahem).
     Sidebar drawer a overlay jsou position:fixed mimo tok. */
  .shpd-shell--mobile {
    flex-direction: column;
  }

  .shpd-shell__main {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
    min-height: 0;
  }

  /* --- Mobilní drawer + overlay --- */

  .shpd-shell__overlay {
    position: fixed;
    inset: 0;
    top: var(--shpd-header-height); /* pod top barem */
    background-color: var(--shpd-color-overlay);
    z-index: 90;
  }

  .shpd-shell__drawer {
    position: fixed;
    top: var(--shpd-header-height);
    bottom: 0;
    left: 0;
    width: 72%;
    max-width: 20rem;
    z-index: 100;
    transform: translateX(-100%);
    transition: transform 0.22s ease;
    /* Drawer obsahuje Sidebar, který má vlastní pozadí (modré).
       Sidebar uvnitř roztáhneme na plnou šířku i výšku drawera. */
    display: flex;
  }

  /* Sidebar uvnitř draweru vyplní celou jeho šířku (desktop má pevných
     260px; v draweru chceme bez průhledného pruhu vpravo). */
  .shpd-shell__drawer :global(.shpd-sidebar) {
    width: 100%;
  }

  .shpd-shell__drawer--open {
    transform: translateX(0);
  }
</style>
