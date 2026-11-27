<script>
  // Panel simulace mostu nativní aplikace (#177 D29, U11, U12). Kreslí ho
  // App, jen když je most na stránce simulace (`__mock`) — zapnutá simulace
  // je vždy vidět: trvalý pruh vpravo dole a sbalitelný panel nad ním.
  //
  // Leží nad dialogy (čtečku je potřeba poslat i do otevřeného formuláře
  // nebo kasy) a není vrstva zásobníku — Esc ho nezavírá. Načítá se
  // dynamickým importem spolu se simulací, v hlavním chunku není.
  import PageBanner from '../ui/PageBanner.svelte';
  import Button from '../ui/Button.svelte';
  import Icon from '../ui/Icon.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import Select from '../ui/Select.svelte';
  import SegmentedControl from '../ui/SegmentedControl.svelte';
  import ReceiptPreview from './ReceiptPreview.svelte';
  import { iconChevronDown, iconChevronUp, iconDownload, iconPrint } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { formatDateTime } from '../../utils/formatDate.js';
  import { saveBlob } from '../../utils/download.js';
  import { nativeStore } from '../../stores/native.svelte.js';
  import { NATIVE_MOCK_FLAG, nativeCall } from '../../native/bridge.js';
  import { CAPABILITIES, PERIPHERAL_STATUSES, SCAN_KINDS } from '../../native/contract.js';
  import { bytesToBase64, randomUuid } from '../../native/bytes.js';
  import { onSubscriberCountChange, subscriberCount } from '../../native/scanRouter.js';
  import { decodeEscPos } from '../../native/escpos/decode.js';
  import { buildTestReceipt } from '../../native/escpos/testReceipt.js';
  import { mockControls } from '../../native/mock/install.js';
  import { MOCK_PRINTER_ID, MOCK_PRINTER_PROFILES } from '../../native/mock/state.js';

  const controls = mockControls();

  let open = $state(false);
  let snapshot = $state(controls.snapshot());
  let jobs = $state(controls.jobs());
  let deviceId = $state(null);
  let subscribers = $state(subscriberCount());
  let scanData = $state('');
  let scanKind = $state('barcode');
  let error = $state(null);

  function refresh() {
    snapshot = controls.snapshot();
    jobs = controls.jobs();
    controls.deviceId().then((id) => (deviceId = id), () => {});
  }

  $effect(() => {
    refresh();
    const stopState = controls.subscribe(refresh);
    const stopCount = onSubscriberCountChange((count) => (subscribers = count));
    return () => {
      stopState();
      stopCount();
    };
  });

  const scanner = $derived(snapshot.peripherals.find((p) => p.kind === 'scanner'));
  const canScan = $derived(snapshot.capabilities.includes('scanner') && scanner?.status === 'ready' && scanData !== '');

  const statusOptions = PERIPHERAL_STATUSES.map((status) => ({ value: status, label: t(`native.mock.status.${status}`) }));
  const scanKindOptions = SCAN_KINDS.map((kind) => ({ value: kind, label: t(`native.mock.scanKind.${kind}`) }));
  const markLabels = {
    cut: t('native.mock.mark.cut'),
    qr: t('native.mock.mark.qr'),
    drawer: t('native.mock.mark.drawer'),
    unknown: t('native.mock.mark.unknown'),
  };

  /** Šířka a kódová stránka tiskárny, na kterou úloha šla. */
  function profileOf(peripheralId) {
    const peripheral = snapshot.peripherals.find((p) => p.id === peripheralId);
    return MOCK_PRINTER_PROFILES[peripheral?.profileId] ?? { columns: 48, codePage: 'cp852' };
  }

  const previews = $derived(
    jobs.map((job) => {
      const profile = profileOf(job.peripheralId);
      return {
        job,
        columns: profile.columns,
        decoded: job.format === 'escpos' ? decodeEscPos(job.bytes, profile) : null,
      };
    }),
  );

  function setCapability(name, enabled) {
    controls.setCapability(name, enabled);
    // Aplikace se řídí seznamem z `info` — po změně ho načíst znovu.
    nativeStore.load({ refresh: true });
  }

  function sendScan() {
    if (!canScan) return;
    if (controls.emitScan({ kind: scanKind, data: scanData })) scanData = '';
  }

  function onScanKeydown(event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      sendScan();
    }
  }

  async function printTestReceipt() {
    error = null;
    const profile = profileOf(MOCK_PRINTER_ID);
    try {
      await nativeCall('print.submit', {
        jobId: randomUuid(),
        peripheralId: MOCK_PRINTER_ID,
        format: 'escpos',
        data: bytesToBase64(buildTestReceipt(profile)),
      });
    } catch (e) {
      error = t('native.mock.error', { code: e.code, message: e.message });
    }
  }

  function download(job) {
    saveBlob(new Blob([job.bytes], { type: 'application/octet-stream' }), `${job.jobId}.${job.format === 'pdf' ? 'pdf' : 'bin'}`);
  }

  function disable() {
    try {
      localStorage.removeItem(NATIVE_MOCK_FLAG);
    } catch {
      // úložiště nedostupné — po načtení stránky simulace stejně nenaběhne
    }
    window.location.reload();
  }
</script>

<div class="shpd-mock" data-testid="native-mock">
  {#if open}
    <section class="shpd-mock__panel" id="native-mock-panel" aria-label={t('native.mock.banner')} data-testid="native-mock-panel">
      <dl class="shpd-mock__facts">
        <dt>{t('native.mock.dataSource')}</dt>
        <dd class="shpd-mock__code" data-testid="native-mock-datasource">{snapshot.dataSource}</dd>
        <dt>{t('native.mock.mode')}</dt>
        <dd>{t(`native.mock.modes.${snapshot.mode}`)}</dd>
        <dt>{t('native.mock.deviceId')}</dt>
        <dd class="shpd-mock__code">{deviceId ?? '…'}</dd>
      </dl>

      {#if !controls.canSign()}
        <p class="shpd-mock__note">{t('native.mock.insecure')}</p>
      {/if}

      <h3 class="shpd-mock__heading">{t('native.mock.capabilities')}</h3>
      <div class="shpd-mock__caps">
        {#each CAPABILITIES as capability (capability)}
          <Checkbox
            label={capability}
            checked={snapshot.storedCapabilities.includes(capability)}
            onchange={(e) => setCapability(capability, e.currentTarget.checked)}
          />
        {/each}
      </div>

      <h3 class="shpd-mock__heading">{t('native.mock.peripherals')}</h3>
      {#each snapshot.peripherals as peripheral (peripheral.id)}
        <div class="shpd-mock__row">
          <span class="shpd-mock__name">{peripheral.name} <span class="shpd-mock__code">{peripheral.id}</span></span>
          <SegmentedControl
            label={peripheral.name}
            options={statusOptions}
            value={peripheral.status}
            onchange={(status) => controls.setPeripheralStatus(peripheral.id, status)}
            data-testid="native-mock-status-{peripheral.id}"
          />
        </div>
      {/each}

      <h3 class="shpd-mock__heading">
        {t('native.mock.scanner')}
        <span class="shpd-mock__count" data-testid="native-mock-subscribers">{t('native.mock.subscribers', { count: subscribers })}</span>
      </h3>
      <div class="shpd-mock__scan">
        <input
          class="shpd-mock__input"
          type="text"
          bind:value={scanData}
          placeholder={t('native.mock.scanPlaceholder')}
          aria-label={t('native.mock.scanPlaceholder')}
          onkeydown={onScanKeydown}
          autocorrect="off"
          autocapitalize="off"
          spellcheck="false"
          data-testid="native-mock-scan-input"
        />
        <div class="shpd-mock__scan-kind">
          <Select bind:value={scanKind} options={scanKindOptions} required />
        </div>
        <Button label={t('native.mock.scanSend')} variant="secondary" disabled={!canScan} onclick={sendScan} testid="native-mock-scan-send" />
      </div>

      <h3 class="shpd-mock__heading">{t('native.mock.jobs')}</h3>
      <div class="shpd-mock__actions">
        <Button label={t('native.mock.testReceipt')} icon={iconPrint} variant="secondary" onclick={printTestReceipt} testid="native-mock-test-receipt" />
      </div>
      {#if error}
        <p class="shpd-mock__note" role="alert">{error}</p>
      {/if}
      {#if previews.length === 0}
        <p class="shpd-mock__empty">{t('native.mock.jobsEmpty')}</p>
      {/if}
      {#each previews as preview (preview.job)}
        <div class="shpd-mock__job" data-testid="native-mock-job">
          <div class="shpd-mock__job-head">
            <span class="shpd-mock__job-meta">
              {formatDateTime(preview.job.at)} · {t('native.mock.jobMeta', { size: preview.job.bytes.length, copies: preview.job.copies })}{preview.job.reprint ? ` · ${t('native.mock.reprint')}` : ''}
            </span>
            <Button label={t('native.mock.download')} icon={iconDownload} variant="ghost" size="sm" onclick={() => download(preview.job)} />
          </div>
          {#if preview.decoded}
            <ReceiptPreview decoded={preview.decoded} columns={preview.columns} labels={markLabels} />
          {:else}
            <p class="shpd-mock__empty">{t('native.mock.pdfNoPreview')}</p>
          {/if}
        </div>
      {/each}

      <div class="shpd-mock__footer">
        <Button label={t('native.mock.disable')} variant="danger" onclick={disable} testid="native-mock-disable" />
      </div>
    </section>
  {/if}

  <PageBanner data-testid="native-mock-banner">
    <button
      type="button"
      class="shpd-mock__toggle"
      aria-expanded={open}
      aria-controls="native-mock-panel"
      onclick={() => (open = !open)}
      data-testid="native-mock-toggle"
    >
      {t('native.mock.banner')}
      <Icon icon={open ? iconChevronDown : iconChevronUp} size="sm" />
    </button>
  </PageBanner>
</div>

<style>
  /* Nad dialogy (Modal 1000) — čtečka z panelu musí jít i do otevřeného dialogu. */
  .shpd-mock {
    position: fixed;
    right: var(--shpd-space-sm);
    bottom: var(--shpd-space-sm);
    z-index: 1100;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: var(--shpd-space-xs);
    max-width: calc(100vw - 2 * var(--shpd-space-sm));
  }

  .shpd-mock__toggle {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: 0;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    cursor: pointer;
  }

  .shpd-mock__panel {
    box-sizing: border-box;
    width: 34rem;
    max-width: 100%;
    max-height: calc(100vh - 5rem);
    max-height: calc(100dvh - 5rem);
    padding: var(--shpd-space-md);
    overflow: auto;
    border: 1px solid var(--shpd-color-border-strong);
    border-radius: var(--shpd-radius-lg);
    background: var(--shpd-color-bg-secondary);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-body);
    box-shadow: var(--shpd-shadow-lg);
  }

  .shpd-mock__facts {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
    margin: 0;
  }

  .shpd-mock__facts dt { color: var(--shpd-color-text-secondary); }
  .shpd-mock__facts dd { margin: 0; overflow-wrap: anywhere; }

  .shpd-mock__code {
    font-family: var(--shpd-font-family-mono);
    font-size: var(--shpd-font-size-meta);
  }

  .shpd-mock__heading {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
    margin: var(--shpd-space-md) 0 var(--shpd-space-sm);
    font-size: var(--shpd-font-size-body);
    font-weight: var(--shpd-font-weight-strong);
  }

  .shpd-mock__count {
    color: var(--shpd-color-text-secondary);
    font-weight: var(--shpd-font-weight-normal);
  }

  .shpd-mock__caps {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
  }

  .shpd-mock__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-mock__name .shpd-mock__code { color: var(--shpd-color-text-secondary); }

  .shpd-mock__scan {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
  }

  .shpd-mock__input {
    flex: 1 1 10rem;
    min-width: 0;
    height: var(--shpd-control-height);
    padding: 0 var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border-control);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    font-family: var(--shpd-font-family);
    font-size: var(--shpd-font-size-field);
    box-sizing: border-box;
  }

  .shpd-mock__input:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-mock__scan-kind { flex: 0 0 9rem; }

  .shpd-mock__actions { margin-bottom: var(--shpd-space-sm); }

  .shpd-mock__note {
    margin: var(--shpd-space-sm) 0;
    color: var(--shpd-color-warning-text);
  }

  .shpd-mock__empty {
    margin: var(--shpd-space-sm) 0;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-mock__job { margin-bottom: var(--shpd-space-md); }

  .shpd-mock__job-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-mock__job-meta {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-meta);
  }

  .shpd-mock__footer {
    display: flex;
    justify-content: flex-end;
    margin-top: var(--shpd-space-md);
    padding-top: var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
  }
</style>
