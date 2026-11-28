<script>
  // Panel „Toto zařízení“ (Nastavení → Zařízení, panelId thisDevice;
  // #177 D13, D33, U26): registrace zařízení, na kterém se uživatel právě
  // dívá, a jeho stav. Most nativní aplikace (nebo simulace pro vývoj)
  // podepisuje, server ukládá — kroky a stavy panelu jsou čisté funkce
  // v thisDeviceState.js.
  //
  // Název a periferie registrovaného zařízení most nevrací; administrátor
  // je čte generickým seznamem nad tabulkami modulu core.devices (U26).
  import { onMount } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import Card from '../ui/Card.svelte';
  import SectionHeading from '../ui/SectionHeading.svelte';
  import Notice from '../ui/Notice.svelte';
  import Button from '../ui/Button.svelte';
  import Input from '../ui/Input.svelte';
  import SegmentedControl from '../ui/SegmentedControl.svelte';
  import PropertyList from '../ui/PropertyList.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import { iconDevice } from '../../icons.js';
  import { API_BASE_URL } from '../../api/config.js';
  import { get, post } from '../../api/client.js';
  import { nativeCall } from '../../native/bridge.js';
  import { nativeStore } from '../../stores/native.svelte.js';
  import { noticeStore } from '../../stores/notice.svelte.js';
  import PrintJobErrorDialog from '../native/PrintJobErrorDialog.svelte';
  import { submitPrintJob } from '../../api/printJobs.js';
  import { printJobSuccessKey } from '../../native/printJobs.js';
  import { authStore } from '../../stores/auth.svelte.js';
  import {
    PANEL, REGISTER_MODES, NAME_MAX_LENGTH, panelState, registrationFormError, registerDevice,
    bridgeErrorKey, deviceRecordPath, peripheralsPath, peripheralRows, labelKey,
  } from './thisDeviceState.js';

  const apiBase = window.location.origin + API_BASE_URL;

  let name = $state('');
  let mode = $state('personal');
  let nameError = $state(null);
  let busy = $state(false);
  // Hláška poslední akce: {tone, text}.
  let message = $state(null);
  // Server zařízení zneplatnil — aplikace zahodila identitu a je znovu neregistrovaná.
  let revoked = $state(false);

  let record = $state(null);
  let peripherals = $state([]);
  let recordState = $state('idle'); // idle | loading | missing | ready | error
  let recordError = $state(null);
  let formOpen = $state(false);
  // Zkušební tisk a šuplík (#177 U41): běžící druh úlohy a neúspěch pro dialog.
  let printing = $state(null);
  let printJobFailure = $state(null);

  const info = $derived(nativeStore.info);
  const state = $derived(panelState({
    available: nativeStore.available,
    info,
    error: nativeStore.error,
    apiBase,
    isAdmin: authStore.isAdmin,
  }));

  const modeOptions = REGISTER_MODES.map((value) => ({ value, label: t(`devices.mode.${value}`) }));

  onMount(async () => {
    // `info` se při startu načítá bez čekání — panel si ho vyžádá čerstvé.
    await nativeStore.load({ refresh: true });
    await loadRecord();
  });

  /** Text chyby mostu — vlastní slovník, ne REST (`INTERNAL_ERROR` tu znamená aplikaci). */
  function bridgeErrorText(error) {
    return t(bridgeErrorKey(error?.code));
  }

  function failureText(failure) {
    return failure.source === 'bridge' ? bridgeErrorText(failure.error) : translateError(failure.error);
  }

  /** Popisek hodnoty; neznámou (novější aplikace) ukázat, jak přišla (kontrakt §2.4). */
  function label(group, value) {
    const key = labelKey(group, value);
    return key ? t(key) : String(value ?? '');
  }

  async function loadRecord() {
    record = null;
    peripherals = [];
    recordError = null;
    if (state !== PANEL.REGISTERED || !authStore.isAdmin || !info?.deviceId) {
      recordState = 'idle';
      return;
    }
    recordState = 'loading';
    const found = await get(deviceRecordPath(info.deviceId));
    if (found?.success !== true) {
      recordState = 'error';
      recordError = translateError(found?.error);
      return;
    }
    const row = Array.isArray(found.data) ? found.data[0] : null;
    if (!row) {
      recordState = 'missing';
      return;
    }
    record = row;
    const list = await get(peripheralsPath(row.id));
    peripherals = list?.success === true ? peripheralRows(list.data) : [];
    recordState = 'ready';
  }

  async function register() {
    message = null;
    revoked = false;
    const invalid = registrationFormError({ name, mode });
    nameError = invalid?.field === 'name' ? t(`devices.register.error.${invalid.reason}`, { max: NAME_MAX_LENGTH }) : null;
    if (invalid) return;

    busy = true;
    try {
      const result = await registerDevice({ info, name, mode, post, nativeCall });
      if (!result.ok) {
        message = { tone: 'danger', text: t(`devices.register.failed.${result.step}`, { reason: failureText(result) }) };
        return;
      }
      await nativeStore.load({ refresh: true });
      await loadRecord();
      message = result.syncError
        ? { tone: 'warning', text: t('devices.register.doneSyncFailed', { reason: bridgeErrorText(result.syncError) }) }
        : { tone: 'success', text: t('devices.register.done') };
      name = '';
    } finally {
      busy = false;
    }
  }

  async function sync() {
    message = null;
    busy = true;
    try {
      await nativeCall('device.sync');
      await nativeStore.load({ refresh: true });
      await loadRecord();
      message = { tone: 'success', text: t('devices.sync.done') };
    } catch (error) {
      // Aplikace po zneplatnění zahodila identitu — `info` je zpět v `unregistered`.
      await nativeStore.load({ refresh: true });
      await loadRecord();
      if (error?.code === 'DEVICE_REVOKED') {
        revoked = true;
      } else {
        message = { tone: 'danger', text: t('devices.sync.failed', { reason: bridgeErrorText(error) }) };
      }
    } finally {
      busy = false;
    }
  }

  /** Zkušební účtenka nebo otevření šuplíku — stejná cesta jako tisk záznamu. */
  async function printJob(kind) {
    if (busy || printing !== null) return;
    message = null;
    printing = kind;
    try {
      const result = await submitPrintJob({ kind });
      if (result.ok) {
        noticeStore.show(t(printJobSuccessKey(kind)), { level: 'success' });
      } else {
        printJobFailure = result;
      }
    } finally {
      printing = null;
    }
  }

  async function handleFormSaved() {
    formOpen = false;
    await loadRecord();
  }

  const overview = $derived([
    { label: t('devices.field.name'), value: record?.name ?? '' },
    { label: t('devices.field.mode'), value: info ? label('mode', info.mode) : '' },
    { label: t('devices.field.platform'), value: info ? label('platform', info.platform) : '' },
    { label: t('devices.field.appVersion'), value: info?.app?.version ?? '' },
  ]);
</script>

<div class="shpd-this-device">
  <Card spacious title={t('devices.thisDevice.title')} headingLevel={2} titleRole="title" icon={iconDevice}>
    <div class="shpd-this-device__body">
    <p class="shpd-this-device__intro">{t('devices.thisDevice.intro')}</p>

    {#if revoked}
      <Notice tone="warning" title={t('devices.revoked.title')}>{t('devices.revoked.text')}</Notice>
    {/if}
    {#if message}
      <Notice tone={message.tone}>{message.text}</Notice>
    {/if}

    {#if state === PANEL.NO_BRIDGE}
      <Notice tone="info" title={t('devices.noBridge.title')}>{t('devices.noBridge.text')}</Notice>
    {:else if state === PANEL.LOADING}
      <p class="shpd-this-device__state">{t('common.loading')}</p>
    {:else if state === PANEL.BRIDGE_ERROR}
      <Notice tone="danger">{t('devices.bridgeFailed', { reason: bridgeErrorText(nativeStore.error) })}</Notice>
    {:else if state === PANEL.OTHER_DATA_SOURCE}
      <Notice tone="danger" title={t('devices.otherDataSource.title')}>{t('devices.otherDataSource.text')}</Notice>
    {:else if state === PANEL.CANNOT_SIGN}
      <Notice tone="warning" title={t('devices.cannotSign.title')}>
        {t(nativeStore.mock ? 'devices.cannotSign.mock' : 'devices.cannotSign.text')}
      </Notice>
    {:else if state === PANEL.NEEDS_ADMIN}
      <Notice tone="info" title={t('devices.unregistered.title')}>{t('devices.needsAdmin.text')}</Notice>
    {:else if state === PANEL.REGISTER}
      <section class="shpd-this-device__block">
        <SectionHeading level={3}>{t('devices.register.heading')}</SectionHeading>
        <p class="shpd-this-device__hint">{t('devices.register.intro')}</p>

        <div class="shpd-this-device__field">
          <label class="shpd-this-device__label" for="this-device-name">{t('devices.field.name')}</label>
          <Input
            id="this-device-name"
            bind:value={name}
            maxlength={NAME_MAX_LENGTH}
            placeholder={t('devices.register.namePlaceholder')}
            error={nameError}
            disabled={busy}
            testid="this-device-name"
            onkeydown={(event) => { if (event.key === 'Enter') register(); }}
          />
        </div>

        <div class="shpd-this-device__field">
          <span class="shpd-this-device__label">{t('devices.field.mode')}</span>
          <SegmentedControl
            options={modeOptions}
            bind:value={mode}
            label={t('devices.field.mode')}
            disabled={busy}
            data-testid="this-device-mode"
          />
          <p class="shpd-this-device__hint">{t(`devices.modeHint.${mode}`)}</p>
        </div>

        <div class="shpd-this-device__actions">
          <Button label={t('devices.register.submit')} loading={busy} onclick={register} testid="this-device-register" />
        </div>
      </section>
    {:else if state === PANEL.REGISTERED}
      <section class="shpd-this-device__block">
        <PropertyList items={overview} />

        {#if !authStore.isAdmin}
          <p class="shpd-this-device__hint">{t('devices.registered.userHint')}</p>
        {:else if recordState === 'loading'}
          <p class="shpd-this-device__state">{t('common.loading')}</p>
        {:else if recordState === 'error'}
          <Notice tone="danger">{recordError}</Notice>
        {:else if recordState === 'missing'}
          <Notice tone="warning">{t('devices.registered.missing')}</Notice>
        {/if}

        <div class="shpd-this-device__actions">
          <Button label={t('devices.sync.submit')} variant="secondary" loading={busy} disabled={printing !== null} onclick={sync} testid="this-device-sync" />
          {#if record}
            <Button label={t('devices.registered.open')} variant="secondary" disabled={busy} onclick={() => { formOpen = true; }} testid="this-device-open" />
          {/if}
          {#if nativeStore.has('print.escpos')}
            <Button
              label={t('native.print.testPrint')}
              variant="secondary"
              loading={printing === 'test'}
              disabled={busy || printing !== null}
              onclick={() => printJob('test')}
              testid="this-device-test-print"
            />
            <Button
              label={t('native.print.openDrawer')}
              variant="secondary"
              loading={printing === 'drawer'}
              disabled={busy || printing !== null}
              onclick={() => printJob('drawer')}
              testid="this-device-open-drawer"
            />
          {/if}
        </div>
      </section>

      {#if recordState === 'ready'}
        <section class="shpd-this-device__block">
          <SectionHeading level={3}>{t('devices.peripherals.heading')}</SectionHeading>
          {#if peripherals.length === 0}
            <p class="shpd-this-device__state">{t('devices.peripherals.empty')}</p>
          {:else}
            <ul class="shpd-this-device__peripherals">
              {#each peripherals as peripheral (peripheral.id)}
                <li class="shpd-this-device__peripheral" class:shpd-this-device__peripheral--absent={!peripheral.present}>
                  <span class="shpd-this-device__peripheral-name">{peripheral.name}</span>
                  <span class="shpd-this-device__peripheral-meta">
                    {label('kind', peripheral.kind)}
                    · {peripheral.present ? label('status', peripheral.status) : t('devices.peripherals.absent')}
                    {#if peripheral.role}· {t('devices.peripherals.role', { role: label('role', peripheral.role) })}{/if}
                  </span>
                </li>
              {/each}
            </ul>
            <p class="shpd-this-device__hint">{t('devices.peripherals.hint')}</p>
          {/if}
        </section>
      {/if}
    {/if}
    </div>
  </Card>
</div>

{#if formOpen && record}
  <FormDialog
    table="core_devices_devices"
    recordId={record.id}
    open={formOpen}
    onSaved={handleFormSaved}
    onClose={() => { formOpen = false; loadRecord(); }}
  />
{/if}

<!-- Zkušební tisk a šuplík: neúspěch s důvodem a Zkusit znovu (#177 U41). -->
<PrintJobErrorDialog
  bind:failure={printJobFailure}
  onSuccess={(_result, request) => noticeStore.show(t(printJobSuccessKey(request?.kind)), { level: 'success' })}
/>

<style>
  .shpd-this-device {
    padding: var(--shpd-space-lg);
    max-width: 47.5rem;
  }

  /* Mezery mezi úvodem, hláškami a bloky dává sloupec — hláška (Notice)
     vlastní vnější okraj nemá. */
  .shpd-this-device__body {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-this-device__intro {
    margin: 0;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-this-device__state {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-this-device__block + .shpd-this-device__block {
    padding-top: var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-this-device__field {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    margin-top: var(--shpd-space-md);
  }

  .shpd-this-device__label {
    font-size: var(--shpd-font-size-sm);
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text);
  }

  .shpd-this-device__hint {
    margin: var(--shpd-space-xs) 0 0;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-this-device__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-lg);
  }

  .shpd-this-device__peripherals {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
    margin: var(--shpd-space-sm) 0 0;
    padding: 0;
    list-style: none;
  }

  .shpd-this-device__peripheral {
    display: flex;
    flex-direction: column;
    gap: 0;
  }

  .shpd-this-device__peripheral--absent .shpd-this-device__peripheral-name {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-this-device__peripheral-meta {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }
</style>
