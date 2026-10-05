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
  import ReadOnlyBanner from '../ui/ReadOnlyBanner.svelte';
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
  };

  const PanelComponent = $derived(
    activeItem?.type === 'panel' ? (panelComponents[activeItem.panelId] ?? null) : null
  );
</script>

<main class="shpd-content">
  {#if appInfoStore.readOnly}
    <ReadOnlyBanner />
  {/if}
  {#if isOutboundMailItem(activeItem)}
    <MailSafetyNotice banner />
  {/if}
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
