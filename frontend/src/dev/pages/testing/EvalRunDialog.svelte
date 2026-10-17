<script>
  // Dialog spuštění evaluace (F6b D54, D61): varianta, opakování, --only,
  // odhad (`ai-eval --estimate` synchronně), strop útraty předvyplněný
  // 1,5× odhad (povinný, server bez něj úlohu nespustí), potvrzení → úloha.
  import { onMount } from 'svelte';
  import DevDialog from '../../components/DevDialog.svelte';
  import Button from '../../../components/ui/Button.svelte';
  import { testingApi } from '../../testing/api.js';
  import { ApiError } from '../../api.js';
  import { suggestedCap } from '../../testing/jobs.js';
  import { formatUsd } from '../../format.js';
  import { t, label } from '../../i18n/index.js';

  let { open = false, set, dataSource, onClose, onStarted } = $props();

  let variants = $state([]);
  let form = $state({ variant: 'baseline', repeat: 1, only: '' });
  let estimate = $state(null);
  let estimating = $state(false);
  let starting = $state(false);
  let maxCost = $state('');
  let error = $state(null); // {text, problems?}

  onMount(async () => {
    try {
      const d = await testingApi.variants();
      variants = d.variants.filter((v) => v.valid);
      if (!variants.some((v) => v.name === form.variant) && variants.length) form.variant = variants[0].name;
    } catch (e) {
      error = { text: t('testing.evalRun.variantsError', { message: e.message }) };
    }
  });

  function body() {
    return {
      dataset: set,
      dataSource: dataSource.id,
      variant: form.variant,
      repeat: Number(form.repeat) || 1,
      only: form.only.trim(),
    };
  }

  async function runEstimate() {
    estimating = true;
    error = null;
    estimate = null;
    try {
      estimate = await testingApi.estimate(body());
      const cap = suggestedCap(estimate.estimate?.total);
      maxCost = cap === null ? '' : String(cap);
    } catch (e) {
      error = e instanceof ApiError
        ? { text: `${e.message} (${e.code})`, problems: e.details?.problems ?? null }
        : { text: t('common.networkError', { message: e.message }) };
    } finally {
      estimating = false;
    }
  }

  const capValue = $derived(Number(String(maxCost).replace(',', '.')));
  const canStart = $derived(!starting && !estimating && estimate !== null && capValue > 0);

  async function start() {
    if (!canStart) return;
    starting = true;
    error = null;
    try {
      const { job } = await testingApi.startEval({ ...body(), maxCostUsd: capValue });
      onStarted?.(job);
    } catch (e) {
      error = e instanceof ApiError ? { text: `${e.message} (${e.code})`, problems: e.details?.problems ?? null } : { text: t('common.networkError', { message: e.message }) };
    } finally {
      starting = false;
    }
  }
</script>

<DevDialog title={t('testing.dsPanel.runEval')} {open} onClose={() => onClose?.()} width="640px">
  <div class="dev-form">
    <dl class="dev-kv dev-small">
      <dt>{t('common.dataset')}</dt><dd><code>{set}</code></dd>
      <dt>{t('common.dataSource')}</dt><dd>{dataSource.name} <code>{dataSource.id}</code></dd>
    </dl>

    <div class="dev-form__row">
      <div class="dev-field">
        <label for="eval-variant">{t('testing.col.variant')}</label>
        <select id="eval-variant" bind:value={form.variant} disabled={estimating || starting} onchange={() => (estimate = null)}>
          {#each variants as v (v.name)}
            <option value={v.name}>{v.name}{v.description ? ` — ${v.description}` : ''}</option>
          {/each}
        </select>
      </div>
      <div class="dev-field">
        <label for="eval-repeat">{t('testing.evalRun.repeat')}</label>
        <input id="eval-repeat" type="number" min="1" max="5" bind:value={form.repeat} disabled={estimating || starting} onchange={() => (estimate = null)} />
      </div>
    </div>
    <div class="dev-field">
      <label for="eval-only">{t('testing.evalRun.only')} <span class="dev-muted">{t('testing.evalRun.onlyHint')}</span></label>
      <input id="eval-only" type="text" bind:value={form.only} placeholder={t('testing.evalRun.onlyPlaceholder')} disabled={estimating || starting} onchange={() => (estimate = null)} />
    </div>

    <div class="dev-btnrow">
      <Button label={t('testing.evalRun.estimate')} variant="secondary" size="sm" loading={estimating} disabled={starting || variants.length === 0} onclick={runEstimate} />
      <span class="dev-small dev-muted">{t('testing.evalRun.estimateNote')}</span>
    </div>

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

    {#if estimate}
      <div class="dev-card">
        <dl class="dev-kv">
          <dt>{t('metrics.metric.calls.total')}</dt><dd>{estimate.calls} {t('testing.evalRun.callsDetail', { messages: estimate.set?.messages, repeat: estimate.repeat })}</dd>
          <dt>{t('testing.variants.col.extractionModel')}</dt><dd><code>{estimate.model}</code> {t('testing.evalRun.connection', { connection: estimate.connection, origin: estimate.connectionOrigin ?? '?' })}</dd>
          <dt>{t('testing.variants.col.tagsModel')}</dt><dd><code>{estimate.tagsModel ?? '—'}</code></dd>
          <dt>{t('testing.evalRun.estimatedCost')}</dt>
          <dd>
            {#if estimate.estimate?.total !== null && estimate.estimate?.total !== undefined}
              <strong>{formatUsd(estimate.estimate.total)}</strong> {t('testing.evalRun.perCall', { cost: formatUsd(estimate.estimate.perCall) })}
            {:else}
              <strong>{t('testing.evalRun.unknown')}</strong>
            {/if}
            <span class="dev-muted dev-small"> — {label('testing.evalRun.basis', estimate.estimate?.basis)}{estimate.estimate?.source?.file ? ` (${estimate.estimate.source.file})` : ''}</span>
          </dd>
        </dl>
      </div>
      <div class="dev-field" class:has-error={!(capValue > 0)}>
        <label for="eval-cap">{t('testing.evalRun.cap')}</label>
        <input id="eval-cap" type="number" min="0.01" step="0.01" bind:value={maxCost} disabled={starting} />
        {#if !(capValue > 0)}<div class="dev-field__error">{t('testing.evalRun.capError')}</div>{/if}
        <div class="dev-field__hint">{t('testing.evalRun.capHint', { set })}</div>
      </div>
    {/if}
  </div>

  {#snippet footer()}
    <Button label={t('common.close')} variant="secondary" size="sm" disabled={starting} onclick={() => onClose?.()} />
    <Button label={t('testing.dsPanel.runEval')} size="sm" loading={starting} disabled={!canStart} onclick={start} />
  {/snippet}
</DevDialog>
