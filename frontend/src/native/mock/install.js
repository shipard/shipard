/**
 * Simulace mostu nativní aplikace v prohlížeči (#177 D29, U11, U12) —
 * `window.shipardNative` podle kontraktu, aby šla webová strana vyvíjet
 * a zkoušet bez aplikace i bez tiskárny.
 *
 * Samostatný chunk: `main.js` ho načte dynamickým importem, jen když je
 * v `localStorage` příznak `shpd_native_mock = "1"` a skutečný most na
 * stránce není. Prostředí (adresa API, úložiště, `fetch`, `crypto`, cíl
 * událostí) jde parametrem — v testech běží v Node bez prohlížeče.
 *
 * Co umí: `info`; `device.register` se skutečným klíčem P-256 a podpisem
 * (U4); `device.sync` jako podepsané `GET /_devices/self` a `PUT
 * /_devices/self/peripherals` (D32); `peripherals.list`; `print.submit`
 * s náhledem místo tisku; události `shipard:scan` a `shipard:peripheral`
 * z ovládání. `device.enroll`, `session.lock`, `session.take`
 * a `auth.external` vrací `NOT_SUPPORTED` (tasky 6 a 7).
 */
import { PERIPHERAL_STATUSES, PRINT_FORMATS, PRINT_MAX_BYTES, PRINT_MAX_COPIES, SCAN_KINDS } from '../contract.js';
import { base64DecodedLength, base64ToBytes, isBase64 } from '../bytes.js';
import { createDeviceKey } from './deviceKey.js';
import { loadState, modeOf, saveState } from './state.js';

/** Kolik posledních `jobId` si simulace pamatuje (ochrana proti dvojímu tisku, kontrakt §5). */
const JOB_MEMORY = 100;
/** Kolik posledních úloh drží pro náhled. */
const PREVIEW_LIMIT = 20;

const KNOWN_METHODS = [
  'info',
  'device.register',
  'device.sync',
  'device.enroll',
  'peripherals.list',
  'print.submit',
  'session.lock',
  'session.take',
  'auth.external',
];

/** Metody dalších tasků (6 a 7) — simulace je zná, ale nenabízí. */
const NOT_IMPLEMENTED = ['device.enroll', 'session.lock', 'session.take', 'auth.external'];

const METHOD_CAPABILITY = {
  'device.register': 'device',
  'device.sync': 'device',
  'peripherals.list': 'peripherals',
};

function fail(code, message, details) {
  const error = { code, message };
  if (details !== undefined) error.details = details;
  return error;
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** ISO 8601 se zónou z místního času: `2026-11-27T10:15:42+01:00`. */
export function isoWithZone(date) {
  const pad = (n) => String(Math.trunc(Math.abs(n))).padStart(2, '0');
  const offset = -date.getTimezoneOffset();
  const sign = offset >= 0 ? '+' : '-';
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
    + `T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
    + `${sign}${pad(offset / 60)}:${pad(offset % 60)}`;
}

/**
 * @param {object} env
 * @param {string} env.apiBase  adresa API zdroje dat aktuální stránky (`location.origin + API_BASE_URL`) — identita zařízení i cíl podepsaných volání
 * @param {Storage} env.storage
 * @param {typeof fetch} env.fetch
 * @param {Crypto} env.crypto
 * @param {EventTarget} env.target  kam jdou události (`window`)
 * @param {() => Date} [env.now]
 * @returns {{bridge: {__mock: true, call: Function}, controls: object}}
 */
export function createMockBridge({ apiBase, storage, fetch, crypto, target, now = () => new Date() }) {
  const key = createDeviceKey({ apiBase, storage, crypto });
  let state = loadState(storage);
  const sentJobIds = [];
  const jobs = [];
  const listeners = new Set();

  function changed() {
    for (const listener of listeners) listener();
  }

  function persist() {
    saveState(storage, state);
    changed();
  }

  function emit(name, detail) {
    target.dispatchEvent(new CustomEvent(`shipard:${name}`, { detail }));
  }

  /** Co simulace nabízí: uložené capabilities, `device` jen když jde podepisovat. */
  function capabilities() {
    return state.capabilities.filter((capability) => capability !== 'device' || key.canSign());
  }

  function publicPeripherals() {
    return state.peripherals.map((peripheral) => ({ ...peripheral, ...(peripheral.reads ? { reads: [...peripheral.reads] } : {}) }));
  }

  async function info() {
    return {
      apiVersion: 1,
      app: { version: '0.0.0-mock', build: 'mock' },
      platform: 'mock',
      osVersion: 'mock',
      dataSource: apiBase,
      deviceId: await key.deviceId(),
      keyStorage: 'mock',
      mode: modeOf(state, apiBase),
      profile: null,
      capabilities: capabilities(),
    };
  }

  // --- podepsaná volání serveru (docs/native-bridge.md §7) ---

  async function api(method, path, { headers = {}, body } = {}) {
    let response;
    try {
      response = await fetch(apiBase + path, {
        method,
        headers: { Accept: 'application/json', ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...headers },
        body: body !== undefined ? JSON.stringify(body) : undefined,
        // Podepsané volání session uživatele nenese.
        credentials: 'omit',
      });
    } catch (error) {
      throw fail('NETWORK_ERROR', `Server is not reachable: ${error instanceof Error ? error.message : String(error)}`);
    }

    let payload = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }
    if (response.ok && payload?.success === true) return payload.data ?? {};

    const code = typeof payload?.error?.code === 'string' ? payload.error.code : null;
    if (code === 'DEVICE_UNKNOWN') {
      throw fail('NOT_REGISTERED', 'The device is not registered in this data source');
    }
    if (code === 'DEVICE_REVOKED') {
      // Zneplatnění je konečné: pryč jde identita jen tohoto zdroje dat (D33).
      key.forget();
      delete state.modes[apiBase];
      persist();
      throw fail('DEVICE_REVOKED', 'The server revoked the device');
    }
    throw fail('SERVER_ERROR', `Server responded with ${response.status}${code ? ` ${code}` : ''}`, { status: response.status, code });
  }

  async function signedCall(method, path, purpose, body) {
    const deviceId = await key.deviceId();
    const { challenge } = await api('POST', '/_devices/challenge', { body: { deviceId, purpose } });
    if (typeof challenge !== 'string' || challenge === '') {
      throw fail('SERVER_ERROR', 'Server did not return a challenge', { status: 200, code: null });
    }
    return api(method, path, {
      headers: {
        'X-Shipard-Device': deviceId,
        'X-Shipard-Challenge': challenge,
        'X-Shipard-Signature': await key.sign(purpose, challenge),
      },
      body,
    });
  }

  async function sync() {
    const self = await signedCall('GET', '/_devices/self', 'self');
    if (self.mode === 'personal' || self.mode === 'shared') {
      state.modes[apiBase] = self.mode;
      persist();
    }
    await signedCall('PUT', '/_devices/self/peripherals', 'peripherals', { peripherals: publicPeripherals() });
    return info();
  }

  // --- metody mostu ---

  async function deviceRegister(params) {
    if (typeof params.challenge !== 'string' || params.challenge === '') {
      throw fail('INVALID_PARAMS', 'challenge must be a non-empty string');
    }
    return {
      deviceId: await key.deviceId(),
      algorithm: 'ES256',
      publicKey: await key.publicKeySpki(),
      signature: await key.sign('register', params.challenge),
    };
  }

  function printSubmit(params) {
    const { jobId, peripheralId, format, data } = params;
    const copies = params.copies ?? 1;
    const reprint = params.reprint ?? false;

    if (typeof jobId !== 'string' || jobId === '') throw fail('INVALID_PARAMS', 'jobId must be a non-empty string');
    if (typeof peripheralId !== 'string' || peripheralId === '') throw fail('INVALID_PARAMS', 'peripheralId must be a non-empty string');
    if (!PRINT_FORMATS.includes(format)) throw fail('INVALID_PARAMS', `format must be one of: ${PRINT_FORMATS.join(', ')}`);
    if (!isBase64(data) || data === '') throw fail('INVALID_PARAMS', 'data must be non-empty base64');
    if (!Number.isInteger(copies) || copies < 1 || copies > PRINT_MAX_COPIES) {
      throw fail('INVALID_PARAMS', `copies must be an integer between 1 and ${PRINT_MAX_COPIES}`);
    }
    if (typeof reprint !== 'boolean') throw fail('INVALID_PARAMS', 'reprint must be a boolean');

    if (base64DecodedLength(data) > PRINT_MAX_BYTES) {
      throw fail('PAYLOAD_TOO_LARGE', `data exceeds ${PRINT_MAX_BYTES} bytes after decoding`);
    }

    const offered = capabilities();
    if (!offered.includes('print.escpos') && !offered.includes('print.pdf')) {
      throw fail('NOT_SUPPORTED', 'Printing is not offered on this device');
    }
    const peripheral = state.peripherals.find((p) => p.id === peripheralId);
    if (!peripheral) throw fail('PERIPHERAL_NOT_FOUND', `No peripheral with id ${peripheralId}`);
    if (peripheral.kind !== 'printer' || !offered.includes(`print.${format}`)) {
      throw fail('UNSUPPORTED_FORMAT', `Format ${format} is not supported here`);
    }
    if (sentJobIds.includes(jobId) && !reprint) {
      throw fail('DUPLICATE_JOB', `Job ${jobId} was already sent`);
    }
    if (peripheral.status !== 'ready') {
      throw fail('PERIPHERAL_UNAVAILABLE', `Peripheral ${peripheralId} is ${peripheral.status}`);
    }

    if (!sentJobIds.includes(jobId)) {
      sentJobIds.push(jobId);
      if (sentJobIds.length > JOB_MEMORY) sentJobIds.shift();
    }
    jobs.unshift({ jobId, peripheralId, format, bytes: base64ToBytes(data), copies, reprint, at: now() });
    if (jobs.length > PREVIEW_LIMIT) jobs.length = PREVIEW_LIMIT;
    changed();
    return { status: 'sent' };
  }

  async function call(method, params) {
    if (typeof method !== 'string' || !KNOWN_METHODS.includes(method)) {
      throw fail('UNKNOWN_METHOD', `Unknown method ${String(method)}`);
    }
    if (params !== undefined && params !== null && !isObject(params)) {
      throw fail('INVALID_PARAMS', 'params must be an object');
    }
    if (NOT_IMPLEMENTED.includes(method)) {
      throw fail('NOT_SUPPORTED', `The simulation does not offer ${method}`);
    }
    const capability = METHOD_CAPABILITY[method];
    if (capability && !capabilities().includes(capability)) {
      throw fail('NOT_SUPPORTED', `Capability ${capability} is not offered`);
    }

    const args = params ?? {};
    try {
      switch (method) {
        case 'info':
          return await info();
        case 'device.register':
          return await deviceRegister(args);
        case 'device.sync':
          return await sync();
        case 'peripherals.list':
          return { peripherals: publicPeripherals() };
        default:
          return printSubmit(args);
      }
    } catch (error) {
      if (isObject(error) && typeof error.code === 'string' && !(error instanceof Error)) throw error;
      throw fail('INTERNAL_ERROR', error instanceof Error ? error.message : String(error));
    }
  }

  // --- ovládání z panelu simulace ---

  const controls = {
    apiBase,

    /** Jde podepisovat? Bez zabezpečeného kontextu simulace capability `device` nenabízí. */
    canSign: () => key.canSign(),

    /** Snímek stavu pro panel. */
    snapshot() {
      return {
        dataSource: apiBase,
        mode: modeOf(state, apiBase),
        storedCapabilities: [...state.capabilities],
        capabilities: capabilities(),
        peripherals: publicPeripherals(),
      };
    },

    deviceId: () => key.deviceId(),

    /** Zapne nebo vypne capability; aplikace si má potom znovu přečíst `info`. */
    setCapability(name, enabled) {
      const set = new Set(state.capabilities);
      if (enabled) set.add(name);
      else set.delete(name);
      state.capabilities = [...set];
      persist();
    },

    /**
     * Přepne stav periferie: vyšle `shipard:peripheral` a registrované
     * zařízení periferie znovu nahlásí serveru (aplikace sync spouští
     * při změně periferií sama, kontrakt §3.3).
     */
    setPeripheralStatus(id, status) {
      const peripheral = state.peripherals.find((p) => p.id === id);
      if (!peripheral || !PERIPHERAL_STATUSES.includes(status) || peripheral.status === status) return false;
      peripheral.status = status;
      persist();
      if (capabilities().includes('peripherals')) emit('peripheral', { peripheralId: id, status });
      if (modeOf(state, apiBase) !== 'unregistered' && capabilities().includes('device')) {
        sync().catch(() => {});
      }
      return true;
    },

    /**
     * Čtečka něco načetla — vyšle `shipard:scan`. Odpojená čtečka nic
     * neposílá.
     *
     * @returns {boolean} šla událost ven?
     */
    emitScan({ kind = 'barcode', data, symbology = null }) {
      if (!SCAN_KINDS.includes(kind) || typeof data !== 'string' || data === '') return false;
      if (!capabilities().includes('scanner')) return false;
      const scanner = state.peripherals.find((p) => p.kind === 'scanner' && p.status === 'ready' && p.reads.includes(kind));
      if (!scanner) return false;
      emit('scan', { peripheralId: scanner.id, kind, data, symbology: kind === 'rfid' ? null : symbology, at: isoWithZone(now()) });
      return true;
    },

    /** Poslední úlohy `print.submit` pro náhled, nejnovější první. */
    jobs: () => [...jobs],

    /** Hlášení změn stavu a úloh. */
    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  };

  return { bridge: { __mock: true, call }, controls };
}

let installed = null;

/**
 * Vloží simulaci jako `window.shipardNative`. Skutečný most má přednost —
 * volající instaluje, jen když na stránce žádný není.
 *
 * @param {Parameters<typeof createMockBridge>[0]} env
 */
export function install(env) {
  installed = createMockBridge(env);
  env.target.shipardNative = installed.bridge;
  return installed;
}

/** Ovládání nainstalované simulace pro panel; `null`, když simulace neběží. */
export function mockControls() {
  return installed?.controls ?? null;
}
