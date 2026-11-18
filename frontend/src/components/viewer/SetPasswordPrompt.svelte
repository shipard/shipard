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

<Modal title={t('viewer.detail.setPasswordTitle')} {open} {onClose} width="30rem">
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
    <Button label={t('common.cancel')} variant="secondary" dismiss disabled={submitting} onclick={onClose} />
    <Button
      label={submitting ? t('common.saving') : t('viewer.detail.setPassword')}
      variant="primary"
      disabled={submitting || password === ''}
      onclick={handleConfirm}
    />
  {/snippet}
</Modal>

<style>
  .shpd-setpw__label {
    display: block;
    margin-bottom: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  /* Výška, písmo a rámeček ovládacího prvku z tokenů (#156 D106–D109). */
  .shpd-setpw__input {
    width: 100%;
    box-sizing: border-box;
    height: var(--shpd-control-height);
    padding: 0 var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border-control);
    border-radius: var(--shpd-radius-sm);
    font-family: inherit;
    font-size: var(--shpd-font-size-field);
  }
</style>
