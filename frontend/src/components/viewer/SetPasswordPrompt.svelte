<script>
  /**
   * Prompt na nastavení hesla SMTP senderu (detail akce setPassword,
   * POST /_mail/senders/{id}/password). Heslo drží jen lokálně a předává
   * ho volajícímu přes onConfirm(password); po každém otevření se čistí.
   * Klon RejectReasonPrompt s password inputem místo textarey.
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import { t } from '../../i18n/index.js';

  let {
    open = false,
    submitting = false,
    onConfirm = () => {},
    onClose = () => {},
  } = $props();

  let password = $state('');

  // Vyčisti heslo při každém otevření — nesmí přežít mezi sendery.
  $effect(() => {
    if (open) password = '';
  });

  function handleConfirm() {
    if (password === '' || submitting) return;
    onConfirm(password);
  }
</script>

<Modal title={t('viewer.detail.setPasswordTitle')} {open} {onClose} width="480px">
  <label for="set-password-prompt" class="shpd-setpw__label">{t('viewer.detail.setPasswordLabel')}</label>
  <input
    id="set-password-prompt"
    class="shpd-setpw__input"
    type="password"
    autocomplete="new-password"
    bind:value={password}
    onkeydown={(e) => { if (e.key === 'Enter') handleConfirm(); }}
  />

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" size="sm" disabled={submitting} onclick={onClose} />
    <Button
      label={submitting ? t('common.saving') : t('viewer.detail.setPassword')}
      variant="primary"
      size="sm"
      disabled={submitting || password === ''}
      onclick={handleConfirm}
    />
  {/snippet}
</Modal>

<style>
  .shpd-setpw__label {
    display: block;
    margin-bottom: 0.375rem;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-setpw__input {
    width: 100%;
    box-sizing: border-box;
    padding: 0.375rem;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
  }
</style>
