<script>
  import { t } from '../../i18n/index.js';

  /**
   * Chip bar filtru feedu. Čistě prezentační — počty i urgent příznaky
   * počítá rodič (Dashboard) z doručených karet.
   * value: 'all' | 'invoices' | 'registry' | 'other'
   * counts: { all, invoices, registry, other }
   * urgent: { invoices: bool, registry: bool, other: bool }
   */
  let { value = 'all', counts = {}, urgent = {}, onChange = () => {} } = $props();

  const TABS = ['all', 'invoices', 'registry', 'other'];
</script>

<div class="shpd-feed-filter" role="tablist">
  {#each TABS as tab (tab)}
    <button
      type="button"
      role="tab"
      class="shpd-feed-filter__chip"
      class:shpd-feed-filter__chip--active={value === tab}
      aria-selected={value === tab}
      onclick={() => onChange(tab)}
    >
      {t(`dashboard.feed.filter.${tab}`)}
      <span class="shpd-feed-filter__count">{counts[tab] ?? 0}</span>
      {#if urgent[tab]}<span class="shpd-feed-filter__dot" aria-hidden="true"></span>{/if}
    </button>
  {/each}
</div>

<style>
  /* Horizontálně scrollovatelný na úzkých šířkách — chipy se nezalamují. */
  .shpd-feed-filter {
    display: flex;
    gap: var(--shpd-space-sm);
    overflow-x: auto;
    white-space: nowrap;
  }

  .shpd-feed-filter__chip {
    position: relative;
    flex-shrink: 0;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-full);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    font: inherit;
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
  }

  .shpd-feed-filter__chip:hover:not(.shpd-feed-filter__chip--active) {
    background: var(--shpd-color-bg-hover);
  }

  .shpd-feed-filter__chip--active {
    background: var(--shpd-color-primary);
    border-color: var(--shpd-color-primary);
    color: #ffffff;
  }

  .shpd-feed-filter__count {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-feed-filter__chip--active .shpd-feed-filter__count {
    color: inherit;
    opacity: 0.8;
  }

  .shpd-feed-filter__dot {
    position: absolute;
    top: -2px;
    right: -2px;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--shpd-color-danger);
  }
</style>
