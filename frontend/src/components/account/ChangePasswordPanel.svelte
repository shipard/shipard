<script>
  // Změna hesla přihlášeného uživatele. Pro OIDC/JIT účty bez lokálního
  // hesla se panel nezobrazuje (rozhoduje rodič dle user.has_password);
  // server chybu stejně vrátí (NO_LOCAL_PASSWORD).
  import { changePassword } from '../../api/security.js';
  import Input from '../ui/Input.svelte';
  import Button from '../ui/Button.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  const MIN_LENGTH = 12;

  let currentPassword = $state('');
  let newPassword = $state('');
  let confirm = $state('');
  let saving = $state(false);
  let message = $state(null); // {type: 'success' | 'error', text}

  async function handleSubmit() {
    if (saving) return;

    message = null;
    if (newPassword.length < MIN_LENGTH) {
      message = { type: 'error', text: t('account.security.tooShort', { min: MIN_LENGTH }) };
      return;
    }
    if (newPassword !== confirm) {
      message = { type: 'error', text: t('account.security.mismatch') };
      return;
    }

    saving = true;
    const response = await changePassword(currentPassword, newPassword);
    saving = false;

    if (response === null) return; // odhlášeno — client.js smazal token

    if (response.success) {
      message = { type: 'success', text: t('account.security.changed') };
      currentPassword = '';
      newPassword = '';
      confirm = '';
    } else {
      message = { type: 'error', text: translateError(response.error) };
    }
  }
</script>

<section class="shpd-security-panel">
  <h3 class="shpd-security-panel__title">{t('account.security.changePassword')}</h3>

  {#if message}
    <div
      class="shpd-security-panel__message"
      class:shpd-security-panel__message--success={message.type === 'success'}
      class:shpd-security-panel__message--error={message.type === 'error'}
    >
      {message.text}
    </div>
  {/if}

  <div class="shpd-security-panel__field">
    <label class="shpd-security-panel__label" for="current-password">{t('account.security.currentPassword')}</label>
    <Input id="current-password" type="password" bind:value={currentPassword} disabled={saving} />
  </div>
  <div class="shpd-security-panel__field">
    <label class="shpd-security-panel__label" for="new-password">{t('account.security.newPassword')}</label>
    <Input id="new-password" type="password" bind:value={newPassword} disabled={saving} />
  </div>
  <div class="shpd-security-panel__field">
    <label class="shpd-security-panel__label" for="confirm-password">{t('account.security.confirmPassword')}</label>
    <Input id="confirm-password" type="password" bind:value={confirm} disabled={saving} />
  </div>

  <p class="shpd-security-panel__hint">{t('account.security.policyHint', { min: MIN_LENGTH })}</p>

  <div class="shpd-security-panel__actions">
    <Button
      label={t('account.security.submit')}
      loading={saving}
      onclick={handleSubmit}
    />
  </div>
</section>

<style>
  .shpd-security-panel {
    /* Kontejner pro @container níže (#133 D43): panel reaguje na svou šířku,
       ne na okno. Blok na celou šířku rodiče, pole až uvnitř. */
    container-type: inline-size;
  }

  .shpd-security-panel__title {
    margin-bottom: var(--shpd-space-md);
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-security-panel__message {
    margin-bottom: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    border-radius: var(--shpd-radius-md);
  }

  .shpd-security-panel__message--success {
    color: var(--shpd-color-success);
    background-color: var(--shpd-color-success-soft);
    border: 1px solid var(--shpd-color-success);
  }

  .shpd-security-panel__message--error {
    color: var(--shpd-color-danger);
    background-color: var(--shpd-color-danger-soft);
    border: 1px solid var(--shpd-color-danger);
  }

  .shpd-security-panel__field {
    display: grid;
    grid-template-columns: 180px minmax(0, 320px);
    align-items: center;
    gap: var(--shpd-space-md);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-security-panel__label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text-secondary);
    text-align: right;
  }

  .shpd-security-panel__hint {
    margin: var(--shpd-space-sm) 0 var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-security-panel__actions {
    display: flex;
    justify-content: flex-end;
    max-width: calc(180px + 320px + var(--shpd-space-md));
  }

  /* Úzký kontejner: popisky nad poli. */
  @container (max-width: 30rem) {
    .shpd-security-panel__field {
      grid-template-columns: 1fr;
      gap: var(--shpd-space-xs);
    }

    .shpd-security-panel__label {
      text-align: left;
    }
  }
</style>
