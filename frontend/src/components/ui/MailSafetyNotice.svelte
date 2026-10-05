<script>
  // Upozornění na pojistku odchozí pošty (#95 D7): na dev a testovacím
  // serveru pošta nejde skutečným příjemcům. Režim nese veřejné
  // GET /_app/info (jen režim, žádné adresy); produkční server s vypnutou
  // pojistkou nic neukazuje.
  //
  // `banner` = pruh nad obsahem (ContentArea, jako ReadOnlyBanner), jinak
  // rámeček uvnitř dialogu.
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { t } from '../../i18n/index.js';

  let { banner = false } = $props();

  const KEYS = {
    redirect: 'mailSafety.notice.redirect',
    allowlist: 'mailSafety.notice.allowlist',
    drop: 'mailSafety.notice.drop',
  };

  const key = $derived(KEYS[appInfoStore.mailSafetyMode] ?? null);
</script>

{#if key}
  <div
    class="shpd-mail-safety"
    class:shpd-mail-safety--banner={banner}
    role="status"
    data-testid="mail-safety-notice"
  >
    {t(key)}
  </div>
{/if}

<style>
  .shpd-mail-safety {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background: var(--shpd-color-alert-warning-bg);
    color: var(--shpd-color-alert-warning-text);
    border-left: 3px solid var(--shpd-color-alert-warning-bar);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
  }

  .shpd-mail-safety--banner {
    padding: var(--shpd-space-xs) var(--shpd-space-lg);
    border-left: 0;
    border-bottom: 2px solid var(--shpd-color-alert-warning-bar);
    border-radius: 0;
    text-align: center;
  }
</style>
