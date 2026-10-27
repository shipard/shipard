<script>
  import { onMount } from 'svelte';
  import { opAuth } from '../../stores/opAuth.svelte.js';
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { approveOpAuth } from '../../api/oidcOp.js';
  import { brandingUrl } from '../../api/app.js';
  import { t } from '../../i18n/index.js';
  import Notice from '../ui/Notice.svelte';
  import { translateError } from '../../i18n/errors.js';

  // Schválení OIDC OP transakce (session bridge D10): mountuje se jen
  // s přihlášenou session a pending opAuth.txn. Úspěch = plná navigace
  // zpět na klientský DS (obrazovka zůstává na „Přesměrování…"), chyba
  // (expirovaná/použitá transakce) = hláška + návrat na portál.
  let errorMessage = $state(null);

  onMount(async () => {
    const response = await approveOpAuth(opAuth.txn);
    if (response?.success && response.data?.redirect) {
      window.location = response.data.redirect;
      return;
    }
    errorMessage = response?.error ? translateError(response.error) : t('common.unknownError');
  });

  function backToPortal() {
    // Vyčištění pending transakce — App.svelte spadne na portál/app shell.
    opAuth.clear();
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

    {#if errorMessage}
      <div class="shpd-login__notice"><Notice tone="danger">{t('opAuth.error')}</Notice></div>
      <p class="shpd-login__intro">{errorMessage}</p>
      <button class="shpd-login__button" type="button" onclick={backToPortal}>
        {t('opAuth.backToPortal')}
      </button>
    {:else}
      <p class="shpd-login__status">{t('opAuth.redirecting')}</p>
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

  .shpd-login__status {
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text-secondary);
    text-align: center;
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
    font-weight: 600;
    color: #fff;
    background-color: var(--shpd-color-primary);
    border: none;
    border-radius: var(--shpd-radius-md);
    cursor: pointer;
    transition: background-color 0.15s;
  }

  .shpd-login__button:hover {
    background-color: var(--shpd-color-primary-hover);
  }
</style>
