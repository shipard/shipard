<script>
  /**
   * Dialog „Odpisy za období“ (docs/assets.md D33) — toolbar vieweru
   * Majetek (všechny karty) i akce detailu Odepsat (jedna karta, `assetId`).
   *
   * Tok: options → volba okruhu a období → preview (karty s částkou,
   * součet, vyloučené s důvodem) → Potvrdit → run → onDone(result). Náhled
   * se přenačítá při každé změně volby; potvrzení je idempotentní, takže
   * dvojklik nezaloží odpis dvakrát.
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import { depreciationRunOptions, depreciationRunPreview, depreciationRun } from '../../api/assets.js';
  import { formatAmount } from '../../utils/formatNumber.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let {
    open = false,
    /** Jen jedna karta (akce detailu Odepsat); null = všechny karty. */
    assetId = null,
    /** Po úspěšném provedení — volající přenačte viewer. */
    onDone = () => {},
    onClose = () => {},
  } = $props();

  let options = $state(null);
  let scope = $state('tax');
  let periodId = $state(null);
  let preview = $state(null);
  let loading = $state(false);
  let submitting = $state(false);
  let error = $state(null);

  const monthly = $derived(scope === 'acc' && options?.accPeriodicity === 'month');
  const periods = $derived(monthly ? (options?.months ?? []) : (options?.years ?? []));
  const canSubmit = $derived(!loading && !submitting && (preview?.assets?.length ?? 0) > 0);

  // Každé otevření začíná znovu: nabídka může mezitím dostat nový rok.
  $effect(() => {
    if (open) {
      preview = null;
      error = null;
      loadOptions();
    }
  });

  async function loadOptions() {
    loading = true;
    const res = await depreciationRunOptions();
    loading = false;
    if (!res?.success) {
      error = translateError(res?.error);
      return;
    }
    options = res.data;
    scope = res.data.defaults?.scope ?? 'tax';
    periodId = defaultPeriod();
    await loadPreview();
  }

  function defaultPeriod() {
    return monthly ? (options?.defaults?.monthId ?? null) : (options?.defaults?.yearId ?? null);
  }

  async function changeScope(next) {
    scope = next;
    periodId = defaultPeriod();
    await loadPreview();
  }

  async function changePeriod(event) {
    periodId = Number(event.target.value) || null;
    await loadPreview();
  }

  async function loadPreview() {
    if (periodId == null) {
      preview = null;
      return;
    }
    loading = true;
    error = null;
    const res = await depreciationRunPreview(scope, periodId, assetId);
    loading = false;
    if (!res?.success) {
      preview = null;
      error = translateError(res?.error);
      return;
    }
    preview = res.data;
  }

  async function submit() {
    if (!canSubmit) return;
    submitting = true;
    error = null;
    const res = await depreciationRun(scope, periodId, assetId);
    submitting = false;
    if (!res?.success) {
      error = translateError(res?.error);
      return;
    }
    onDone(res.data);
    onClose();
  }

  function reasonText(item) {
    const text = t(`assets.depreciationRun.reason.${item.reason}`);
    return item.detail ? `${text} (${item.detail})` : text;
  }
</script>

<Modal title={t('assets.depreciationRun.title')} {open} {onClose} width="720px">
  <div class="shpd-deprun">
    <div class="shpd-deprun__controls">
      <div class="shpd-deprun__scope" role="group" aria-label={t('assets.depreciationRun.scope')}>
        <Button
          label={t('assets.depreciationRun.scopeTax')}
          variant={scope === 'tax' ? 'primary' : 'secondary'}
          size="sm"
          disabled={loading || submitting}
          onclick={() => changeScope('tax')}
        />
        <Button
          label={t('assets.depreciationRun.scopeAcc')}
          variant={scope === 'acc' ? 'primary' : 'secondary'}
          size="sm"
          disabled={loading || submitting}
          onclick={() => changeScope('acc')}
        />
      </div>
      <label class="shpd-deprun__period">
        {monthly ? t('assets.depreciationRun.month') : t('assets.depreciationRun.year')}
        <select class="shpd-deprun__select" value={periodId ?? ''} onchange={changePeriod} disabled={loading || submitting}>
          {#each periods as p (p.id)}
            <option value={p.id}>{p.name}{p.locked ? ` — ${t('assets.depreciationRun.locked')}` : ''}</option>
          {/each}
        </select>
      </label>
    </div>

    {#if error}
      <p class="shpd-deprun__error">{error}</p>
    {/if}

    {#if loading && !preview}
      <p class="shpd-deprun__muted">{t('common.loading')}</p>
    {:else if preview}
      {#if preview.assets.length === 0}
        <p class="shpd-deprun__muted">{t('assets.depreciationRun.empty')}</p>
      {:else}
        <table class="shpd-deprun__table">
          <thead>
            <tr>
              <th>{t('assets.depreciationRun.colNumber')}</th>
              <th>{t('assets.depreciationRun.colName')}</th>
              <th>{t('assets.depreciationRun.colFormula')}</th>
              <th class="shpd-deprun__num">{t('assets.depreciationRun.colAmount')}</th>
            </tr>
          </thead>
          <tbody>
            {#each preview.assets as item (item.id)}
              <tr>
                <td>{item.number}</td>
                <td>
                  {item.name}
                  {#each item.messages as msg}
                    <div class="shpd-deprun__warning">{msg}</div>
                  {/each}
                </td>
                <td class="shpd-deprun__formula">{item.formula}</td>
                <td class="shpd-deprun__num">{formatAmount(item.amount)}</td>
              </tr>
            {/each}
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3">{t('assets.depreciationRun.total', { count: preview.assets.length })}</td>
              <td class="shpd-deprun__num">{formatAmount(preview.total)}</td>
            </tr>
          </tfoot>
        </table>
      {/if}

      {#if preview.excluded.length > 0}
        <h4 class="shpd-deprun__heading">{t('assets.depreciationRun.excluded')}</h4>
        <ul class="shpd-deprun__excluded">
          {#each preview.excluded as item (item.id)}
            <li><strong>{item.number}</strong> {item.name} — {reasonText(item)}</li>
          {/each}
        </ul>
      {/if}
    {/if}
  </div>

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" size="sm" disabled={submitting} onclick={onClose} />
    <Button
      label={submitting ? t('common.saving') : t('assets.depreciationRun.submit')}
      variant="primary"
      size="sm"
      disabled={!canSubmit}
      onclick={submit}
    />
  {/snippet}
</Modal>

<style>
  .shpd-deprun {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-deprun__controls {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--shpd-space-md);
  }

  .shpd-deprun__scope {
    display: flex;
    gap: var(--shpd-space-xs);
  }

  .shpd-deprun__period {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-deprun__select {
    padding: 6px;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    background: var(--shpd-color-surface);
    color: var(--shpd-color-text);
  }

  .shpd-deprun__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-deprun__table th,
  .shpd-deprun__table td {
    padding: 4px 8px;
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
    vertical-align: top;
  }

  .shpd-deprun__table tfoot td {
    font-weight: 600;
    border-top: 2px solid var(--shpd-color-border);
    border-bottom: none;
  }

  .shpd-deprun__num {
    text-align: right !important;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  .shpd-deprun__formula {
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }

  .shpd-deprun__warning {
    color: var(--shpd-color-state-warning-text, var(--shpd-color-text-secondary));
    font-size: var(--shpd-font-size-xs, 12px);
  }

  .shpd-deprun__heading {
    margin: var(--shpd-space-sm) 0 0;
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
  }

  .shpd-deprun__excluded {
    margin: 0;
    padding-left: 1.2em;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-deprun__muted {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-deprun__error {
    color: var(--shpd-color-state-error-text);
    font-size: var(--shpd-font-size-sm);
  }
</style>
