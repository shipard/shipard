<script>
  // Hodnota pole projekce: prázdná (null) jako „∅“, objekt jako JSON,
  // ostatní jak jsou (čísla bez formátování — jde o syrový tvar výstupu).
  import { t } from '../i18n/index.js';

  let { value, muted = false } = $props();
  const text = $derived.by(() => {
    if (value === null || value === undefined) return null;
    if (typeof value === 'object') return JSON.stringify(value);
    if (typeof value === 'boolean') return value ? 'true' : 'false';
    return String(value);
  });
</script>

{#if text === null}
  <span class="empty" title={t('common.empty')}>∅</span>
{:else}
  <span class="value" class:dev-muted={muted}>{text}</span>
{/if}

<style>
  .empty { color: var(--shpd-color-text-secondary); opacity: 0.7; }
  .value { white-space: pre-wrap; word-break: break-word; }
</style>
