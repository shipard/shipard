<script>
  // Shell vývojářského dashboardu (#129 D6–D8): jeden lepicí blok z oranžového
  // pruhu (DEVELOPMENT DASHBOARD, server, verze jako tlačítko s bublinou stavu
  // vůči stable — VersionMenu, D23) a modré lišty (logo → Zdroje dat, navigace
  // s ikonami, Dokumentace), pod ním outlet routeru. Stránky v pages/. Výšku
  // bloku měří a dává do --dev-top-height pro lepicí prvky pod lištou (PdfPane).
  import { onMount } from 'svelte';
  import Icon from '../components/ui/Icon.svelte';
  import { iconDatabase, iconTesting, iconVideo, iconDocument, iconDoctor, iconUpgrade, iconBook, iconServer } from '../icons.js';
  import logoUrl from './assets/shipard-logo.svg';
  import { router, onLinkClick } from './router.svelte.js';
  import { devInfo } from './info.svelte.js';
  import { updates } from './updates.svelte.js';
  import VersionMenu from './components/VersionMenu.svelte';
  import { docsUrl } from './links.js';
  import { t, LANGUAGES, currentLanguage, setLanguage } from './i18n/index.js';
  import DataSourcesPage from './pages/DataSourcesPage.svelte';
  import DsCreatePage from './pages/DsCreatePage.svelte';
  import LogsPage from './pages/LogsPage.svelte';
  import ActionPage from './pages/ActionPage.svelte';
  import TestingPage from './pages/testing/TestingPage.svelte';
  import VideosPage from './pages/videos/VideosPage.svelte';
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
    videos: 'videos',
  };

  const isTesting = $derived(route.name.startsWith('testing'));

  const navItems = $derived([
    { id: 'dataSources', label: t('app.nav.dataSources'), icon: iconDatabase, href: router.href('dataSources') },
    ...(devInfo.value?.testing ? [{ id: 'testing', label: t('app.nav.testing'), icon: iconTesting, href: router.href('testingDatasets') }] : []),
    ...(devInfo.value?.videos ? [{ id: 'videos', label: t('app.nav.videos'), icon: iconVideo, href: router.href('videos') }] : []),
    { id: 'logs', label: t('app.nav.logs'), icon: iconDocument, href: router.href('logs') },
    { id: 'doctor', label: t('app.nav.doctor'), icon: iconDoctor, href: router.href('doctor') },
    { id: 'upgradeAll', label: t('app.nav.upgradeAll'), icon: iconUpgrade, href: router.href('upgradeAll') },
  ]);

  const activeNav = $derived(isTesting ? 'testing' : (NAV_OF_ROUTE[route.name] ?? null));

  // Server v pruhu: při načítání „…“, při chybě „?“ (verzi kreslí VersionMenu).
  const pending = $derived(devInfo.error ? '?' : '…');

  // Výška pruhu + lišty — lepicí prvky pod lištou (PdfPane) ji čtou z --dev-top-height.
  let topHeight = $state(84);

  /** Titulek okna podle routy (slovník dashboardu); „Shipard dev“ je značka, nepřekládá se. */
  const TITLE_KEYS = {
    dataSources: 'app.nav.dataSources',
    dsCreate: 'app.title.dsCreate',
    logs: 'app.nav.logs',
    doctor: 'app.nav.doctor',
    upgradeAll: 'app.nav.upgradeAll',
    dsUpgrade: 'app.title.dsUpgrade',
    videos: 'app.nav.videos',
    notFound: 'app.title.notFound',
  };

  $effect(() => {
    const title = isTesting
      ? [t('app.nav.testing'), route.name === 'testingJobs' || route.name === 'testingJob' ? t('app.title.jobs') : null, route.name === 'testingVariants' || route.name === 'testingVariant' ? t('app.title.variants') : null, route.params.set, route.params.file, route.params.messageId, route.params.jobId, route.params.name].filter(Boolean).join(' · ')
      : t(TITLE_KEYS[route.name] ?? 'app.title.dashboard');
    document.title = `${title} — Shipard dev`;
  });

  onMount(() => {
    devInfo.load();
    // Stav vůči stable (#129 D18, D25): načíst, dál se ptát při kontrole / úloze, po focusu a na viditelné stránce každých 10 minut.
    updates.startPolling();
    return () => updates.stopPolling();
  });
</script>

<div class="dev-shell" style:--dev-top-height="{topHeight}px">
  <header class="dev-top" bind:clientHeight={topHeight}>
    <div class="dev-stripe">
      <strong class="dev-stripe__title">DEVELOPMENT DASHBOARD</strong>
      <span class="dev-stripe__meta">
        <span class="dev-stripe__item" title={t('app.stripe.server')}>
          <Icon icon={iconServer} size="sm" />
          <span>{devInfo.value?.hostname ?? pending}</span>
        </span>
        <VersionMenu />
      </span>
    </div>
    <div class="dev-topbar">
      <a class="dev-topbar__logo" href={router.href('dataSources')} onclick={onLinkClick} title={t('app.nav.dataSources')}>
        <img src={logoUrl} alt="Shipard" height="28" />
      </a>
      <nav class="dev-topbar__nav" aria-label={t('app.nav.ariaLabel')}>
        {#each navItems as item (item.id)}
          <a
            class="dev-topbar__link"
            href={item.href}
            onclick={onLinkClick}
            aria-current={activeNav === item.id ? 'page' : undefined}
          ><Icon icon={item.icon} size="sm" />{item.label}</a>
        {/each}
      </nav>
      <!-- Přepínač jazyka (D12): CS · EN, aktuální zvýrazněný; přepnutí uloží volbu a načte dashboard znovu. -->
      <div class="dev-topbar__lang" role="group" aria-label={t('app.lang.label')}>
        {#each LANGUAGES as lang, i (lang)}
          {#if i > 0}<span class="dev-topbar__lang-sep" aria-hidden="true">·</span>{/if}
          {#if lang === currentLanguage()}
            <span class="dev-topbar__lang-current" aria-current="true" {lang} title={t(`app.lang.${lang}`)}>{lang.toUpperCase()}</span>
          {:else}
            <button type="button" class="dev-topbar__lang-btn" {lang} title={t(`app.lang.${lang}`)} onclick={() => setLanguage(lang)}>{lang.toUpperCase()}</button>
          {/if}
        {/each}
      </div>
      <a class="dev-topbar__link dev-topbar__docs" href={docsUrl(currentLanguage())} target="_blank" rel="noopener">
        <Icon icon={iconBook} size="sm" />{t('app.nav.docs')}
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
    {:else if route.name === 'videos'}
      <VideosPage />
    {:else}
      <NotFoundPage />
    {/if}
  </main>
</div>
