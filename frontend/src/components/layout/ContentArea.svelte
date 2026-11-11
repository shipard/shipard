<script>
  import TableBrowser from '../browser/TableBrowser.svelte';
  import Viewer from '../viewer/Viewer.svelte';
  import Dashboard from '../dashboard/Dashboard.svelte';
  import ChatView from '../chat/ChatView.svelte';
  import SettingsPage from '../settings/SettingsPage.svelte';
  import AccountSecurity from '../account/AccountSecurity.svelte';
  import DsSetup from '../settings/DsSetup.svelte';
  import DsAbout from '../settings/DsAbout.svelte';
  import ContentTagsSettings from '../settings/ContentTagsSettings.svelte';
  import PortalContent from '../portal/PortalContent.svelte';
  import ReportsPage from '../reports/ReportsPage.svelte';
  import CompanyOverview from '../overview/CompanyOverview.svelte';
  import ReadOnlyBanner from '../ui/ReadOnlyBanner.svelte';
  import RealCopyBanner from '../ui/RealCopyBanner.svelte';
  import CronOffBanner from '../ui/CronOffBanner.svelte';
  import MailSafetyNotice from '../ui/MailSafetyNotice.svelte';
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { t } from '../../i18n/index.js';
  import { isOutboundMailItem } from '../../utils/mailSafety.js';

  let { activeItem = null, onOpenThemePanel } = $props();

  // Panel = klientská komponenta registrovaná v module.jsonc `panels[]`;
  // server posílá jen {type: 'panel', panelId}, mapa je tady.
  const panelComponents = {
    accountSecurity: AccountSecurity,
    dsSetup: DsSetup,
    dsAbout: DsAbout,
    contentTags: ContentTagsSettings,
    hostingPortal: PortalContent,
    reports: ReportsPage,
    companyOverview: CompanyOverview,
  };

  const PanelComponent = $derived(
    activeItem?.type === 'panel' ? (panelComponents[activeItem.panelId] ?? null) : null
  );

  // Pruh pojistky pošty jen u položek odchozí pošty; jestli se podle režimu
  // pojistky opravdu vykreslí, rozhoduje MailSafetyNotice (jinak zůstane
  // kontejner pruhů prázdný, bez výšky).
  const mailSafetyBanner = $derived(isOutboundMailItem(activeItem));
  const hasBanners = $derived(
    appInfoStore.readOnly || appInfoStore.realCopy || appInfoStore.cronDisabled || mailSafetyBanner
  );
</script>

<!-- Pruhy leží NAD rolovací oblastí, ne v ní (#133): obsah s `height: 100%`
     (viewer, chat, reporty) by jinak byl o výšku pruhů vyšší než
     `.shpd-content` — spodek uříznutý a celá stránka rolovala pod pruhy.
     Rodič je ve všech shellech flex sloupec; pruhy pod sebou (jen ke čtení →
     kopie reálných dat → vypnutý cron → pojistka pošty). -->
{#if hasBanners}
  <div class="shpd-content__banners">
    {#if appInfoStore.readOnly}
      <ReadOnlyBanner />
    {/if}
    {#if appInfoStore.realCopy}
      <RealCopyBanner />
    {/if}
    {#if appInfoStore.cronDisabled}
      <CronOffBanner />
    {/if}
    {#if mailSafetyBanner}
      <MailSafetyNotice banner />
    {/if}
  </div>
{/if}
<main class="shpd-content">
  {#if activeItem?.type === 'dashboard'}
    <Dashboard />
  {:else if activeItem?.type === 'chat'}
    <ChatView />
  {:else if activeItem?.type === 'viewer'}
    <Viewer tab={activeItem} />
  {:else if activeItem?.type === 'table'}
    <TableBrowser tab={activeItem} />
  {:else if activeItem?.type === 'page'}
    <SettingsPage tab={activeItem} {onOpenThemePanel} />
  {:else if PanelComponent}
    <!-- item: panel s panelParams (reporty) — ostatní panely prop ignorují -->
    <PanelComponent item={activeItem} />
  {:else if activeItem}
    <!-- Placeholder for future content types (form, …) -->
    <div class="shpd-content__empty">
      <p class="shpd-content__empty-text">{t('app.unsupportedPanel', { type: activeItem.type })}</p>
    </div>
  {:else}
    <div class="shpd-content__empty">
      <p class="shpd-content__empty-text">{t('app.selectMenuItem')}</p>
    </div>
  {/if}
</main>

<style>
  .shpd-content {
    flex: 1;
    overflow-y: auto;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-content__banners {
    flex-shrink: 0;
  }

  .shpd-content__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
  }

  .shpd-content__empty-text {
    font-size: var(--shpd-font-size-lg);
    color: var(--shpd-color-text-secondary);
  }

</style>
