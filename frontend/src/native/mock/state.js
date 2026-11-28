/**
 * Stav simulace mostu v `localStorage` (#177 U12): režim zařízení pro každý
 * zdroj dat (D33), capabilities a periferie se stavem. Úložiště dostává
 * parametrem — v testech stačí objekt s `getItem` / `setItem`.
 */

export const MOCK_STATE_KEY = 'shpd_native_mock_state';

export const MOCK_PRINTER_ID = 'mock-receipt';
/** Druhá, úzká tiskárna (#177 U43) — jde na ní proklikat jedinečnost role i záložní šířku. */
export const MOCK_NARROW_PRINTER_ID = 'mock-receipt-58';
export const MOCK_SCANNER_ID = 'mock-scanner';

export const DEFAULT_CAPABILITIES = ['device', 'peripherals', 'print.escpos', 'scanner'];

/**
 * Co simulace potřebuje vědět o profilu tiskárny pro náhled. Katalog
 * profilů je na serveru (docs/native-bridge.md §6,
 * modules/core/prints/config/printerProfiles.jsonc) a registrovaná simulace
 * si ho stahuje (`profilesFromCatalog`); tyhle vestavěné hodnoty platí jen
 * do registrace a když se katalog stáhnout nepovede. Musí sedět na katalog
 * (hlídá mock.test.mjs).
 */
export const MOCK_PRINTER_PROFILES = {
  'escpos-80-cp852': { columns: 42, columnsSmall: 56, codePage: 'cp852' },
  'escpos-58-cp852': { columns: 32, columnsSmall: 42, codePage: 'cp852' },
};

/** Profil pro tiskárnu, kterou simulace nezná: nejužší z katalogu, vejde se všude. */
export const MOCK_FALLBACK_PROFILE = { columns: 32, columnsSmall: 42, codePage: 'cp852' };

const MODES = ['unregistered', 'personal', 'shared'];
const STATUSES = ['ready', 'offline', 'error'];

/**
 * Katalog profilů ze serveru (`GET /_devices/printer-profiles`) → to, co
 * potřebuje náhled. Profil s neúplnými údaji se přeskočí; odpověď, která
 * katalog není, dá `null` (simulace zůstane u vestavěných profilů).
 *
 * @param {unknown} data  `data` odpovědi serveru
 * @returns {Record<string, {columns: number, columnsSmall: number, codePage: string}>|null}
 */
export function profilesFromCatalog(data) {
  if (data === null || typeof data !== 'object' || !Array.isArray(data.profiles)) return null;
  const profiles = {};
  for (const profile of data.profiles) {
    if (profile === null || typeof profile !== 'object') continue;
    const { id, columns, columnsSmall, codePage } = profile;
    if (typeof id !== 'string' || id === '' || typeof codePage !== 'string' || codePage === '') continue;
    if (!Number.isInteger(columns) || columns < 1 || !Number.isInteger(columnsSmall) || columnsSmall < 1) continue;
    profiles[id] = { columns, columnsSmall, codePage };
  }
  return profiles;
}

/**
 * Profil pro náhled: katalog ze serveru má přednost, bez něj vestavěný,
 * neznámý profil dostane záložní — stejně jako na serveru.
 *
 * @param {string|null|undefined} profileId
 * @param {Record<string, object>|null} catalog
 */
export function previewProfile(profileId, catalog) {
  return catalog?.[profileId] ?? MOCK_PRINTER_PROFILES[profileId] ?? MOCK_FALLBACK_PROFILE;
}

export function defaultState() {
  return {
    modes: {},
    capabilities: [...DEFAULT_CAPABILITIES],
    peripherals: [
      { id: MOCK_PRINTER_ID, kind: 'printer', name: 'Mock receipt printer', transport: 'tcp', status: 'ready', profileId: 'escpos-80-cp852' },
      { id: MOCK_SCANNER_ID, kind: 'scanner', name: 'Mock scanner', transport: 'usb', status: 'ready', reads: ['barcode', 'rfid'] },
      { id: MOCK_NARROW_PRINTER_ID, kind: 'printer', name: 'Mock receipt printer 58 mm', transport: 'bluetooth', status: 'ready', profileId: 'escpos-58-cp852' },
    ],
  };
}

/** Uložený stav, nebo výchozí — poškozený záznam se nahradí výchozím po částech. */
export function loadState(storage) {
  const state = defaultState();
  let stored = null;
  try {
    stored = JSON.parse(storage.getItem(MOCK_STATE_KEY) ?? 'null');
  } catch {
    stored = null;
  }
  if (stored === null || typeof stored !== 'object') return state;

  if (stored.modes !== null && typeof stored.modes === 'object') {
    for (const [apiBase, mode] of Object.entries(stored.modes)) {
      if (MODES.includes(mode)) state.modes[apiBase] = mode;
    }
  }
  if (Array.isArray(stored.capabilities)) {
    state.capabilities = stored.capabilities.filter((c) => typeof c === 'string' && c !== '');
  }
  // Periferie simulace jsou pevné — z uloženého stavu se bere jen jejich stav.
  if (Array.isArray(stored.peripherals)) {
    for (const peripheral of state.peripherals) {
      const saved = stored.peripherals.find((p) => p?.id === peripheral.id);
      if (saved && STATUSES.includes(saved.status)) peripheral.status = saved.status;
    }
  }
  return state;
}

export function saveState(storage, state) {
  try {
    storage.setItem(MOCK_STATE_KEY, JSON.stringify(state));
  } catch {
    // plné nebo zakázané úložiště — simulace běží dál se stavem v paměti
  }
}

/** Režim zařízení v daném zdroji dat (D33). */
export function modeOf(state, apiBase) {
  return state.modes[apiBase] ?? 'unregistered';
}
