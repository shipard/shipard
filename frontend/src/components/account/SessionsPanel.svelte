<script>
  // Přehled vlastních relací: vytvořeno, IP, badge aktuální. Revoke per
  // řádek (kromě aktuální) + „Odhlásit ostatní zařízení". Tokeny server
  // nikdy neposílá — aktuální relaci značí flag `current`.
  import { listSessions, deleteSession, revokeOtherSessions } from '../../api/security.js';
  import Button from '../ui/Button.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let sessions = $state([]);
  let loading = $state(true);
  let working = $state(false);
  let errorMessage = $state('');

  const hasOthers = $derived(sessions.some((s) => !s.current));

  async function load() {
    loading = true;
    errorMessage = '';
    const response = await listSessions();
    loading = false;

    if (response === null) return;
    if (response.success) {
      sessions = response.data.sessions;
    } else {
      errorMessage = translateError(response.error);
    }
  }

  async function handleRevoke(id) {
    if (working) return;
    working = true;
    const response = await deleteSession(id);
    working = false;

    if (response !== null && !response.success) {
      errorMessage = translateError(response.error);
      return;
    }
    await load();
  }

  async function handleRevokeOthers() {
    if (working) return;
    working = true;
    const response = await revokeOtherSessions();
    working = false;

    if (response !== null && !response.success) {
      errorMessage = translateError(response.error);
      return;
    }
    await load();
  }

  function formatDate(iso) {
    return new Date(iso).toLocaleString();
  }

  load();
</script>

<section class="shpd-sessions-panel">
  <h3 class="shpd-sessions-panel__title">{t('account.security.sessions')}</h3>

  {#if errorMessage}
    <div class="shpd-sessions-panel__error">{errorMessage}</div>
  {/if}

  {#if loading}
    <p class="shpd-sessions-panel__status">{t('common.loading')}</p>
  {:else if sessions.length === 0}
    <p class="shpd-sessions-panel__status">{t('account.security.noSessions')}</p>
  {:else}
    <table class="shpd-sessions-panel__table">
      <thead>
        <tr>
          <th>{t('account.security.created')}</th>
          <th>{t('account.security.ipAddress')}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {#each sessions as session (session.id)}
          <tr>
            <td>
              {formatDate(session.created)}
              {#if session.current}
                <span class="shpd-sessions-panel__badge">{t('account.security.current')}</span>
              {/if}
            </td>
            <td>{session.ip_address ?? '—'}</td>
            <td class="shpd-sessions-panel__cell-action">
              {#if !session.current}
                <Button
                  label={t('account.security.revoke')}
                  variant="secondary"
                  size="sm"
                  disabled={working}
                  onclick={() => handleRevoke(session.id)}
                />
              {/if}
            </td>
          </tr>
        {/each}
      </tbody>
    </table>

    {#if hasOthers}
      <div class="shpd-sessions-panel__actions">
        <Button
          label={t('account.security.revokeOthers')}
          variant="secondary"
          disabled={working}
          onclick={handleRevokeOthers}
        />
      </div>
    {/if}
  {/if}
</section>

<style>
  .shpd-sessions-panel__title {
    margin-bottom: var(--shpd-space-md);
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-sessions-panel__error {
    margin-bottom: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    background-color: var(--shpd-color-danger-soft);
    border: 1px solid var(--shpd-color-danger);
    border-radius: var(--shpd-radius-md);
  }

  .shpd-sessions-panel__status {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-sessions-panel__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-sessions-panel__table th {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-weight: 500;
    color: var(--shpd-color-text-secondary);
    text-align: left;
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-sessions-panel__table td {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    color: var(--shpd-color-text);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-sessions-panel__cell-action {
    text-align: right;
    width: 1%;
    white-space: nowrap;
  }

  .shpd-sessions-panel__badge {
    margin-left: var(--shpd-space-xs);
    padding: 0.125rem var(--shpd-space-sm);
    font-size: var(--shpd-font-size-xs);
    font-weight: 500;
    color: var(--shpd-color-success);
    background-color: var(--shpd-color-success-soft);
    border: 1px solid var(--shpd-color-success);
    border-radius: var(--shpd-radius-full);
  }

  .shpd-sessions-panel__actions {
    display: flex;
    justify-content: flex-end;
    margin-top: var(--shpd-space-md);
  }
</style>
