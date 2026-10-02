<script>
  /**
   * Náhled tisku záznamu (docs/prints.md, #90 D19) — otevírá ho akce `print`
   * v detailu záznamu. PDF se stáhne jako Blob (Bearer auth) a ukáže
   * v <iframe> z object URL; Stáhnout ho uloží pod názvem, který určil
   * server. Měkká hlášení builderu (QR platba nevznikla) jsou nad náhledem.
   *
   * Prohlížeč bez vestavěného prohlížeče PDF (typicky mobilní) náhled
   * nedostane — jen Stáhnout. Object URL se uvolní při zavření.
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import { fetchPrintPdf } from '../../api/prints.js';
  import { saveBlob } from '../../utils/download.js';
  import { iconDownload } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let {
    open = false,
    printId = '',
    recordId = null,
    onClose = () => {},
  } = $props();

  let loading = $state(false);
  let error = $state(null);
  let pdfUrl = $state(null);
  let fileName = $state('');
  let messages = $state([]);

  // Blob a jeho object URL mimo reaktivitu — effect je jen zapisuje a uvolňuje.
  let blob = null;
  let objectUrl = null;
  // Guard proti out-of-order odpovědím: zavření nebo jiný tisk během
  // renderu starou odpověď zahodí.
  let fetchToken = 0;

  const canPreview = typeof navigator === 'undefined' || navigator.pdfViewerEnabled !== false;

  $effect(() => {
    if (open && printId && recordId !== null && recordId !== undefined) {
      void load(printId, recordId);
    } else {
      reset();
    }
    return reset;
  });

  function reset() {
    fetchToken++;
    if (objectUrl !== null) {
      URL.revokeObjectURL(objectUrl);
      objectUrl = null;
    }
    blob = null;
    pdfUrl = null;
    fileName = '';
    messages = [];
    error = null;
    loading = false;
  }

  async function load(id, record) {
    const token = ++fetchToken;
    loading = true;
    error = null;

    const result = await fetchPrintPdf(id, record);
    if (token !== fetchToken) return;

    loading = false;
    if (result === null) return; // 401 — klient už přesměrovává na přihlášení
    if (!result.success) {
      error = translateError(result.error);
      return;
    }

    blob = result.blob;
    objectUrl = URL.createObjectURL(result.blob);
    pdfUrl = objectUrl;
    fileName = result.fileName;
    messages = result.messages;
  }

  function download() {
    if (blob !== null) saveBlob(blob, fileName);
  }
</script>

<Modal title={t('print.preview.title')} {open} {onClose} width="960px" height="90vh" testid="print-preview">
  <div class="shpd-print">
    {#if loading}
      <div class="shpd-print__state">
        <span class="shpd-print__spinner"></span>
        <span>{t('print.preview.loading')}</span>
      </div>
    {:else if error}
      <div class="shpd-print__state shpd-print__state--error" role="alert">{error}</div>
    {:else if pdfUrl}
      {#if messages.length > 0}
        <ul class="shpd-print__messages">
          {#each messages as message (message.code)}
            <li>{message.text}</li>
          {/each}
        </ul>
      {/if}
      {#if canPreview}
        <iframe class="shpd-print__frame" src={pdfUrl} title={fileName}></iframe>
      {:else}
        <div class="shpd-print__state">{t('print.preview.unsupported')}</div>
      {/if}
    {/if}
  </div>

  {#snippet footer()}
    <Button label={t('common.close')} variant="secondary" size="sm" onclick={onClose} />
    <Button
      label={t('print.download')}
      icon={iconDownload}
      variant="primary"
      size="sm"
      disabled={pdfUrl === null}
      onclick={download}
    />
  {/snippet}
</Modal>

<style>
  .shpd-print {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
    height: 100%;
    min-height: 0;
  }

  .shpd-print__frame {
    flex: 1;
    width: 100%;
    min-height: 0;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg-secondary);
  }

  .shpd-print__state {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-lg);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-print__state--error {
    color: var(--shpd-color-danger);
  }

  .shpd-print__messages {
    margin: 0;
    padding: var(--shpd-space-sm) var(--shpd-space-md) var(--shpd-space-sm) calc(var(--shpd-space-md) + 1.2em);
    border: 1px solid var(--shpd-color-warning);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-warning-soft);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-print__spinner {
    width: 16px;
    height: 16px;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-print-spin 0.8s linear infinite;
  }

  @keyframes shpd-print-spin {
    to { transform: rotate(360deg); }
  }
</style>
