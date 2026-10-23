<script>
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { t } from '../../i18n/index.js';
</script>

{#if navigationStore.tabs.length > 0}
  <div class="shpd-tabbar" role="tablist">
    {#each navigationStore.tabs as tab (tab.id)}
      <div
        class="shpd-tabbar__tab"
        class:shpd-tabbar__tab--active={navigationStore.activeTabId === tab.id}
        role="tab"
        tabindex="0"
        aria-selected={navigationStore.activeTabId === tab.id}
        onclick={() => navigationStore.activateTab(tab.id)}
        onkeydown={(e) => e.key === 'Enter' && navigationStore.activateTab(tab.id)}
      >
        <span class="shpd-tabbar__label">{tab.label}</span>
        <button
          class="shpd-tabbar__close"
          aria-label={t('tabbar.close', { tab: tab.label })}
          onclick={(e) => { e.stopPropagation(); navigationStore.closeTab(tab.id); }}
        >×</button>
      </div>
    {/each}
  </div>
{/if}

<style>
  .shpd-tabbar {
    display: flex;
    align-items: flex-end;
    gap: 0.125rem;
    padding: 0 var(--shpd-space-md);
    padding-top: var(--shpd-space-xs);
    background-color: var(--shpd-color-bg);
    border-bottom: 1px solid var(--shpd-color-border);
    overflow-x: auto;
    flex-shrink: 0;
  }

  /* Hide scrollbar but keep functionality */
  .shpd-tabbar::-webkit-scrollbar {
    height: 3px;
  }
  .shpd-tabbar::-webkit-scrollbar-thumb {
    background: var(--shpd-color-border);
    border-radius: 0.125rem;
  }

  .shpd-tabbar__tab {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    background-color: var(--shpd-color-bg-secondary);
    border: 1px solid var(--shpd-color-border);
    border-bottom: none;
    border-radius: var(--shpd-radius-sm) var(--shpd-radius-sm) 0 0;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color 0.15s, color 0.15s;
    position: relative;
    bottom: -1px;
    user-select: none;
  }

  .shpd-tabbar__tab:hover {
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text);
  }

  .shpd-tabbar__tab--active {
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    border-bottom-color: var(--shpd-color-bg);
    font-weight: 500;
  }

  .shpd-tabbar__label {
    pointer-events: none;
  }

  .shpd-tabbar__close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    font-size: var(--shpd-font-size-sm);
    line-height: 1;
    color: var(--shpd-color-text-secondary);
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.15s, color 0.15s;
    flex-shrink: 0;
  }

  .shpd-tabbar__close:hover {
    background-color: rgb(0 0 0 / 0.08);
    color: var(--shpd-color-danger);
  }
</style>
