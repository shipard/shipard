<script>
  // Banner zámku záznamu (documentLockProviders, #55 D24) — sdílí ho
  // FormEditor (nad tab-contentem, formulář je read-only) i ViewerDetail
  // (nad záložkami). Vstup je serverový kontrakt
  // `lock: {locked, reasons: [{source, title, message, params}]}`; text se
  // lokalizuje podle `source` + `params`, neznámý zdroj spadne na text ze
  // serveru.
  import Icon from './Icon.svelte';
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
</script>

{#if reasons.length > 0}
  <div class="shpd-lock-banner" role="status" data-testid="document-lock-banner">
    <div class="shpd-lock-banner__icon"><Icon icon={iconLock} /></div>
    <div class="shpd-lock-banner__body">
      <div class="shpd-lock-banner__title">{t('lock.banner.title')}</div>
      <ul class="shpd-lock-banner__list">
        {#each reasons as reason (reason.source + ':' + (reason.subjectRowId ?? reason.title))}
          <li>
            <strong>{title(reason)}</strong>
            {#if message(reason)}<span class="shpd-lock-banner__message"> — {message(reason)}</span>{/if}
          </li>
        {/each}
      </ul>
    </div>
  </div>
{/if}

<style>
  .shpd-lock-banner {
    display: flex;
    gap: var(--shpd-space-sm);
    align-items: flex-start;
    margin: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background: var(--shpd-color-alert-warning-bg);
    color: var(--shpd-color-alert-warning-text);
    border: 1px solid var(--shpd-color-alert-warning-bar);
    border-left-width: 4px;
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-sm);
    flex-shrink: 0;
  }

  .shpd-lock-banner__icon {
    flex-shrink: 0;
    margin-top: 0.125rem;
  }

  .shpd-lock-banner__title {
    font-weight: 600;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-lock-banner__list {
    margin: 0;
    padding-left: var(--shpd-space-md);
  }

  .shpd-lock-banner__message {
    opacity: 0.85;
  }
</style>
