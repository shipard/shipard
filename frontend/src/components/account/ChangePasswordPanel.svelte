<script>
  // Změna hesla přihlášeného uživatele. Pro OIDC/JIT účty bez lokálního
  // hesla se panel nezobrazuje (rozhoduje rodič dle user.has_password);
  // server chybu stejně vrátí (NO_LOCAL_PASSWORD).
  import { changePassword } from '../../api/security.js';
  import Input from '../ui/Input.svelte';
  import Button from '../ui/Button.svelte';
  import Notice from '../ui/Notice.svelte';
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
    <div class="shpd-security-panel__notice">
      <Notice tone={message.type === 'success' ? 'success' : 'danger'}>{message.text}</Notice>
    </div>
  {/if}

  <div class="shpd-security-panel__fields">
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

  /* Výsledek změny hesla je sdílená Notice (#133 D61) — obal dává jen mezeru. */
  .shpd-security-panel__notice {
    margin-bottom: var(--shpd-space-md);
  }

  /* Popisek vlevo, pole vpravo podle pravidel formuláře (#157 D117, D118):
     stopa popisků podle nejdelšího popisku, nejvýš 40 %, pole nejvýš 20rem;
     popisek nahoře s řádkem na svislém středu pole. Řádky sdílejí stopy
     přes subgrid (vzor SettingsPage); nápověda a tlačítko jdou přes obě
     stopy, takže tlačítko končí u pravého okraje polí. */
  .shpd-security-panel__fields {
    display: grid;
    grid-template-columns: fit-content(40%) minmax(0, 20rem);
    column-gap: var(--shpd-space-md);
    row-gap: var(--shpd-space-sm);
    align-items: start;
  }

  .shpd-security-panel__field {
    display: grid;
    grid-template-columns: subgrid;
    grid-column: 1 / -1;
    align-items: start;
  }

  .shpd-security-panel__label {
    padding-top: calc((var(--shpd-control-height) - 1lh) / 2);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text-secondary);
    text-align: right;
    overflow-wrap: anywhere;
  }

  .shpd-security-panel__hint {
    grid-column: 1 / -1;
    margin: 0 0 var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-security-panel__actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: flex-end;
  }

  /* Úzký kontejner: popisky nad poli; popisek–pole `xs`, mezi dvojicemi `md`. */
  @container (max-width: 30rem) {
    .shpd-security-panel__fields,
    .shpd-security-panel__field {
      grid-template-columns: minmax(0, 1fr);
    }

    .shpd-security-panel__fields {
      row-gap: var(--shpd-space-md);
    }

    .shpd-security-panel__field {
      row-gap: var(--shpd-space-xs);
    }

    .shpd-security-panel__label {
      padding-top: 0;
      text-align: left;
    }

    .shpd-security-panel__hint {
      margin: 0;
    }
  }
</style>
