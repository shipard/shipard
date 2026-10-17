<script>
  // Dialog dev dashboardu: overlay + karta s titulkem, tělem a patičkou.
  // Záměrně bez sdíleného `Modal` (ten tahá `t()` a `api/client.js`,
  // docs/frontend.md → Vývojářský dashboard). Esc a klik mimo kartu zavírá.
  import { t } from '../i18n/index.js';

  let { open = false, title = '', width = '640px', onClose, children, footer } = $props();

  function onKeydown(e) {
    if (open && e.key === 'Escape') onClose?.();
  }
</script>

<svelte:window onkeydown={onKeydown} />

{#if open}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="dev-dialog" role="dialog" aria-modal="true" aria-label={title} tabindex="-1" onclick={(e) => { if (e.target === e.currentTarget) onClose?.(); }}>
    <div class="dev-dialog__card" style="width: {width}">
      <div class="dev-dialog__header">
        <span class="dev-dialog__title">{title}</span>
        <button type="button" class="dev-dialog__close" aria-label={t('common.close')} onclick={() => onClose?.()}>×</button>
      </div>
      <div class="dev-dialog__body">
        {@render children?.()}
      </div>
      {#if footer}
        <div class="dev-dialog__footer">
          {@render footer()}
        </div>
      {/if}
    </div>
  </div>
{/if}
