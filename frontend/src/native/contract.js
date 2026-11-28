/**
 * Kontrakt mostu verze 1 jako data a čisté validátory (docs/native-bridge.md).
 * Používá je simulace (sama sebe kontroluje v testech) a stránka Most ve
 * vývojářském dashboardu. Nic neimportuje.
 *
 * Validátor vrací seznam nálezů `{path, message}`; prázdný seznam = tvar
 * odpovídá. Zprávy jsou pro vývojáře, anglicky jako `message` chyby mostu.
 * Kontrakt dovoluje přidat hodnotu výčtu bez změny verze (§2.4): neznámá
 * capability a neznámý `transport` proto nález nejsou, ostatní výčty se
 * hlásí — stránka Most ověřuje aplikaci proti verzi, kterou web zná.
 */

export const API_VERSION = 1;

/** §2.5 */
export const CAPABILITIES = ['device', 'peripherals', 'print.escpos', 'print.pdf', 'scanner', 'profiles', 'auth.external'];

/** §2.3 a kódy metod z §3 */
export const ERROR_CODES = [
  'UNKNOWN_METHOD',
  'INVALID_PARAMS',
  'NOT_SUPPORTED',
  'NOT_REGISTERED',
  'DEVICE_REVOKED',
  'NETWORK_ERROR',
  'SERVER_ERROR',
  'CANCELLED',
  'INTERNAL_ERROR',
  'PIN_POLICY',
  'PIN_INVALID',
  'TICKET_INVALID',
  'PERIPHERAL_NOT_FOUND',
  'PERIPHERAL_UNAVAILABLE',
  'UNSUPPORTED_FORMAT',
  'DUPLICATE_JOB',
  'PAYLOAD_TOO_LARGE',
  'PRINTER_ERROR',
  'TIMEOUT',
];

/** `mock` hlásí jen simulace pro vývoj (#177 U12). */
export const PLATFORMS = ['macos', 'ipados', 'ios', 'mock'];
export const KEY_STORAGES = ['secureEnclave', 'keychain', 'mock'];
export const MODES = ['unregistered', 'personal', 'shared'];

export const PERIPHERAL_KINDS = ['printer', 'scanner'];
export const PERIPHERAL_STATUSES = ['ready', 'offline', 'error'];
export const PERIPHERAL_TRANSPORTS = ['tcp', 'ble', 'mfi', 'usb', 'serial'];
export const SCAN_KINDS = ['barcode', 'rfid'];
export const PRINT_FORMATS = ['escpos', 'pdf'];

/** Limity `print.submit` (#177 U8). */
export const PRINT_MAX_BYTES = 5 * 1024 * 1024;
export const PRINT_MAX_COPIES = 10;

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const ERROR_CODE = /^[A-Z][A-Z0-9_]*$/;

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isText(value) {
  return typeof value === 'string' && value !== '';
}

function collector() {
  const findings = [];
  return {
    findings,
    add(path, message) {
      findings.push({ path, message });
    },
    text(obj, key, prefix = '') {
      if (!isText(obj[key])) this.add(prefix + key, 'expected a non-empty string');
    },
    oneOf(obj, key, allowed, prefix = '') {
      if (!allowed.includes(obj[key])) this.add(prefix + key, `expected one of: ${allowed.join(', ')}`);
    },
  };
}

/**
 * Výsledek `info` (§3.1).
 *
 * @param {unknown} info
 * @returns {{path: string, message: string}[]}
 */
export function validateInfo(info) {
  const c = collector();
  if (!isObject(info)) {
    c.add('', 'expected an object');
    return c.findings;
  }

  if (!Number.isInteger(info.apiVersion) || info.apiVersion < 1) {
    c.add('apiVersion', 'expected a positive integer');
  }
  if (!isObject(info.app)) {
    c.add('app', 'expected an object');
  } else {
    c.text(info.app, 'version', 'app.');
    c.text(info.app, 'build', 'app.');
  }
  c.oneOf(info, 'platform', PLATFORMS);
  c.text(info, 'osVersion');
  c.oneOf(info, 'keyStorage', KEY_STORAGES);
  c.oneOf(info, 'mode', MODES);

  if (info.dataSource !== null && !isText(info.dataSource)) {
    c.add('dataSource', 'expected a string or null');
  }
  if (info.deviceId !== null && !(typeof info.deviceId === 'string' && UUID.test(info.deviceId))) {
    c.add('deviceId', 'expected a UUID or null');
  }
  // Stránka mimo zdroj dat (§1): bez identity a vždy unregistered.
  if (info.dataSource === null) {
    if (info.deviceId !== null && info.deviceId !== undefined) c.add('deviceId', 'expected null outside a data source');
    if (MODES.includes(info.mode) && info.mode !== 'unregistered') c.add('mode', 'expected unregistered outside a data source');
  }

  if (info.profile !== null) {
    if (!isObject(info.profile)) {
      c.add('profile', 'expected an object or null');
    } else {
      if (!(typeof info.profile.id === 'string' && UUID.test(info.profile.id))) c.add('profile.id', 'expected a UUID');
      if (info.profile.userId !== null && !Number.isInteger(info.profile.userId)) {
        c.add('profile.userId', 'expected an integer or null');
      }
      if (MODES.includes(info.mode) && info.mode !== 'shared') c.add('profile', 'expected null outside the shared mode');
    }
  }

  if (!Array.isArray(info.capabilities)) {
    c.add('capabilities', 'expected an array');
  } else {
    info.capabilities.forEach((capability, index) => {
      if (!isText(capability)) c.add(`capabilities.${index}`, 'expected a non-empty string');
    });
    if (new Set(info.capabilities).size !== info.capabilities.length) {
      c.add('capabilities', 'expected no duplicates');
    }
  }
  return c.findings;
}

/**
 * Popis periferie z `peripherals.list` (§3.5).
 *
 * @param {unknown} peripheral
 * @returns {{path: string, message: string}[]}
 */
export function validatePeripheral(peripheral) {
  const c = collector();
  if (!isObject(peripheral)) {
    c.add('', 'expected an object');
    return c.findings;
  }
  c.text(peripheral, 'id');
  c.oneOf(peripheral, 'kind', PERIPHERAL_KINDS);
  c.text(peripheral, 'name');
  // Výčet transportů je otevřený (§2.4) — hlídá se jen typ.
  c.text(peripheral, 'transport');
  c.oneOf(peripheral, 'status', PERIPHERAL_STATUSES);

  if (peripheral.kind === 'printer') {
    c.text(peripheral, 'profileId');
  }
  if (peripheral.kind === 'scanner') {
    if (!Array.isArray(peripheral.reads) || peripheral.reads.length === 0) {
      c.add('reads', 'expected a non-empty array');
    } else {
      peripheral.reads.forEach((kind, index) => {
        if (!SCAN_KINDS.includes(kind)) c.add(`reads.${index}`, `expected one of: ${SCAN_KINDS.join(', ')}`);
      });
    }
  }
  return c.findings;
}

/**
 * Výsledek `peripherals.list` jako celek — nálezy periferií s cestou
 * `peripherals.<index>.<pole>`.
 *
 * @param {unknown} result
 * @returns {{path: string, message: string}[]}
 */
export function validatePeripheralList(result) {
  if (!isObject(result) || !Array.isArray(result.peripherals)) {
    return [{ path: 'peripherals', message: 'expected an array' }];
  }
  const findings = [];
  const seen = new Set();
  result.peripherals.forEach((peripheral, index) => {
    for (const finding of validatePeripheral(peripheral)) {
      findings.push({ path: ['peripherals', index, finding.path].filter((part) => part !== '').join('.'), message: finding.message });
    }
    if (isObject(peripheral) && isText(peripheral.id)) {
      if (seen.has(peripheral.id)) findings.push({ path: `peripherals.${index}.id`, message: 'expected a unique id' });
      seen.add(peripheral.id);
    }
  });
  return findings;
}

/**
 * Chyba mostu `{code, message, details?}` (§2.3) — tak, jak ji most
 * zamítl, i jako `NativeBridgeError`.
 *
 * @param {unknown} error
 * @returns {{path: string, message: string}[]}
 */
export function validateError(error) {
  const c = collector();
  if (!isObject(error)) {
    c.add('', 'expected an object');
    return c.findings;
  }
  if (typeof error.code !== 'string' || !ERROR_CODE.test(error.code)) {
    c.add('code', 'expected an upper-case code');
  } else if (!ERROR_CODES.includes(error.code)) {
    c.add('code', `unknown code ${error.code}`);
  }
  if (typeof error.message !== 'string') {
    c.add('message', 'expected a string');
  }
  if (error.details !== undefined && error.details !== null && !isObject(error.details)) {
    c.add('details', 'expected an object');
  }
  return c.findings;
}
