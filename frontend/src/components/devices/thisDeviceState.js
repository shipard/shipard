/**
 * Panel Toto zařízení (Nastavení → Zařízení, #177 D13, D33) — čisté funkce:
 * co panel ukazuje podle mostu a odpovědí serveru, kroky registrace a čtení
 * záznamu zařízení. Bez importu `t()`, klienta API i mostu — vše jde
 * parametrem, aby se dalo testovat v Node.
 *
 * Kontrakt: docs/native-bridge.md §7.3 (registrace), §9a (simulace).
 */

/** Stavy panelu. */
export const PANEL = Object.freeze({
  /** Stránka nemá most — registrovat jde jen z nativní aplikace nebo ze simulace. */
  NO_BRIDGE: 'noBridge',
  /** Most je, ale `info` ještě není načtené. */
  LOADING: 'loading',
  /** Most je, ale `info` selhalo. */
  BRIDGE_ERROR: 'bridgeError',
  /** Most patří jinému zdroji dat než stránka (D33) — registrace by šla jinam. */
  OTHER_DATA_SOURCE: 'otherDataSource',
  /** Neregistrováno a most neumí podepisovat (chybí capability `device`). */
  CANNOT_SIGN: 'cannotSign',
  /** Neregistrováno, přihlášený je administrátor — formulář registrace. */
  REGISTER: 'register',
  /** Neregistrováno, přihlášený administrátor není. */
  NEEDS_ADMIN: 'needsAdmin',
  /** Registrované zařízení. */
  REGISTERED: 'registered',
});

/** Režimy, které jde při registraci zvolit (docs/native-bridge.md §1). */
export const REGISTER_MODES = Object.freeze(['personal', 'shared']);

export const NAME_MAX_LENGTH = 100;

/**
 * @param {object} input
 * @param {boolean} input.available  je na stránce most?
 * @param {object|null} input.info   poslední `info` mostu
 * @param {unknown} input.error      chyba posledního `info`
 * @param {string} input.apiBase     adresa API zdroje dat stránky (`location.origin + API_BASE_URL`)
 * @param {boolean} input.isAdmin
 * @returns {string} jeden ze stavů `PANEL`
 */
export function panelState({ available, info, error, apiBase, isAdmin }) {
  if (!available) return PANEL.NO_BRIDGE;
  if (info == null) return error ? PANEL.BRIDGE_ERROR : PANEL.LOADING;
  // Identita zařízení platí pro jeden zdroj dat; most musí mluvit o tomtéž
  // jako stránka, jinak by se zařízení zaregistrovalo jinam, než kde se pak ověřuje.
  if (info.dataSource !== apiBase) return PANEL.OTHER_DATA_SOURCE;
  if (info.mode === 'personal' || info.mode === 'shared') return PANEL.REGISTERED;
  if (!Array.isArray(info.capabilities) || !info.capabilities.includes('device')) return PANEL.CANNOT_SIGN;
  return isAdmin ? PANEL.REGISTER : PANEL.NEEDS_ADMIN;
}

/**
 * Kontrola formuláře registrace. Vrací klíč chyby (`required`, `tooLong`,
 * `mode`), nebo `null`.
 *
 * @param {{name: unknown, mode: unknown}} form
 * @returns {{field: 'name'|'mode', reason: string}|null}
 */
export function registrationFormError({ name, mode }) {
  const trimmed = typeof name === 'string' ? name.trim() : '';
  if (trimmed === '') return { field: 'name', reason: 'required' };
  if ([...trimmed].length > NAME_MAX_LENGTH) return { field: 'name', reason: 'tooLong' };
  if (!REGISTER_MODES.includes(mode)) return { field: 'mode', reason: 'mode' };
  return null;
}

/** Tělo `POST /_devices/register` (kontrakt §7.2). */
export function registerBody({ info, signed, challenge, name, mode }) {
  const body = {
    deviceId: signed.deviceId,
    algorithm: signed.algorithm,
    publicKey: signed.publicKey,
    challenge,
    signature: signed.signature,
    name: name.trim(),
    mode,
    platform: info.platform,
  };
  if (typeof info.app?.version === 'string' && info.app.version !== '') body.appVersion = info.app.version;
  if (typeof info.keyStorage === 'string' && info.keyStorage !== '') body.keyStorage = info.keyStorage;
  return body;
}

function serverFailure(step, response) {
  const error = response?.error;
  return {
    ok: false,
    step,
    source: 'server',
    // `null` vrací klient po 401 bez obnovení session.
    error: error && typeof error.code === 'string' ? error : { code: 'UNAUTHORIZED', message: 'Authentication required' },
  };
}

function bridgeFailure(step, error) {
  return {
    ok: false,
    step,
    source: 'bridge',
    error: { code: typeof error?.code === 'string' ? error.code : 'INTERNAL_ERROR', message: String(error?.message ?? error) },
  };
}

/**
 * Registrace zařízení (kontrakt §7.3):
 *
 *   1. server  POST /_devices/challenge {deviceId, purpose: "register"}
 *   2. most    device.register {challenge}
 *   3. server  POST /_devices/register
 *   4. most    device.sync
 *
 * Výzva platí 60 s — formulář je vyplněný předem a mezi kroky se na
 * uživatele nečeká. Chyba kroku 4 registraci neruší: záznam na serveru už
 * je, panel jen nabídne synchronizaci znovu (`syncError`).
 *
 * @param {object} deps
 * @param {object} deps.info                      `info` mostu
 * @param {string} deps.name
 * @param {string} deps.mode
 * @param {(path: string, body: object) => Promise<object|null>} deps.post  klient API (se session)
 * @param {(method: string, params?: object) => Promise<object>} deps.nativeCall
 * @returns {Promise<{ok: true, device: object, syncError: object|null}
 *   |{ok: false, step: string, source: 'server'|'bridge', error: {code: string, message: string}}>}
 */
export async function registerDevice({ info, name, mode, post, nativeCall }) {
  const issued = await post('/_devices/challenge', { deviceId: info.deviceId, purpose: 'register' });
  const challenge = issued?.success === true ? issued.data?.challenge : null;
  if (typeof challenge !== 'string' || challenge === '') return serverFailure('challenge', issued);

  let signed;
  try {
    signed = await nativeCall('device.register', { challenge });
  } catch (error) {
    return bridgeFailure('sign', error);
  }

  const registered = await post('/_devices/register', registerBody({ info, signed, challenge, name, mode }));
  if (registered?.success !== true) return serverFailure('register', registered);

  let syncError = null;
  try {
    await nativeCall('device.sync');
  } catch (error) {
    syncError = bridgeFailure('sync', error).error;
  }
  return { ok: true, device: registered.data ?? {}, syncError };
}

/** Kódy chyb mostu, pro které má panel vlastní text (`devices.bridgeError.<kód>`). */
export const BRIDGE_ERROR_CODES = Object.freeze([
  'NOT_AVAILABLE', 'INVALID_RESPONSE', 'UNKNOWN_METHOD', 'INVALID_PARAMS', 'NOT_SUPPORTED',
  'NOT_REGISTERED', 'DEVICE_REVOKED', 'NETWORK_ERROR', 'SERVER_ERROR', 'CANCELLED', 'INTERNAL_ERROR',
]);

/**
 * Klíč textu pro chybu mostu. Chyby mostu mají vlastní slovník — kódy
 * `INTERNAL_ERROR` a `NETWORK_ERROR` sdílejí jméno s REST chybami, ale
 * znamenají chybu aplikace, ne serveru.
 *
 * @param {string|null|undefined} code
 * @returns {string}
 */
export function bridgeErrorKey(code) {
  return `devices.bridgeError.${BRIDGE_ERROR_CODES.includes(code) ? code : 'unknown'}`;
}

/** Hodnoty, pro které má panel popisek (`devices.<skupina>.<hodnota>`). */
const KNOWN_LABELS = Object.freeze({
  mode: ['unregistered', 'personal', 'shared'],
  platform: ['macos', 'ipados', 'mock'],
  kind: ['printer', 'scanner'],
  status: ['ready', 'offline', 'error'],
  role: ['receipt'],
});

/**
 * Klíč popisku hodnoty hlášené mostem nebo serverem; `null` pro hodnotu,
 * kterou panel nezná — novější aplikace smí hlásit víc (kontrakt §2.4)
 * a panel ji pak ukáže, jak přišla.
 *
 * @param {'mode'|'platform'|'kind'|'status'|'role'} group
 * @param {unknown} value
 * @returns {string|null}
 */
export function labelKey(group, value) {
  return typeof value === 'string' && (KNOWN_LABELS[group] ?? []).includes(value) ? `devices.${group}.${value}` : null;
}

/** Cesta generického seznamu se záznamem zařízení podle `deviceId` z mostu (U26). */
export function deviceRecordPath(deviceId) {
  return `/core_devices_devices?filter[device_id]=eq:${encodeURIComponent(deviceId)}&limit=1`;
}

/** Cesta generického seznamu s periferiemi zařízení. */
export function peripheralsPath(deviceRowId) {
  return `/core_devices_peripherals?filter[device]=eq:${encodeURIComponent(String(deviceRowId))}&limit=100`;
}

/**
 * Periferie pro panel: připojené napřed, pak podle druhu a názvu; čísla
 * z CRUD na `present` boolean.
 *
 * @param {unknown} rows  `data` generického seznamu
 * @returns {Array<{id: number, name: string, kind: string, transport: string, status: string, present: boolean, role: string|null}>}
 */
export function peripheralRows(rows) {
  if (!Array.isArray(rows)) return [];
  return rows
    .filter((row) => row !== null && typeof row === 'object')
    .map((row) => ({
      id: Number(row.id),
      name: String(row.name ?? ''),
      kind: String(row.kind ?? ''),
      transport: String(row.transport ?? ''),
      status: String(row.status ?? ''),
      present: Number(row.present) === 1 || row.present === true,
      role: typeof row.role === 'string' && row.role.trim() !== '' ? row.role.trim() : null,
    }))
    .sort((a, b) => Number(b.present) - Number(a.present)
      || a.kind.localeCompare(b.kind)
      || a.name.localeCompare(b.name)
      || a.id - b.id);
}
