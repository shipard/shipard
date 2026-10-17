<script>
  // Shell vývojářského dashboardu (#129 D6–D8): jeden lepicí blok z oranžového
  // pruhu (DEVELOPMENT DASHBOARD, server, verze s hashem jako odkaz na commit)
  // a modré lišty (logo → Zdroje dat, navigace s ikonami, Dokumentace), pod ním
  // outlet routeru. Stránky v pages/. Výšku bloku měří a dává do
  // --dev-top-height pro lepicí prvky pod lištou (PdfPane).
  import { onMount } from 'svelte';
  import Icon from '../components/ui/Icon.svelte';
  import { iconDatabase, iconTesting, iconDocument, iconDoctor, iconUpgrade, iconBook, iconServer, iconCommit } from '../icons.js';
  import logoUrl from './assets/shipard-logo.svg';
  import { router, onLinkClick } from './router.svelte.js';
  import { devInfo } from './info.svelte.js';
  import { versionLabel, formatDateTime } from './format.js';
  import DataSourcesPage from './pages/DataSourcesPage.svelte';
  import DsCreatePage from './pages/DsCreatePage.svelte';
  import LogsPage from './pages/LogsPage.svelte';
  import ActionPage from './pages/ActionPage.svelte';
  import TestingPage from './pages/testing/TestingPage.svelte';
  import NotFoundPage from './pages/NotFoundPage.svelte';

  const DOCS_URL = 'https://shipard.dev/pro-vyvojare/';
  const COMMIT_URL = 'https://github.com/shipard/shipard/commit/';

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

  const isTesting = $derived(route.name.startsWith('testing'));

  const navItems = $derived([
    { id: 'dataSources', label: 'Zdroje dat', icon: iconDatabase, href: router.href('dataSources') },
    ...(devInfo.value?.testing ? [{ id: 'testing', label: 'Testování', icon: iconTesting, href: router.href('testingDatasets') }] : []),
    { id: 'logs', label: 'Logy', icon: iconDocument, href: router.href('logs') },
    { id: 'doctor', label: 'Doctor', icon: iconDoctor, href: router.href('doctor') },
    { id: 'upgradeAll', label: 'Upgrade všech', icon: iconUpgrade, href: router.href('upgradeAll') },
  ]);

  const activeNav = $derived(isTesting ? 'testing' : (NAV_OF_ROUTE[route.name] ?? null));

  // Server a verze v pruhu: při načítání „…“, při chybě „?“.
  const pending = $derived(devInfo.error ? '?' : '…');
  const version = $derived(devInfo.value?.version ?? null);

  // Výška pruhu + lišty — lepicí prvky pod lištou (PdfPane) ji čtou z --dev-top-height.
  let topHeight = $state(84);

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
    const title = isTesting
      ? ['Testování', route.name === 'testingJobs' || route.name === 'testingJob' ? 'Úlohy' : null, route.name === 'testingVariants' || route.name === 'testingVariant' ? 'Varianty' : null, route.params.set, route.params.file, route.params.messageId, route.params.jobId, route.params.name].filter(Boolean).join(' · ')
      : (TITLES[route.name] ?? 'Dashboard');
    document.title = `${title} — Shipard dev`;
  });

  onMount(() => {
    devInfo.load();
  });
</script>

<div class="dev-shell" style:--dev-top-height="{topHeight}px">
  <header class="dev-top" bind:clientHeight={topHeight}>
    <div class="dev-stripe">
      <strong class="dev-stripe__title">DEVELOPMENT DASHBOARD</strong>
      <span class="dev-stripe__meta">
        <span class="dev-stripe__item" title="Server">
          <Icon icon={iconServer} size="sm" />
          <span>{devInfo.value?.hostname ?? pending}</span>
        </span>
        <span class="dev-stripe__item">
          <Icon icon={iconCommit} size="sm" />
          {#if version?.fullHash}
            <a
              class="dev-stripe__version"
              href={COMMIT_URL + version.fullHash}
              target="_blank"
              rel="noopener"
              title={version.commitDate ? `Commit z ${formatDateTime(version.commitDate)} — otevřít na GitHubu` : 'Otevřít commit na GitHubu'}
            >{versionLabel(version)}</a>
          {:else}
            <span class="dev-stripe__version">{version ? versionLabel(version) : pending}</span>
          {/if}
        </span>
      </span>
    </div>
    <div class="dev-topbar">
      <a class="dev-topbar__logo" href={router.href('dataSources')} onclick={onLinkClick} title="Zdroje dat">
        <img src={logoUrl} alt="Shipard" height="28" />
      </a>
      <nav class="dev-topbar__nav" aria-label="Sekce dashboardu">
        {#each navItems as item (item.id)}
          <a
            class="dev-topbar__link"
            href={item.href}
            onclick={onLinkClick}
            aria-current={activeNav === item.id ? 'page' : undefined}
          ><Icon icon={item.icon} size="sm" />{item.label}</a>
        {/each}
      </nav>
      <!-- Přepínač jazyka (fáze 2, D12) přijde sem, před Dokumentaci. -->
      <a class="dev-topbar__link dev-topbar__docs" href={DOCS_URL} target="_blank" rel="noopener">
        <Icon icon={iconBook} size="sm" />Dokumentace
      </a>
    </div>
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
    {:else if isTesting}
      <TestingPage />
    {:else}
      <NotFoundPage />
    {/if}
  </main>
</div>
