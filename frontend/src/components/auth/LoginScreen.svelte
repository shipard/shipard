<script>
  import { login, oidcStartUrl, forgotPassword } from '../../api/auth.js';
  import { authStore } from '../../stores/auth.svelte.js';
  import { opAuth } from '../../stores/opAuth.svelte.js';
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { loginNotice } from '../../stores/loginNotice.svelte.js';
  import { brandingUrl } from '../../api/app.js';
  import { language, t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let { onSuccess } = $props();

  let loginName = $state('');
  let password = $state('');
  let loading = $state(false);
  // Předvyplněná chyba z OIDC redirectu (?login_error=…) — main.js ji uloží
  // do loginNotice před mountem.
  let errorMessage = $state(loginNotice.error ? t(`login.error.${loginNotice.error}`) : '');
  // Úspěšná flash hláška (např. heslo nastavené na SetPasswordScreen).
  let successMessage = $state(
    loginNotice.type === 'success' ? t(`login.notice.${loginNotice.code}`) : ''
  );

  // Inline režim „Zapomenuté heslo?" — identifier + submit, odpověď je vždy
  // stejná (anti-enumerace řeší server).
  let forgotMode = $state(false);
  let forgotIdentifier = $state('');
  let forgotSent = $state(false);

  // Auth politika z /_app/info: než se načte (null), zobrazuje se lokální
  // formulář bez provider tlačítek — bezpečný default shodný s dneškem.
  const localEnabled = $derived(appInfoStore.auth?.local !== false);
  const providers = $derived(appInfoStore.auth?.providers ?? []);

  let loginInput = $state(null);

  $effect(() => {
    loginInput?.focus();
  });

  function startOidc(providerId) {
    loginNotice.clear();
    // Rozjednaná OP transakce (?op_auth=…) by plnou navigaci na IdP
    // nepřežila — server ji vrátí v handoff redirectu (return_to).
    const returnTo = opAuth.txn ? `?op_auth=${opAuth.txn}` : null;
    window.location.href = oidcStartUrl(providerId, returnTo);
  }

  async function handleSubmit() {
    if (loading) return;

    loading = true;
    errorMessage = '';

    const response = await login(loginName, password);

    loading = false;

    if (response?.success) {
      authStore.setAuth(response);
      onSuccess?.();
    } else {
      errorMessage = response?.error ? translateError(response.error) : t('login.failed');
    }
  }

  function handleKeydown(event) {
    if (event.key === 'Enter') {
      handleSubmit();
    }
  }

  function enterForgotMode() {
    forgotMode = true;
    forgotSent = false;
    forgotIdentifier = loginName;
    errorMessage = '';
    successMessage = '';
    loginNotice.clear();
  }

  function exitForgotMode() {
    forgotMode = false;
    forgotSent = false;
  }

  async function handleForgotSubmit() {
    if (loading || forgotIdentifier.trim() === '') return;

    loading = true;
    try {
      await forgotPassword(forgotIdentifier.trim());
    } catch {
      // Odpověď je informačně prázdná — i síťovou chybu skryjeme za
      // jednotnou hlášku, ať flow nic neprozrazuje.
    }
    loading = false;
    forgotSent = true;
  }

  function handleForgotKeydown(event) {
    if (event.key === 'Enter') {
      handleForgotSubmit();
    }
  }

  // Discreet picker v patce karty — Phase 1B varianta B. Native <select>:
  // klávesnice + a11y dostupné zadarmo, žádný vlastní open/close state,
  // a v sidebar dropdown už dropdown stejně máme. setMode() reloadne stránku.
  const languageOptions = [
    { value: 'cs',   labelKey: 'sidebar.language.cs' },
    { value: 'en',   labelKey: 'sidebar.language.en' },
    { value: 'auto', labelKey: 'sidebar.language.auto' },
  ];

  function handleLanguageChange(event) {
    language.setMode(event.target.value);
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

    {#if forgotMode}
      {#if forgotSent}
        <div class="shpd-login__success">{t('login.forgot.sent')}</div>
        <button class="shpd-login__button" type="button" onclick={exitForgotMode}>
          {t('login.forgot.backToLogin')}
        </button>
      {:else}
        <p class="shpd-login__intro">{t('login.forgot.intro')}</p>
        <div class="shpd-login__field">
          <label class="shpd-login__label" for="forgot-identifier">{t('login.forgot.identifier')}</label>
          <input
            id="forgot-identifier"
            class="shpd-login__input"
            type="text"
            autocomplete="username"
            disabled={loading}
            bind:value={forgotIdentifier}
            onkeydown={handleForgotKeydown}
          />
        </div>
        <button
          class="shpd-login__button"
          type="button"
          disabled={loading}
          onclick={handleForgotSubmit}
        >
          {loading ? t('login.forgot.submitting') : t('login.forgot.submit')}
        </button>
        <button class="shpd-login__link" type="button" onclick={exitForgotMode}>
          {t('login.forgot.backToLogin')}
        </button>
      {/if}
    {:else}
    {#if localEnabled}
      <div class="shpd-login__field">
        <label class="shpd-login__label" for="login-name">{t('login.username')}</label>
        <input
          bind:this={loginInput}
          id="login-name"
          data-testid="login-name"
          class="shpd-login__input"
          type="text"
          autocomplete="username"
          disabled={loading}
          bind:value={loginName}
          onkeydown={handleKeydown}
        />
      </div>

      <div class="shpd-login__field">
        <label class="shpd-login__label" for="login-password">{t('login.password')}</label>
        <input
          id="login-password"
          data-testid="login-password"
          class="shpd-login__input"
          type="password"
          autocomplete="current-password"
          disabled={loading}
          bind:value={password}
          onkeydown={handleKeydown}
        />
      </div>
    {/if}

    {#if successMessage}
      <div class="shpd-login__success">{successMessage}</div>
    {/if}

    {#if errorMessage}
      <div class="shpd-login__error" data-testid="login-error">{errorMessage}</div>
    {/if}

    {#if localEnabled}
      <button
        class="shpd-login__button"
        type="button"
        disabled={loading}
        onclick={handleSubmit}
      >
        {loading ? t('login.submitting') : t('login.submit')}
      </button>
      <button class="shpd-login__link" type="button" onclick={enterForgotMode}>
        {t('login.forgot.link')}
      </button>
    {/if}

    {#if providers.length > 0}
      <!-- Oddělovač jen když je vidět i lokální formulář -->
      {#if localEnabled}
        <div class="shpd-login__divider">{t('login.providerHint')}</div>
      {/if}
      {#each providers as provider (provider.id)}
        <button
          class="shpd-login__provider"
          type="button"
          onclick={() => startOidc(provider.id)}
        >
          {provider.label}
        </button>
      {/each}
    {/if}
    {/if}

    <div class="shpd-login__footer">
      <label class="shpd-login__lang-label" for="login-language">
        {t('login.languagePicker.label')}
      </label>
      <select
        id="login-language"
        class="shpd-login__lang-select"
        value={language.mode}
        onchange={handleLanguageChange}
      >
        {#each languageOptions as opt}
          <option value={opt.value}>{t(opt.labelKey)}</option>
        {/each}
      </select>
    </div>
  </div>
</div>

<style>
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

  .shpd-login__error {
    margin-bottom: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    background-color: var(--shpd-color-danger-soft);
    border: 1px solid var(--shpd-color-danger);
    border-radius: var(--shpd-radius-md);
  }

  .shpd-login__success {
    margin-bottom: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-success);
    background-color: var(--shpd-color-success-soft);
    border: 1px solid var(--shpd-color-success);
    border-radius: var(--shpd-radius-md);
  }

  .shpd-login__intro {
    margin-bottom: var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-login__link {
    display: block;
    margin: var(--shpd-space-md) auto 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    background: none;
    border: none;
    cursor: pointer;
    text-decoration: underline;
  }

  .shpd-login__link:hover {
    color: var(--shpd-color-text);
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

  .shpd-login__divider {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin: var(--shpd-space-lg) 0 var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-login__divider::before,
  .shpd-login__divider::after {
    content: '';
    flex: 1;
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-login__provider {
    display: block;
    width: 100%;
    margin-top: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-base);
    font-weight: 500;
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    transition: border-color 0.15s, background-color 0.15s;
  }

  .shpd-login__provider:hover {
    background-color: var(--shpd-color-bg-secondary);
    border-color: var(--shpd-color-border-focus);
  }

  .shpd-login__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-lg);
    padding-top: var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-login__lang-label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-login__lang-select {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    font-family: inherit;
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
  }

  .shpd-login__lang-select:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }
</style>
