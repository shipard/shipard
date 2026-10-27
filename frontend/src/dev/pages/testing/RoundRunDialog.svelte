<script>
  // Dialog spuštění testovacího kola (#103 F8a D130, D131): vrstvy, filtr
  // sad a scénářů, volitelně evaluace — varianta, opakování, odhad ceny per
  // sada (`ai-eval --estimate`, nic se nevolá) a strop útraty předvyplněný
  // 1,5× odhad; server nad stropem kolo nespustí. Výchozí kolo neutrácí.
  import DevDialog from '../../components/DevDialog.svelte';
  import Button from '../../../components/ui/Button.svelte';
  import { testingApi } from '../../testing/api.js';
  import { ApiError } from '../../api.js';
  import { suggestedCap } from '../../testing/jobs.js';
  import { roundLayerLabel, ROUND_LAYERS } from '../../testing/round.js';
  import { formatUsd } from '../../format.js';
  import { t } from '../../i18n/index.js';

  let { open = false, options, onClose, onStarted } = $props();

  let layers = $state({ code: true, datasets: true, videos: true });
  let datasets = $state({});
  let scenarios = $state({});
  let evalEnabled = $state(false);
  let variant = $state(options?.variants?.[0] ?? 'baseline');
  let repeat = $state(1);
  let estimate = $state(null);
  let estimating = $state(false);
  let starting = $state(false);
  let maxCost = $state('');
  let error = $state(null); // {text, problems?}

  const selectedLayers = $derived(ROUND_LAYERS.filter((l) => layers[l]));
  const selectedDatasets = $derived(Object.keys(datasets).filter((n) => datasets[n]));
  const selectedScenarios = $derived(Object.keys(scenarios).filter((n) => scenarios[n]));
  const capValue = $derived(Number(String(maxCost).replace(',', '.')));
  const evalActive = $derived(evalEnabled && layers.datasets);
  const canStart = $derived(!starting && !estimating && selectedLayers.length > 0 && (!evalActive || (estimate !== null && capValue > 0)));
  const toolProblems = $derived([...(layers.datasets ? options?.tools?.e2e ?? [] : []), ...(layers.videos ? options?.tools?.video ?? [] : [])]);

  function body() {
    return {
      layers: selectedLayers,
      datasets: selectedDatasets,
      scenarios: selectedScenarios,
      eval: evalActive ? { variant, repeat: Number(repeat) || 1, maxCostUsd: capValue } : null,
    };
  }

  function invalidate() {
    estimate = null;
  }

  function fail(e) {
    error = e instanceof ApiError
      ? { text: `${e.message} (${e.code})`, problems: e.details?.problems ?? null }
      : { text: t('common.networkError', { message: e.message }) };
  }

  async function runEstimate() {
    estimating = true;
    error = null;
    estimate = null;
    try {
      estimate = await testingApi.roundEstimate({ datasets: selectedDatasets, variant, repeat: Number(repeat) || 1 });
      const cap = suggestedCap(estimate.total);
      maxCost = cap === null ? '' : String(cap);
    } catch (e) {
      fail(e);
    } finally {
      estimating = false;
    }
  }

  async function start() {
    if (!canStart) return;
    starting = true;
    error = null;
    try {
      const { job } = await testingApi.startRound(body());
      onStarted?.(job);
    } catch (e) {
      fail(e);
    } finally {
      starting = false;
    }
  }
</script>

<DevDialog title={t('testing.round.run')} {open} onClose={() => onClose?.()} width="680px">
  <div class="dev-form">
    <div class="dev-field">
      <span class="dev-field__label">{t('testing.round.layers')}</span>
      <div class="dev-checks">
        {#each ROUND_LAYERS as layer (layer)}
          <label><input type="checkbox" bind:checked={layers[layer]} disabled={starting} onchange={invalidate} /> {roundLayerLabel(layer)}</label>
        {/each}
      </div>
    </div>

    {#if layers.datasets}
      <div class="dev-field">
        <span class="dev-field__label">{t('testing.round.datasets')}</span>
        <div class="dev-checks">
          {#each options?.datasets ?? [] as ds (ds.name)}
            {@const existing = options?.dataSources?.datasets?.[ds.name]}
            <label>
              <input type="checkbox" bind:checked={datasets[ds.name]} disabled={starting} onchange={invalidate} />
              <code>{ds.name}</code> <span class="dev-muted dev-small">— {existing ? t('testing.round.dsExisting', { id: existing.id }) : t('testing.round.dsCreate')}</span>
            </label>
          {/each}
        </div>
      </div>
    {/if}

    {#if layers.videos}
      <div class="dev-field">
        <span class="dev-field__label">{t('testing.round.scenarios')}</span>
        <div class="dev-checks">
          {#each options?.scenarios ?? [] as sc (sc.id)}
            {@const existing = sc.dataset ? options?.dataSources?.videos?.[sc.dataset] : null}
            <label>
              <input type="checkbox" bind:checked={scenarios[sc.id]} disabled={starting} />
              <code>{sc.id}</code>{#if sc.title} {sc.title}{/if}
              <span class="dev-muted dev-small">— {#if sc.problem}{sc.problem}{:else}{existing ? t('testing.round.dsExisting', { id: existing.id }) : t('testing.round.dsCreate')}{/if}</span>
            </label>
          {/each}
        </div>
      </div>
    {/if}

    {#if toolProblems.length}
      <div class="dev-alert dev-alert--warning">
        <span class="dev-alert__text">{t('testing.round.toolsMissing')}<ul class="dev-blockers">{#each toolProblems as p (p)}<li>{p}</li>{/each}</ul></span>
      </div>
    {/if}

    {#if layers.datasets}
      <div class="dev-field">
        <span class="dev-field__label">{t('testing.round.eval')}</span>
        <label><input type="checkbox" bind:checked={evalEnabled} disabled={starting} onchange={invalidate} /> {t('testing.round.evalEnabled')}</label>
      </div>
      {#if evalEnabled}
        <div class="dev-form__row">
          <div class="dev-field">
            <label for="round-variant">{t('testing.col.variant')}</label>
            <select id="round-variant" bind:value={variant} disabled={estimating || starting} onchange={invalidate}>
              {#each options?.variants ?? [] as v (v)}
                <option value={v}>{v}</option>
              {/each}
            </select>
          </div>
          <div class="dev-field">
            <label for="round-repeat">{t('testing.evalRun.repeat')}</label>
            <input id="round-repeat" type="number" min="1" max={options?.maxRepeat ?? 5} bind:value={repeat} disabled={estimating || starting} onchange={invalidate} />
          </div>
        </div>
        <div class="dev-btnrow">
          <Button label={t('testing.round.estimate')} variant="secondary" size="sm" loading={estimating} disabled={starting || !(options?.variants?.length)} onclick={runEstimate} />
          <span class="dev-small dev-muted">{t('testing.round.estimateNote')}</span>
        </div>
        {#if estimate}
          <div class="dev-card">
            <dl class="dev-kv">
              {#each estimate.rows ?? [] as row (row.dataset)}
                <dt><code>{row.dataset}</code></dt>
                <dd>{row.total === null || row.total === undefined ? t('testing.evalRun.unknown') : formatUsd(row.total)}{#if row.calls !== null && row.calls !== undefined} <span class="dev-muted dev-small">({row.calls} ×)</span>{/if}{#if row.model} <code class="dev-small">{row.model}</code>{/if}</dd>
              {/each}
              <dt>{t('testing.round.estimateTotal')}</dt><dd><strong>{formatUsd(estimate.total)}</strong></dd>
            </dl>
          </div>
          <div class="dev-field" class:has-error={!(capValue > 0)}>
            <label for="round-cap">{t('testing.round.cap')}</label>
            <input id="round-cap" type="number" min="0.01" step="0.01" bind:value={maxCost} disabled={starting} />
            {#if !(capValue > 0)}<div class="dev-field__error">{t('testing.round.capError')}</div>{/if}
            <div class="dev-field__hint">{t('testing.round.capHint')}</div>
          </div>
        {/if}
      {/if}
    {/if}

    {#if error}
      <div class="dev-alert dev-alert--danger">
        <span class="dev-alert__text">
          {error.text}
          {#if error.problems}
            <ul class="dev-blockers">{#each error.problems as p (p)}<li>{p}</li>{/each}</ul>
          {/if}
        </span>
      </div>
    {/if}
    <p class="dev-small dev-muted">{t('testing.round.cli')}</p>
  </div>

  {#snippet footer()}
    <Button label={t('common.close')} variant="secondary" size="sm" disabled={starting} onclick={() => onClose?.()} />
    <Button label={t('testing.round.run')} size="sm" loading={starting} disabled={!canStart} onclick={start} />
  {/snippet}
</DevDialog>

<style>
  .dev-checks { display: flex; flex-direction: column; gap: 0.25rem; }
  .dev-checks label { display: flex; align-items: baseline; gap: 0.375rem; }
  .dev-field__label { font-weight: 600; display: block; margin-bottom: 0.25rem; }
</style>
