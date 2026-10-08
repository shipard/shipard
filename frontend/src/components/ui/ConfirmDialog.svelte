<script>
  /**
   * Potvrzovací dialog nad Modal — náhrada `window.confirm` (issue #53,
   * fáze 1). V této fázi nasazený jen v FormSubTable; zbývající výskyty
   * `window.confirm` viz tasks/TODO.md.
   *
   * Klávesy: Enter = potvrdit (po otevření má fokus potvrzovací tlačítko,
   * takže Enter je jeho nativní klik — žádný globální listener, který by
   * kolidoval s formulářem pod dialogem), Esc = zrušit (řeší Modal přes
   * modal stack — zavře jen tento dialog, rodičovský zůstane). Karta je
   * `fixedSize` — na hloubce 2 by 480 px po depth-shrinku už nestačilo.
   */
  import { tick } from 'svelte';
  import Modal from './Modal.svelte';
  import Button from './Button.svelte';
  import { t } from '../../i18n/index.js';

  let {
    open = false,
    title,
    message,
    confirmLabel = t('common.confirm'),
    cancelLabel = t('common.cancel'),
    /** 'primary' | 'danger' — barva potvrzovacího tlačítka. */
    variant = 'primary',
    /** Probíhá potvrzená akce — tlačítka vypnutá, potvrzení se spinnerem. */
    busy = false,
    onConfirm,
    onCancel,
    testid = 'confirm-dialog',
  } = $props();

  const confirmVariant = $derived(variant === 'danger' ? 'danger' : 'primary');

  // Fokus na potvrzovací tlačítko po vykreslení karty — Enter = potvrdit
  // a fokus opustí prvek pod overlayem (jinak by Enter klikl na něj).
  $effect(() => {
    if (!open) return;
    tick().then(() => {
      document
        .querySelector(`[data-testid="${testid}"] [data-testid="confirm-ok"]`)
        ?.focus();
    });
  });

  function handleCancel() {
    if (busy) return;
    onCancel?.();
  }

  function handleConfirm() {
    if (busy) return;
    onConfirm?.();
  }
</script>

<Modal {title} {open} onClose={handleCancel} width="480px" fixedSize {testid}>
  <div class="shpd-confirm__message">{message}</div>

  {#snippet footer()}
    <Button
      label={cancelLabel}
      variant="secondary"
      size="sm"
      disabled={busy}
      onclick={handleCancel}
      testid="confirm-cancel"
    />
    <Button
      label={confirmLabel}
      variant={confirmVariant}
      size="sm"
      loading={busy}
      disabled={busy}
      onclick={handleConfirm}
      testid="confirm-ok"
    />
  {/snippet}
</Modal>

<style>
  .shpd-confirm__message {
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text);
    line-height: 1.5;
    white-space: pre-line;
  }
</style>
