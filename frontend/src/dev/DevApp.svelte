<script>
  // Shell vývojářského dashboardu: pruh DEVELOPMENT MODE, hlavička se
  // serverem, společná navigace a outlet routeru. Stránky v pages/.
  import { onMount } from 'svelte';
  import { router, onLinkClick } from './router.svelte.js';
  import { devInfo } from './info.svelte.js';
  import DataSourcesPage from './pages/DataSourcesPage.svelte';
  import DsCreatePage from './pages/DsCreatePage.svelte';
  import LogsPage from './pages/LogsPage.svelte';
  import ActionPage from './pages/ActionPage.svelte';
  import NotFoundPage from './pages/NotFoundPage.svelte';

  const route = $derived(router.route);

  /** Název routy → položka navigace, která má být zvýrazněná. */
  const NAV_OF_ROUTE = {
    dataSources: 'dataSources',
    dsCreate: 'dataSources',
    dsUpgrade: 'dataSources',
    logs: 'logs',
    doctor: 'doctor',
    upgradeAll: 'upgradeAll',
  };

  const navItems = $derived([
    { id: 'dataSources', label: 'Zdroje dat', href: router.href('dataSources') },
    { id: 'logs', label: 'Logy', href: router.href('logs') },
    { id: 'doctor', label: 'Doctor', href: router.href('doctor') },
    { id: 'upgradeAll', label: 'Upgrade všech', href: router.href('upgradeAll') },
  ]);

  const activeNav = $derived(NAV_OF_ROUTE[route.name] ?? null);

  const TITLES = {
    dataSources: 'Zdroje dat',
    dsCreate: 'Nový zdroj dat',
    logs: 'Logy',
    doctor: 'Doctor',
    upgradeAll: 'Upgrade všech',
    dsUpgrade: 'Upgrade zdroje dat',
    notFound: 'Stránka nenalezena',
  };

  $effect(() => {
    document.title = `${TITLES[route.name] ?? 'Dashboard'} — Shipard dev`;
  });

  onMount(() => {
    devInfo.load();
  });
</script>

<div class="dev-shell">
  <div class="dev-banner">DEVELOPMENT MODE — nenasazovat veřejně</div>
  <header class="dev-header">
    <a class="dev-header__title" href={router.href('dataSources')} onclick={onLinkClick}>Shipard · vývojářský dashboard</a>
    <nav class="dev-nav" aria-label="Sekce dashboardu">
      {#each navItems as item (item.id)}
        <a
          class="dev-nav__item"
          class:dev-nav__item--active={activeNav === item.id}
          href={item.href}
          onclick={onLinkClick}
        >{item.label}</a>
      {/each}
    </nav>
    <span class="dev-header__server">
      Server: <code>{devInfo.value?.hostname ?? (devInfo.error ? '?' : '…')}</code>
    </span>
  </header>

  <main class="dev-main">
    {#if route.name === 'dataSources'}
      <DataSourcesPage />
    {:else if route.name === 'dsCreate'}
      <DsCreatePage />
    {:else if route.name === 'logs'}
      <LogsPage />
    {:else if route.name === 'doctor' || route.name === 'upgradeAll' || route.name === 'dsUpgrade'}
      <ActionPage />
    {:else}
      <NotFoundPage />
    {/if}
  </main>
</div>
