<script>
  // Dialog „Nahrát datovou sadu“ ze seznamu zdrojů dat (#103 D66): sady
  // z tests/datasets se zemí zdroje dat (ostatní neaktivní s důvodem),
  // dnešní sada zdroje dat (pole `dataset`, nebo jen z původu před F6b),
  // varování o resetu. Výměna držené sady za jinou chce druhé potvrzení
  // a posílá `replaceDataset: true` — bez něj server vrátí 409. Pojistku
  // (jen free, jen development) dělá server; u reálné kopie se dialog
  // v seznamu ani nenabízí.
  import { onMount } from 'svelte';
  import DevDialog from '../components/DevDialog.svelte';
  import DevConfirm from '../components/DevConfirm.svelte';
  import Button from '../../components/ui/Button.svelte';
  import { testingApi } from '../testing/api.js';
  import { ApiError } from '../api.js';
  import { router, onLinkClick } from '../router.svelte.js';

  let { open = false, ds, onClose, onStarted } = $props();

  let datasets = $state(null);
  let loadError = $state(null);
  let selected = $state('');
  let busy = $state(false);
  let error = $state(null); // {text, jobId?}
  let confirmReplace = $state(false);

  /** Sada, kterou zdroj dat dnes drží (pole `dataset`, jinak původ `dataset-seed <sada>`). */
  const held = $derived(ds.dataset?.name ?? ds.legacyDataset ?? null);
  const chosen = $derived((datasets ?? []).find((d) => d.name === selected) ?? null);
  const isReplace = $derived(held !== null && chosen !== null && chosen.name !== held);

  function allowed(d) {
    return !d.country || !ds.country || d.country === ds.country;
  }

  function label(d) {
    const base = `${d.title} — ${d.name} · ${d.country ?? 'bez země'} · ${d.profile ?? 'bez profilu'}`;
    return allowed(d) ? base : `${base} (země ${d.country} ≠ ${ds.country})`;
  }

  onMount(async () => {
    try {
      datasets = await testingApi.datasets();
      const first = datasets.find((d) => allowed(d) && d.name === held) ?? datasets.find(allowed);
      selected = first?.name ?? '';
    } catch (e) {
      loadError = e.message;
    }
  });

  function submit() {
    if (!chosen || busy) return;
    if (isReplace) {
      confirmReplace = true;
      return;
    }
    launch(false);
  }

  async function launch(replace) {
    busy = true;
    error = null;
    try {
      const { job } = await testingApi.reseed(chosen.name, ds.id, { replaceDataset: replace });
      confirmReplace = false;
      onStarted?.(job);
    } catch (e) {
      confirmReplace = false;
      error = e instanceof ApiError
        ? { text: `${e.message} (${e.code})`, jobId: e.details?.jobId ?? null }
        : { text: `Chyba sítě: ${e.message}` };
    } finally {
      busy = false;
    }
  }
</script>

<DevDialog title="Nahrát datovou sadu" {open} width="560px" onClose={() => { if (!busy && !confirmReplace) onClose?.(); }}>
  <div class="dev-form">
    <dl class="dev-kv dev-small">
      <dt>Zdroj dat</dt>
      <dd>{ds.name} <code>{ds.id}</code>{#if ds.country} · země <code>{ds.country}</code>{/if}</dd>
      <dt>Dnešní sada</dt>
      <dd>
        {#if held}
          <code>{held}</code>{#if !ds.dataset} <span class="dev-muted">(jen z původu, seedováno před F6b)</span>{/if}
        {:else}
          <span class="dev-muted">žádná</span>
        {/if}
      </dd>
    </dl>

    {#if loadError}
      <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">Sady se nepodařilo načíst: {loadError}</span></div>
    {:else if !datasets}
      <div class="dev-empty">Načítám sady…</div>
    {:else}
      <div class="dev-field">
        <label for="load-dataset">Sada</label>
        <select id="load-dataset" bind:value={selected} disabled={busy}>
          {#each datasets as d (d.name)}
            <option value={d.name} disabled={!allowed(d)}>{label(d)}</option>
          {:else}
            <option value="" disabled>Žádná sada v tests/datasets</option>
          {/each}
        </select>
        <div class="dev-field__hint">Nabízí se jen sady se zemí zdroje dat; ostatní jsou neaktivní.</div>
      </div>
    {/if}

    <div class="dev-alert dev-alert--warning">
      <span class="dev-alert__text">
        Reset smaže data zdroje dat. Uživatelé, nastavení a API klíče zůstanou.
        {#if isReplace}
          Sada <code>{held}</code> bude nahrazena sadou <code>{chosen.name}</code> — chce to druhé potvrzení.
        {/if}
      </span>
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
    <Button label={isReplace ? 'Nahradit sadu…' : 'Nahrát'} variant="danger" size="sm" loading={busy && !confirmReplace} disabled={busy || !chosen} onclick={submit} />
  {/snippet}
</DevDialog>

<DevConfirm
  open={confirmReplace}
  title="Nahradit sadu"
  message={`Zdroj dat „${ds.name}“ (${ds.id}) drží sadu ${held}. RESETUJE se — její data zmizí — a naseeduje sadou ${chosen?.name ?? ''}. Uživatelé, nastavení a API klíče zůstanou.`}
  variant="danger"
  confirmLabel={`Nahradit sadu ${held} sadou ${chosen?.name ?? ''}`}
  cancelLabel="Zpět"
  busy={busy}
  onConfirm={() => launch(true)}
  onCancel={() => (confirmReplace = false)}
/>
