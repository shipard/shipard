<script>
  // PDF zprávy v prohlížeči (vestavěný prohlížeč PDF přes <iframe>, jako
  // AttachmentGrid v aplikaci). Bez prohlížeče PDF (navigator.pdfViewerEnabled
  // false, headless) jen odkaz.
  let { src = null, title = 'PDF zprávy' } = $props();
  const canEmbed = typeof navigator === 'undefined' || navigator.pdfViewerEnabled !== false;
</script>

<div class="pane">
  {#if !src}
    <div class="dev-empty">Zpráva nemá přílohu PDF.</div>
  {:else}
    <div class="bar dev-small">
      <span class="dev-muted">{title}</span>
      <a href={src} target="_blank" rel="noopener">otevřít v novém okně</a>
    </div>
    {#if canEmbed}
      <iframe class="pdf" {src} {title}></iframe>
    {:else}
      <div class="dev-empty">Prohlížeč neumí zobrazit PDF v rámu — použij odkaz výše.</div>
    {/if}
  {/if}
</div>

<style>
  .pane { position: sticky; top: var(--shpd-space-md); }
  .bar { display: flex; justify-content: space-between; margin-bottom: var(--shpd-space-xs); }
  .pdf {
    width: 100%;
    height: calc(100vh - 170px);
    min-height: 560px;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: #525659;
  }
</style>
