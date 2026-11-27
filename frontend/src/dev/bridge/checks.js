/**
 * Kontroly kontraktu mostu nativní aplikace pro stránku Most (#177 D29) —
 * čisté funkce nad funkcí `call`, testovatelné v Node proti simulaci.
 * Nic netisknou: ověřují tvar `info`, chybové kódy a seznam periferií.
 *
 * Volají most **napřímo** (ne přes `nativeCall`), aby viděly chybu tak,
 * jak ji aplikace zamítla — obálka by chybějící zprávu nebo kód dorovnala.
 * Podrobnosti jsou text pro vývojáře, anglicky jako zprávy validátorů.
 */
import { validateError, validateInfo, validatePeripheralList } from '../../native/contract.js';

/** Metoda, kterou kontrakt nezná — aplikace musí odpovědět `UNKNOWN_METHOD`. */
export const UNKNOWN_METHOD_PROBE = 'shipard.contract-check';

const findingText = (finding) => (finding.path === '' ? finding.message : `${finding.path}: ${finding.message}`);

function describe(value) {
  try {
    return JSON.stringify(value) ?? String(value);
  } catch {
    return String(value);
  }
}

const ok = (id, detail = []) => ({ id, status: 'ok', detail });
const failed = (id, detail) => ({ id, status: 'failed', detail });
const skipped = (id, detail) => ({ id, status: 'skipped', detail });

/** Volání má skončit chybou s daným kódem a tvarem podle `validateError`. */
async function expectError(id, call, method, params, expected) {
  let result;
  try {
    result = await (params === undefined ? call(method) : call(method, params));
  } catch (error) {
    const detail = validateError(error).map(findingText);
    if (error?.code !== expected) detail.unshift(`expected ${expected}, got ${describe(error?.code ?? error)}`);
    return detail.length ? failed(id, detail) : ok(id, [expected]);
  }
  return failed(id, [`expected ${expected}, the call resolved with ${describe(result)}`]);
}

/**
 * @param {(method: string, params?: object) => Promise<object>} call  `window.shipardNative.call`
 * @param {{outsideDataSource?: boolean}} [options]  stránka mimo zdroj dat (vývojářský dashboard) čeká `dataSource: null` (D33)
 * @returns {Promise<{id: string, status: 'ok'|'failed'|'skipped', detail: string[]}[]>}
 */
export async function runContractChecks(call, { outsideDataSource = true } = {}) {
  const rows = [];
  let info = null;

  try {
    info = await call('info');
    const findings = validateInfo(info).map(findingText);
    rows.push(findings.length ? failed('info', findings) : ok('info'));
  } catch (error) {
    rows.push(failed('info', [`info rejected with ${describe(error?.code ?? error)}`, ...validateError(error).map(findingText)]));
  }

  if (outsideDataSource) {
    if (info === null || typeof info !== 'object') {
      rows.push(skipped('infoOutsideDataSource', ['info is not available']));
    } else {
      const detail = [];
      if (info.dataSource !== null) detail.push(`dataSource: expected null, got ${describe(info.dataSource)}`);
      if (info.deviceId !== null) detail.push(`deviceId: expected null, got ${describe(info.deviceId)}`);
      if (info.mode !== 'unregistered') detail.push(`mode: expected unregistered, got ${describe(info.mode)}`);
      rows.push(detail.length ? failed('infoOutsideDataSource', detail) : ok('infoOutsideDataSource'));
    }
  }

  rows.push(await expectError('unknownMethod', call, UNKNOWN_METHOD_PROBE, undefined, 'UNKNOWN_METHOD'));

  const capabilities = Array.isArray(info?.capabilities) ? info.capabilities : [];

  if (capabilities.includes('print.escpos') || capabilities.includes('print.pdf')) {
    rows.push(await expectError('printInvalidParams', call, 'print.submit', undefined, 'INVALID_PARAMS'));
  } else {
    rows.push(skipped('printInvalidParams', ['no print.escpos or print.pdf capability']));
  }

  if (capabilities.includes('peripherals')) {
    try {
      const findings = validatePeripheralList(await call('peripherals.list')).map(findingText);
      rows.push(findings.length ? failed('peripheralsList', findings) : ok('peripheralsList'));
    } catch (error) {
      rows.push(failed('peripheralsList', [`peripherals.list rejected with ${describe(error?.code ?? error)}`, ...validateError(error).map(findingText)]));
    }
  } else {
    rows.push(skipped('peripheralsList', ['no peripherals capability']));
  }

  return rows;
}
