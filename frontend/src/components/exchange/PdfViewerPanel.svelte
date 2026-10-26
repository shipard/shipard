<script>
  // Embed panel for the PDF / image attachment(s) of an extracted document.
  //
  // - 0 attachments  → empty state
  // - 1 attachment   → render directly, no tab switcher
  // - 2+ attachments → tab switcher across attachments
  //
  // PDF / image MIME types are rendered inline via the AttachmentController
  // ?inline=1 endpoint. Other types fall back to a download link (see
  // backend allowlist in AttachmentController::computeDisposition).

  import { t } from '../../i18n/index.js';
  import { attachmentUrl } from '../../api/exchange.js';

  let { attachments = [] } = $props();

  // null means "use first attachment". Once the user clicks a tab we
  // remember their pick; if `attachments` changes (e.g. modal reopened
  // for a different document), the picked ndx may not exist any more —
  // then `selected` falls back to attachments[0].
  let selectedNdx = $state(null);

  let selected = $derived(
    attachments.find((a) => a.ndx === selectedNdx) ?? attachments[0] ?? null,
  );

  let inlineUrl = $derived(selected ? attachmentUrl(selected.ndx, true) : null);
  let downloadUrl = $derived(selected ? attachmentUrl(selected.ndx, false) : null);

  function isPdf(mime) {
    return mime === 'application/pdf';
  }

  function isImage(mime) {
    return typeof mime === 'string' && mime.startsWith('image/');
  }
</script>

{#if attachments.length === 0}
  <div class="shpd-pdf-panel__empty">{t('exchange.preview.pdf.empty')}</div>
{:else}
  <div class="shpd-pdf-panel">
    {#if attachments.length > 1}
      <div class="shpd-pdf-panel__tabs" role="tablist">
        {#each attachments as att (att.ndx)}
          <button
            class="shpd-pdf-panel__tab"
            class:shpd-pdf-panel__tab--active={att.ndx === (selected?.ndx ?? null)}
            role="tab"
            aria-selected={att.ndx === (selected?.ndx ?? null)}
            onclick={() => (selectedNdx = att.ndx)}
          >
            {att.filename}
          </button>
        {/each}
      </div>
    {/if}

    {#if selected && isPdf(selected.mime_type)}
      <iframe
        class="shpd-pdf-panel__iframe"
        src={inlineUrl}
        title={selected.filename}
      ></iframe>
    {:else if selected && isImage(selected.mime_type)}
      <div class="shpd-pdf-panel__img-wrap">
        <img class="shpd-pdf-panel__img" src={inlineUrl} alt={selected.filename} />
      </div>
    {:else if selected}
      <div class="shpd-pdf-panel__unsupported">
        <p>{t('exchange.preview.pdf.unsupported')}</p>
        <p class="shpd-pdf-panel__filename">{selected.filename}</p>
        <a class="shpd-pdf-panel__download" href={downloadUrl} download>
          {t('exchange.preview.pdf.download')}
        </a>
      </div>
    {/if}
  </div>
{/if}

<style>
  .shpd-pdf-panel {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
  }

  .shpd-pdf-panel__empty,
  .shpd-pdf-panel__unsupported {
    padding: var(--shpd-space-lg);
    color: var(--shpd-color-text-secondary);
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    height: 100%;
  }

  .shpd-pdf-panel__filename {
    font-family: var(--shpd-font-mono, monospace);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-pdf-panel__download {
    display: inline-block;
    margin-top: var(--shpd-space-sm);
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    background: var(--shpd-color-primary);
    color: white;
    text-decoration: none;
    border-radius: var(--shpd-radius-sm);
  }

  .shpd-pdf-panel__download:hover {
    background: var(--shpd-color-primary-hover);
  }

  .shpd-pdf-panel__tabs {
    display: flex;
    gap: var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    padding: 0 var(--shpd-space-xs);
    overflow-x: auto;
    flex-shrink: 0;
  }

  .shpd-pdf-panel__tab {
    border: 0;
    background: transparent;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    cursor: pointer;
    color: var(--shpd-color-text-secondary);
    border-bottom: 2px solid transparent;
    margin-bottom: -0.0625rem;
    white-space: nowrap;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-pdf-panel__tab--active {
    color: var(--shpd-color-primary);
    border-bottom-color: var(--shpd-color-primary);
    font-weight: 500;
  }

  .shpd-pdf-panel__iframe {
    flex: 1;
    width: 100%;
    border: 0;
    min-height: 0;
  }

  .shpd-pdf-panel__img-wrap {
    flex: 1;
    overflow: auto;
    background: var(--shpd-color-bg-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--shpd-space-sm);
  }

  .shpd-pdf-panel__img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
  }
</style>
