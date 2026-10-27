<script>
  // Panel Zabezpečení v Nastavení účtu (nav item type 'panel', panelId
  // accountSecurity): změna hesla + správa relací. Panel změny hesla se
  // skrývá OIDC/JIT účtům bez lokálního hesla (user.has_password z login
  // envelope; starší cache bez flagu → zobrazit, server chybu vrátí sám).
  import { authStore } from '../../stores/auth.svelte.js';
  import ChangePasswordPanel from './ChangePasswordPanel.svelte';
  import SessionsPanel from './SessionsPanel.svelte';
  import Card from '../ui/Card.svelte';
  import { iconLock } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  const hasLocalPassword = $derived(authStore.getUser()?.has_password !== false);
</script>

<div class="shpd-account-security">
  <!-- Karta stránky Nastavení účtu = sdílená Card s odsazením lg (#137 D33, D35);
       nadpis v roli title s ikonou tématu (D36, D38). -->
  <Card spacious title={t('account.security.title')} headingLevel={2} titleRole="title" icon={iconLock}>
    {#if hasLocalPassword}
      <ChangePasswordPanel />
      <hr class="shpd-account-security__divider" />
    {/if}

    <SessionsPanel />
  </Card>
</div>

<style>
  .shpd-account-security {
    padding: var(--shpd-space-lg);
    max-width: 760px;
  }

  .shpd-account-security__divider {
    margin: var(--shpd-space-lg) 0;
    border: none;
    border-top: 1px solid var(--shpd-color-border);
  }
</style>
