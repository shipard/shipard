<script>
  // Nastavení hesla z mailového linku (pozvánka i reset — jedna landing
  // page). Token drží in-memory authAction store (main.js ho vytáhl z URL
  // před mountem), úspěch vrací na LoginScreen s flash hláškou.
  import { resetPassword } from '../../api/auth.js';
  import { authAction } from '../../stores/authAction.svelte.js';
  import { loginNotice } from '../../stores/loginNotice.svelte.js';
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { brandingUrl } from '../../api/app.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import Notice from '../ui/Notice.svelte';

  const MIN_LENGTH = 12;

  let password = $state('');
  let confirm = $state('');
  let loading = $state(false);
  let errorMessage = $state('');
  // INVALID_TOKEN je terminální — formulář nahradí hláška s návratem na login.
  let tokenInvalid = $state(false);

  let passwordInput = $state(null);

  $effect(() => {
    passwordInput?.focus();
  });

  async function handleSubmit() {
    if (loading) return;

    errorMessage = '';
    if (password.length < MIN_LENGTH) {
      errorMessage = t('setPassword.tooShort', { min: MIN_LENGTH });
      return;
    }
    if (password !== confirm) {
      errorMessage = t('setPassword.mismatch');
      return;
    }

    loading = true;
    const response = await resetPassword(authAction.token, password);
    loading = false;

    if (response?.success) {
      loginNotice.set('password_set', 'success');
      authAction.clear();
      return;
    }

    if (response?.error?.code === 'INVALID_TOKEN') {
      tokenInvalid = true;
      return;
    }
    errorMessage = response?.error ? translateError(response.error) : t('common.unknownError');
  }

  function backToLogin() {
    loginNotice.clear();
    authAction.clear();
  }

  function handleKeydown(event) {
    if (event.key === 'Enter') {
      handleSubmit();
    }
  }
</script>

<div class="shpd-login">
  <div class="shpd-login__card">
    {#if appInfoStore.companyLogo}
      <img
        class="shpd-login__logo"
        src={brandingUrl('companyLogo', appInfoStore.companyLogo.hash)}
        alt=""
      />
    {/if}
    <h1 class="shpd-login__heading">{appInfoStore.name ?? t('login.heading')}</h1>

    {#if tokenInvalid}
      <div class="shpd-login__notice"><Notice tone="danger">{t('setPassword.invalidToken')}</Notice></div>
      <button class="shpd-login__button" type="button" onclick={backToLogin}>
        {t('setPassword.backToLogin')}
      </button>
    {:else}
      <p class="shpd-login__intro">{t('setPassword.intro')}</p>

      <div class="shpd-login__field">
        <label class="shpd-login__label" for="set-password">{t('setPassword.password')}</label>
        <input
          bind:this={passwordInput}
          id="set-password"
          class="shpd-login__input"
          type="password"
          autocomplete="new-password"
          disabled={loading}
          bind:value={password}
          onkeydown={handleKeydown}
        />
      </div>

      <div class="shpd-login__field">
        <label class="shpd-login__label" for="set-password-confirm">{t('setPassword.confirm')}</label>
        <input
          id="set-password-confirm"
          class="shpd-login__input"
          type="password"
          autocomplete="new-password"
          disabled={loading}
          bind:value={confirm}
          onkeydown={handleKeydown}
        />
      </div>

      <p class="shpd-login__hint">{t('setPassword.policyHint', { min: MIN_LENGTH })}</p>

      {#if errorMessage}
        <div class="shpd-login__notice"><Notice tone="danger">{errorMessage}</Notice></div>
      {/if}

      <button
        class="shpd-login__button"
        type="button"
        disabled={loading}
        onclick={handleSubmit}
      >
        {loading ? t('setPassword.submitting') : t('setPassword.submit')}
      </button>
    {/if}
  </div>
</div>

<style>
  /* Sdílí vizuál s LoginScreen — stejné třídy, vlastní kopie stylů
     (Svelte styly jsou scoped per komponenta). */
  .shpd-login {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-login__card {
    width: 100%;
    max-width: 400px;
    padding: var(--shpd-space-xl);
    background-color: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-md);
  }

  .shpd-login__logo {
    display: block;
    max-height: 48px;
    max-width: 200px;
    margin: 0 auto var(--shpd-space-md);
  }

  .shpd-login__heading {
    margin-bottom: var(--shpd-space-lg);
    font-size: var(--shpd-font-size-xl);
    font-weight: 700;
    color: var(--shpd-color-text);
    text-align: center;
  }

  .shpd-login__intro {
    margin-bottom: var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-login__field {
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-login__label {
    display: block;
    margin-bottom: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-login__input {
    display: block;
    width: 100%;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    outline: none;
    transition: border-color 0.15s;
  }

  .shpd-login__input:focus {
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 3px var(--shpd-color-focus-ring);
  }

  .shpd-login__input:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .shpd-login__hint {
    margin-bottom: var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  /* Hláška přihlášení (#137 D40): sdílená Notice, obal dává jen mezeru. */
  .shpd-login__notice {
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-login__button {
    display: block;
    width: 100%;
    margin-top: var(--shpd-space-lg);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-base);
    font-weight: 500;
    color: #ffffff;
    background-color: var(--shpd-color-primary);
    border-radius: var(--shpd-radius-md);
    transition: background-color 0.15s;
  }

  .shpd-login__button:hover:not(:disabled) {
    background-color: var(--shpd-color-primary-hover);
  }

  .shpd-login__button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }
</style>
