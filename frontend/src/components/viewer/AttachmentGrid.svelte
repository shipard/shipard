<script>
  // Read-only zobrazení příloh ve dvou režimech:
  //
  //  - mode="grid" (default) — grid karet s thumbnail/ikonou, název,
  //    velikost. Extrakce z ViewerDetail (content type `attachments`) —
  //    sdílí ji detail došlé pošty a DocumentDetail (detail dokladu).
  //
  //  - mode="full" — velké náhledy přes celou šířku: PDF v <iframe> přes
  //    /download?inline=1 (nativní PDF viewer prohlížeče, všechny stránky),
  //    obrázky jako <img>. Ostatní MIME typy inline renderovat nejde
  //    (server je z bezpečnostních důvodů nepustí —
  //    AttachmentController::computeDisposition) a zůstávají jako karta.
  //
  //    Pozn.: iOS Safari vykreslí PDF v <iframe> jen jako první stránku
  //    (limitace WebKitu) — odkaz v popisku otevře plný viewer v nové
  //    záložce. Proto DocumentDetail na mobilu defaultuje na "grid".
  import { thumbnailUrl, downloadUrl, inlineUrl, formatFileSize } from '../../api/attachments.js';
  import Icon from '../ui/Icon.svelte';
  import { iconFile, iconFilePdf, iconFileImage } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  let { attachments = [], mode = 'grid' } = $props();

  // Mime → ikona pro přílohy bez náhledu (mirror AttachmentPanel.fileIcon).
  function attachmentIcon(mime) {
    if (mime === 'application/pdf') return iconFilePdf;
    if ((mime ?? '').startsWith('image/')) return iconFileImage;
    return iconFile;
  }

  function attachmentHasThumbnail(mime) {
    return (mime ?? '').startsWith('image/') || mime === 'application/pdf';
  }

  function isPdf(mime) {
    return mime === 'application/pdf';
  }

  function isImage(mime) {
    return (mime ?? '').startsWith('image/');
  }

  // Co umí server vyrenderovat inline (whitelist v computeDisposition()).
  function isInlineRenderable(mime) {
    return isPdf(mime) || isImage(mime);
  }
</script>

{#snippet card(att)}
  <a
    class="shpd-attgrid__card"
    href={downloadUrl(att.id)}
    target="_blank"
    rel="noopener"
    title={att.name}
  >
    <div class="shpd-attgrid__thumb">
      {#if attachmentHasThumbnail(att.mime_type)}
        <img
          src={thumbnailUrl(att.id, 200)}
          alt={att.name}
          class="shpd-attgrid__thumb-img"
          loading="lazy"
        />
      {:else}
        <div class="shpd-attgrid__thumb-icon">
          <Icon icon={attachmentIcon(att.mime_type)} size="xl" />
        </div>
      {/if}
    </div>
    <div class="shpd-attgrid__info">
      <span class="shpd-attgrid__name">{att.name}</span>
      <span class="shpd-attgrid__size">
        {formatFileSize(att.file_size)}
        {#if att.generated}
          <!-- Příloha vygenerovaná předzpracováním zprávy (provenance metadata). -->
          <span class="shpd-attgrid__badge">{t('attachments.generated')}</span>
        {/if}
        {#if att.send_with_record}
          <!-- Příloha se posílá spolu s dokladem (#94). -->
          <span class="shpd-attgrid__badge">{t('attachments.sendWith')}</span>
        {/if}
      </span>
    </div>
  </a>
{/snippet}

{#if mode === 'full'}
  <div class="shpd-attgrid shpd-attgrid--full">
    {#each attachments as att (att.id)}
      {#if isInlineRenderable(att.mime_type)}
        <figure class="shpd-attgrid__full-item">
          <figcaption class="shpd-attgrid__full-caption">
            <a
              class="shpd-attgrid__full-name"
              href={inlineUrl(att.id)}
              target="_blank"
              rel="noopener"
            >{att.name}</a>
            <span class="shpd-attgrid__size">{formatFileSize(att.file_size)}</span>
            {#if att.generated}
              <span class="shpd-attgrid__badge">{t('attachments.generated')}</span>
            {/if}
            {#if att.send_with_record}
              <span class="shpd-attgrid__badge">{t('attachments.sendWith')}</span>
            {/if}
          </figcaption>
          {#if isPdf(att.mime_type)}
            <iframe
              class="shpd-attgrid__full-pdf"
              src={inlineUrl(att.id)}
              title={att.name}
              loading="lazy"
            ></iframe>
          {:else}
            <img
              class="shpd-attgrid__full-img"
              src={inlineUrl(att.id)}
              alt={att.name}
              loading="lazy"
            />
          {/if}
        </figure>
      {:else}
        <!-- Inline nerenderovatelné typy zůstávají jako karta. -->
        <div class="shpd-attgrid__full-card">
          {@render card(att)}
        </div>
      {/if}
    {/each}
  </div>
{:else}
  <div class="shpd-attgrid">
    {#each attachments as att (att.id)}
      {@render card(att)}
    {/each}
  </div>
{/if}

<style>
  .shpd-attgrid__badge {
    display: inline-block;
    margin-left: var(--shpd-space-xs);
    padding: 0 6px;
    border-radius: var(--shpd-radius-sm);
    font-size: 0.75em;
    line-height: 1.5;
    background: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
    vertical-align: middle;
  }

  .shpd-attgrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: var(--shpd-space-md);
    margin-top: var(--shpd-space-sm);
  }

  .shpd-attgrid__card {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    overflow: hidden;
    background: var(--shpd-color-bg);
    text-decoration: none;
    color: inherit;
    transition: box-shadow 0.15s;
  }

  .shpd-attgrid__card:hover {
    box-shadow: var(--shpd-shadow-md);
  }

  .shpd-attgrid__thumb {
    aspect-ratio: 4 / 3;
    background: var(--shpd-color-bg-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  .shpd-attgrid__thumb-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .shpd-attgrid__thumb-icon {
    color: var(--shpd-color-text-secondary);
    font-size: 2rem;
  }

  .shpd-attgrid__info {
    padding: var(--shpd-space-sm);
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .shpd-attgrid__name {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .shpd-attgrid__size {
    font-size: 0.75rem;
    color: var(--shpd-color-text-secondary);
  }

  /* ── Režim "full" — velké náhledy ───────────────────────────────────── */

  .shpd-attgrid--full {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-attgrid__full-item {
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-attgrid__full-caption {
    display: flex;
    align-items: baseline;
    gap: var(--shpd-space-sm);
    min-width: 0;
  }

  .shpd-attgrid__full-name {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    text-decoration: none;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-attgrid__full-name:hover {
    color: var(--shpd-color-primary);
    text-decoration: underline;
  }

  .shpd-attgrid__full-pdf {
    width: 100%;
    height: 70vh;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg-secondary);
  }

  @media (max-width: 768px) {
    /* Breakpoint 768px ladí s MOBILE_BREAKPOINT v layout.svelte.js. */
    .shpd-attgrid__full-pdf {
      height: 50vh;
    }
  }

  .shpd-attgrid__full-img {
    display: block;
    max-width: 100%;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
  }

  .shpd-attgrid__full-card {
    max-width: 220px;
  }
</style>
