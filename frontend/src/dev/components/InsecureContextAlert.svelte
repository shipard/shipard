<script>
  // Upozornění na nezabezpečený kontext (#183 D3, U10, U18): stránka běží na
  // http mimo localhost, takže prohlížeč nenabízí schránku, WebCrypto
  // (simulace mostu nedostane klíč zařízení) ani instalaci PWA. Když server
  // nabízí https vstup, odkáže na tutéž stránku na něm; jinak řekne, jak ho
  // zapnout. Na https, `localhost` a `127.0.0.1` se nekreslí nic.
  // `context`: `dataSources` (co nepojede obecně) | `bridge` (simulace mostu).
  // Vzhled: hláška dashboardu `.dev-alert` (dashboard sdílenou `Notice`
  // nepoužívá — docs/frontend.md → Vývojářský dashboard).
  import { devInfo } from '../info.svelte.js';
  import { entryUrl, httpsEntry } from '../access.js';
  import { t } from '../i18n/index.js';

  let { context = 'dataSources' } = $props();

  const insecure = typeof window !== 'undefined' && window.isSecureContext === false;
  const target = $derived(httpsEntry(devInfo.value?.access));
  const href = $derived(target ? entryUrl(target.origin, window.location) : null);
</script>

{#if insecure}
  <div class="dev-alert dev-alert--warning" data-testid="insecure-context-alert">
    <span class="dev-alert__text">
      <strong>{t('access.insecure.title')}</strong>
      {context === 'bridge' ? t('access.insecure.bridge') : t('access.insecure.dataSources')}
      {#if target}
        {#if target.kind === 'https-ip'}{t('access.insecure.certHint')}{/if}
        <a {href} data-testid="insecure-context-link">{t('access.insecure.open', { origin: target.origin })}</a>
      {:else}
        {t('access.insecure.noHttps')}
      {/if}
    </span>
  </div>
{/if}
