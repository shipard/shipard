<script>
  // Chrome primitiv: „← Zpět do aplikace" v settings/account módu.
  // `compact` = ikonové tlačítko (sbalený sidebar).
  import Icon from '../ui/Icon.svelte';
  import { iconChevronLeft } from '../../icons.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { t } from '../../i18n/index.js';

  let { compact = false } = $props();

  // Výstup ze sekundárního módu (Nastavení aplikace i Nastavení účtu) zpět
  // do aplikace. Na mobilu navíc zavři drawer — exitToApp neprochází přes
  // AppShell.handleNavigate, takže by drawer zůstal otevřený.
  function handleExitToApp() {
    navigationStore.exitToApp();
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }
</script>

{#if compact}
  <div class="shpd-modeback shpd-modeback--compact">
    <button
      class="shpd-modeback__button shpd-modeback__button--icon-only"
      onclick={handleExitToApp}
      title={t('sidebar.backToApp')}
      aria-label={t('sidebar.backToApp')}
    >
      <Icon icon={iconChevronLeft} size="sm" />
    </button>
  </div>
{:else}
  <div class="shpd-modeback">
    <button class="shpd-modeback__button" onclick={handleExitToApp}>
      <Icon icon={iconChevronLeft} size="sm" />
      <span>{t('sidebar.backToApp')}</span>
    </button>
  </div>
{/if}

<style>
  .shpd-modeback {
    padding: var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
    flex-shrink: 0;
  }

  .shpd-modeback__button {
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
    cursor: pointer;
    text-align: left;
    transition: background-color 0.15s;
  }

  .shpd-modeback__button:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  /* Kompaktní varianta ve sbaleném sidebaru. */
  .shpd-modeback--compact {
    display: flex;
    justify-content: center;
    padding: var(--shpd-space-xs);
  }

  .shpd-modeback__button--icon-only {
    width: 2rem;
    height: 2rem;
    padding: 0;
    gap: 0;
    justify-content: center;
  }
</style>
