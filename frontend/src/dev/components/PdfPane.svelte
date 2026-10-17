<script>
  // PDF zprávy v prohlížeči (vestavěný prohlížeč PDF přes <iframe>, jako
  // AttachmentGrid v aplikaci). Bez prohlížeče PDF (navigator.pdfViewerEnabled
  // false, headless) jen odkaz.
  import { t } from '../i18n/index.js';

  let { src = null, title = t('testing.pdf.title') } = $props();
  const canEmbed = typeof navigator === 'undefined' || navigator.pdfViewerEnabled !== false;
</script>

<div class="pane">
  {#if !src}
    <div class="dev-empty">{t('testing.pdf.none')}</div>
  {:else}
    <div class="bar dev-small">
      <span class="dev-muted">{title}</span>
      <a href={src} target="_blank" rel="noopener">{t('testing.pdf.openWindow')}</a>
    </div>
    {#if canEmbed}
      <iframe class="pdf" {src} {title}></iframe>
    {:else}
      <div class="dev-empty">{t('testing.pdf.noViewer')}</div>
    {/if}
  {/if}
</div>

<style>
  /* Lepí se pod lepicí pruh + lištu (#129 D7); jejich výšku dává DevApp do --dev-top-height. */
  .pane { position: sticky; top: calc(var(--dev-top-height, 84px) + var(--shpd-space-md)); }
  .bar { display: flex; justify-content: space-between; margin-bottom: var(--shpd-space-xs); }
  .pdf {
    width: 100%;
    height: calc(100vh - var(--dev-top-height, 84px) - 110px);
    min-height: 560px;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: #525659;
  }
</style>
