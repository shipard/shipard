/**
 * Obálka mostu nativní aplikace `window.shipardNative` (docs/native-bridge.md
 * §2, #177 D21). Most se zjišťuje přítomností objektu, ne podle prohlížeče.
 *
 * Bez importů z `api/client.js` a bez `t()` aplikace — používá ji i
 * vývojářský dashboard (stránka Most). Čisté funkce nad `window`, v Node
 * testovatelné s `globalThis.window = new EventTarget()`.
 */

/**
 * Příznak simulace mostu v `localStorage` (#177 U11): hodnota `"1"` zapne
 * simulaci v aplikaci na tomto originu. Nastavuje ho stránka Most ve
 * vývojářském dashboardu, čte `main.js` aplikace.
 */
export const NATIVE_MOCK_FLAG = 'shpd_native_mock';

/** Chyba volání mostu — vždy tento tvar, ať most odmítl čímkoli. */
export class NativeBridgeError extends Error {
  /**
   * @param {string} code     kód kontraktu, nebo `NOT_AVAILABLE` / `INVALID_RESPONSE` (jen frontend)
   * @param {string} message  text pro vývojáře, ne pro uživatele
   * @param {object|null} [details]
   */
  constructor(code, message, details = null) {
    super(message);
    this.name = 'NativeBridgeError';
    this.code = code;
    this.details = details;
  }
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function currentBridge() {
  const bridge = typeof window === 'undefined' ? null : window.shipardNative;
  return typeof bridge?.call === 'function' ? bridge : null;
}

/** Je na stránce most (skutečný, nebo simulace)? */
export function isNativeAvailable() {
  return currentBridge() !== null;
}

/** Je most simulace z `native/mock/` (#177 D29)? */
export function isNativeMock() {
  return currentBridge()?.__mock === true;
}

function normalizeError(error) {
  if (error instanceof NativeBridgeError) return error;
  if (isObject(error) && typeof error.code === 'string' && error.code !== '') {
    const message = typeof error.message === 'string' && error.message !== '' ? error.message : error.code;
    return new NativeBridgeError(error.code, message, isObject(error.details) ? error.details : null);
  }
  const text = error instanceof Error ? error.message : String(error);
  return new NativeBridgeError('INVALID_RESPONSE', `Bridge rejected outside the contract: ${text}`);
}

/**
 * Zavolá metodu mostu. Odmítnutí je vždy `NativeBridgeError`: chybějící
 * most `NOT_AVAILABLE`, odmítnutí nebo výsledek mimo tvar kontraktu
 * `INVALID_RESPONSE`, jinak kód, zpráva a podrobnosti z mostu.
 *
 * @param {string} method
 * @param {object} [params]
 * @returns {Promise<object>}
 */
export async function nativeCall(method, params) {
  const bridge = currentBridge();
  if (!bridge) {
    throw new NativeBridgeError('NOT_AVAILABLE', 'Native bridge is not available');
  }
  let result;
  try {
    result = await (params === undefined ? bridge.call(method) : bridge.call(method, params));
  } catch (error) {
    throw normalizeError(error);
  }
  if (!isObject(result)) {
    throw new NativeBridgeError('INVALID_RESPONSE', `Bridge method ${method} did not return an object`);
  }
  return result;
}

let lastInfo = null;
let pendingInfo = null;

/**
 * `info` mostu s pamětí: druhé volání vrátí poslední výsledek, souběžná
 * volání sdílejí jeden dotaz. `refresh: true` se zeptá znovu (po
 * `device.sync`).
 *
 * @param {{refresh?: boolean}} [options]
 * @returns {Promise<object>}
 */
export function nativeInfo({ refresh = false } = {}) {
  if (!refresh && lastInfo) return Promise.resolve(lastInfo);
  if (!refresh && pendingInfo) return pendingInfo;
  const request = nativeCall('info').then(
    (info) => {
      if (pendingInfo === request) pendingInfo = null;
      lastInfo = info;
      return info;
    },
    (error) => {
      if (pendingInfo === request) pendingInfo = null;
      throw error;
    },
  );
  pendingInfo = request;
  return request;
}

/** Zapomene poslední `info` (testy, výměna mostu). */
export function resetNativeInfo() {
  lastInfo = null;
  pendingInfo = null;
}

/**
 * Nabízí aplikace capability? Čte poslední `info` — před prvním
 * `nativeInfo()` a bez mostu vrací `false`.
 *
 * @param {string} name
 */
export function hasCapability(name) {
  return Array.isArray(lastInfo?.capabilities) && lastInfo.capabilities.includes(name);
}

/**
 * Odběr události mostu `shipard:<name>` na `window`; handler dostane
 * `event.detail`. Nativní část události nefrontuje — kdo neposlouchá,
 * nedostane.
 *
 * @param {string} name  např. `scan`, `peripheral`, `session`
 * @param {(detail: object) => void} handler
 * @returns {() => void} odhlášení
 */
export function onNativeEvent(name, handler) {
  if (typeof window === 'undefined') return () => {};
  const type = `shipard:${name}`;
  const listener = (event) => handler(event.detail);
  window.addEventListener(type, listener);
  return () => window.removeEventListener(type, listener);
}
