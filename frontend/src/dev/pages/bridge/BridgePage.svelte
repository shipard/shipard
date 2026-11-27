<script>
  // Stránka Most (#177 D29): ověří most nativní aplikace `window.shipardNative`
  // kterékoli platformy proti kontraktu docs/native-bridge.md. Otevírá se
  // v nativní aplikaci (vývojová sestavení dovolí přidat origin dev serveru).
  //
  // Dashboard je stránka mimo zdroj dat — `info` tu čeká `dataSource: null`
  // (D33). Simulace mostu se tady nespouští; stránka jen přepíná příznak,
  // kterým se simulace zapne v aplikaci na tomto serveru (U11).
  //
  // Z aplikace bere jen native/bridge.js, native/contract.js, native/bytes.js,
  // native/escpos/* a ReceiptPreview — nic z toho netahá t() ani api/client.js.
  import { onMount } from 'svelte';
  import Button from '../../../components/ui/Button.svelte';
  import TableScroll from '../../../components/ui/TableScroll.svelte';
  import ReceiptPreview from '../../../components/native/ReceiptPreview.svelte';
  import Crumbs from '../../components/Crumbs.svelte';
  import { iconRefresh, iconPlay, iconPrint } from '../../../icons.js';
  import { t } from '../../i18n/index.js';
  import { formatTime } from '../../format.js';
  import { NATIVE_MOCK_FLAG, isNativeAvailable, isNativeMock, nativeCall, onNativeEvent } from '../../../native/bridge.js';
  import { validateInfo } from '../../../native/contract.js';
  import { bytesToBase64, randomUuid } from '../../../native/bytes.js';
  import { CODE_PAGES, DEFAULT_CODE_PAGE } from '../../../native/escpos/codePages.js';
  import { decodeEscPos } from '../../../native/escpos/decode.js';
  import { buildTestReceipt } from '../../../native/escpos/testReceipt.js';
  import { runContractChecks } from '../../bridge/checks.js';

  const EVENT_LIMIT = 50;
  const origin = window.location.origin;

  let available = $state(isNativeAvailable());
  let mockOn = $state(readFlag());
  let storageError = $state(false);

  let info = $state(null);
  let infoError = $state(null);
  let checks = $state(null);
  let checking = $state(false);
  let peripherals = $state(null);
  let peripheralsError = $state(null);

  let codePage = $state(DEFAULT_CODE_PAGE);
  let columns = $state(48);
  let printing = $state(false);
  let lastPrint = $state(null); // {jobId, peripheral, decoded, columns, result: {kind, text}, duplicate: {kind, text}|null}

  let events = $state([]);
  let eventSeq = 0;

  const infoFindings = $derived(info ? validateInfo(info) : []);
  const capabilities = $derived(Array.isArray(info?.capabilities) ? info.capabilities : []);
  const markLabels = {
    cut: t('bridge.mark.cut'),
    qr: t('bridge.mark.qr'),
    drawer: t('bridge.mark.drawer'),
    unknown: t('bridge.mark.unknown'),
  };

  function readFlag() {
    try {
      return localStorage.getItem(NATIVE_MOCK_FLAG) === '1';
    } catch {
      return false;
    }
  }

  function toggleMock() {
    try {
      if (mockOn) localStorage.removeItem(NATIVE_MOCK_FLAG);
      else localStorage.setItem(NATIVE_MOCK_FLAG, '1');
      storageError = false;
    } catch {
      storageError = true;
    }
    mockOn = readFlag();
  }

  const errorText = (e) => `${e?.code ?? 'ERROR'}: ${e?.message ?? String(e)}`;

  async function load() {
    available = isNativeAvailable();
    if (!available) return;
    try {
      info = await nativeCall('info');
      infoError = null;
    } catch (e) {
      info = null;
      infoError = errorText(e);
    }
    if (info && Array.isArray(info.capabilities) && info.capabilities.includes('peripherals')) {
      try {
        const list = await nativeCall('peripherals.list');
        peripherals = Array.isArray(list.peripherals) ? list.peripherals : [];
        peripheralsError = null;
      } catch (e) {
        peripherals = null;
        peripheralsError = errorText(e);
      }
    } else {
      peripherals = null;
      peripheralsError = null;
    }
  }

  async function runChecks() {
    checking = true;
    try {
      // Napřímo, bez obálky — kontroly chtějí vidět chybu tak, jak ji aplikace zamítla.
      checks = await runContractChecks((method, params) => window.shipardNative.call(method, params));
    } finally {
      checking = false;
    }
  }

  function peripheralDetails(peripheral) {
    if (peripheral.kind === 'printer') return peripheral.profileId ?? '';
    return Array.isArray(peripheral.reads) ? peripheral.reads.join(', ') : '';
  }

  async function testPrint(peripheral) {
    printing = true;
    const width = Number.isInteger(columns) && columns >= 16 && columns <= 96 ? columns : 48;
    const bytes = buildTestReceipt({ codePage, columns: width });
    const jobId = randomUuid();
    const print = { jobId, peripheral, data: bytesToBase64(bytes), decoded: decodeEscPos(bytes, { codePage, columns: width }), columns: width, result: null, duplicate: null };
    try {
      await nativeCall('print.submit', { jobId, peripheralId: peripheral.id, format: 'escpos', data: print.data });
      print.result = { kind: 'success', text: t('bridge.print.sent', { name: peripheral.name, jobId }) };
    } catch (e) {
      print.result = { kind: 'danger', text: t('bridge.print.failed', { error: errorText(e) }) };
    }
    lastPrint = print;
    printing = false;
  }

  // Stejné jobId podruhé a bez `reprint` — aplikace musí odmítnout, nic se nevytiskne.
  async function checkDuplicate() {
    if (!lastPrint) return;
    printing = true;
    let duplicate;
    try {
      await nativeCall('print.submit', { jobId: lastPrint.jobId, peripheralId: lastPrint.peripheral.id, format: 'escpos', data: lastPrint.data });
      duplicate = { kind: 'danger', text: t('bridge.print.duplicateSent') };
    } catch (e) {
      duplicate = e?.code === 'DUPLICATE_JOB'
        ? { kind: 'success', text: t('bridge.print.duplicateOk') }
        : { kind: 'danger', text: t('bridge.print.duplicateOther', { error: errorText(e) }) };
    }
    lastPrint = { ...lastPrint, duplicate };
    printing = false;
  }

  function pushEvent(name, detail) {
    events = [{ seq: ++eventSeq, at: new Date().toISOString(), name, detail }, ...events].slice(0, EVENT_LIMIT);
  }

  onMount(() => {
    load();
    const stopScan = onNativeEvent('scan', (detail) => pushEvent('shipard:scan', detail));
    const stopPeripheral = onNativeEvent('peripheral', (detail) => {
      pushEvent('shipard:peripheral', detail);
      load();
    });
    return () => {
      stopScan();
      stopPeripheral();
    };
  });
</script>

<Crumbs items={[{ label: t('app.nav.bridge') }]} />

<div class="dev-page__head">
  <h1>{t('app.nav.bridge')}</h1>
  <span class="dev-page__spacer"></span>
  <Button label={t('common.refresh')} icon={iconRefresh} variant="secondary" size="sm" onclick={load} testid="bridge-refresh" />
</div>
<p class="dev-muted bridge-intro">{t('bridge.intro')}</p>

<div class="dev-card" data-testid="bridge-status">
  <h2 class="dev-card__title">{t('bridge.status.title')}</h2>
  {#if available}
    <p>
      <span class="dev-badge dev-badge--free">{t('bridge.status.present')}</span>
      {#if isNativeMock()}<span class="dev-badge dev-badge--outline">{t('bridge.status.mock')}</span>{/if}
    </p>
  {:else}
    <p><span class="dev-badge dev-badge--outline">{t('bridge.status.absent')}</span></p>
    <p>{t('bridge.status.absentHelp', { origin })}</p>
  {/if}
</div>

<div class="dev-card" data-testid="bridge-mock">
  <h2 class="dev-card__title">{t('bridge.mock.title')}</h2>
  <p>{t('bridge.mock.help', { origin })}</p>
  <div class="dev-btnrow">
    <span class="dev-badge {mockOn ? 'dev-badge--free' : 'dev-badge--outline'}" data-testid="bridge-mock-state">{t(mockOn ? 'bridge.mock.on' : 'bridge.mock.off')}</span>
    <Button label={t(mockOn ? 'bridge.mock.disable' : 'bridge.mock.enable')} variant="secondary" size="sm" onclick={toggleMock} testid="bridge-mock-toggle" />
  </div>
  {#if storageError}
    <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{t('bridge.mock.storageError')}</span></div>
  {/if}
</div>

{#if available}
  <div class="dev-card" data-testid="bridge-info">
    <h2 class="dev-card__title">{t('bridge.info.title')}</h2>
    {#if infoError}
      <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{t('bridge.info.failed', { error: infoError })}</span></div>
    {:else if !info}
      <div class="dev-empty">{t('common.loading')}</div>
    {:else}
      <p class="bridge-caps">
        {#each capabilities as capability (capability)}
          <span class="dev-badge dev-badge--outline">{capability}</span>
        {:else}
          <span class="dev-muted">{t('bridge.info.noCapabilities')}</span>
        {/each}
      </p>
      {#if infoFindings.length}
        <div class="dev-alert dev-alert--warning" data-testid="bridge-info-findings">
          <span class="dev-alert__text">
            {t('bridge.info.findings')}
            <ul class="dev-blockers">{#each infoFindings as finding, i (i)}<li><code>{finding.path}</code> {finding.message}</li>{/each}</ul>
          </span>
        </div>
      {:else}
        <p class="dev-muted">{t('bridge.info.valid')}</p>
      {/if}
      <pre class="bridge-json">{JSON.stringify(info, null, 2)}</pre>
    {/if}
  </div>

  <div class="dev-card" data-testid="bridge-checks">
    <div class="dev-page__head">
      <h2 class="dev-card__title">{t('bridge.checks.title')}</h2>
      <span class="dev-page__spacer"></span>
      <Button label={t('bridge.checks.run')} icon={iconPlay} variant="primary" size="sm" loading={checking} onclick={runChecks} testid="bridge-checks-run" />
    </div>
    <p class="dev-muted">{t('bridge.checks.help')}</p>
    {#if checks}
      <div class="dev-table-wrap">
        <TableScroll>
          <table class="dev-table">
            <thead>
              <tr>
                <th>{t('bridge.checks.col.check')}</th>
                <th>{t('bridge.checks.col.result')}</th>
                <th>{t('bridge.checks.col.details')}</th>
              </tr>
            </thead>
            <tbody>
              {#each checks as row (row.id)}
                <tr data-testid="bridge-check-{row.id}" data-status={row.status}>
                  <td>{t(`bridge.check.${row.id}`)}</td>
                  <td><span class="dev-lvl dev-lvl--{row.status === 'ok' ? 'ok' : row.status === 'failed' ? 'bad' : 'warn'}">{t(`bridge.result.${row.status}`)}</span></td>
                  <td>{#each row.detail as line, i (i)}<div class="dev-small"><code>{line}</code></div>{/each}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        </TableScroll>
      </div>
    {/if}
  </div>

  <div class="dev-card" data-testid="bridge-peripherals">
    <h2 class="dev-card__title">{t('bridge.peripherals.title')}</h2>
    {#if peripheralsError}
      <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{peripheralsError}</span></div>
    {:else if peripherals === null}
      <div class="dev-empty">{t('bridge.peripherals.noCapability')}</div>
    {:else if peripherals.length === 0}
      <div class="dev-empty">{t('bridge.peripherals.empty')}</div>
    {:else}
      <div class="dev-form__row bridge-print-options">
        <div class="dev-field">
          <label for="bridge-code-page">{t('bridge.print.codePage')}</label>
          <select id="bridge-code-page" bind:value={codePage} data-testid="bridge-code-page">
            {#each Object.entries(CODE_PAGES) as [name, page] (name)}
              <option value={name}>{page.label}</option>
            {/each}
          </select>
        </div>
        <div class="dev-field">
          <label for="bridge-columns">{t('bridge.print.columns')}</label>
          <input id="bridge-columns" type="number" min="16" max="96" step="1" bind:value={columns} autocorrect="off" autocapitalize="off" spellcheck="false" data-testid="bridge-columns" />
        </div>
      </div>
      <div class="dev-table-wrap">
        <TableScroll>
          <table class="dev-table">
            <thead>
              <tr>
                <th>{t('bridge.peripherals.col.name')}</th>
                <th>ID</th>
                <th>{t('bridge.peripherals.col.kind')}</th>
                <th>{t('bridge.peripherals.col.transport')}</th>
                <th>{t('bridge.peripherals.col.status')}</th>
                <th>{t('bridge.peripherals.col.details')}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {#each peripherals as peripheral (peripheral.id)}
                <tr>
                  <td>{peripheral.name}</td>
                  <td><code>{peripheral.id}</code></td>
                  <td>{peripheral.kind}</td>
                  <td>{peripheral.transport}</td>
                  <td>{peripheral.status}</td>
                  <td><code>{peripheralDetails(peripheral)}</code></td>
                  <td class="dev-actions">
                    <span class="dev-actions__inner">
                      {#if peripheral.kind === 'printer'}
                        <Button label={t('bridge.print.test')} icon={iconPrint} variant="secondary" size="sm" disabled={printing} onclick={() => testPrint(peripheral)} testid="bridge-test-print-{peripheral.id}" />
                      {/if}
                    </span>
                  </td>
                </tr>
              {/each}
            </tbody>
          </table>
        </TableScroll>
      </div>

      {#if lastPrint}
        <div class="bridge-print" data-testid="bridge-print-result">
          <div class="dev-alert dev-alert--{lastPrint.result.kind}"><span class="dev-alert__text">{lastPrint.result.text}</span></div>
          {#if lastPrint.result.kind === 'success'}
            <div class="dev-btnrow">
              <Button label={t('bridge.print.duplicate')} variant="secondary" size="sm" disabled={printing} onclick={checkDuplicate} testid="bridge-duplicate" />
            </div>
          {/if}
          {#if lastPrint.duplicate}
            <div class="dev-alert dev-alert--{lastPrint.duplicate.kind}" data-testid="bridge-duplicate-result"><span class="dev-alert__text">{lastPrint.duplicate.text}</span></div>
          {/if}
          <p class="dev-muted">{t('bridge.print.preview')}</p>
          <ReceiptPreview decoded={lastPrint.decoded} columns={lastPrint.columns} labels={markLabels} />
        </div>
      {/if}
    {/if}
  </div>

  <div class="dev-card" data-testid="bridge-events">
    <div class="dev-page__head">
      <h2 class="dev-card__title">{t('bridge.events.title')}</h2>
      <span class="dev-page__spacer"></span>
      <Button label={t('bridge.events.clear')} variant="secondary" size="sm" disabled={events.length === 0} onclick={() => (events = [])} />
    </div>
    {#if events.length === 0}
      <div class="dev-empty">{t('bridge.events.empty')}</div>
    {:else}
      <div class="dev-table-wrap">
        <TableScroll>
          <table class="dev-table">
            <thead>
              <tr>
                <th>{t('bridge.events.col.time')}</th>
                <th>{t('bridge.events.col.event')}</th>
                <th>{t('bridge.events.col.detail')}</th>
              </tr>
            </thead>
            <tbody>
              {#each events as event (event.seq)}
                <tr data-testid="bridge-event">
                  <td class="dev-nowrap">{formatTime(event.at)}</td>
                  <td><code>{event.name}</code></td>
                  <td><code>{JSON.stringify(event.detail)}</code></td>
                </tr>
              {/each}
            </tbody>
          </table>
        </TableScroll>
      </div>
    {/if}
  </div>
{/if}

<style>
  .bridge-intro { margin-bottom: var(--shpd-space-md); }

  .bridge-caps {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs);
  }

  .bridge-json {
    margin: var(--shpd-space-sm) 0 0;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    overflow: auto;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg-secondary);
    font-family: var(--dev-font-mono);
    font-size: var(--shpd-font-size-sm);
  }

  .bridge-print-options { margin-bottom: var(--shpd-space-sm); }

  .bridge-print {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-md);
  }
</style>
