<script>
  /**
   * Sdílený prompt na povinný důvod zamítnutí. Vytažen z ViewerDetail, aby ho
   * mohl použít i dashboard feed. Reason drží interně; volajícímu předává jen
   * ořezaný neprázdný důvod přes onConfirm(reason).
   *
   * Texty jsou props (title/reasonLabel/placeholder/confirmLabel), aby si každý
   * volající nesl vlastní i18n klíče (viewer.detail.* vs dashboard.reject.*).
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import { t } from '../../i18n/index.js';

  let {
    open = false,
    submitting = false,
    title = t('viewer.detail.rejectTitle'),
    reasonLabel = t('viewer.detail.rejectReasonLabel'),
    placeholder = t('viewer.detail.rejectReasonPlaceholder'),
    confirmLabel = t('viewer.detail.reject'),
    onConfirm = () => {},
    onClose = () => {},
  } = $props();

  let reason = $state('');

  // Vyčisti důvod při každém otevření (znovupoužití napříč doklady).
  $effect(() => {
    if (open) reason = '';
  });

  function handleConfirm() {
    const trimmed = reason.trim();
    if (trimmed === '' || submitting) return;
    onConfirm(trimmed);
  }
</script>

<Modal {title} {open} {onClose} width="480px">
  <label for="reject-reason-prompt" class="shpd-reject__label">{reasonLabel}</label>
  <textarea
    id="reject-reason-prompt"
    class="shpd-reject__textarea"
    bind:value={reason}
    rows="3"
    {placeholder}
  ></textarea>

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" size="sm" onclick={onClose} />
    <Button
      label={submitting ? t('common.saving') : confirmLabel}
      variant="danger"
      size="sm"
      disabled={submitting || reason.trim() === ''}
      onclick={handleConfirm}
    />
  {/snippet}
</Modal>

<style>
  .shpd-reject__label {
    display: block;
    margin-bottom: 0.375rem;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-reject__textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 0.375rem;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
  }
</style>
