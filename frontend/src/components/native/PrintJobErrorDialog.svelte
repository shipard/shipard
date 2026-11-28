<script>
  /**
   * Dialog neúspěšného tisku na účtenkovou tiskárnu (#177 U40, U41):
   * důvod slovy a u chyb mostu a tiskárny tlačítko Zkusit znovu. Chyba
   * nastavení (zařízení nemá tiskárnu účtenek…) opakování nenabízí — text
   * říká, co nastavit.
   *
   * Opakování dělá dialog sám: po úspěchu zavolá `onSuccess` a zavře se,
   * po dalším neúspěchu ukáže novou chybu. Hostitel jen drží `failure`
   * (výsledek z `native/printJobs.js`, `null` = zavřeno).
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import { t } from '../../i18n/index.js';
  import { retryFailedPrintJob } from '../../api/printJobs.js';
  import { printJobErrorKey, printJobFailureTitleKey } from '../../native/printJobs.js';

  let {
    /** Neúspěšný výsledek tisku; `null` = dialog je zavřený. */
    failure = $bindable(null),
    /** Opakování se povedlo — dostane výsledek `{ok: true, jobId, warnings}` a původní požadavek. */
    onSuccess = null,
  } = $props();

  let busy = $state(false);

  function close() {
    if (busy) return;
    failure = null;
  }

  async function retry() {
    if (busy || failure === null) return;
    const request = failure.request;
    busy = true;
    try {
      const result = await retryFailedPrintJob(failure);
      if (result.ok) {
        failure = null;
        onSuccess?.(result, request);
      } else {
        failure = result;
      }
    } finally {
      busy = false;
    }
  }
</script>

<Modal
  title={failure ? t(printJobFailureTitleKey(failure)) : ''}
  open={failure !== null}
  onClose={close}
  width="30rem"
  fixedSize
  testid="print-job-error"
>
  {#if failure}
    <p class="shpd-print-job-error__message" data-testid="print-job-error-message">{t(printJobErrorKey(failure))}</p>
    <p class="shpd-print-job-error__code" data-testid="print-job-error-code">
      {t('native.print.failed.code', { code: failure.code })}
    </p>
  {/if}

  {#snippet footer()}
    <Button
      label={t('common.close')}
      variant="secondary"
      disabled={busy}
      onclick={close}
      testid="print-job-error-close"
    />
    {#if failure?.retryable}
      <Button
        label={t('native.print.retry')}
        variant="primary"
        loading={busy}
        disabled={busy}
        onclick={retry}
        testid="print-job-error-retry"
      />
    {/if}
  {/snippet}
</Modal>

<style>
  .shpd-print-job-error__message {
    margin: 0;
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text);
    line-height: 1.5;
  }

  .shpd-print-job-error__code {
    margin: var(--shpd-space-sm) 0 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }
</style>
