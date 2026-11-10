<script>
  // Chrome primitiv: ikona aplikace + logo/shortName (branding sloty).
  // Čte appInfoStore sám — shelly ho jen umístí, data netahají.
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { brandingUrl } from '../../api/app.js';
</script>

<div class="shpd-brandheader">
  {#if appInfoStore.icon}
    <img
      class="shpd-brandheader__app-icon"
      src={brandingUrl('icon', appInfoStore.icon.hash)}
      alt=""
    />
  {/if}
  <span class="shpd-brandheader__logo">{appInfoStore.shortName ?? 'Shipard'}</span>
</div>

<style>
  .shpd-brandheader {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    min-width: 0;
  }

  .shpd-brandheader__app-icon {
    width: 1.5rem;
    height: 1.5rem;
    object-fit: contain;
    border-radius: var(--shpd-radius-sm);
    flex-shrink: 0;
  }

  .shpd-brandheader__logo {
    font-size: var(--shpd-font-size-lg);
    font-weight: 700;
    color: var(--shpd-color-text-sidebar);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
</style>
