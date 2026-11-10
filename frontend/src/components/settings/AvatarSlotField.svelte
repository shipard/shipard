<script>
  // Avatar slot pole pro Nastavení účtu → Základní — náhled + Nahrát/Odebrat.
  // Analogie ImageSlotField, ale na per-user avatar endpointy (/_app/avatar).
  // Upload jde okamžitě (bez tlačítka Uložit); po změně refreshneme avatar
  // store, ze kterého čte i patička sidebaru — fotka se tam projeví hned.
  //
  // Náhled i viditelnost tlačítka Odebrat se odvozují z avatarStore.objectUrl
  // (blob fetchovaný s Bearer hlavičkou) — <img src> by auth hlavičku neposlal,
  // a store je jediný zdroj pravdy, takže náhled a tlačítko vždy souhlasí.
  import { uploadAvatar, deleteAvatar } from '../../api/app.js';
  import { avatarStore } from '../../stores/avatar.svelte.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import Button from '../ui/Button.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconUpload, iconDelete, iconUser } from '../../icons.js';

  // onchange jen informuje parent (SettingsPage) o změně, aby si mohl
  // synchronizovat stav pole. Zdroj pravdy pro náhled je avatarStore.
  let { onchange } = $props();

  let fileInput = $state(null);
  let busy      = $state(false);
  let error     = $state(null);

  let hasAvatar = $derived(avatarStore.objectUrl != null);

  function openFilePicker() {
    fileInput?.click();
  }

  async function handleFileSelected(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || busy) return;

    busy = true;
    error = null;
    try {
      const response = await uploadAvatar(file);
      if (response?.success) {
        await avatarStore.reload();
        onchange?.(true);
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
      const ok = await deleteAvatar();
      if (ok) {
        await avatarStore.reload();
        onchange?.(false);
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
  <div class="shpd-image-slot__preview shpd-image-slot__preview--avatar">
    {#if avatarStore.objectUrl}
      <img src={avatarStore.objectUrl} alt="" />
    {:else}
      <Icon icon={iconUser} size="lg" />
    {/if}
  </div>

  <div class="shpd-image-slot__body">
    <div class="shpd-image-slot__actions">
      <Button
        label={t('settingsPage.image.upload')}
        icon={iconUpload}
        variant="secondary"
        size="sm"
        loading={busy}
        onclick={openFilePicker}
      />
      {#if hasAvatar}
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
    accept="image/png,image/jpeg,image/webp"
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

  /* Avatar je kulatý — odlišíme od hranatého brandingu. */
  .shpd-image-slot__preview--avatar {
    border-radius: 50%;
  }

  .shpd-image-slot__preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .shpd-image-slot__body {
    flex: 1;
    min-width: 0;
  }

  .shpd-image-slot__actions {
    display: flex;
    gap: var(--shpd-space-sm);
  }

  .shpd-image-slot__error {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
  }

  .shpd-image-slot__file {
    display: none;
  }
</style>
