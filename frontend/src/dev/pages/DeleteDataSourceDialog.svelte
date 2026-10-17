<script>
  // Dialog „Smazat zdroj dat“ ze seznamu zdrojů dat (tasks/ds-delete.md
  // § 2, D70): co se smaže, varování „Nevratné“, potvrzení opsáním názvu,
  // `POST /_dev/api/data-sources/{id}/delete` → úloha na pozadí
  // (`shpd-server ds-delete <id> --yes`). Pojistku (jen volný zdroj dat, jen
  // režim development, žádná běžící úloha) dělá server; u reálné kopie se
  // položka v kebab menu ani nenabídne.
  import { tick } from 'svelte';
  import DevDialog from '../components/DevDialog.svelte';
  import Button from '../../components/ui/Button.svelte';
  import { postJson, ApiError } from '../api.js';
  import { router, onLinkClick } from '../router.svelte.js';
  import { t } from '../i18n/index.js';

  let { open = false, ds, onClose, onStarted } = $props();

  let typed = $state('');
  let busy = $state(false);
  let error = $state(null); // {text, jobId?}
  let inputEl = $state(null);

  const matches = $derived(typed.trim() === ds.name);
  const inputId = $derived(`delete-ds-name-${ds.id}`);

  $effect(() => {
    if (open) tick().then(() => inputEl?.focus());
  });

  async function submit() {
    if (!matches || busy) return;
    busy = true;
    error = null;
    try {
      const { job } = await postJson(`/data-sources/${encodeURIComponent(ds.id)}/delete`, {});
      onStarted?.(job);
    } catch (e) {
      error = e instanceof ApiError
        ? { text: `${e.message} (${e.code})`, jobId: e.details?.jobId ?? null }
        : { text: t('common.networkError', { message: e.message }) };
    } finally {
      busy = false;
    }
  }
</script>

<DevDialog title={t('dsDelete.title')} {open} width="560px" onClose={() => { if (!busy) onClose?.(); }}>
  <div class="dev-form">
    <dl class="dev-kv dev-small">
      <dt>{t('common.dataSource')}</dt>
      <dd>{ds.name} <code>{ds.id}</code></dd>
      <dt>{t('dsDelete.willDelete')}</dt>
      <dd>
        <ul class="delete-list">
          <li>{t('dsDelete.items.database')} <code>{ds.database_name ?? '?'}</code></li>
          <li>{t('dsDelete.items.dbUser')}</li>
          <li>{t('dsDelete.items.directory')} (<code>config/</code>, <code>secrets/</code>, {t('dsDelete.items.attachmentsEtc')})</li>
          <li>{t('dsDelete.items.grants')}</li>
        </ul>
      </dd>
    </dl>

    <div class="dev-alert dev-alert--danger">
      <span class="dev-alert__text">{t('dsDelete.irreversible')}</span>
    </div>

    <div class="dev-field">
      <label for={inputId}>{t('dsDelete.typeName')} <strong>{ds.name}</strong></label>
      <input
        id={inputId}
        type="text"
        bind:this={inputEl}
        bind:value={typed}
        disabled={busy}
        autocomplete="off"
        spellcheck="false"
        onkeydown={(e) => { if (e.key === 'Enter') submit(); }}
      />
      <div class="dev-field__hint">{t('dsDelete.hint')}</div>
    </div>

    {#if error}
      <div class="dev-alert dev-alert--danger">
        <span class="dev-alert__text">
          {error.text}
          {#if error.jobId}
            — <a href={router.href('testingJob', { jobId: error.jobId })} onclick={onLinkClick}>{t('common.runningJob', { id: error.jobId })}</a>
          {/if}
        </span>
      </div>
    {/if}
  </div>

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" size="sm" disabled={busy} onclick={() => onClose?.()} />
    <Button label={t('dsDelete.confirm')} variant="danger" size="sm" loading={busy} disabled={busy || !matches} onclick={submit} />
  {/snippet}
</DevDialog>

<style>
  .delete-list {
    margin: 0;
    padding-left: 1.2em;
  }
</style>
