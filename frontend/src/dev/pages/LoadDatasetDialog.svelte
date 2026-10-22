<script>
  // Dialog „Nahrát datovou sadu“ ze seznamu zdrojů dat (#103 D66): testovací
  // sady z tests/datasets a demo sady z demo/datasets (#131) ve dvou
  // skupinách, se zemí zdroje dat (ostatní neaktivní s důvodem), dnešní
  // sada zdroje dat (pole `dataset`, nebo jen z původu před F6b), varování
  // o resetu. Běží jako úloha `reseed` přes `POST /_dev/api/data-sources/{id}/reseed`
  // — nezávisle na sekci Testování. Výměna držené sady za jinou chce druhé
  // potvrzení a posílá `replaceDataset: true` — bez něj server vrátí 409.
  // Pojistku (jen free, jen development) dělá server; u reálné kopie se
  // dialog v seznamu ani nenabízí.
  import { onMount } from 'svelte';
  import DevDialog from '../components/DevDialog.svelte';
  import DevConfirm from '../components/DevConfirm.svelte';
  import Button from '../../components/ui/Button.svelte';
  import { datasetsApi, groupDatasets, datasetLabel } from '../datasets.js';
  import { ApiError } from '../api.js';
  import { router, onLinkClick } from '../router.svelte.js';
  import { t } from '../i18n/index.js';

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
    const base = datasetLabel(d);
    return allowed(d) ? base : `${base} ${t('dsLoad.countryMismatch', { dataset: d.country, dataSource: ds.country })}`;
  }

  onMount(async () => {
    try {
      datasets = await datasetsApi.list();
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
      const { job } = await datasetsApi.reseed(ds.id, chosen.name, { replaceDataset: replace });
      confirmReplace = false;
      onStarted?.(job);
    } catch (e) {
      confirmReplace = false;
      error = e instanceof ApiError
        ? { text: `${e.message} (${e.code})`, jobId: e.details?.jobId ?? null }
        : { text: t('common.networkError', { message: e.message }) };
    } finally {
      busy = false;
    }
  }
</script>

<DevDialog title={t('ds.menu.loadDataset')} {open} width="560px" onClose={() => { if (!busy && !confirmReplace) onClose?.(); }}>
  <div class="dev-form">
    <dl class="dev-kv dev-small">
      <dt>{t('common.dataSource')}</dt>
      <dd>{ds.name} <code>{ds.id}</code>{#if ds.country} · {t('dsLoad.country')} <code>{ds.country}</code>{/if}</dd>
      <dt>{t('dsLoad.currentDataset')}</dt>
      <dd>
        {#if held}
          <code>{held}</code>{#if !ds.dataset} <span class="dev-muted">{t('dsLoad.legacyNote')}</span>{/if}
        {:else}
          <span class="dev-muted">{t('dsLoad.noneHeld')}</span>
        {/if}
      </dd>
    </dl>

    {#if loadError}
      <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{t('dsLoad.loadError', { message: loadError })}</span></div>
    {:else if !datasets}
      <div class="dev-empty">{t('dsLoad.loading')}</div>
    {:else}
      <div class="dev-field">
        <label for="load-dataset">{t('common.dataset')}</label>
        <select id="load-dataset" bind:value={selected} disabled={busy}>
          {#each groupDatasets(datasets) as g (g.kind)}
            <optgroup label={g.label}>
              {#each g.items as d (d.name)}
                <option value={d.name} disabled={!allowed(d)}>{label(d)}</option>
              {/each}
            </optgroup>
          {:else}
            <option value="" disabled>{t('dsLoad.noDatasets')}</option>
          {/each}
        </select>
        <div class="dev-field__hint">{t('dsLoad.hint')}</div>
      </div>
    {/if}

    <div class="dev-alert dev-alert--warning">
      <span class="dev-alert__text">
        {t('dsLoad.resetWarning')}
        {#if isReplace}
          {t('dsLoad.replaceNote', { held, chosen: chosen.name })}
        {/if}
      </span>
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
    <Button label={isReplace ? t('dsLoad.replace') : t('dsLoad.load')} variant={isReplace ? 'secondary' : 'danger'} size="sm" loading={busy && !confirmReplace} disabled={busy || !chosen} onclick={submit} />
  {/snippet}
</DevDialog>

<DevConfirm
  open={confirmReplace}
  title={t('dsLoad.replaceTitle')}
  message={t('dsLoad.replaceMessage', { name: ds.name, id: ds.id, held, chosen: chosen?.name ?? '' })}
  variant="danger"
  confirmLabel={t('dsLoad.replaceConfirm', { held, chosen: chosen?.name ?? '' })}
  cancelLabel={t('common.back')}
  busy={busy}
  onConfirm={() => launch(true)}
  onCancel={() => (confirmReplace = false)}
/>
