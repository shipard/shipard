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
        : { text: `Chyba sítě: ${e.message}` };
    } finally {
      busy = false;
    }
  }
</script>

<DevDialog title="Smazat zdroj dat" {open} width="560px" onClose={() => { if (!busy) onClose?.(); }}>
  <div class="dev-form">
    <dl class="dev-kv dev-small">
      <dt>Zdroj dat</dt>
      <dd>{ds.name} <code>{ds.id}</code></dd>
      <dt>Smaže se</dt>
      <dd>
        <ul class="delete-list">
          <li>databáze <code>{ds.database_name ?? '?'}</code></li>
          <li>DB uživatel zdroje dat</li>
          <li>adresář zdroje dat (<code>config/</code>, <code>secrets/</code>, přílohy, …)</li>
          <li>přístupová práva ostatních DB účtů k databázi</li>
        </ul>
      </dd>
    </dl>

    <div class="dev-alert dev-alert--danger">
      <span class="dev-alert__text">Nevratné — bez koše a bez zálohy. Logy a záznamy úloh zůstanou.</span>
    </div>

    <div class="dev-field">
      <label for={inputId}>Opiš název zdroje dat: <strong>{ds.name}</strong></label>
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
      <div class="dev-field__hint">Tlačítko Smazat se odemkne až po přesném opsání názvu.</div>
    </div>

    {#if error}
      <div class="dev-alert dev-alert--danger">
        <span class="dev-alert__text">
          {error.text}
          {#if error.jobId}
            — <a href={router.href('testingJob', { jobId: error.jobId })} onclick={onLinkClick}>běžící úloha {error.jobId}</a>
          {/if}
        </span>
      </div>
    {/if}
  </div>

  {#snippet footer()}
    <Button label="Zrušit" variant="secondary" size="sm" disabled={busy} onclick={() => onClose?.()} />
    <Button label="Smazat" variant="danger" size="sm" loading={busy} disabled={busy || !matches} onclick={submit} />
  {/snippet}
</DevDialog>

<style>
  .delete-list {
    margin: 0;
    padding-left: 1.2em;
  }
</style>
