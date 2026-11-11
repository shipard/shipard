<script>
  /**
   * Wizard nového zdroje dat z portálu (tasks/hosting-08-self-service-ds.md).
   * Jeden krok: název, web_id s živou kontrolou (debounce + requestToken
   * proti out-of-order odpovědím), země/jazyk z create-meta, roletka install
   * modulu jen při >1 nabízeném (D2). Submit založí požadavek
   * lifecycle=request — kartu „Připravuje se…" vloží rodič bez refetche.
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import Notice from '../ui/Notice.svelte';
  import Input from '../ui/Input.svelte';
  import Select from '../ui/Select.svelte';
  import Icon from '../ui/Icon.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { checkWebId, createDatasource } from '../../api/portal.js';
  import { iconSuccess, iconWarning } from '../../icons.js';

  const CHECK_DEBOUNCE_MS = 400;

  let { open = false, meta = null, onClose = () => {}, onCreated = () => {} } = $props();

  let name = $state('');
  let webId = $state('');
  let language = $state(null);
  let country = $state(null);
  let installModule = $state(null);
  let submitting = $state(false);
  let errorMsg = $state(null);
  let fieldErrors = $state({});

  // Živá kontrola web_id: idle | checking | ok | {reason}
  let webIdStatus = $state('idle');
  let checkTimer = null;
  let checkToken = 0;

  const installModules = $derived(meta?.installModules ?? []);
  const languageOptions = $derived((meta?.languages ?? []).map((l) => ({ value: l.id, label: l.name })));
  const countryOptions = $derived((meta?.countries ?? []).map((c) => ({ value: c.id, label: c.name })));
  const moduleOptions = $derived(installModules.map((m) => ({ value: m.id, label: m.name })));

  // Reset při každém otevření; defaults ze serveru (cs/cz).
  $effect(() => {
    if (open) {
      name = '';
      webId = '';
      language = meta?.defaults?.language ?? 'cs';
      country = meta?.defaults?.country ?? 'cz';
      installModule = installModules.length === 1 ? installModules[0].id : null;
      submitting = false;
      errorMsg = null;
      fieldErrors = {};
      webIdStatus = 'idle';
    } else if (checkTimer !== null) {
      clearTimeout(checkTimer);
      checkTimer = null;
    }
  });

  function handleWebIdInput() {
    fieldErrors = { ...fieldErrors, web_id: null };
    if (checkTimer !== null) clearTimeout(checkTimer);
    const value = webId.trim().toLowerCase();
    if (value === '') {
      webIdStatus = 'idle';
      return;
    }
    webIdStatus = 'checking';
    checkTimer = setTimeout(() => void runCheck(value), CHECK_DEBOUNCE_MS);
  }

  async function runCheck(value) {
    const token = ++checkToken;
    try {
      const res = await checkWebId(value);
      if (token !== checkToken) return; // mezitím přišel novější vstup
      if (res?.success) {
        webIdStatus = res.data.available ? 'ok' : (res.data.reason ?? 'format');
      } else {
        webIdStatus = 'idle'; // kontrola je informativní — chybu neblokujeme
      }
    } catch {
      if (token === checkToken) webIdStatus = 'idle';
    }
  }

  const webIdHint = $derived.by(() => {
    switch (webIdStatus) {
      case 'ok': return { kind: 'ok', text: t('portal.create.webIdAvailable') };
      case 'format': return { kind: 'error', text: t('portal.create.webIdFormat') };
      case 'reserved': return { kind: 'error', text: t('portal.create.webIdReserved') };
      case 'taken': return { kind: 'error', text: t('portal.create.webIdTaken') };
      case 'checking': return { kind: 'muted', text: t('portal.create.webIdChecking') };
      default: return null;
    }
  });

  const canSubmit = $derived(
    name.trim() !== '' && webId.trim() !== '' && !submitting
    && webIdStatus !== 'format' && webIdStatus !== 'reserved' && webIdStatus !== 'taken',
  );

  async function submit() {
    if (!canSubmit) return;
    submitting = true;
    errorMsg = null;
    fieldErrors = {};
    try {
      const payload = {
        name: name.trim(),
        web_id: webId.trim().toLowerCase(),
        language,
        country,
      };
      if (installModules.length > 1 && installModule) {
        payload.install_module = installModule;
      }
      const result = await createDatasource(payload);
      if (result?.success) {
        onCreated(result.data?.item ?? null);
      } else if (result?.error?.code === 'VALIDATION_ERROR' && Array.isArray(result.error.details)) {
        const mapped = {};
        for (const d of result.error.details) {
          // Form-level chyby (_form) padají do banneru.
          if (d.field && d.field !== '_form') mapped[d.field] = d.message;
        }
        fieldErrors = mapped;
        const formError = result.error.details.find((d) => d.field === '_form');
        if (formError || Object.keys(mapped).length === 0) {
          errorMsg = formError?.message ?? translateError(result.error);
        }
      } else {
        errorMsg = translateError(result?.error);
      }
    } catch (err) {
      console.error('Create datasource failed:', err);
      errorMsg = t('common.unknownError');
    } finally {
      submitting = false;
    }
  }
</script>

<Modal title={t('portal.create.title')} {open} {onClose} width="30rem">
  <div class="shpd-ds-create__field">
    <label class="shpd-ds-create__label" for="ds-create-name">{t('portal.create.name')}</label>
    <Input id="ds-create-name" bind:value={name} disabled={submitting} maxlength={200}
      error={fieldErrors.name ?? null} />
  </div>

  <div class="shpd-ds-create__field">
    <label class="shpd-ds-create__label" for="ds-create-webid">{t('portal.create.webId')}</label>
    <Input id="ds-create-webid" bind:value={webId} disabled={submitting} maxlength={50}
      placeholder={t('portal.create.webIdPlaceholder')}
      error={fieldErrors.web_id ?? null}
      oninput={handleWebIdInput} />
    <p class="shpd-ds-create__hint">{t('portal.create.webIdHint')}</p>
    {#if webIdHint && !fieldErrors.web_id}
      <p class="shpd-ds-create__check shpd-ds-create__check--{webIdHint.kind}">
        {#if webIdHint.kind === 'ok'}<Icon icon={iconSuccess} size="sm" />{/if}
        {#if webIdHint.kind === 'error'}<Icon icon={iconWarning} size="sm" />{/if}
        {webIdHint.text}
      </p>
    {/if}
  </div>

  <div class="shpd-ds-create__row">
    <div class="shpd-ds-create__field">
      <label class="shpd-ds-create__label" for="ds-create-country">{t('portal.create.country')}</label>
      <Select id="ds-create-country" bind:value={country} options={countryOptions}
        required disabled={submitting} error={fieldErrors.country ?? null} />
    </div>
    <div class="shpd-ds-create__field">
      <label class="shpd-ds-create__label" for="ds-create-language">{t('portal.create.language')}</label>
      <Select id="ds-create-language" bind:value={language} options={languageOptions}
        required disabled={submitting} error={fieldErrors.language ?? null} />
    </div>
  </div>

  {#if installModules.length > 1}
    <div class="shpd-ds-create__field">
      <label class="shpd-ds-create__label" for="ds-create-module">{t('portal.create.installModule')}</label>
      <Select id="ds-create-module" bind:value={installModule} options={moduleOptions}
        required disabled={submitting} error={fieldErrors.install_module ?? null} />
    </div>
  {/if}

  {#if errorMsg}
    <!-- Mezeru nad hláškou dává spodní okraj posledního pole. -->
    <Notice tone="danger">{errorMsg}</Notice>
  {/if}

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" size="sm" disabled={submitting} onclick={onClose} />
    <Button
      label={submitting ? t('common.saving') : t('portal.create.submit')}
      size="sm"
      disabled={!canSubmit}
      loading={submitting}
      onclick={submit}
    />
  {/snippet}
</Modal>

<style>
  .shpd-ds-create__field {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    margin-bottom: var(--shpd-space-md);
    flex: 1;
    min-width: 0;
  }

  .shpd-ds-create__label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
  }

  .shpd-ds-create__row {
    display: flex;
    gap: var(--shpd-space-md);
  }

  .shpd-ds-create__hint {
    margin: 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-ds-create__check {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    margin: 0;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-ds-create__check--ok {
    color: var(--shpd-color-success-text);
  }

  .shpd-ds-create__check--error {
    color: var(--shpd-color-danger-text);
  }

  .shpd-ds-create__check--muted {
    color: var(--shpd-color-text-secondary);
  }
</style>
