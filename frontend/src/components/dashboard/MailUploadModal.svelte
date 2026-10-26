<script>
  /**
   * Modal ručního nahrání souborů do došlé pošty (tasks/mail-dashboard-upload.md).
   * Otevírá ho tlačítko Nahrát v hlavičce dashboardu i drag-n-drop na plochu
   * (initialFiles) — drop nikdy neukládá rovnou, mód se potvrzuje tady (D1).
   * Mód se pamatuje v localStorage; při jediném souboru se přepínač skrývá,
   * módy jsou ekvivalentní (D2).
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { uploadMailMessages } from '../../api/mail.js';
  import { formatFileSize } from '../../api/attachments.js';
  import { iconUpload } from '../../icons.js';

  const MAX_FILES = 20;
  const MODE_KEY = 'shpd_mail_upload_mode';
  const MODES = ['perFile', 'single'];

  let { open = false, initialFiles = [], onClose = () => {}, onUploaded = () => {} } = $props();

  let files = $state([]);
  let mode = $state(localStorage.getItem(MODE_KEY) === 'single' ? 'single' : 'perFile');
  let submitting = $state(false);
  let errorMsg = $state(null);
  let limitHit = $state(false);
  let dragOver = $state(false);
  let fileInput = $state(null);

  // Čisté sloučení výběru — effect(open) nesmí číst stav `files` (zápis do
  // něj by effect spouštěl znovu → effect_update_depth_exceeded), proto
  // merge dostává aktuální seznam parametrem.
  function mergeFiles(current, incoming) {
    const list = [...current];
    let refused = false;
    for (const file of Array.from(incoming ?? [])) {
      // Duplicitní přidání téhož souboru (name+size) se ignoruje.
      if (list.some((f) => f.name === file.name && f.size === file.size)) continue;
      if (list.length >= MAX_FILES) {
        refused = true;
        continue;
      }
      list.push(file);
    }
    return { list, refused };
  }

  // Reset výběru při každém otevření; initialFiles přichází z dropu na dashboard.
  $effect(() => {
    if (open) {
      errorMsg = null;
      dragOver = false;
      submitting = false;
      const { list, refused } = mergeFiles([], initialFiles);
      files = list;
      limitHit = refused;
    }
  });

  function addFiles(incoming) {
    const { list, refused } = mergeFiles(files, incoming);
    files = list;
    limitHit = refused;
  }

  function removeFile(index) {
    files = files.filter((_, i) => i !== index);
    if (files.length < MAX_FILES) limitHit = false;
  }

  function selectMode(value) {
    mode = value;
    localStorage.setItem(MODE_KEY, value);
  }

  function handleFileInput(event) {
    addFiles(event.target.files);
    event.target.value = '';
  }

  function handleDragOver(event) {
    event.preventDefault();
    if (!submitting) dragOver = true;
  }

  function handleDragLeave() {
    dragOver = false;
  }

  function handleDrop(event) {
    event.preventDefault();
    dragOver = false;
    if (!submitting) addFiles(event.dataTransfer?.files);
  }

  async function submit() {
    if (files.length === 0 || submitting) return;
    submitting = true;
    errorMsg = null;
    try {
      const result = await uploadMailMessages(files, mode);
      if (result?.success) {
        onUploaded(result.data?.messages?.length ?? files.length);
      } else {
        errorMsg = translateError(result?.error);
      }
    } catch (err) {
      console.error('Mail upload failed:', err);
      errorMsg = t('common.unknownError');
    } finally {
      submitting = false;
    }
  }
</script>

<Modal title={t('dashboard.upload.title')} {open} {onClose} width="520px">
  <!-- svelte-ignore a11y_no_static_element_interactions -->
  <div
    class="shpd-mail-upload__dropzone"
    class:shpd-mail-upload__dropzone--dragover={dragOver}
    ondragover={handleDragOver}
    ondragleave={handleDragLeave}
    ondrop={handleDrop}
    role="region"
    aria-label={t('dashboard.upload.dropHint')}
  >
    <span class="shpd-mail-upload__hint">{t('dashboard.upload.dropHint')}</span>
    <Button
      variant="secondary"
      size="sm"
      icon={iconUpload}
      label={t('dashboard.upload.selectFiles')}
      disabled={submitting}
      onclick={() => fileInput.click()}
    />
    <input
      bind:this={fileInput}
      type="file"
      multiple
      class="shpd-mail-upload__file-input"
      onchange={handleFileInput}
    />
  </div>

  {#if files.length > 0}
    <ul class="shpd-mail-upload__list">
      {#each files as file, i (file.name + file.size)}
        <li class="shpd-mail-upload__item">
          <span class="shpd-mail-upload__name">{file.name}</span>
          <span class="shpd-mail-upload__size">{formatFileSize(file.size)}</span>
          <button
            type="button"
            class="shpd-mail-upload__remove"
            aria-label={t('dashboard.upload.removeFile')}
            disabled={submitting}
            onclick={() => removeFile(i)}
          >×</button>
        </li>
      {/each}
    </ul>
  {/if}

  {#if limitHit}
    <div class="shpd-mail-upload__notice">{t('dashboard.upload.tooMany')}</div>
  {/if}

  {#if files.length > 1}
    <div class="shpd-mail-upload__modes" role="radiogroup" aria-label={t('dashboard.upload.modeLabel')}>
      {#each MODES as value (value)}
        <button
          type="button"
          role="radio"
          class="shpd-mail-upload__mode"
          class:shpd-mail-upload__mode--active={mode === value}
          aria-checked={mode === value}
          disabled={submitting}
          onclick={() => selectMode(value)}
        >
          {t(value === 'single' ? 'dashboard.upload.modeSingle' : 'dashboard.upload.modePerFile')}
        </button>
      {/each}
    </div>
  {/if}

  {#if errorMsg}
    <div class="shpd-mail-upload__error">{errorMsg}</div>
  {/if}

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" size="sm" disabled={submitting} onclick={onClose} />
    <Button
      label={submitting ? t('common.saving') : t('dashboard.upload.submit')}
      size="sm"
      disabled={files.length === 0}
      loading={submitting}
      onclick={submit}
    />
  {/snippet}
</Modal>

<style>
  .shpd-mail-upload__dropzone {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-lg);
    border: 2px dashed var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
  }

  .shpd-mail-upload__dropzone--dragover {
    background: color-mix(in srgb, var(--shpd-color-primary) 6%, transparent);
    border-color: var(--shpd-color-primary);
  }

  .shpd-mail-upload__hint {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-mail-upload__file-input {
    display: none;
  }

  .shpd-mail-upload__list {
    margin: var(--shpd-space-md) 0 0;
    padding: 0;
    list-style: none;
    max-height: 220px;
    overflow-y: auto;
  }

  .shpd-mail-upload__item {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xs) 0;
    font-size: var(--shpd-font-size-sm);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-mail-upload__item:last-child {
    border-bottom: none;
  }

  .shpd-mail-upload__name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--shpd-color-text);
  }

  .shpd-mail-upload__size {
    flex-shrink: 0;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-mail-upload__remove {
    flex-shrink: 0;
    border: none;
    background: none;
    color: var(--shpd-color-text-secondary);
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 var(--shpd-space-xs);
  }

  .shpd-mail-upload__remove:hover:not(:disabled) {
    color: var(--shpd-color-danger);
  }

  .shpd-mail-upload__notice {
    margin-top: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
  }

  .shpd-mail-upload__modes {
    display: flex;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-md);
  }

  .shpd-mail-upload__mode {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-full);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    font: inherit;
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
  }

  .shpd-mail-upload__mode:hover:not(.shpd-mail-upload__mode--active):not(:disabled) {
    background: var(--shpd-color-bg-hover);
  }

  .shpd-mail-upload__mode--active {
    background: var(--shpd-color-primary);
    border-color: var(--shpd-color-primary);
    color: #ffffff;
  }

  .shpd-mail-upload__error {
    margin-top: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-radius: var(--shpd-radius-sm);
    background: color-mix(in srgb, var(--shpd-color-danger) 10%, transparent);
    color: var(--shpd-color-danger);
    font-size: var(--shpd-font-size-sm);
  }
</style>
