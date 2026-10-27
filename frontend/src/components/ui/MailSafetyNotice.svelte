<script>
  // Upozornění na pojistku odchozí pošty (#95 D7): na dev a testovacím
  // serveru pošta nejde skutečným příjemcům. Režim nese veřejné
  // GET /_app/info (jen režim, žádné adresy); produkční server s vypnutou
  // pojistkou nic neukazuje.
  //
  // `banner` = pruh nad obsahem (ContentArea, sdílený `PageBanner` jako
  // ReadOnlyBanner, #137 D41), jinak hláška `Notice` uvnitř dialogu
  // Odeslat (D40). Text i logika režimu zůstávají tady.
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { t } from '../../i18n/index.js';
  import Notice from './Notice.svelte';
  import PageBanner from './PageBanner.svelte';

  let { banner = false } = $props();

  const KEYS = {
    redirect: 'mailSafety.notice.redirect',
    allowlist: 'mailSafety.notice.allowlist',
    drop: 'mailSafety.notice.drop',
  };

  const key = $derived(KEYS[appInfoStore.mailSafetyMode] ?? null);
</script>

{#if key}
  {#if banner}
    <PageBanner data-testid="mail-safety-notice">{t(key)}</PageBanner>
  {:else}
    <Notice tone="warning" data-testid="mail-safety-notice">{t(key)}</Notice>
  {/if}
{/if}
