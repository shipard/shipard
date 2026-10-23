<script>
  /**
   * Chip přílohy na mail kartě feedu — vždy ikona dle typu souboru (bez mini
   * náhledu), klik otevře přílohu v nové záložce (PDF/obrázky inline, ostatní
   * download; whitelist zrcadlí AttachmentController::computeDisposition přes
   * vzor AttachmentGrid). Hover po krátké prodlevě ukáže plovoucí náhled
   * (jen PDF/obrázky, jen zařízení s hoverem — na mobilu tap rovnou otevírá).
   */
  import { onDestroy } from 'svelte';
  import { thumbnailUrl, downloadUrl, inlineUrl, formatFileSize } from '../../api/attachments.js';
  import Icon from '../ui/Icon.svelte';
  import { iconFile, iconFilePdf, iconFileImage } from '../../icons.js';

  let { att } = $props();

  const PREVIEW_DELAY_MS = 300;
  const PREVIEW_MARGIN = 8;
  /* Pod tuhle šířku nemá boční náhled smysl → fallback nad/pod chip. */
  const PREVIEW_MIN_WIDTH = 280;
  /* Musí ladit s max-height .shpd-feed-att__preview (90vh). */
  const PREVIEW_MAX_HEIGHT_VH = 0.9;

  // Mime → ikona pro přílohy bez náhledu (mirror AttachmentGrid.attachmentIcon).
  function attachmentIcon(mime) {
    if (mime === 'application/pdf') return iconFilePdf;
    if ((mime ?? '').startsWith('image/')) return iconFileImage;
    return iconFile;
  }

  // Co umí server vyrenderovat inline (mirror AttachmentGrid.isInlineRenderable).
  const inlineRenderable = $derived(
    att.mime_type === 'application/pdf' || (att.mime_type ?? '').startsWith('image/'),
  );

  const canHover = window.matchMedia('(hover: hover)').matches;

  let chipEl = $state(null);
  let previewOpen = $state(false);
  let previewStyle = $state('');
  let hoverTimer = null;

  function openPreview() {
    if (!chipEl) return;
    const rect = chipEl.getBoundingClientRect();
    const maxHeight = window.innerHeight * PREVIEW_MAX_HEIGHT_VH;

    // Cílová šířka ~3/4 max výšky — většina příloh je portrét (A4 faktury),
    // širší popup by kolem obrázku nechával prázdné pruhy.
    const desiredWidth = Math.round(maxHeight * 0.75);

    // Preferovaně vpravo vedle chipu; vlevo jen když tam je víc místa a
    // vpravo se plná šířka nevejde. Svisle centrovaný na chip s clampem,
    // aby popup (max 90vh) zůstal ve viewportu.
    const spaceRight = window.innerWidth - rect.right - 2 * PREVIEW_MARGIN;
    const spaceLeft = rect.left - 2 * PREVIEW_MARGIN;
    const side = spaceRight >= Math.min(desiredWidth, spaceLeft) ? 'right' : 'left';
    const width = Math.min(desiredWidth, side === 'right' ? spaceRight : spaceLeft);

    if (width >= PREVIEW_MIN_WIDTH) {
      const left = side === 'right'
        ? rect.right + PREVIEW_MARGIN
        : rect.left - PREVIEW_MARGIN - width;
      const halfMax = maxHeight / 2;
      const centerY = Math.min(
        Math.max(rect.top + rect.height / 2, PREVIEW_MARGIN + halfMax),
        window.innerHeight - PREVIEW_MARGIN - halfMax,
      );
      previewStyle = `width:${width}px; left:${left}px; top:${centerY}px; transform:translateY(-50%);`;
      previewOpen = true;
      return;
    }

    // Úzké okno bez místa po stranách → nad chipem (kotva přes bottom —
    // výška popupu je auto), při nedostatku místa nahoře pod ním.
    const fallbackWidth = Math.min(desiredWidth, window.innerWidth - 2 * PREVIEW_MARGIN);
    const left = Math.min(
      Math.max(rect.left + rect.width / 2 - fallbackWidth / 2, PREVIEW_MARGIN),
      window.innerWidth - fallbackWidth - PREVIEW_MARGIN,
    );
    const spaceAbove = rect.top;
    const spaceBelow = window.innerHeight - rect.bottom;
    const above = spaceAbove >= 320 || spaceAbove >= spaceBelow;
    previewStyle = above
      ? `width:${fallbackWidth}px; left:${left}px; bottom:${window.innerHeight - rect.top + PREVIEW_MARGIN}px;`
      : `width:${fallbackWidth}px; left:${left}px; top:${rect.bottom + PREVIEW_MARGIN}px;`;
    previewOpen = true;
  }

  // Prodleva brání blikání při přejíždění myší přes řadu chipů.
  function schedulePreview() {
    if (!canHover || !inlineRenderable) return;
    clearTimeout(hoverTimer);
    hoverTimer = setTimeout(openPreview, PREVIEW_DELAY_MS);
  }

  function showPreviewNow() {
    if (!canHover || !inlineRenderable) return;
    openPreview();
  }

  function closePreview() {
    clearTimeout(hoverTimer);
    hoverTimer = null;
    previewOpen = false;
  }

  onDestroy(closePreview);
</script>

<span class="shpd-feed-att">
  <a
    class="shpd-feed-att__chip"
    href={inlineRenderable ? inlineUrl(att.id) : downloadUrl(att.id)}
    target="_blank"
    rel="noopener"
    title={att.name}
    bind:this={chipEl}
    onmouseenter={schedulePreview}
    onmouseleave={closePreview}
    onclick={closePreview}
    onfocusin={showPreviewNow}
    onfocusout={closePreview}
  >
    <span class="shpd-feed-att__icon">
      <Icon icon={attachmentIcon(att.mime_type)} size="sm" />
    </span>
    <span class="shpd-feed-att__name">{att.name}</span>
  </a>
  {#if previewOpen}
    <div class="shpd-feed-att__preview" style={previewStyle}>
      <img src={thumbnailUrl(att.id, 1600)} alt={att.name} />
      <div class="shpd-feed-att__preview-caption">
        <span class="shpd-feed-att__preview-name">{att.name}</span>
        <span class="shpd-feed-att__preview-size">{formatFileSize(att.file_size)}</span>
      </div>
    </div>
  {/if}
</span>

<style>
  .shpd-feed-att {
    display: inline-flex;
    min-width: 0;
  }

  /* Výška srovnatelná s Button size="sm" (padding xs + font sm). */
  .shpd-feed-att__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    line-height: 1;
    text-decoration: none;
    min-width: 0;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  .shpd-feed-att__chip:hover {
    border-color: var(--shpd-color-primary);
    box-shadow: var(--shpd-shadow-md);
  }

  .shpd-feed-att__icon {
    color: var(--shpd-color-text-secondary);
    flex-shrink: 0;
    display: inline-flex;
  }

  .shpd-feed-att__name {
    max-width: 12em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Fixed — nesmí ho oříznout karta; čistě informační, bez interakce.
     Šířku počítá openPreview() (inline style, dle volného místa);
     max-height musí ladit s PREVIEW_MAX_HEIGHT_VH (clamp svislé pozice). */
  .shpd-feed-att__preview {
    position: fixed;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    max-height: 90vh;
    padding: var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg);
    box-shadow: var(--shpd-shadow-lg);
    pointer-events: none;
  }

  .shpd-feed-att__preview img {
    display: block;
    width: 100%;
    height: auto;
    min-height: 0;
    object-fit: contain;
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg-secondary);
  }

  .shpd-feed-att__preview-caption {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-xs);
    min-width: 0;
    flex-shrink: 0;
  }

  .shpd-feed-att__preview-name {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .shpd-feed-att__preview-size {
    font-size: var(--shpd-font-size-xs);
    color: var(--shpd-color-text-secondary);
    flex-shrink: 0;
  }
</style>
