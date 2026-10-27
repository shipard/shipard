<script>
  // Banner zámku záznamu (documentLockProviders, #55 D24) — sdílí ho
  // FormEditor (nad tab-contentem, formulář je read-only) i ViewerDetail
  // (nad záložkami). Vstup je serverový kontrakt
  // `lock: {locked, reasons: [{source, title, message, params}]}`; text se
  // lokalizuje podle `source` + `params`, neznámý zdroj spadne na text ze
  // serveru. Vzhled dává sdílená `Notice` (#137 D40: tón warning, ikona
  // zámku, nadpis a seznam „zdroj: zpráva“, bez levého pruhu); obal tady
  // nese jen vnější mezeru, aby oba hostitelé zůstali beze změny.
  import Notice from './Notice.svelte';
  import { iconLock } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  let { lock = null } = $props();

  const reasons = $derived(lock?.locked ? (lock.reasons ?? []) : []);

  // t() vrací klíč, když překlad chybí — neznámý zdroj zámku spadne na
  // text ze serveru.
  function localized(key, params, fallback) {
    const text = t(key, params ?? {});
    return text === key ? fallback : text;
  }

  function title(reason) {
    return localized(`lock.source.${reason.source}`, reason.params, reason.title);
  }

  function message(reason) {
    return localized(`lock.message.${reason.source}`, reason.params, reason.message ?? '');
  }

  const items = $derived(reasons.map((reason) => ({ label: title(reason), text: message(reason) })));
</script>

{#if reasons.length > 0}
  <div class="shpd-lock-banner">
    <Notice
      tone="warning"
      icon={iconLock}
      title={t('lock.banner.title')}
      {items}
      data-testid="document-lock-banner"
    />
  </div>
{/if}

<style>
  .shpd-lock-banner {
    margin: var(--shpd-space-md);
    flex-shrink: 0;
  }
</style>
