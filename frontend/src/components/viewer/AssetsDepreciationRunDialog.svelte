<script>
  /**
   * Dialog „Odpisy za období“ (docs/assets.md D33) — toolbar vieweru
   * Majetek (všechny karty) i akce detailu Odepsat (jedna karta, `assetId`).
   *
   * Dva režimy podle okruhu:
   *  - **odpisy** (daňový okruh; účetní okruh jedné karty): options →
   *    preview (karty s částkou, součet, vyloučené s důvodem) → Potvrdit →
   *    run → onDone(result);
   *  - **zaúčtování** (účetní okruh nad všemi kartami, D50–D55): náhled
   *    ve dvou částech (nové odpisy, události k zaúčtování) + souhrn účtů
   *    MD/DAL → Zaúčtovat → číslo dokladu s odkazem. U posledního
   *    zaúčtovaného období navíc „Zrušit zaúčtování období“.
   *
   * Náhled se přenačítá při každé změně volby; potvrzení i zaúčtování jsou
   * idempotentní, takže dvojklik nezaloží odpis ani doklad dvakrát.
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import ConfirmDialog from '../ui/ConfirmDialog.svelte';
  import ViewerDetailModal from './ViewerDetailModal.svelte';
  import {
    depreciationRunOptions, depreciationRunPreview, depreciationRun,
    postingPreview, postingRun, postingCancel,
  } from '../../api/assets.js';
  import { formatAmount } from '../../utils/formatNumber.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  /** Viewer, ve kterém se otevírá detail účetního dokladu majetku. */
  const DOC_VIEWER_ID = 'docs.core.heads';

  let {
    open = false,
    /** Jen jedna karta (akce detailu Odepsat); null = všechny karty. */
    assetId = null,
    /** Po úspěšném potvrzení odpisů — volající ohlásí výsledek a přenačte viewer. */
    onDone = () => {},
    /** Po zaúčtování / zrušení zaúčtování — volající jen přenačte viewer. */
    onChanged = () => {},
    onClose = () => {},
  } = $props();

  let options = $state(null);
  let scope = $state('tax');
  let periodId = $state(null);
  let preview = $state(null);
  let loading = $state(false);
  let submitting = $state(false);
  let error = $state(null);

  // Zaúčtování: výsledek posledního běhu / zrušení, detail dokladu, potvrzení zrušení.
  let postResult = $state(null);
  let notice = $state(null);
  let docOpenId = $state(null);
  let cancelConfirmOpen = $state(false);

  /** Účetní okruh nad všemi kartami = odpisy + zaúčtování. */
  const posting = $derived(scope === 'acc' && assetId == null);
  const monthly = $derived(scope === 'acc' && options?.accPeriodicity === 'month');
  const periods = $derived(monthly ? (options?.months ?? []) : (options?.years ?? []));
  const canSubmit = $derived(
    !loading && !submitting && postResult == null
      && (posting ? preview?.canPost === true : (preview?.assets?.length ?? 0) > 0),
  );
  /** Zrušit jde jen poslední zaúčtované období (D53). */
  const lastPosting = $derived(options?.lastPosting ?? null);
  const canCancelPosting = $derived(
    posting && postResult == null && lastPosting != null && lastPosting.periodId === periodId,
  );
  const lastPostingNumbers = $derived((lastPosting?.docs ?? []).map(d => d.number).join(', '));

  // Každé otevření začíná znovu: nabídka může mezitím dostat nový rok.
  $effect(() => {
    if (open) {
      preview = null;
      error = null;
      postResult = null;
      notice = null;
      loadOptions(true);
    }
  });

  async function loadOptions(reset = false) {
    loading = true;
    const res = await depreciationRunOptions();
    loading = false;
    if (!res?.success) {
      error = translateError(res?.error);
      return;
    }
    options = res.data;
    if (reset) {
      scope = res.data.defaults?.scope ?? 'tax';
      periodId = defaultPeriod();
      await loadPreview();
    }
  }

  function defaultPeriod() {
    return monthly ? (options?.defaults?.monthId ?? null) : (options?.defaults?.yearId ?? null);
  }

  async function changeScope(next) {
    scope = next;
    periodId = defaultPeriod();
    postResult = null;
    notice = null;
    await loadPreview();
  }

  async function changePeriod(event) {
    periodId = Number(event.target.value) || null;
    postResult = null;
    notice = null;
    await loadPreview();
  }

  async function loadPreview() {
    if (periodId == null) {
      preview = null;
      return;
    }
    loading = true;
    error = null;
    const res = posting
      ? await postingPreview(periodId)
      : await depreciationRunPreview(scope, periodId, assetId);
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
    if (posting) {
      const res = await postingRun(periodId);
      submitting = false;
      if (!res?.success) {
        error = translateError(res?.error);
        return;
      }
      postResult = res.data;
      notice = null;
      onChanged();
      await loadOptions();
      return;
    }
    const res = await depreciationRun(scope, periodId, assetId);
    submitting = false;
    if (!res?.success) {
      error = translateError(res?.error);
      return;
    }
    onDone(res.data);
    onClose();
  }

  async function confirmCancelPosting() {
    submitting = true;
    error = null;
    const res = await postingCancel(periodId);
    submitting = false;
    cancelConfirmOpen = false;
    if (!res?.success) {
      error = translateError(res?.error);
      return;
    }
    notice = t('assets.posting.cancelled', { numbers: (res.data?.docNumbers ?? []).join(', ') });
    onChanged();
    await loadOptions();
    await loadPreview();
  }

  function reasonText(item) {
    const text = t(`assets.depreciationRun.reason.${item.reason}`);
    return item.detail ? `${text} (${item.detail})` : text;
  }

  /** ISO datum → `15. 3. 2026`. */
  function formatDate(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso ?? '');
    return m ? `${Number(m[3])}. ${Number(m[2])}. ${m[1]}` : (iso ?? '');
  }
</script>

<Modal title={posting ? t('assets.posting.title') : t('assets.depreciationRun.title')} {open} {onClose} width="760px">
  <div class="shpd-deprun" data-testid="assets-depreciation-run">
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
          label={assetId == null ? t('assets.depreciationRun.scopeAccPosting') : t('assets.depreciationRun.scopeAcc')}
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

    {#if scope === 'acc' && assetId != null}
      <p class="shpd-deprun__muted">{t('assets.depreciationRun.accSingleHint')}</p>
    {/if}

    {#if error}
      <p class="shpd-deprun__error">{error}</p>
    {/if}
    {#if notice}
      <p class="shpd-deprun__notice">{notice}</p>
    {/if}

    {#if postResult}
      <!-- Výsledek zaúčtování: číslo dokladu s odkazem na detail. -->
      <div class="shpd-deprun__result" data-testid="assets-posting-result">
        {#if postResult.posted}
          <p><strong>{t('assets.posting.posted', { number: postResult.docNumber })}</strong></p>
          <p class="shpd-deprun__muted">
            {t('assets.posting.postedDetail', {
              rows: postResult.rowCount,
              count: postResult.depreciationCount,
              total: formatAmount(postResult.depreciationTotal),
            })}
          </p>
          <div>
            <Button
              label={t('assets.posting.openDocument')}
              variant="secondary"
              size="sm"
              onclick={() => { docOpenId = postResult.docId; }}
            />
          </div>
        {:else}
          <p>{t('assets.posting.nothingPosted')}</p>
        {/if}
      </div>
    {:else if loading && !preview}
      <p class="shpd-deprun__muted">{t('common.loading')}</p>
    {:else if preview && posting}
      {#each preview.blockers as blocker (blocker.code)}
        <p class="shpd-deprun__error">{blocker.message}</p>
      {/each}
      {#if preview.series}
        <p class="shpd-deprun__muted">{t('assets.posting.series', { name: preview.series.name })}</p>
      {/if}

      {#if preview.rowCount === 0}
        <p class="shpd-deprun__muted">{t('assets.posting.empty')}</p>
      {:else}
        <h4 class="shpd-deprun__heading">{t('assets.posting.depreciations')}</h4>
        {#if preview.depreciations.length === 0}
          <p class="shpd-deprun__muted">{t('assets.posting.noDepreciations')}</p>
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
              {#each preview.depreciations as item (item.id)}
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
                <td colspan="3">{t('assets.depreciationRun.total', { count: preview.depreciations.length })}</td>
                <td class="shpd-deprun__num">{formatAmount(preview.depreciationTotal)}</td>
              </tr>
            </tfoot>
          </table>
        {/if}

        {#if preview.events.length > 0}
          <h4 class="shpd-deprun__heading">{t('assets.posting.events')}</h4>
          <table class="shpd-deprun__table">
            <thead>
              <tr>
                <th>{t('assets.depreciationRun.colNumber')}</th>
                <th>{t('assets.depreciationRun.colName')}</th>
                <th>{t('assets.posting.colKind')}</th>
                <th>{t('assets.posting.colDate')}</th>
                <th class="shpd-deprun__num">{t('assets.depreciationRun.colAmount')}</th>
              </tr>
            </thead>
            <tbody>
              {#each preview.events as item, i (i)}
                <tr>
                  <td>{item.number}</td>
                  <td>{item.name}</td>
                  <td>{t(`assets.posting.kind.${item.kind}`)}</td>
                  <td>{formatDate(item.date)}</td>
                  <td class="shpd-deprun__num">{item.kind === 'disposal' ? '' : formatAmount(item.amount)}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        {/if}

        <h4 class="shpd-deprun__heading">{t('assets.posting.accounts')}</h4>
        <table class="shpd-deprun__table">
          <thead>
            <tr>
              <th>{t('assets.posting.colAccount')}</th>
              <th class="shpd-deprun__num">{t('assets.posting.colDebit')}</th>
              <th class="shpd-deprun__num">{t('assets.posting.colCredit')}</th>
            </tr>
          </thead>
          <tbody>
            {#each preview.accounts as account (account.account)}
              <tr>
                <td>{account.number} {account.name}</td>
                <td class="shpd-deprun__num">{account.debit ? formatAmount(account.debit) : ''}</td>
                <td class="shpd-deprun__num">{account.credit ? formatAmount(account.credit) : ''}</td>
              </tr>
            {/each}
          </tbody>
          <tfoot>
            <tr>
              <td>{t('assets.posting.accountsTotal')}</td>
              <td class="shpd-deprun__num">{formatAmount(preview.totalDebit)}</td>
              <td class="shpd-deprun__num">{formatAmount(preview.totalCredit)}</td>
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

    {#if canCancelPosting}
      <!-- Poslední zaúčtované období jde zrušit (storno dokladu + odpojení událostí). -->
      <div class="shpd-deprun__last">
        <span class="shpd-deprun__muted">
          {t('assets.posting.lastPosting', { period: lastPosting.periodName, numbers: lastPostingNumbers })}
        </span>
        <Button
          label={t('assets.posting.cancel')}
          variant="danger"
          size="sm"
          disabled={loading || submitting}
          onclick={() => { cancelConfirmOpen = true; }}
        />
      </div>
    {/if}
  </div>

  {#snippet footer()}
    <Button
      label={postResult ? t('common.close') : t('common.cancel')}
      variant="secondary"
      size="sm"
      disabled={submitting}
      onclick={onClose}
    />
    {#if !postResult}
      <Button
        label={submitting ? t('common.saving') : (posting ? t('assets.posting.submit') : t('assets.depreciationRun.submit'))}
        variant="primary"
        size="sm"
        disabled={!canSubmit}
        onclick={submit}
      />
    {/if}
  {/snippet}
</Modal>

<ConfirmDialog
  open={cancelConfirmOpen}
  title={t('assets.posting.cancelTitle', { period: lastPosting?.periodName ?? '' })}
  message={t('assets.posting.cancelMessage', { numbers: lastPostingNumbers })}
  confirmLabel={t('assets.posting.cancel')}
  variant="danger"
  busy={submitting}
  onConfirm={confirmCancelPosting}
  onCancel={() => { cancelConfirmOpen = false; }}
  testid="assets-posting-cancel-confirm"
/>

<!-- Detail účetního dokladu majetku (read-only, zámek „spravuje Majetek“). -->
<ViewerDetailModal
  open={docOpenId != null}
  viewerId={DOC_VIEWER_ID}
  recordId={docOpenId}
  onClose={() => { docOpenId = null; }}
/>

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

  .shpd-deprun__notice {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-deprun__result {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-deprun__result p {
    margin: 0;
  }

  .shpd-deprun__last {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
    padding-top: var(--shpd-space-sm);
    border-top: 1px solid var(--shpd-color-border);
  }
</style>
