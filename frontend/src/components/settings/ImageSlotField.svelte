<script>
  // Image slot pole pro settings page — náhled + Nahrát/Odebrat.
  // Upload jde okamžitě (bez tlačítka Uložit) na POST /_app/branding/{slot};
  // po změně volá onchange(newState), parent si refreshne appInfo store.
  //
  // `slotId` (ne `slot`) — slot je v Svelte rezervovaný atribut.
  import { uploadBranding, deleteBranding, brandingUrl } from '../../api/app.js';
  import { formatFileSize } from '../../api/attachments.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import Button from '../ui/Button.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconUpload, iconDelete, iconImage } from '../../icons.js';

  let { slotId, slotState = null, onchange } = $props();

  let fileInput = $state(null);
  let busy      = $state(false);
  let error     = $state(null);

  function openFilePicker() {
    fileInput?.click();
  }

  async function handleFileSelected(event) {
    const file = event.target.files?.[0];
    event.target.value = ''; // stejný soubor jde vybrat znovu
    if (!file || busy) return;

    busy = true;
    error = null;
    try {
      const response = await uploadBranding(slotId, file);
      if (response?.success) {
        const d = response.data;
        onchange?.({
          url: d.url,
          hash: d.hash,
          filename: d.filename,
          mime: d.mime,
          size: d.size,
        });
      } else {
        error = response?.error ? translateError(response.error) : t('settingsPage.image.uploadFailed');
      }
    } catch {
      error = t('settingsPage.image.uploadFailed');
    } finally {
      busy = false;
    }
  }

  async function handleRemove() {
    if (busy) return;
    busy = true;
    error = null;
    try {
      const ok = await deleteBranding(slotId);
      if (ok) {
        onchange?.(null);
      } else {
        error = t('settingsPage.image.removeFailed');
      }
    } catch {
      error = t('settingsPage.image.removeFailed');
    } finally {
      busy = false;
    }
  }
</script>

<div class="shpd-image-slot">
  <div class="shpd-image-slot__preview">
    {#if slotState}
      <img src={brandingUrl(slotId, slotState.hash)} alt={slotState.filename ?? ''} />
    {:else}
      <Icon icon={iconImage} size="lg" />
    {/if}
  </div>

  <div class="shpd-image-slot__body">
    {#if slotState}
      <div class="shpd-image-slot__meta">
        <span class="shpd-image-slot__filename">{slotState.filename}</span>
        {#if slotState.size}
          <span class="shpd-image-slot__size">{formatFileSize(slotState.size)}</span>
        {/if}
      </div>
    {:else}
      <div class="shpd-image-slot__meta shpd-image-slot__meta--empty">
        {t('settingsPage.image.empty')}
      </div>
    {/if}

    <div class="shpd-image-slot__actions">
      <Button
        label={t('settingsPage.image.upload')}
        icon={iconUpload}
        variant="secondary"
        size="sm"
        loading={busy}
        onclick={openFilePicker}
      />
      {#if slotState}
        <Button
          label={t('settingsPage.image.remove')}
          icon={iconDelete}
          variant="ghost"
          size="sm"
          disabled={busy}
          onclick={handleRemove}
        />
      {/if}
    </div>

    {#if error}
      <div class="shpd-image-slot__error">{error}</div>
    {/if}
  </div>

  <input
    bind:this={fileInput}
    class="shpd-image-slot__file"
    type="file"
    accept="image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon,.ico"
    onchange={handleFileSelected}
  />
</div>

<style>
  .shpd-image-slot {
    display: flex;
    align-items: flex-start;
    gap: var(--shpd-space-md);
  }

  .shpd-image-slot__preview {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 4.5rem;
    height: 4.5rem;
    flex-shrink: 0;
    background-color: var(--shpd-color-bg-secondary);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-text-secondary);
    overflow: hidden;
  }

  .shpd-image-slot__preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
  }

  .shpd-image-slot__body {
    flex: 1;
    min-width: 0;
  }

  .shpd-image-slot__meta {
    display: flex;
    align-items: baseline;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-image-slot__meta--empty {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-image-slot__filename {
    color: var(--shpd-color-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-image-slot__size {
    color: var(--shpd-color-text-secondary);
    flex-shrink: 0;
  }

  .shpd-image-slot__actions {
    display: flex;
    gap: var(--shpd-space-sm);
  }

  .shpd-image-slot__error {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger-text);
  }

  .shpd-image-slot__file {
    display: none;
  }
</style>
